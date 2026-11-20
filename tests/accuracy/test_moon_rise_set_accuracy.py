"""Moonrise and moonset against the US Naval Observatory (aa.usno.navy.mil).

USNO gives each time rounded to the minute, so times are required within
30 seconds of it.
"""

from __future__ import annotations

import pytest

from sunrise_sunset_calendar import php
from sunrise_sunset_calendar.astronomy import moon_rise_set


def local_timestamp(date: str, time: str) -> int:
    """strtotime('YYYY-MM-DD HH:MM') in the default time zone."""
    y, m, d = (int(part) for part in date.split("-"))
    hour, minute = (int(part) for part in time.split(":"))
    return php.mktime(hour, minute, 0, m, d, y)


@pytest.mark.parametrize(
    ("lat", "lon", "zone", "date", "expected"),
    [
        pytest.param(
            41.9028,
            12.4964,
            "Etc/GMT-1",
            "2026-11-15",
            [("rise", "12:12"), ("set", "21:45")],
            id="Rome waxing crescent",
        ),
        pytest.param(
            41.9028, 12.4964, "Etc/GMT-1", "2026-11-20", [("set", "02:04"), ("rise", "14:06")], id="Rome waxing gibbous"
        ),
        pytest.param(
            41.9028, 12.4964, "Etc/GMT-1", "2026-11-24", [("set", "07:05"), ("rise", "16:13")], id="Rome full moon"
        ),
        pytest.param(
            41.9028, 12.4964, "Etc/GMT-1", "2026-11-28", [("set", "11:14"), ("rise", "20:53")], id="Rome waning gibbous"
        ),
        pytest.param(
            -33.8688, 151.2093, "Etc/GMT-10", "2027-06-10", [("rise", "11:19"), ("set", "22:56")], id="Sydney"
        ),
        pytest.param(69.65, 18.96, "Etc/GMT-1", "2027-01-20", [], id="Tromsø, moon up all day"),
    ],
)
def test_rise_set_matches_usno(lat: float, lon: float, zone: str, date: str, expected: list[tuple[str, str]]) -> None:
    """Expected: [event, local HH:MM] from USNO."""
    with php.default_timezone_set(zone):
        start = local_timestamp(date, "00:00")
        events = moon_rise_set(start, start + 86400, lat, lon)["events"]

        assert [e[0] for e in events] == [e[0] for e in expected], f"Events on {date}"
        for i, (kind, time) in enumerate(expected):
            assert abs(local_timestamp(date, time) - events[i][1]) <= 30, f"{kind} on {date}"
