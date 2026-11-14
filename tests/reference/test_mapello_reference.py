"""Mapello reference data validation test.

Validates solar calculations against real-world data for Mapello, Bergamo (Italy).
Reference date: February 1, 2026
Reference source: timeanddate.com, NOAA Solar Calculator.

This test uses actual observed/calculated times from authoritative sources
to validate the accuracy of our solar calculation algorithms.
"""

from __future__ import annotations

import pytest

from sunrise_sunset_calendar.astronomy import calculate_sun_times, fraction_to_timestamp
from sunrise_sunset_calendar.php import mktime, round_int
from tests.fixtures.reference_locations import mapello
from tests.helpers import assert_duration_equals, assert_time_equals

LAT = 45.7
LON = 9.6
TIMEZONE_OFFSET = 1  # CET (UTC+1 in February)
TEST_YEAR = 2026
TEST_MONTH = 2
TEST_DAY = 1


@pytest.fixture
def reference() -> dict:
    # Load reference data from fixtures
    return mapello()["reference_dates"]["2026-02-01"]


def calculate_test_sun_times() -> dict:
    """Calculate sun times for the test date."""
    return calculate_sun_times(TEST_YEAR, TEST_MONTH, TEST_DAY, LAT, LON, TIMEZONE_OFFSET)


def convert_fraction(fraction: float) -> int:
    """Convert a fraction to a timestamp for the test date."""
    # Wall-clock time read as UTC, matching the HH:MM reference strings
    return fraction_to_timestamp(TEST_YEAR, TEST_MONTH, TEST_DAY, fraction, 0.0)


def test_astronomical_twilight_times(reference: dict) -> None:
    result = calculate_test_sun_times()

    astro_begin = convert_fraction(result["astro_begin_frac"])
    astro_end = convert_fraction(result["astro_end_frac"])
    nautical_begin = convert_fraction(result["nautical_begin_frac"])
    nautical_end = convert_fraction(result["nautical_end_frac"])

    assert_time_equals(reference["astronomical_dawn_start"], astro_begin, "Astro dawn start (night end)", 60)
    assert_time_equals(reference["astronomical_dawn_end"], nautical_begin, "Astro dawn end (nautical begins)", 60)
    assert_time_equals(reference["astronomical_dusk_start"], nautical_end, "Astro dusk start (nautical ends)", 60)
    assert_time_equals(reference["astronomical_dusk_end"], astro_end, "Astro dusk end (night begins)", 60)


def test_astronomical_twilight_duration(reference: dict) -> None:
    result = calculate_test_sun_times()

    astro_begin = convert_fraction(result["astro_begin_frac"])
    astro_end = convert_fraction(result["astro_end_frac"])
    nautical_begin = convert_fraction(result["nautical_begin_frac"])
    nautical_end = convert_fraction(result["nautical_end_frac"])

    total_duration = (nautical_begin - astro_begin) + (astro_end - nautical_end)

    assert_duration_equals(
        reference["astronomical_twilight_duration_seconds"], total_duration, "Total astronomical twilight duration", 60
    )


def test_nautical_twilight_times(reference: dict) -> None:
    result = calculate_test_sun_times()

    nautical_begin = convert_fraction(result["nautical_begin_frac"])
    nautical_end = convert_fraction(result["nautical_end_frac"])
    civil_begin = convert_fraction(result["civil_begin_frac"])
    civil_end = convert_fraction(result["civil_end_frac"])

    assert_time_equals(reference["nautical_dawn_start"], nautical_begin, "Nautical dawn start", 60)
    assert_time_equals(reference["nautical_dawn_end"], civil_begin, "Nautical dawn end (civil begins)", 60)
    assert_time_equals(reference["nautical_dusk_start"], civil_end, "Nautical dusk start (civil ends)", 60)
    assert_time_equals(reference["nautical_dusk_end"], nautical_end, "Nautical dusk end", 60)


def test_nautical_twilight_duration(reference: dict) -> None:
    result = calculate_test_sun_times()

    nautical_begin = convert_fraction(result["nautical_begin_frac"])
    nautical_end = convert_fraction(result["nautical_end_frac"])
    civil_begin = convert_fraction(result["civil_begin_frac"])
    civil_end = convert_fraction(result["civil_end_frac"])

    total_duration = (civil_begin - nautical_begin) + (nautical_end - civil_end)

    assert_duration_equals(
        reference["nautical_twilight_duration_seconds"], total_duration, "Total nautical twilight duration", 60
    )


def test_civil_twilight_times(reference: dict) -> None:
    result = calculate_test_sun_times()

    civil_begin = convert_fraction(result["civil_begin_frac"])
    civil_end = convert_fraction(result["civil_end_frac"])
    sunrise = convert_fraction(result["sunrise_frac"])
    sunset = convert_fraction(result["sunset_frac"])

    assert_time_equals(reference["civil_dawn_start"], civil_begin, "Civil dawn start", 60)
    assert_time_equals(reference["civil_dawn_end"], sunrise, "Civil dawn end (sunrise)", 60)
    assert_time_equals(reference["civil_dusk_start"], sunset, "Civil dusk start (sunset)", 60)
    assert_time_equals(reference["civil_dusk_end"], civil_end, "Civil dusk end", 60)


def test_civil_twilight_duration(reference: dict) -> None:
    result = calculate_test_sun_times()

    civil_begin = convert_fraction(result["civil_begin_frac"])
    civil_end = convert_fraction(result["civil_end_frac"])
    sunrise = convert_fraction(result["sunrise_frac"])
    sunset = convert_fraction(result["sunset_frac"])

    total_duration = (sunrise - civil_begin) + (civil_end - sunset)

    assert_duration_equals(
        reference["civil_twilight_duration_seconds"], total_duration, "Total civil twilight duration", 60
    )


def test_sunrise_sunset_times(reference: dict) -> None:
    result = calculate_test_sun_times()

    sunrise = convert_fraction(result["sunrise_frac"])
    sunset = convert_fraction(result["sunset_frac"])

    assert_time_equals(reference["sunrise"], sunrise, "Sunrise", 60)
    assert_time_equals(reference["sunset"], sunset, "Sunset", 60)


def test_daylight_duration(reference: dict) -> None:
    result = calculate_test_sun_times()

    sunrise = convert_fraction(result["sunrise_frac"])
    sunset = convert_fraction(result["sunset_frac"])

    daylight_duration = sunset - sunrise

    assert_duration_equals(reference["daylight_duration_seconds"], daylight_duration, "Daylight duration", 120)

    # Also test from daylength_h calculation
    daylight_from_calc = round_int(result["daylength_h"] * 3600)
    assert_duration_equals(reference["daylight_duration_seconds"], daylight_from_calc, "Day length calculation", 120)


def test_solar_noon(reference: dict) -> None:
    result = calculate_test_sun_times()
    solar_noon = convert_fraction(result["solar_noon_frac"])

    assert_time_equals(reference["solar_noon"], solar_noon, "Solar noon", 30)


def test_solar_midnight(reference: dict) -> None:
    result = calculate_test_sun_times()

    # Solar midnight occurs when the sun is at its lowest point
    # For Feb 1, it's around 00:35 (between midnight and 1 AM)
    # We calculate it as solar_noon - 12 hours
    solar_noon = result["solar_noon_frac"]
    solar_midnight_frac = solar_noon - 0.5
    if solar_midnight_frac < 0:
        solar_midnight_frac += 1.0

    solar_midnight = convert_fraction(solar_midnight_frac)

    assert_time_equals(reference["solar_midnight"], solar_midnight, "Solar midnight", 30)


def test_night_duration(reference: dict) -> None:
    result = calculate_test_sun_times()

    astro_begin = convert_fraction(result["astro_begin_frac"])
    astro_end = convert_fraction(result["astro_end_frac"])

    # Night is from end of astronomical dusk to beginning of astronomical dawn
    # Split across midnight: from astro_end to midnight (23:59:59) + midnight to astro_begin
    midnight_end = mktime(23, 59, 59, TEST_MONTH, TEST_DAY, TEST_YEAR)
    midnight_start = mktime(0, 0, 0, TEST_MONTH, TEST_DAY, TEST_YEAR)

    night_duration = (midnight_end - astro_end) + (astro_begin - midnight_start) + 1  # +1 for inclusive

    assert_duration_equals(reference["night_duration_seconds"], night_duration, "Total night duration", 120)


def test_day_length_change_from_previous_day(reference: dict) -> None:
    # Feb 1, 2026
    result_today = calculate_sun_times(TEST_YEAR, TEST_MONTH, TEST_DAY, LAT, LON, TIMEZONE_OFFSET)

    # Jan 31, 2026 (yesterday)
    result_yesterday = calculate_sun_times(TEST_YEAR, 1, 31, LAT, LON, TIMEZONE_OFFSET)

    daylight_today = result_today["daylength_h"] * 3600
    daylight_yesterday = result_yesterday["daylength_h"] * 3600

    change = round_int(daylight_today - daylight_yesterday)

    assert_duration_equals(reference["day_length_change_seconds"], change, "Day length change from yesterday", 10)


def test_total_day_breakdown_sums_to_24_hours() -> None:
    result = calculate_test_sun_times()

    sunrise = convert_fraction(result["sunrise_frac"])
    sunset = convert_fraction(result["sunset_frac"])
    civil_begin = convert_fraction(result["civil_begin_frac"])
    civil_end = convert_fraction(result["civil_end_frac"])
    nautical_begin = convert_fraction(result["nautical_begin_frac"])
    nautical_end = convert_fraction(result["nautical_end_frac"])
    astro_begin = convert_fraction(result["astro_begin_frac"])
    astro_end = convert_fraction(result["astro_end_frac"])

    # Verify the day sums to 24 hours
    daylight = sunset - sunrise
    civil = (sunrise - civil_begin) + (civil_end - sunset)
    nautical = (civil_begin - nautical_begin) + (nautical_end - civil_end)
    astro = (nautical_begin - astro_begin) + (astro_end - nautical_end)

    midnight_start = mktime(0, 0, 0, TEST_MONTH, TEST_DAY, TEST_YEAR)
    midnight_end = mktime(23, 59, 59, TEST_MONTH, TEST_DAY, TEST_YEAR)
    night = (astro_begin - midnight_start) + (midnight_end - astro_end) + 1

    total = daylight + civil + nautical + astro + night
    day_seconds = 86400

    diff = abs(total - day_seconds)

    assert diff <= 10, f"Total day breakdown should sum to 24h (difference: {diff}s)"
