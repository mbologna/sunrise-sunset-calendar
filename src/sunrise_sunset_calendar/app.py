"""The HTTP entry point: the feed, the URL-builder page, its assets and a health check.

Configuration comes from the environment:

- AUTH_TOKEN (required): the secret in feed URLs and in the page's
- AUTH_TOKENS: more secrets, comma-separated, each as good as AUTH_TOKEN
- CALENDAR_WINDOW_DAYS (400): days of future events in a feed
- CALENDAR_PAST_DAYS (30): days of past events
- UPDATE_INTERVAL (86400): refresh interval suggested to calendar apps, in seconds
- PORT (8080): where the server listens
"""

from __future__ import annotations

import dataclasses
import hashlib
import hmac
import json
import math
import os
import re
import time
from collections.abc import Callable, Iterable
from dataclasses import dataclass
from datetime import UTC, datetime
from email.parser import BytesParser
from email.policy import HTTP
from importlib import resources
from urllib.parse import parse_qsl, urlencode
from wsgiref.types import StartResponse, WSGIApplication, WSGIEnvironment

from .api import now_json
from .astronomy import FIRST_YEAR, LAST_YEAR
from .clock import zone
from .feed import Options, render_feed
from .strings import STRINGS
from .timezones import timezone_identifiers, timezone_set
from .web import DEFAULT_LAT, DEFAULT_LAT_TEXT, DEFAULT_LON, DEFAULT_LON_TEXT, DEFAULT_TIMEZONE, render_page

# Behind a proxy that serves the app on another host
# (sunrise-sunset-calendar.michelebologna.net, through the feeds Worker, which
# reaches Cloud Run under the service's own host), the subscription URL must
# name the proxy's host. A malformed X-Forwarded-Host is ignored.
FORWARDED_HOST = re.compile(r"[A-Za-z0-9.-]+(:[0-9]{1,5})?")


def public_host(environ: dict) -> str:
    """The host the client used: X-Forwarded-Host when set and well formed, else the Host header."""
    forwarded = environ.get("HTTP_X_FORWARDED_HOST", "").split(",")[0].strip()
    if FORWARDED_HOST.fullmatch(forwarded):
        return forwarded
    return environ.get("HTTP_HOST", "").encode("latin-1").decode("utf-8", "replace")


PLACEHOLDER_TOKEN = b"CHANGE_ME_TO_A_RANDOM_STRING"
# The page runs only its own script and stylesheet, and looks addresses up on Nominatim
CONTENT_SECURITY_POLICY = (
    "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; "
    "connect-src https://nominatim.openstreetmap.org; form-action 'self'; frame-ancestors 'none'; base-uri 'none'"
)
SECURITY_HEADERS = [
    ("X-Content-Type-Options", "nosniff"),
    ("X-Frame-Options", "DENY"),
    ("Referrer-Policy", "no-referrer"),
    ("Content-Security-Policy", CONTENT_SECURITY_POLICY),
]
# Responses that carry the token (the page after the form, the feed's URL) stay out of shared caches
NO_STORE = ("Cache-Control", "no-store")
ASSETS = {
    "/assets/styles.css": ("styles.css", "text/css"),
    "/assets/script.js": ("script.js", "text/javascript"),
}
HTML = "text/html; charset=UTF-8"
JSON = ("Content-Type", "application/json")
API_NOW = "/api/v1/now"
POST_MAX_SIZE = 8 * 1024 * 1024


@dataclass(frozen=True)
class Config:
    auth_token: bytes | None
    window_days: int = 400
    past_days: int = 30
    update_interval: int = 86400
    # More tokens, from AUTH_TOKENS: one per person, so one can be revoked alone
    extra_tokens: tuple[bytes, ...] = ()

    @property
    def tokens(self) -> tuple[bytes, ...]:
        """Every usable token: AUTH_TOKEN and AUTH_TOKENS, without empty ones or the placeholder."""
        candidates = (self.auth_token, *self.extra_tokens)
        return tuple(t for t in candidates if t and t != PLACEHOLDER_TOKEN)

    @classmethod
    def from_env(cls, environ: dict[str, str] | None = None) -> Config:
        env = os.environ if environ is None else environ
        token = env.get("AUTH_TOKEN")
        extra = [t.strip() for t in env.get("AUTH_TOKENS", "").split(",")]

        def number(name: str, default: int) -> int:
            value = env.get(name)
            if value is None:
                return default
            try:
                return int(value.strip())
            except ValueError:
                raise SystemExit(f"{name} must be a whole number, not {value!r}") from None

        return cls(
            auth_token=None if token is None else os.fsencode(token),
            window_days=number("CALENDAR_WINDOW_DAYS", 400),
            past_days=number("CALENDAR_PAST_DAYS", 30),
            update_interval=number("UPDATE_INTERVAL", 86400),
            extra_tokens=tuple(os.fsencode(t) for t in extra if t),
        )


# ============================================================================
# INPUT
# ============================================================================


_WHITESPACE = b" \t\r\n\v\f"
_DECIMAL = re.compile(rb"[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?")
_INTEGER = re.compile(rb"[+-]?\d+")
# A tag, or one cut off at the end
_TAG = re.compile(rb"<[^>]*(?:>|$)")
# Control characters, which iCalendar text can't hold
_CONTROL = re.compile(rb"[\x00-\x1f\x7f]")


def parse_query(query: bytes) -> dict[str, bytes]:
    """A query string or urlencoded body; a repeated name keeps its last value."""
    return {key.decode("utf-8", "replace"): value for key, value in parse_qsl(query, keep_blank_values=True)}


def param(source: dict[str, bytes], key: str, default: bytes = b"") -> bytes:
    return source.get(key, default)


def parse_float(value: bytes) -> float | None:
    """A decimal number, surrounding whitespace allowed ("41.9", " 4.19e1 "), or None."""
    value = value.strip(_WHITESPACE)
    if not _DECIMAL.fullmatch(value):
        return None
    number = float(value)
    # + 0.0 turns -0.0 into 0.0, so "-0" writes as "0"
    return number + 0.0 if math.isfinite(number) else None


def parse_int(value: bytes) -> int | None:
    """A whole number, surrounding whitespace allowed, or None."""
    value = value.strip(_WHITESPACE)
    return int(value) if _INTEGER.fullmatch(value) else None


def sanitize_float(value: bytes, default: float, low: float, high: float) -> float:
    number = parse_float(value)
    return default if number is None or number < low or number > high else number


def sanitize_int(value: bytes, default: int, low: int = -1440, high: int = 1440) -> int:
    number = parse_int(value)
    return default if number is None or number < low or number > high else number


def sanitize_timezone(value: bytes) -> str:
    try:
        name = value.decode("utf-8")
    except UnicodeDecodeError:
        return DEFAULT_TIMEZONE
    return name if name in timezone_set() else DEFAULT_TIMEZONE


def sanitize_text(value: bytes, max_length: int = 500) -> str:
    """Strip tags, flatten newlines and tabs, drop other control characters, cap the length in characters.

    Invalid UTF-8 becomes U+FFFD.
    """
    clean = _TAG.sub(b"", value)
    clean = clean.replace(b"\r\n", b" ").replace(b"\r", b" ").replace(b"\n", b" ").replace(b"\t", b" ")
    clean = _CONTROL.sub(b"", clean)
    return clean.decode("utf-8", "replace")[:max_length]


def code_revision() -> str:
    """What code is running: Cloud Run's revision name, or else a hash of the package's source."""
    revision = os.environ.get("K_REVISION")
    if revision:
        return revision
    digest = hashlib.sha256()
    for entry in sorted(resources.files(__package__).iterdir(), key=lambda e: e.name):
        if entry.name.endswith(".py"):
            digest.update(entry.read_bytes())
    return digest.hexdigest()


def feed_etag(revision: str, options: Options, now: int) -> str:
    """A feed's ETag: what its content depends on, DTSTAMP aside.

    The code, the options (the token isn't one) and the date in the feed's zone: the
    events start from a date, so the content holds until local midnight.
    """
    today = options.today or datetime.fromtimestamp(now, zone(options.timezone)).date().isoformat()
    fields = dataclasses.asdict(options)
    del fields["now"]
    key = json.dumps([revision, today, fields], sort_keys=True)
    return '"' + hashlib.sha256(key.encode()).hexdigest()[:20] + '"'


def etag_matches(etag: str, if_none_match: str) -> bool:
    """Whether an If-None-Match header names the ETag (weak or not) or is "*"."""
    tags = [tag.strip().removeprefix("W/") for tag in if_none_match.split(",")]
    return etag in tags or "*" in tags


def parse_moment(value: bytes, zone_name: str, default: int) -> int | None:
    """A Unix time, or an ISO 8601 date and time (read in zone_name without an offset); default if empty."""
    text = value.decode("utf-8", "replace").strip()
    if not text:
        return default
    if _INTEGER.fullmatch(value.strip(_WHITESPACE)):
        return int(text)
    try:
        moment = datetime.fromisoformat(text)
    except ValueError:
        return None
    if moment.tzinfo is None:
        moment = moment.replace(tzinfo=zone(zone_name))
    return int(moment.timestamp())


def api_now(query: dict[str, bytes], now: int) -> tuple[str, bytes, list[tuple[str, str]]]:
    """GET /api/v1/now: status, body and headers. Unlike the feed, it refuses input it can't read."""

    def error(message: str) -> tuple[str, bytes, list[tuple[str, str]]]:
        return "400 Bad Request", json.dumps({"error": message}, separators=(",", ":")).encode(), [JSON, NO_STORE]

    lat = parse_float(param(query, "lat"))
    lon = parse_float(param(query, "lon"))
    zone_name = param(query, "zone").decode("utf-8", "replace")
    if lat is None or not -90 <= lat <= 90:
        return error("lat must be a number from -90 to 90")
    if lon is None or not -180 <= lon <= 180:
        return error("lon must be a number from -180 to 180")
    if zone_name not in timezone_set():
        return error("zone must be a time zone name, such as Europe/Rome")
    at = parse_moment(param(query, "at"), zone_name, now)
    if at is None:
        return error("at must be a Unix time or an ISO 8601 date and time")
    if not FIRST_YEAR <= datetime.fromtimestamp(at, UTC).year <= LAST_YEAR:
        return error(f"at must fall between {FIRST_YEAR} and {LAST_YEAR}")
    body = json.dumps(now_json(lat, lon, zone_name, at), separators=(",", ":"), ensure_ascii=False)
    return "200 OK", body.encode(), [JSON, NO_STORE]


def verify_token(config: Config, provided: bytes) -> bool:
    """Whether provided is one of the tokens, comparing with each in constant time."""
    matched = False
    for token in config.tokens:
        matched |= hmac.compare_digest(token, provided)
    return matched


def _form(environ: WSGIEnvironment) -> dict[str, bytes]:
    """The fields of a POSTed form: urlencoded or multipart, files left out."""
    content_type = environ.get("CONTENT_TYPE", "")
    try:
        length = int(environ.get("CONTENT_LENGTH") or 0)
    except ValueError:
        length = 0
    # Bodies over 8 MB are ignored
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
        fields: dict[str, bytes] = {}
        if message.is_multipart():
            for part in message.iter_parts():
                if part.get_filename() is not None:
                    continue
                name = part.get_param("name", header="content-disposition")
                # EmailMessage decodes RFC 2231 values (name*=) to str; only the compat32
                # policy returns them as (charset, language, value) tuples
                if isinstance(name, str) and name:
                    payload = part.get_payload(decode=True)
                    fields[name] = payload if isinstance(payload, bytes) else b""
        return fields
    return {}


# ============================================================================
# APPLICATION
# ============================================================================


def make_app(config: Config | None = None, clock: Callable[[], float] = time.time) -> WSGIApplication:
    """The WSGI application."""
    config = config or Config.from_env()
    revision = code_revision()
    strings = STRINGS

    def app(environ: WSGIEnvironment, start_response: StartResponse) -> Iterable[bytes]:
        path = environ.get("PATH_INFO", "") or "/"
        method = environ.get("REQUEST_METHOD", "GET")

        def respond(status: str, body: bytes, headers: list[tuple[str, str]]) -> list[bytes]:
            start_response(status, [*SECURITY_HEADERS, *headers, ("Content-Length", str(len(body)))])
            return [b"" if method == "HEAD" else body]

        if path in ASSETS:
            filename, content_type = ASSETS[path]
            body = resources.files(__package__).joinpath("assets", filename).read_bytes()
            return respond("200 OK", body, [("Content-Type", content_type)])
        # The page and feed at /, the health check at /health, JSON at /api/v1/now. The PHP
        # version's /sunrise-sunset-calendar.php and /?health are gone (2026-11-19).
        if path not in ("/", "/health", API_NOW):
            return respond("404 Not Found", b"Not Found", [("Content-Type", "text/plain; charset=utf-8"), NO_STORE])

        if not config.tokens:
            return respond(
                "500 Internal Server Error",
                strings["errors"]["missing_token"].encode(),
                [("Content-Type", HTML), NO_STORE],
            )

        query = parse_query(environ.get("QUERY_STRING", "").encode("latin-1"))
        now = int(clock())

        if path == "/health":
            # Down when the feed window reaches years the ephemeris can't serve
            first = datetime.fromtimestamp(now - config.past_days * 86400, UTC).year
            last = datetime.fromtimestamp(now + config.window_days * 86400, UTC).year
            health: dict = {"status": "ok", "years": [FIRST_YEAR, LAST_YEAR]}
            if first < FIRST_YEAR or last > LAST_YEAR:
                health |= {"status": "down", "problems": [f"feeds reach {first} to {last}, outside the ephemeris"]}
            body = json.dumps(health, separators=(",", ":")).encode()
            status = "200 OK" if health["status"] == "ok" else "503 Service Unavailable"
            return respond(status, body, [JSON, NO_STORE])

        # The feed and the page that builds its URL both need a token; only /health doesn't
        token = param(query, "token")
        if not verify_token(config, token):
            return respond(
                "403 Forbidden", strings["errors"]["invalid_token"].encode(), [("Content-Type", HTML), NO_STORE]
            )

        if path == API_NOW:
            return respond(*api_now(query, now))

        if "feed" in query:

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
            etag = feed_etag(revision, options, now)
            # Calendar apps may keep it; shared caches must not, as its URL holds the token
            cache_headers = [("ETag", etag), ("Cache-Control", f"private, max-age={config.update_interval}")]
            # A client that still has today's feed gets a 304, and the feed isn't built
            if etag_matches(etag, environ.get("HTTP_IF_NONE_MATCH", "")):
                return respond("304 Not Modified", b"", cache_headers)
            body = render_feed(options)
            return respond("200 OK", body, [("Content-Type", "text/calendar; charset=utf-8"), *cache_headers])

        # The form posts back to the page's own URL, token and all
        subscription_url = webcal_url = None
        if method == "POST":
            form = _form(environ)
            if "generate_url" in form:
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
                host = public_host(environ)
                subscription_url = ("https" if https else "http") + "://" + host + "/?" + urlencode(params)
                webcal_url = re.sub(r"^https?://", "webcal://", subscription_url, count=1)

        page = render_page(now, timezone_identifiers(), subscription_url, webcal_url)
        return respond("200 OK", page.encode("utf-8"), [("Content-Type", HTML), NO_STORE])

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
        host="0.0.0.0",  # noqa: S104  # nosec B104: a container's listener
        port=port,
        threads=int(os.environ.get("THREADS", "2")),
        ident=None,
        clear_untrusted_proxy_headers=False,
        max_request_body_size=POST_MAX_SIZE,
    )


if __name__ == "__main__":
    main()
