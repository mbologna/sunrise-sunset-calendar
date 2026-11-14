"""The HTTP layer: status codes, headers, routing and configuration, as the PHP entry point had them."""

from __future__ import annotations

import io
import json

import pytest

from sunrise_sunset_calendar import app as app_module
from sunrise_sunset_calendar.app import Config, make_app

TOKEN = b"app-test-token"
NOW = 1774778400  # 2026-03-29 10:00 UTC
SECURITY = [("X-Content-Type-Options", "nosniff"), ("X-Frame-Options", "DENY"), ("Referrer-Policy", "no-referrer")]


def call(config: Config, path: str = "/", query: str = "", method: str = "GET", body: bytes = b"", **extra):
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
    result: list = []
    output = b"".join(application(environ, lambda status, headers: result.extend([status, headers])))
    return result[0], result[1], output


@pytest.fixture
def config() -> Config:
    return Config(auth_token=TOKEN, window_days=3, past_days=1, update_interval=7200)


@pytest.mark.parametrize("token", [None, b"", b"CHANGE_ME_TO_A_RANDOM_STRING"])
def test_without_a_token_every_request_fails(token) -> None:
    status, headers, body = call(Config(auth_token=token), query="health")
    assert status == "500 Internal Server Error"
    assert body == b"Error: Please set AUTH_TOKEN via environment variable or config/config.php"
    # PHP exits before it sets the security headers
    assert headers[0] == ("Content-Type", "text/html; charset=UTF-8")
    assert not any(name.startswith("X-") for name, _ in headers)


def test_health(config: Config) -> None:
    status, headers, body = call(config, query="health")
    assert status == "200 OK"
    assert headers[:4] == [*SECURITY, ("Content-Type", "application/json")]
    data = json.loads(body)
    assert data["status"] == "ok"
    assert data["timestamp"] == NOW
    assert "python" in data


def test_feed_headers(config: Config) -> None:
    status, headers, body = call(config, query=f"feed=1&token={TOKEN.decode()}&daynight=1")
    assert status == "200 OK"
    assert headers[:5] == [
        *SECURITY,
        ("Content-Type", "text/calendar; charset=utf-8"),
        ("Cache-Control", "max-age=7200"),
    ]
    assert body.startswith(b"BEGIN:VCALENDAR\r\n")
    assert b"REFRESH-INTERVAL;VALUE=DURATION:PT2H\r\n" in body


@pytest.mark.parametrize("query", ["feed=1", "feed=1&token=wrong", "feed=1&token[]=app-test-token", "feed&token=app"])
def test_feed_rejects_bad_tokens(config: Config, query: str) -> None:
    status, headers, body = call(config, query=query)
    assert status == "403 Forbidden"
    assert body == b"Invalid authentication token"
    assert headers[:4] == [*SECURITY, ("Content-Type", "text/html; charset=UTF-8")]


def test_page(config: Config) -> None:
    for path in ("/", "/sunrise-sunset-calendar.php", "/sunrise-sunset-calendar.php/extra"):
        status, headers, body = call(config, path)
        assert status == "200 OK"
        assert headers[:4] == [*SECURITY, ("Content-Type", "text/html; charset=UTF-8")]
        assert b'<option value="Europe/Rome" selected>Europe/Rome</option>' in body


def test_head_has_no_body(config: Config) -> None:
    status, headers, body = call(config, method="HEAD")
    assert status == "200 OK"
    assert body == b""
    assert int(dict(headers)["Content-Length"]) > 1000


@pytest.mark.parametrize(
    ("path", "content_type"), [("/assets/styles.css", "text/css"), ("/assets/script.js", "text/javascript")]
)
def test_assets(config: Config, path: str, content_type: str) -> None:
    status, headers, body = call(config, path)
    assert status == "200 OK"
    assert dict(headers)["Content-Type"] == content_type
    assert len(body) == int(dict(headers)["Content-Length"]) > 100
    assert call(config, path, method="HEAD")[2] == b""


@pytest.mark.parametrize("path", ["/index.php", "/src/app.py", "/assets/", "/favicon.ico"])
def test_other_paths_are_not_found(config: Config, path: str) -> None:
    assert call(config, path)[0] == "404 Not Found"


def form_post(config: Config, body: bytes, content_type: str, **extra):
    return call(config, method="POST", body=body, CONTENT_TYPE=content_type, **extra)


def test_form_generates_urls(config: Config) -> None:
    body = b"generate_url=1&password=app-test-token&lat=1&lon=2&zone=UTC&location=A+%26+B&civil=on&daynight=on"
    _, _, page = form_post(config, body, "application/x-www-form-urlencoded", HTTP_X_FORWARDED_PROTO="https")
    url = (
        "https://calendar.example/sunrise-sunset-calendar.php?feed=1&amp;token=app-test-token&amp;lat=1&amp;lon=2"
        "&amp;zone=UTC&amp;location=A+%26+B&amp;rise_off=0&amp;set_off=0&amp;desc=&amp;civil=1&amp;daynight=1"
    )
    assert url.encode() in page
    assert url.replace("https://", "webcal://").encode() in page


def test_form_over_plain_http(config: Config) -> None:
    _, _, page = form_post(config, b"generate_url=1&password=app-test-token", "application/x-www-form-urlencoded")
    assert b"http://calendar.example/sunrise-sunset-calendar.php?feed=1&amp;" in page
    _, _, page = form_post(
        config, b"generate_url=1&password=app-test-token", "application/x-www-form-urlencoded", HTTPS="on"
    )
    assert b"https://calendar.example/sunrise-sunset-calendar.php?feed=1&amp;" in page


def test_form_rejects_a_wrong_password(config: Config) -> None:
    _, _, page = form_post(config, b"generate_url=1&password=nope", "application/x-www-form-urlencoded")
    assert b"<strong>Error:</strong> Invalid password" in page
    assert b"subscription-url" not in page.split(b"<form")[0]


def test_multipart_form(config: Config) -> None:
    boundary = "XyZ"
    parts = [("generate_url", "1"), ("password", "app-test-token"), ("location", "Côte"), ("astro", "on")]
    body = "".join(
        f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n' for name, value in parts
    )
    body += f'--{boundary}\r\nContent-Disposition: form-data; name="up"; filename="a.txt"\r\n\r\nfile\r\n'
    body += f"--{boundary}--\r\n"
    _, _, page = form_post(config, body.encode(), f"multipart/form-data; boundary={boundary}")
    assert b"location=C%C3%B4te" in page
    assert b"&amp;astro=1" in page


def test_post_without_a_form_body_shows_the_page(config: Config) -> None:
    _, _, page = form_post(config, b"generate_url=1&password=app-test-token", "text/plain", CONTENT_LENGTH="x")
    assert b"success-box" not in page


def test_config_from_env() -> None:
    config = Config.from_env(
        {"AUTH_TOKEN": "s3cret", "CALENDAR_WINDOW_DAYS": "12abc", "CALENDAR_PAST_DAYS": " 3", "UPDATE_INTERVAL": "1e3"}
    )
    assert config == Config(auth_token=b"s3cret", window_days=12, past_days=3, update_interval=1000)
    assert Config.from_env({}) == Config(auth_token=None)


def test_main_serves_on_port(monkeypatch: pytest.MonkeyPatch) -> None:
    served = {}

    def serve(application, **kwargs):
        served.update(kwargs, app=application)

    monkeypatch.setattr("waitress.serve", serve)
    monkeypatch.setenv("PORT", "9999")
    monkeypatch.setenv("AUTH_TOKEN", "x")
    app_module.main()
    assert served["port"] == 9999
    assert served["host"] == "0.0.0.0"  # noqa: S104
    assert callable(served["app"])
