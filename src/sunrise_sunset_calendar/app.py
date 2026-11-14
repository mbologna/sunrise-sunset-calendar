"""The HTTP entry point: the feed, the URL-builder page, its assets and a health check.

Configuration comes from the environment:

- AUTH_TOKEN (required): the secret in feed URLs, and the page's password
- CALENDAR_WINDOW_DAYS (400): days of future events in a feed
- CALENDAR_PAST_DAYS (30): days of past events
- UPDATE_INTERVAL (86400): refresh interval suggested to calendar apps, in seconds
- PORT (8080): where the server listens
"""

from __future__ import annotations

import hmac
import json
import os
import platform
import re
import time
from dataclasses import dataclass
from email.parser import BytesParser
from email.policy import HTTP
from importlib import resources

from .feed import Options, render_feed
from .php import ARRAY, env_int, filter_validate_float, filter_validate_int, http_build_query, mb_substr, parse_query
from .php import strip_tags as php_strip_tags
from .strings import STRINGS
from .timezones import timezone_identifiers, timezone_set
from .web import DEFAULT_LAT, DEFAULT_LAT_TEXT, DEFAULT_LON, DEFAULT_LON_TEXT, DEFAULT_TIMEZONE, render_page

SCRIPT_NAME = "/sunrise-sunset-calendar.php"
PLACEHOLDER_TOKEN = b"CHANGE_ME_TO_A_RANDOM_STRING"
SECURITY_HEADERS = [
    ("X-Content-Type-Options", "nosniff"),
    ("X-Frame-Options", "DENY"),
    ("Referrer-Policy", "no-referrer"),
]
ASSETS = {
    "/assets/styles.css": ("styles.css", "text/css"),
    "/assets/script.js": ("script.js", "text/javascript"),
}
HTML = "text/html; charset=UTF-8"
POST_MAX_SIZE = 8 * 1024 * 1024


@dataclass(frozen=True)
class Config:
    auth_token: bytes | None
    window_days: int = 400
    past_days: int = 30
    update_interval: int = 86400

    @classmethod
    def from_env(cls, environ: dict[str, str] | None = None) -> Config:
        env = os.environ if environ is None else environ
        token = env.get("AUTH_TOKEN")

        def number(name: str, default: int) -> int:
            value = env.get(name)
            return default if value is None else env_int(value)

        return cls(
            auth_token=None if token is None else os.fsencode(token),
            window_days=number("CALENDAR_WINDOW_DAYS", 400),
            past_days=number("CALENDAR_PAST_DAYS", 30),
            update_interval=number("UPDATE_INTERVAL", 86400),
        )


# ============================================================================
# INPUT
# ============================================================================


def param(source: dict, key: str, default: bytes = b"") -> bytes:
    """A string request parameter; anything else (missing, array) yields the default."""
    value = source.get(key)
    return value if isinstance(value, bytes) else default


def sanitize_float(value: bytes, default: float, low: float, high: float) -> float:
    filtered = filter_validate_float(value)
    return default if filtered is None or filtered < low or filtered > high else filtered


def sanitize_int(value: bytes, default: int, low: int = -1440, high: int = 1440) -> int:
    filtered = filter_validate_int(value)
    return default if filtered is None or filtered < low or filtered > high else filtered


def sanitize_timezone(value: bytes) -> str:
    try:
        name = value.decode("utf-8")
    except UnicodeDecodeError:
        return DEFAULT_TIMEZONE
    return name if name in timezone_set() else DEFAULT_TIMEZONE


def sanitize_text(value: bytes, max_length: int = 500) -> str:
    """Strip tags, flatten newlines, cap the length in characters."""
    clean = php_strip_tags(value)
    clean = clean.replace(b"\r\n", b" ").replace(b"\r", b" ").replace(b"\n", b" ")
    return mb_substr(clean, max_length)


def verify_token(config: Config, provided: bytes) -> bool:
    """Constant-time comparison with AUTH_TOKEN."""
    return hmac.compare_digest(config.auth_token or b"", provided)


def _form(environ) -> dict:
    """The POST body as PHP's $_POST: urlencoded or multipart forms only."""
    content_type = environ.get("CONTENT_TYPE", "")
    try:
        length = int(environ.get("CONTENT_LENGTH") or 0)
    except ValueError:
        length = 0
    # PHP's post_max_size (8M): a larger body leaves $_POST empty
    if not 0 < length <= POST_MAX_SIZE:
        return {}
    body = environ["wsgi.input"].read(length)
    media = content_type.split(";", 1)[0].strip().lower()
    if media == "application/x-www-form-urlencoded":
        return parse_query(body)
    if media == "multipart/form-data":
        message = BytesParser(policy=HTTP).parsebytes(
            b"Content-Type: " + content_type.encode("latin-1") + b"\r\n\r\n" + body
        )
        fields: dict = {}
        if message.is_multipart():
            for part in message.iter_parts():
                if part.get_filename() is not None:
                    continue
                name = part.get_param("name", header="content-disposition")
                if name:
                    key = parse_query(urlencode_key(name) + b"=")
                    for k in key:
                        fields[k] = ARRAY if key[k] is ARRAY else (part.get_payload(decode=True) or b"")
        return fields
    return {}


def urlencode_key(name: str) -> bytes:
    from urllib.parse import quote_from_bytes

    return quote_from_bytes(name.encode("utf-8"), safe="").encode("ascii")


# ============================================================================
# APPLICATION
# ============================================================================


def make_app(config: Config | None = None, clock=time.time):
    """The WSGI application."""
    config = config or Config.from_env()
    strings = STRINGS

    def app(environ, start_response):
        path = environ.get("PATH_INFO", "") or "/"
        method = environ.get("REQUEST_METHOD", "GET")

        if path in ASSETS:
            filename, content_type = ASSETS[path]
            body = resources.files(__package__).joinpath("assets", filename).read_bytes()
            start_response("200 OK", [("Content-Type", content_type), ("Content-Length", str(len(body)))])
            return [b"" if method == "HEAD" else body]
        if path not in ("/", SCRIPT_NAME) and not path.startswith(SCRIPT_NAME + "/"):
            body = b"Not Found"
            start_response("404 Not Found", [("Content-Type", "text/plain; charset=utf-8")])
            return [body]

        def respond(status: str, body: bytes, headers: list[tuple[str, str]]):
            start_response(status, [*headers, ("Content-Length", str(len(body)))])
            return [b"" if method == "HEAD" else body]

        token = config.auth_token
        if token is None or token in (b"", PLACEHOLDER_TOKEN):
            return respond(
                "500 Internal Server Error", strings["errors"]["missing_token"].encode(), [("Content-Type", HTML)]
            )

        query = parse_query(environ.get("QUERY_STRING", "").encode("latin-1"))
        now = int(clock())

        if "health" in query:
            body = json.dumps(
                {"status": "ok", "python": platform.python_version(), "timestamp": now}, separators=(",", ":")
            ).encode()
            return respond("200 OK", body, [*SECURITY_HEADERS, ("Content-Type", "application/json")])

        if "feed" in query:
            if not verify_token(config, param(query, "token")):
                return respond(
                    "403 Forbidden",
                    strings["errors"]["invalid_token"].encode(),
                    [*SECURITY_HEADERS, ("Content-Type", HTML)],
                )

            def flag(key: str) -> bool:
                return param(query, key) == b"1"

            options = Options(
                lat=sanitize_float(param(query, "lat"), DEFAULT_LAT, -90, 90),
                lon=sanitize_float(param(query, "lon"), DEFAULT_LON, -180, 180),
                timezone=sanitize_timezone(param(query, "zone", DEFAULT_TIMEZONE.encode())),
                location=sanitize_text(param(query, "location")),
                description=sanitize_text(param(query, "desc")),
                rise_offset=sanitize_int(param(query, "rise_off"), 0) * 60,
                set_offset=sanitize_int(param(query, "set_off"), 0) * 60,
                enabled={
                    "civil": flag("civil"),
                    "nautical": flag("nautical"),
                    "astro": flag("astro"),
                    "daylight": flag("daynight"),
                },
                past_days=config.past_days,
                window_days=config.window_days,
                refresh_seconds=config.update_interval,
                now=now,
            )
            body = render_feed(options)
            return respond(
                "200 OK",
                body,
                [
                    *SECURITY_HEADERS,
                    ("Content-Type", "text/calendar; charset=utf-8"),
                    ("Cache-Control", f"max-age={config.update_interval}"),
                ],
            )

        error = subscription_url = webcal_url = None
        if method == "POST":
            form = _form(environ)
            if "generate_url" in form:
                if not verify_token(config, param(form, "password")):
                    error = strings["errors"]["invalid_password"]
                else:
                    params = [
                        ("feed", b"1"),
                        ("token", token),
                        ("lat", param(form, "lat", DEFAULT_LAT_TEXT.encode())),
                        ("lon", param(form, "lon", DEFAULT_LON_TEXT.encode())),
                        ("zone", param(form, "zone", DEFAULT_TIMEZONE.encode())),
                        ("location", param(form, "location")),
                        ("rise_off", param(form, "rise_off", b"0")),
                        ("set_off", param(form, "set_off", b"0")),
                        ("desc", param(form, "description")),
                    ]
                    params += [(kind, b"1") for kind in ("civil", "nautical", "astro", "daynight") if kind in form]
                    https = environ.get("HTTPS", "") == "on" or environ.get("HTTP_X_FORWARDED_PROTO", "") == "https"
                    host = environ.get("HTTP_HOST", "").encode("latin-1").decode("utf-8", "replace")
                    subscription_url = (
                        ("https" if https else "http") + "://" + host + SCRIPT_NAME + "?" + http_build_query(params)
                    )
                    webcal_url = re.sub(r"^https?://", "webcal://", subscription_url, count=1)

        page = render_page(now, timezone_identifiers(), error, subscription_url, webcal_url)
        return respond("200 OK", page.encode("utf-8"), [*SECURITY_HEADERS, ("Content-Type", HTML)])

    return app


def main() -> None:
    """Serve the app with waitress on $PORT."""
    import logging

    import waitress

    # waitress reports where it listens, and errors, through logging
    logging.basicConfig(level=logging.INFO, format="%(levelname)s %(name)s: %(message)s")
    port = int(os.environ.get("PORT", "8080"))
    waitress.serve(
        make_app(),
        host="0.0.0.0",  # noqa: S104 - a container's listener
        port=port,
        threads=int(os.environ.get("THREADS", "2")),
        ident=None,
        clear_untrusted_proxy_headers=False,
        max_request_body_size=POST_MAX_SIZE,
    )


if __name__ == "__main__":
    main()
