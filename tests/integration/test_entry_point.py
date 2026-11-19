"""Drives the WSGI application in-process, as a web request would."""

from __future__ import annotations

import io
import json
import platform
import time
import warnings
from urllib.parse import urlencode

import pytest

from sunrise_sunset_calendar import app
from tests.helpers import StartResponse

TOKEN = b"entry-point-test-token"


def request(query: str, path: str = "/") -> str:
    """The response body for a GET request to path with the given query string."""
    application = app.make_app(app.Config(auth_token=TOKEN, window_days=7, past_days=0))
    environ = {
        "REQUEST_METHOD": "GET",
        "PATH_INFO": path,
        "QUERY_STRING": query,
        "wsgi.input": io.BytesIO(b""),
    }
    start_response = StartResponse()

    with warnings.catch_warnings(record=True) as caught:
        warnings.simplefilter("always")
        body = b"".join(application(environ, start_response))

    assert len(start_response.calls) == 1
    assert caught == [], "No warnings or errors"

    return body.decode("utf-8")


def test_health_endpoint() -> None:
    data = json.loads(request("", path="/health"))

    assert data["status"] == "ok"
    assert data["python"] == platform.python_version()
    assert data["timestamp"] == pytest.approx(time.time(), abs=60)


def test_feed_rejects_wrong_token() -> None:
    assert request("feed=1&token=wrong") == "Invalid authentication token"


def test_feed_rejects_non_string_token() -> None:
    assert request("feed=1&token[]=x") == "Invalid authentication token"


def test_feed_returns_calendar() -> None:
    body = request(
        urlencode(
            {
                "feed": "1",
                "token": TOKEN.decode(),
                "lat": "40.7128",
                "lon": "-74.0060",
                "zone": "America/New_York",
                "location": "New York",
                "daynight": "1",
            }
        )
    )

    assert body.startswith("BEGIN:VCALENDAR\r\n")
    assert "X-WR-TIMEZONE:America/New_York\r\n" in body
    assert body.count("SUMMARY:☀️ Daylight") == 8
    assert body.endswith("END:VCALENDAR\r\n")


def test_web_interface_renders() -> None:
    body = request("")

    assert "<title>Sun &amp; Twilight Calendar</title>" in body
    assert '<option value="Europe/Rome" selected>' in body
