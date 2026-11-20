"""The two rise and set searches: skyfield's hour-angle search, and sampling for high latitudes."""

from __future__ import annotations

from collections.abc import Callable
from typing import Any

import numpy as np
import pytest
from skyfield.api import wgs84

from sunrise_sunset_calendar import astronomy, php
from sunrise_sunset_calendar.php import gmdate, gmmktime

START, STOP = gmmktime(0, 0, 0, 1, 1, 2026), gmmktime(0, 0, 0, 1, 1, 2027)


def compare(observer: Any, body: Any, height: Callable[[np.ndarray], np.ndarray], horizon: float | None) -> None:
    grid = np.arange(START, STOP + astronomy._SAMPLE, astronomy._SAMPLE, dtype=float)
    sampled = astronomy._sampled_crossings(height, grid, height(grid))
    searched = astronomy._hour_angle_crossings(observer, body, START, STOP, horizon)
    for mine, theirs in zip(sampled, searched, strict=True):
        assert len(mine) == len(theirs)
        assert len(mine) == 0 or np.max(np.abs(mine - theirs)) < 0.001


def test_moon_sampling_agrees_with_the_hour_angle_search() -> None:
    """At 60°, where the sampling starts and both work, they find the same crossings within a millisecond."""
    observer = astronomy._EARTH + wgs84.latlon(60.0, 9.0)
    compare(observer, astronomy._MOON, astronomy._moon_height(observer), None)


@pytest.mark.parametrize("altitude", sorted(set(astronomy._BANDS.values())))
def test_sun_sampling_agrees_with_the_hour_angle_search(altitude: float) -> None:
    """Just short of 85°, where the sampling starts, at every altitude the bands use."""
    observer = astronomy._EARTH + wgs84.latlon(84.9, 9.0)
    compare(observer, astronomy._SUN, astronomy._sun_height(observer, altitude), altitude)


@pytest.mark.parametrize(
    ("lat", "lon"), [(90.0, -180.0), (89.5, -180.0), (89.5, 0.0), (78.2232, 15.6267), (-90.0, 0.0)]
)
def test_each_crossing_belongs_to_one_day(lat: float, lon: float) -> None:
    """Consecutive days' events follow one another: no crossing is counted on two days."""
    fields = [f"{field}_frac" for band in astronomy._BANDS for field in band]
    previous: dict[str, int] = {}
    for day in range(365):
        y, m, d = (int(part) for part in gmdate("Y-n-j", START + day * 86400).split("-"))
        times = astronomy.calculate_sun_times(y, m, d, lat, lon, 0.0)
        for field in fields:
            if times[field] is None:
                continue
            at = astronomy.fraction_to_timestamp(y, m, d, times[field], 0.0)
            assert at > previous.get(field, 0), f"{field} on {y}-{m}-{d}"
            previous[field] = at


def test_the_sun_rises_and_sets_once_a_year_at_the_north_pole() -> None:
    _, _, crossings = astronomy._solar_year(90.0, 0.0, 2026)
    rises, sets = crossings[astronomy.SUNRISE_ALTITUDE]
    in_year = [[gmdate("Y-m-d", round(t)) for t in ts if START <= t < STOP] for ts in (rises, sets)]
    # Refraction lifts the sun two to three days before the March equinox and keeps it after September's
    assert in_year == [["2026-03-18"], ["2026-09-25"]]


def test_the_moon_rises_and_sets_at_the_north_pole() -> None:
    """Twice a month, as its declination changes sign; the hour-angle search finds none of these."""
    times, is_rise = astronomy._moon_year(90.0, 0.0, 2026)
    in_year = (times >= START) & (times < STOP)
    assert 12 <= np.count_nonzero(is_rise & in_year) <= 14
    assert 12 <= np.count_nonzero(~is_rise & in_year) <= 14
    # They alternate
    assert not np.any(is_rise[1:] == is_rise[:-1])

    night = astronomy.moon_rise_set(START, START + 30 * 86400, 90.0, 0.0)
    kinds = [kind for kind, _ in night["events"]]
    assert kinds in (["rise", "set"], ["set", "rise"], ["set", "rise", "set"], ["rise", "set", "rise"])
    assert night["up_at_start"] == (kinds[0] == "set")


@pytest.mark.parametrize(
    ("lat", "lon", "zone"), [(-90.0, 0.0, "Antarctica/McMurdo"), (51.5074, -0.1278, "Pacific/Kiritimati")]
)
def test_consecutive_dates_have_consecutive_solar_days(lat: float, lon: float, zone: str) -> None:
    """Even where solar noon falls near local midnight, which the equation of time moves either side."""
    tz = php.zone(zone)
    day = php.LocalDate.parse("2026-01-01", tz)
    noons = []
    while day.year == 2026:
        noons.append(astronomy.sun_events(day, lat, lon)["solar_noon"])
        day = day.add_days(1)
    gaps = np.diff(noons)
    assert gaps.min() > 86400 - 60
    assert gaps.max() < 86400 + 60
