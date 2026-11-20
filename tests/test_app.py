"""The HTTP layer: status codes, headers, routing and configuration."""

from __future__ import annotations

import io
import json
import re

import pytest

from sunrise_sunset_calendar import app as app_module
from sunrise_sunset_calendar import web
from sunrise_sunset_calendar.app import Config, make_app
from tests.helpers import StartResponse

TOKEN = b"app-test-token"
NOW = 1774778400  # 2026-03-29 10:00 UTC
SECURITY = [
    ("X-Content-Type-Options", "nosniff"),
    ("X-Frame-Options", "DENY"),
    ("Referrer-Policy", "no-referrer"),
    ("Content-Security-Policy", app_module.CONTENT_SECURITY_POLICY),
]
NO_STORE = ("Cache-Control", "no-store")


def call(
    config: Config, path: str = "/", query: str = "", method: str = "GET", body: bytes = b"", **extra: str
) -> tuple[str, list[tuple[str, str]], bytes]:
    application = make_app(config, clock=lambda: NOW)
    environ = {
        "REQUEST_METHOD": method,
        "PATH_INFO": path,
        "QUERY_STRING": query,
        "HTTP_HOST": "calendar.example",
        "wsgi.input": io.BytesIO(body),
        "CONTENT_LENGTH": str(len(body)),
        **extra,
    }
    start_response = StartResponse()
    output = b"".join(application(environ, start_response))
    status, headers = start_response.calls[0]
    return status, headers, output


@pytest.fixture
def config() -> Config:
    return Config(auth_token=TOKEN, window_days=3, past_days=1, update_interval=7200)


@pytest.mark.parametrize("token", [None, b"", b"CHANGE_ME_TO_A_RANDOM_STRING"])
def test_without_a_token_every_request_fails(token: bytes | None) -> None:
    status, headers, body = call(Config(auth_token=token), "/health")
    assert status == "500 Internal Server Error"
    assert body == b"Error: set the AUTH_TOKEN environment variable"
    assert headers[:6] == [*SECURITY, ("Content-Type", "text/html; charset=UTF-8"), NO_STORE]


def test_config_reads_several_tokens() -> None:
    config = Config.from_env({"AUTH_TOKEN": "main", "AUTH_TOKENS": " alice, ,bob "})
    assert config.tokens == (b"main", b"alice", b"bob")
    assert Config.from_env({"AUTH_TOKENS": "alice"}).tokens == (b"alice",)


def test_any_of_the_tokens_opens_feed_and_page() -> None:
    config = Config(auth_token=None, extra_tokens=(b"alice", b"bob"))
    assert call(config, query="feed=1&token=bob&daynight=1")[0] == "200 OK"
    assert call(config, query="token=alice")[0] == "200 OK"
    assert call(config, query="token=carol")[0] == "403 Forbidden"


def test_the_page_builds_urls_with_its_own_token() -> None:
    """Revoking one person's token then stops only the feeds that person built."""
    config = Config(auth_token=TOKEN, extra_tokens=(b"alice",))
    _, _, page = call(
        config,
        query="token=alice",
        method="POST",
        body=b"generate_url=1",
        CONTENT_TYPE="application/x-www-form-urlencoded",
    )
    assert b"token=alice&amp;" in page
    assert TOKEN not in page


def test_health(config: Config) -> None:
    status, headers, body = call(config, "/health")
    assert status == "200 OK"
    assert headers[:6] == [*SECURITY, ("Content-Type", "application/json"), NO_STORE]
    assert json.loads(body) == {"status": "ok", "years": [2000, 2148]}


@pytest.mark.parametrize(("past", "window"), [(30, 50000), (10000, 400)])
def test_health_is_down_when_feeds_reach_past_the_ephemeris(past: int, window: int) -> None:
    status, _, body = call(Config(auth_token=TOKEN, past_days=past, window_days=window), "/health")
    assert status == "503 Service Unavailable"
    data = json.loads(body)
    assert data["status"] == "down"
    assert data["problems"][0].endswith("outside the ephemeris")


def test_health_head_has_no_body(config: Config) -> None:
    status, _, body = call(config, "/health", method="HEAD")
    assert status == "200 OK"
    assert body == b""


def test_the_old_health_query_is_just_the_page(config: Config) -> None:
    status, headers, body = call(config, query="health")
    assert status == "403 Forbidden", "The page needs the token"
    status, headers, body = call(config, query="health&token=app-test-token")
    assert status == "200 OK"
    assert ("Content-Type", "text/html; charset=UTF-8") in headers
    assert b"<!DOCTYPE html>" in body


def test_feed_headers(config: Config) -> None:
    status, headers, body = call(config, query=f"feed=1&token={TOKEN.decode()}&daynight=1")
    assert status == "200 OK"
    etag = dict(headers)["ETag"]
    assert headers[:7] == [
        *SECURITY,
        ("Content-Type", "text/calendar; charset=utf-8"),
        ("ETag", etag),
        ("Cache-Control", "private, max-age=7200"),
    ]
    assert re.fullmatch(r'"[0-9a-f]{20}"', etag)
    assert body.startswith(b"BEGIN:VCALENDAR\r\n")
    assert b"REFRESH-INTERVAL;VALUE=DURATION:PT2H\r\n" in body


FEED = f"feed=1&token={TOKEN.decode()}&lat=41.9&lon=12.5&zone=Europe%2FRome&daynight=1"


def test_an_unchanged_feed_is_a_304(config: Config, monkeypatch: pytest.MonkeyPatch) -> None:
    etag = dict(call(config, query=FEED)[1])["ETag"]

    def no_feed(*_: object) -> bytes:
        raise AssertionError("A 304 builds no feed")

    monkeypatch.setattr(app_module, "render_feed", no_feed)
    for header in (etag, f"W/{etag}", f'"other", {etag}', "*"):
        status, headers, body = call(config, query=FEED, HTTP_IF_NONE_MATCH=header)
        assert status == "304 Not Modified", header
        assert body == b""
        assert ("ETag", etag) in headers
        assert ("Cache-Control", "private, max-age=7200") in headers


def test_the_etag_follows_what_the_feed_depends_on(config: Config, monkeypatch: pytest.MonkeyPatch) -> None:
    def etag(query: str = FEED, now: int = NOW, cfg: Config = config) -> str:
        application = app_module.make_app(cfg, clock=lambda: now)
        environ = {
            "REQUEST_METHOD": "GET",
            "PATH_INFO": "/",
            "QUERY_STRING": query,
            "HTTP_HOST": "x",
            "wsgi.input": io.BytesIO(b""),
            "CONTENT_LENGTH": "0",
        }
        response = StartResponse()
        b"".join(application(environ, response))
        return dict(response.calls[0][1])["ETag"]

    base = etag()
    assert etag(now=NOW + 3600) == base, "Same local date"
    assert etag(now=NOW + 86400) != base, "Next day"
    assert etag(FEED.replace("lat=41.9", "lat=45")) != base, "Another place"
    assert etag(cfg=Config(auth_token=TOKEN, window_days=4, past_days=1, update_interval=7200)) != base
    other = Config(auth_token=b"other", window_days=3, past_days=1, update_interval=7200)
    assert etag(FEED.replace(TOKEN.decode(), "other"), cfg=other) == base, "The token isn't part of it"
    monkeypatch.setenv("K_REVISION", "sunrise-sunset-calendar-00042-abc")
    assert etag() != base, "A new deploy"


@pytest.mark.parametrize("query", ["feed=1", "feed=1&token=wrong", "feed=1&token[]=app-test-token", "feed&token=app"])
def test_feed_rejects_bad_tokens(config: Config, query: str) -> None:
    status, headers, body = call(config, query=query)
    assert status == "403 Forbidden"
    assert body == b"Invalid authentication token"
    assert headers[:6] == [*SECURITY, ("Content-Type", "text/html; charset=UTF-8"), NO_STORE]


API = f"token={TOKEN.decode()}&lat=41.9028&lon=12.4964&zone=Europe%2FRome"


def test_api_now(config: Config) -> None:
    status, headers, body = call(config, "/api/v1/now", query=API)
    assert status == "200 OK"
    assert headers[:6] == [*SECURITY, ("Content-Type", "application/json"), NO_STORE]
    data = json.loads(body)
    assert data["at"] == "2026-03-29T12:00:00+02:00"
    assert data["location"] == {"lat": 41.9028, "lon": 12.4964, "zone": "Europe/Rome"}
    assert data["sun"]["period"] == "daylight" and data["sun"]["up"] is True
    assert call(config, "/api/v1/now", query=API, method="HEAD")[2] == b""


@pytest.mark.parametrize(
    ("at", "expected"),
    [("1767268800", "2026-01-01T13:00:00+01:00"), ("2026-07-01T06:30", "2026-07-01T06:30:00+02:00")],
)
def test_api_now_at_a_moment(config: Config, at: str, expected: str) -> None:
    status, _, body = call(config, "/api/v1/now", query=f"{API}&at={at}")
    assert status == "200 OK"
    assert json.loads(body)["at"] == expected


@pytest.mark.parametrize("authorization", ["", "Bearer wrong", f"Basic {TOKEN.decode()}"])
def test_api_needs_the_token(config: Config, authorization: str) -> None:
    status, headers, body = call(config, "/api/v1/now", query="lat=1&lon=2&zone=UTC", HTTP_AUTHORIZATION=authorization)
    assert status == "401 Unauthorized"
    assert headers[:7] == [*SECURITY, ("Content-Type", "application/json"), NO_STORE, ("WWW-Authenticate", "Bearer")]
    assert json.loads(body) == {"error": "Invalid authentication token"}


@pytest.mark.parametrize("authorization", [f"Bearer {TOKEN.decode()}", f"bearer  {TOKEN.decode()} "])
def test_api_takes_the_token_from_a_bearer_header(config: Config, authorization: str) -> None:
    query = "lat=41.9028&lon=12.4964&zone=Europe%2FRome"
    assert call(config, "/api/v1/now", query=query, HTTP_AUTHORIZATION=authorization)[0] == "200 OK"


def test_a_bearer_header_wins_over_the_query(config: Config) -> None:
    status = call(config, "/api/v1/now", query=API, HTTP_AUTHORIZATION="Bearer wrong")[0]
    assert status == "401 Unauthorized"


@pytest.mark.parametrize(
    ("query", "message"),
    [
        ("lon=12&zone=UTC", "lat must be"),
        ("lat=91&lon=12&zone=UTC", "lat must be"),
        ("lat=41&lon=x&zone=UTC", "lon must be"),
        ("lat=41&lon=12", "zone must be"),
        ("lat=41&lon=12&zone=Mars%2FOlympus", "zone must be"),
        ("lat=41&lon=12&zone=UTC&at=yesterday", "at must be a Unix time"),
        ("lat=41&lon=12&zone=UTC&at=1990-01-01T00:00", "at must fall between"),
    ],
)
def test_api_refuses_what_it_cannot_read(config: Config, query: str, message: str) -> None:
    status, headers, body = call(config, "/api/v1/now", query=f"token={TOKEN.decode()}&{query}")
    assert status == "400 Bad Request"
    assert ("Content-Type", "application/json") in headers
    assert json.loads(body)["error"].startswith(message)


@pytest.mark.parametrize("query", ["", "token=wrong", "token[]=app-test-token"])
def test_page_needs_the_token(config: Config, query: str) -> None:
    status, headers, body = call(config, query=query)
    assert status == "403 Forbidden"
    assert body == b"Invalid authentication token"
    assert headers[:6] == [*SECURITY, ("Content-Type", "text/html; charset=UTF-8"), NO_STORE]


def test_page(config: Config) -> None:
    status, headers, body = call(config, "/", query="token=app-test-token")
    assert status == "200 OK"
    assert headers[:6] == [*SECURITY, ("Content-Type", "text/html; charset=UTF-8"), NO_STORE]
    assert b'<option value="Europe/Rome" selected>Europe/Rome</option>' in body
    # Nothing inline for the Content-Security-Policy to block
    assert b"<script>" not in body
    assert b"onclick" not in body and b"onchange" not in body and b"onsubmit" not in body
    assert b"style=" not in body
    assert b"password" not in body, "The token is in the URL already"


@pytest.mark.parametrize("path", ["/sunrise-sunset-calendar.php", "/sunrise-sunset-calendar.php/extra"])
def test_the_php_path_is_gone(config: Config, path: str) -> None:
    status, _, body = call(config, path, query="feed=1&token=app-test-token")
    assert status == "404 Not Found"
    assert body == b"Not Found"


def test_head_has_no_body(config: Config) -> None:
    status, headers, body = call(config, method="HEAD", query="token=app-test-token")
    assert status == "200 OK"
    assert body == b""
    assert int(dict(headers)["Content-Length"]) > 1000


@pytest.mark.parametrize(
    ("path", "content_type"), [("/assets/styles.css", "text/css"), ("/assets/script.js", "text/javascript")]
)
def test_assets(config: Config, path: str, content_type: str) -> None:
    status, headers, body = call(config, path)
    assert status == "200 OK"
    assert headers[:4] == SECURITY
    assert dict(headers)["Content-Type"] == content_type
    assert len(body) == int(dict(headers)["Content-Length"]) > 100
    assert call(config, path, method="HEAD")[2] == b""


def test_the_page_names_its_assets_by_content(config: Config) -> None:
    _, _, body = call(config, "/", query="token=app-test-token")
    for name in ("styles.css", "script.js"):
        url = web.asset_url(name)
        assert url.startswith(f"assets/{name}?v=") and len(url) == len(f"assets/{name}?v=") + 12
        assert url.encode() in body
        # The server serves the asset whatever the version says
        status, _, served = call(config, f"/assets/{name}", query="v=anything")
        assert status == "200 OK" and len(served) > 100


@pytest.mark.parametrize("path", ["/index.php", "/src/app.py", "/assets/", "/favicon.ico"])
def test_other_paths_are_not_found(config: Config, path: str) -> None:
    status, headers, _ = call(config, path)
    assert status == "404 Not Found"
    assert headers[:4] == SECURITY


def form_post(
    config: Config, body: bytes, content_type: str, query: str = "token=app-test-token", **extra: str
) -> tuple[str, list[tuple[str, str]], bytes]:
    """The form posts back to the page's own URL, which holds the token."""
    return call(config, query=query, method="POST", body=body, CONTENT_TYPE=content_type, **extra)


def test_form_generates_urls(config: Config) -> None:
    body = b"generate_url=1&lat=1&lon=2&zone=UTC&location=A+%26+B&civil=on&daynight=on"
    _, _, page = form_post(config, body, "application/x-www-form-urlencoded", HTTP_X_FORWARDED_PROTO="https")
    url = (
        "https://calendar.example/?feed=1&amp;token=app-test-token&amp;lat=1&amp;lon=2"
        "&amp;zone=UTC&amp;location=A+%26+B&amp;rise_off=0&amp;set_off=0&amp;desc=&amp;civil=1&amp;daynight=1"
    )
    assert url.encode() in page
    assert url.replace("https://", "webcal://").encode() in page


def test_form_over_plain_http(config: Config) -> None:
    _, _, page = form_post(config, b"generate_url=1", "application/x-www-form-urlencoded")
    assert b"http://calendar.example/?feed=1&amp;" in page
    _, _, page = form_post(config, b"generate_url=1", "application/x-www-form-urlencoded", HTTPS="on")
    assert b"https://calendar.example/?feed=1&amp;" in page


def test_form_behind_a_proxy(config: Config) -> None:
    _, _, page = form_post(
        config,
        b"generate_url=1",
        "application/x-www-form-urlencoded",
        HTTP_X_FORWARDED_PROTO="https",
        HTTP_X_FORWARDED_HOST="calendar.proxy.example",
    )
    assert b"https://calendar.proxy.example/?feed=1&amp;" in page
    assert b"webcal://calendar.proxy.example/?feed=1&amp;" in page


@pytest.mark.parametrize(
    ("forwarded", "expected"),
    [
        ({"HTTP_X_FORWARDED_HOST": "evil.example/<script>"}, b"http://calendar.example/?"),
        ({"HTTP_X_FORWARDED_HOST": "a.example, b.example"}, b"http://a.example/?"),
    ],
)
def test_form_ignores_malformed_forwarded_headers(config: Config, forwarded: dict, expected: bytes) -> None:
    _, _, page = form_post(config, b"generate_url=1", "application/x-www-form-urlencoded", **forwarded)
    assert expected in page


@pytest.mark.parametrize("query", ["", "token=nope"])
def test_form_needs_the_token(config: Config, query: str) -> None:
    status, _, page = form_post(config, b"generate_url=1", "application/x-www-form-urlencoded", query=query)
    assert status == "403 Forbidden"
    assert b"feed=1" not in page


def test_multipart_form(config: Config) -> None:
    boundary = "XyZ"
    parts = [("generate_url", "1"), ("location", "Côte"), ("astro", "on")]
    body = "".join(
        f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n' for name, value in parts
    )
    body += f'--{boundary}\r\nContent-Disposition: form-data; name="up"; filename="a.txt"\r\n\r\nfile\r\n'
    body += f"--{boundary}--\r\n"
    _, _, page = form_post(config, body.encode(), f"multipart/form-data; boundary={boundary}")
    assert b"location=C%C3%B4te" in page
    assert b"&amp;astro=1" in page


def test_post_without_a_form_body_shows_the_page(config: Config) -> None:
    _, _, page = form_post(config, b"generate_url=1", "text/plain", CONTENT_LENGTH="x")
    assert b"success-box" not in page


def test_config_from_env() -> None:
    config = Config.from_env(
        {"AUTH_TOKEN": "s3cret", "CALENDAR_WINDOW_DAYS": "12", "CALENDAR_PAST_DAYS": " 3", "UPDATE_INTERVAL": "1000"}
    )
    assert config == Config(auth_token=b"s3cret", window_days=12, past_days=3, update_interval=1000)
    assert Config.from_env({}) == Config(auth_token=None)


@pytest.mark.parametrize("value", ["12abc", "3 days", "1e3", "x", ""])
def test_config_refuses_a_number_it_cannot_read(value: str) -> None:
    with pytest.raises(SystemExit, match="CALENDAR_WINDOW_DAYS must be a whole number"):
        Config.from_env({"AUTH_TOKEN": "s3cret", "CALENDAR_WINDOW_DAYS": value})


def test_main_serves_on_port(monkeypatch: pytest.MonkeyPatch) -> None:
    served: dict[str, object] = {}

    def serve(application: object, **kwargs: object) -> None:
        served.update(kwargs, app=application)

    monkeypatch.setattr("waitress.serve", serve)
    monkeypatch.setenv("PORT", "9999")
    monkeypatch.setenv("AUTH_TOKEN", "x")
    app_module.main()
    assert served["port"] == 9999
    assert served["host"] == "0.0.0.0"  # noqa: S104
    assert callable(served["app"])
