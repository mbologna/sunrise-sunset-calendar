"""Replay the golden corpus: every response must match the PHP release byte for byte.

Only DTSTAMP (when the feed was generated) and the subscription URL's path
may differ (the health check is no longer a PHP output: /healthz replaced
/?health on 2026-11-19): the PHP release built it on
/sunrise-sunset-calendar.php, which this app no longer serves (2026-11-19).
"""

from __future__ import annotations

import io
import re
import tarfile
from calendar import timegm
from datetime import datetime
from functools import cache
from pathlib import Path

import pytest

from sunrise_sunset_calendar.app import Config, make_app

from .cases import CASES, TOKEN, Case

CORPUS = Path(__file__).parent / "corpus"
DTSTAMP = re.compile(rb"^DTSTAMP:\d{8}T\d{6}Z\r$", re.M)
# The form's subscription and webcal URLs, as the PHP release wrote them.
PHP_SCRIPT_URL = re.compile(rb"((?:https?|webcal)://[^/\"<\s]*)/sunrise-sunset-calendar\.php\?")


@cache
def bodies(group: str) -> dict[str, bytes]:
    with tarfile.open(CORPUS / f"{group}.tar.xz", "r:xz") as tar:
        return {m.name: tar.extractfile(m).read() for m in tar.getmembers()}


@cache
def overrides() -> dict[str, bytes]:
    """Cases whose real clock rules changed in a tz release after the PHP recording.

    The corpus was recorded with tzdata 2026e (the PHP image's). A later tz
    release that changes a zone's rules changes that zone's correct output: the
    old bytes would then test for the wrong times. Such cases are re-recorded
    from this app, once the changed lines are checked against the release's
    NEWS entry, into corpus/tz-overrides.tar.xz (record_overrides.py).
    """
    path = CORPUS / "tz-overrides.tar.xz"
    if not path.exists():
        return {}
    with tarfile.open(path, "r:xz") as tar:
        return {m.name: tar.extractfile(m).read() for m in tar.getmembers()}


def request(case: Case) -> tuple[int, list[tuple[str, str]], bytes]:
    env = {"AUTH_TOKEN": TOKEN, **case.env}
    now = timegm(datetime.fromisoformat(case.now).timetuple())
    app = make_app(Config.from_env(env), clock=lambda: now)
    body = (case.body or "").encode("latin-1")
    environ = {
        "REQUEST_METHOD": "GET" if case.body is None else "POST",
        "PATH_INFO": "/",
        "QUERY_STRING": case.query,
        "HTTP_HOST": case.host,
        "wsgi.input": io.BytesIO(body),
        "CONTENT_LENGTH": str(len(body)),
    }
    if case.body is not None:
        environ["CONTENT_TYPE"] = "application/x-www-form-urlencoded"
    if case.https:
        environ["HTTP_X_FORWARDED_PROTO"] = "https"
    status: list = []
    output = b"".join(app(environ, lambda s, h: status.extend([s, h])))
    return int(status[0].split()[0]), status[1], output


@pytest.mark.parametrize("case", CASES, ids=[c.id for c in CASES])
def test_matches_php(case: Case) -> None:
    # The corpus was recorded with PHP's CLI, which keeps no status or headers:
    # test_app.py covers those, against what the PHP entry point sets
    _, _, body = request(case)
    php_body = overrides().get(case.id) or bodies(case.group)[case.id]

    php_body = PHP_SCRIPT_URL.sub(rb"\1/?", php_body)
    assert DTSTAMP.sub(b"DTSTAMP", body) == DTSTAMP.sub(b"DTSTAMP", php_body)
