"""Replay the golden corpus: every response must match its recording byte for byte.

The corpus is a snapshot of this app's responses, DTSTAMP (when the feed
was generated) aside. A change that alters the output on purpose records it
again with make_corpus.py, after the changed lines are reviewed.
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
from tests.helpers import StartResponse

from .cases import CASES, TOKEN, Case

CORPUS = Path(__file__).parent / "corpus"
DTSTAMP = re.compile(rb"^DTSTAMP:\d{8}T\d{6}Z\r$", re.M)


@cache
def bodies(group: str) -> dict[str, bytes]:
    """Every response of a group's .tar.xz, by case id."""
    files = {}
    with tarfile.open(CORPUS / f"{group}.tar.xz", "r:xz") as tar:
        for member in tar.getmembers():
            data = tar.extractfile(member)
            assert data is not None, f"{group}: {member.name} is not a regular file"
            files[member.name] = data.read()
    return files


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
    start_response = StartResponse()
    output = b"".join(app(environ, start_response))
    status, headers = start_response.calls[0]
    return int(status.split()[0]), headers, output


@pytest.mark.parametrize("case", CASES, ids=[c.id for c in CASES])
def test_matches_corpus(case: Case) -> None:
    # The corpus keeps bodies only: test_app.py covers status codes and headers
    _, _, body = request(case)
    assert DTSTAMP.sub(b"DTSTAMP", body) == DTSTAMP.sub(b"DTSTAMP", bodies(case.group)[case.id])
