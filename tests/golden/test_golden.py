"""Replay the golden corpus: every response must match the PHP release byte for byte.

Only DTSTAMP (when the feed was generated) and the health check's runtime
and clock may differ.
"""

from __future__ import annotations

import io
import json
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


@cache
def bodies(group: str) -> dict[str, bytes]:
    with tarfile.open(CORPUS / f"{group}.tar.xz", "r:xz") as tar:
        return {m.name: tar.extractfile(m).read() for m in tar.getmembers()}


def request(case: Case) -> tuple[int, list[tuple[str, str]], bytes]:
    env = {"AUTH_TOKEN": TOKEN, **case.env}
    now = timegm(datetime.fromisoformat(case.now).timetuple())
    app = make_app(Config.from_env(env), clock=lambda: now)
    body = (case.body or "").encode("latin-1")
    environ = {
        "REQUEST_METHOD": "GET" if case.body is None else "POST",
        "PATH_INFO": "/sunrise-sunset-calendar.php",
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
    php_body = bodies(case.group)[case.id]

    if case.id.startswith("http-health"):
        ours_json, php_json = json.loads(body), json.loads(php_body)
        assert ours_json["status"] == php_json["status"] == "ok"
        assert abs(ours_json["timestamp"] - php_json["timestamp"]) < 120
        return
    assert DTSTAMP.sub(b"DTSTAMP", body) == DTSTAMP.sub(b"DTSTAMP", php_body)
