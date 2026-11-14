"""The PHP primitives against what PHP 8.5 itself returns (php_reference/primitives-*.json)."""

from __future__ import annotations

import base64
import json
import math
from functools import cache
from pathlib import Path

import pytest

from sunrise_sunset_calendar import php

REFERENCES = sorted((Path(__file__).parent / "php_reference").glob("primitives-*.json"))


@cache
def load(path: Path) -> dict:
    return json.loads(path.read_text())


def ref() -> dict:
    return load(REFERENCES[0])


def b(text: str) -> bytes:
    return base64.b64decode(text)


def same_float(a: float, b: float) -> bool:
    return a == b and math.copysign(1.0, a) == math.copysign(1.0, b)


def test_round() -> None:
    for value, r0, r1, r2 in ref()["round"]:
        assert same_float(php.php_round(value), r0), value
        assert same_float(php.php_round(value, 1), r1), value
        assert same_float(php.php_round(value, 2), r2), value


def test_float_to_str() -> None:
    for value, text in ref()["float_to_str"]:
        assert php.float_to_str(value) == text, value


def test_filters() -> None:
    for raw, as_float, as_int, float_text in ref()["filters"]:
        value = b(raw)
        got = php.filter_validate_float(value)
        assert (got is None) == (as_float is None), value
        if got is not None:
            assert php.float_to_str(got) == float_text, value
        assert php.filter_validate_int(value) == as_int, value


def test_strip_tags() -> None:
    for raw, expected in ref()["strip_tags"]:
        assert php.strip_tags(b(raw)) == b(expected), b(raw)


def test_mb_substr() -> None:
    for raw, long, short in ref()["mb_substr"]:
        assert php.mb_substr(b(raw), 500).encode() == b(long), b(raw)
        assert php.mb_substr(b(raw), 2).encode() == b(short), b(raw)


def test_urlencode() -> None:
    for raw, expected in ref()["urlencode"]:
        assert php.urlencode(b(raw)) == expected


def test_parse_str() -> None:
    for query, expected in ref()["parse_str"]:
        got = php.parse_query(query.encode())
        want = {k: (php.ARRAY if v == "ARRAY" else b(v)) for k, v in (expected or {}).items()}
        assert got == want, query


def test_htmlspecialchars() -> None:
    for text, expected in ref()["htmlspecialchars"]:
        assert php.htmlspecialchars(text) == expected


@pytest.mark.parametrize("path", REFERENCES, ids=[p.stem for p in REFERENCES])
def test_wall_clock(path: Path) -> None:
    data = load(path)
    for row in data["clock"]:
        (
            zone,
            ts,
            date,
            midnight_ts,
            midnight_text,
            today_ts,
            tomorrow_ts,
            yesterday_text,
            noon_offset,
            month_start,
            month_end,
            two_thirty,
            plus3,
            first,
            next_month,
            fmt1,
            fmt2,
        ) = row
        tz = php.zone(zone)
        with php.default_timezone_set(zone), php.frozen_time(data["now"]):
            day = php.LocalDate.from_timestamp(ts, tz)
            midnight = day.set_time(0, 0)
            assert (midnight.ts, midnight.format("Y-m-d H:i:s")) == (midnight_ts, midnight_text), row
            today = php.LocalDate.parse(date, tz)
            assert today.ts == today_ts, row
            assert today.add_days(1).ts == tomorrow_ts, row
            assert today.add_days(-1).format("Y-m-d H:i") == yesterday_text, row
            assert today.set_time(12, 0).offset() == noon_offset, row
            assert php.mktime(0, 0, 0, day.month, 1, day.year) == month_start, row
            assert php.mktime(23, 59, 59, day.month + 1, 0, day.year) == month_end, row
            assert php.mktime(2, 30, 0, day.month, day.day, day.year) == two_thirty, row
            assert php.strtotime_days(3, ts) == plus3, row
            assert php.first_of_month(ts) == first, row
            assert php.add_month(first) == next_month, row
            assert php.php_date("D, M j, H:i", ts) == fmt1, row
            assert php.php_date("l, F j, Y w", ts) == fmt2, row
