"""Cache test suite: cache hit/miss behavior."""

from __future__ import annotations

import pytest

from sunrise_sunset_calendar import cache, helpers, php


@pytest.fixture(autouse=True)
def fresh_cache():
    cache.reset()


def test_year_profile_is_cached_per_utc_offset() -> None:
    profile = helpers.get_year_profile(40.0, 15.0, 2026, 1.0)
    assert len(profile) == 365
    assert helpers.get_year_profile(40.0, 15.0, 2026, 1.0) == profile
    assert helpers.get_year_profile(40.0, 15.0, 2026, 1.0) is profile
    assert helpers.get_year_profile(40.0, 15.0, 2026, 2.0) != profile


def test_solar_calc_cache_hit() -> None:
    store = cache.get().solar_calc

    key = "2026-02-15:40.0000:15.0000:1.00"
    data = {"test": "data"}

    store[key] = data
    result = cache.get().solar_calc.get(key)

    assert result == data


def test_solar_calc_cache_miss() -> None:
    result = cache.get().solar_calc.get("nonexistent-key")
    assert result is None


def test_moon_phase_cache_hit() -> None:
    store = cache.get().moon_phase

    key = "2026-02"
    data = [{"phase": "new_moon", "timestamp": 1707000000}]

    store[key] = data
    result = cache.get().moon_phase.get(key)

    assert result == data


def test_moon_phase_cache_miss() -> None:
    result = cache.get().moon_phase.get("2026-13")
    assert result is None


def test_week_summary_is_cached() -> None:
    week_start = php.mktime(0, 0, 0, 2, 1, 2026)

    summary = helpers.get_week_summary(week_start, 40.0, 15.0, 1.0)

    assert helpers.get_week_summary(week_start, 40.0, 15.0, 1.0) == summary
    assert helpers.get_week_summary(week_start, 40.0, 15.0, 1.0) is summary
    assert summary["end_length"] > summary["start_length"], "Days lengthen in February"
    assert summary["trend"] == "increasing"
