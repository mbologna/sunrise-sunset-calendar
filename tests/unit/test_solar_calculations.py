"""Comprehensive solar calculations test suite.

Tests high-precision NREL SPA-inspired solar calculations.

Coverage:
- Solar position calculations
- Sunrise/sunset calculations for various latitudes
- Twilight calculations (civil, nautical, astronomical)
- Edge cases (polar regions, equator, solstices, equinoxes)
- Hour angle calculations
- Leap year handling
"""

from __future__ import annotations

from sunrise_sunset_calendar.astronomy import sunrise_hour_angle
from sunrise_sunset_calendar.php import mktime
from tests.helpers import (
    assert_float_equals,
    assert_in_range,
    assert_time_equals,
    calculate_sun_times,
    fraction_to_timestamp,
)


def test_sunrise_hour_angle_for_sunrise() -> None:
    # Test sunrise hour angle for typical location (45°N)
    lat = 45.0
    decl = 0.0  # Equinox
    angle = -0.833  # Standard sunrise/sunset

    ha = sunrise_hour_angle(lat, decl, angle)

    # At equinox, sunrise should be around 6 hours before noon (90°)
    # Hour angle in degrees should be close to 90°
    assert_in_range(ha, 85.0, 95.0, "Hour angle at equinox for 45°N")


def test_sunrise_hour_angle_for_twilight() -> None:
    # Civil twilight has larger hour angle (earlier start)
    lat = 45.0
    decl = 0.0

    ha_sunrise = sunrise_hour_angle(lat, decl, -0.833)
    ha_civil = sunrise_hour_angle(lat, decl, -6.0)

    # Civil twilight starts before sunrise (larger hour angle)
    assert ha_civil > ha_sunrise, "Civil twilight hour angle > sunrise hour angle"


def test_rome_sunrise_sunset() -> None:
    # Rome, Italy (41.9°N, 12.5°E) on 2026-01-29
    result = calculate_sun_times(2026, 1, 29, 41.9, 12.5, 1)

    # Sunrise should be around 7:00-7:30 local time
    sunrise = fraction_to_timestamp(2026, 1, 29, result["sunrise_frac"])
    expected_sunrise = mktime(7, 15, 0, 1, 29, 2026)
    assert_time_equals(expected_sunrise, sunrise, "Rome sunrise on Jan 29, 2026", 1800)

    # Sunset should be around 17:15-17:45
    sunset = fraction_to_timestamp(2026, 1, 29, result["sunset_frac"])
    expected_sunset = mktime(17, 30, 0, 1, 29, 2026)
    assert_time_equals(expected_sunset, sunset, "Rome sunset on Jan 29, 2026", 1800)


def test_new_york_sunrise_sunset() -> None:
    # New York (40.7°N, -74.0°W) on 2026-06-21 (summer solstice)
    result = calculate_sun_times(2026, 6, 21, 40.7, -74.0, -4)

    # Sunrise should be around 5:20-5:30
    sunrise = fraction_to_timestamp(2026, 6, 21, result["sunrise_frac"])
    expected_sunrise = mktime(5, 25, 0, 6, 21, 2026)
    assert_time_equals(expected_sunrise, sunrise, "NYC sunrise on summer solstice", 1800)

    # Sunset should be around 20:25-20:35
    sunset = fraction_to_timestamp(2026, 6, 21, result["sunset_frac"])
    expected_sunset = mktime(20, 30, 0, 6, 21, 2026)
    assert_time_equals(expected_sunset, sunset, "NYC sunset on summer solstice", 1800)


def test_tokyo_sunrise_sunset() -> None:
    # Tokyo (35.7°N, 139.7°E) on 2026-03-20 (equinox)
    result = calculate_sun_times(2026, 3, 20, 35.7, 139.7, 9)

    # At equinox, sunrise/sunset should be roughly 12 hours apart
    daylength = result["daylength_h"]
    assert_in_range(daylength, 11.8, 12.2, "Tokyo day length at equinox")


def test_twilight_calculations_rome() -> None:
    # Test all twilight phases for Rome
    result = calculate_sun_times(2026, 1, 29, 41.9, 12.5, 1)

    # Astronomical twilight begins earliest
    astro_begin = result["astro_begin_frac"]
    nautical_begin = result["nautical_begin_frac"]
    civil_begin = result["civil_begin_frac"]
    sunrise = result["sunrise_frac"]

    # Verify correct ordering: astro < nautical < civil < sunrise
    assert astro_begin < nautical_begin, "Astronomical dawn before nautical"
    assert nautical_begin < civil_begin, "Nautical dawn before civil"
    assert civil_begin < sunrise, "Civil dawn before sunrise"

    # Same for evening (in reverse)
    sunset = result["sunset_frac"]
    civil_end = result["civil_end_frac"]
    nautical_end = result["nautical_end_frac"]
    astro_end = result["astro_end_frac"]

    assert sunset < civil_end, "Sunset before civil dusk"
    assert civil_end < nautical_end, "Civil dusk before nautical"
    assert nautical_end < astro_end, "Nautical dusk before astronomical"


def test_equator_consistent_day_length() -> None:
    # At the equator, day length should be close to 12h year-round
    lat = 0.0
    lon = 0.0

    # Test summer solstice (atmospheric refraction causes slight variation)
    result_summer = calculate_sun_times(2026, 6, 21, lat, lon, 0)
    assert_in_range(result_summer["daylength_h"], 11.9, 12.2, "Equator day length at summer solstice")

    # Test winter solstice
    result_winter = calculate_sun_times(2026, 12, 21, lat, lon, 0)
    assert_in_range(result_winter["daylength_h"], 11.9, 12.2, "Equator day length at winter solstice")


def test_arctic_summer_solstice() -> None:
    # Arctic Circle (70°N) on summer solstice - midnight sun
    result = calculate_sun_times(2026, 6, 21, 70.0, 0.0, 0)

    # Day length should be close to 24 hours
    assert result["daylength_h"] > 23.0, "Arctic midnight sun - very long day"


def test_arctic_winter_solstice() -> None:
    # Arctic Circle (70°N) on winter solstice - polar night
    result = calculate_sun_times(2026, 12, 21, 70.0, 0.0, 0)

    # Day length should be very short or zero
    assert result["daylength_h"] < 2.0, "Arctic polar night - very short day"


def test_solstice_comparison_new_york() -> None:
    # Compare summer vs winter solstice in New York
    lat = 40.7
    lon = -74.0
    offset = -5

    summer = calculate_sun_times(2026, 6, 21, lat, lon, offset)
    winter = calculate_sun_times(2026, 12, 21, lat, lon, offset)

    # Summer should have longer days than winter
    assert summer["daylength_h"] > winter["daylength_h"], "Summer day longer than winter"

    # Difference should be significant (at least 4 hours)
    diff = summer["daylength_h"] - winter["daylength_h"]
    assert diff > 4.0, "Solstice day length difference > 4 hours"


def test_equinox_day_length() -> None:
    # At equinox, day length should be close to 12h everywhere
    equinox_date = (2026, 3, 20)

    # Test various latitudes
    locations = [
        {"lat": 0, "name": "Equator"},
        {"lat": 30, "name": "30°N"},
        {"lat": 45, "name": "45°N"},
        {"lat": 60, "name": "60°N"},
    ]

    for loc in locations:
        result = calculate_sun_times(*equinox_date, float(loc["lat"]), 0.0, 0)

        assert_in_range(result["daylength_h"], 11.5, 12.5, f"{loc['name']} day length at equinox")


def test_leap_year_february_29() -> None:
    # Test that Feb 29 in leap year works correctly
    result = calculate_sun_times(2024, 2, 29, 45.0, 0.0, 0)

    # Should have valid day length
    assert result["daylength_h"] > 0, "Leap day has positive day length"
    assert result["daylength_h"] < 24, "Leap day has day length < 24h"


def test_non_leap_year_february() -> None:
    # Test Feb 28 in non-leap year
    result = calculate_sun_times(2026, 2, 28, 45.0, 0.0, 0)

    # Should have valid day length
    assert result["daylength_h"] > 0, "Feb 28 has positive day length"


def test_fraction_to_timestamp_noon() -> None:
    # Test fraction 0.5 = noon
    ts = fraction_to_timestamp(2026, 1, 29, 0.5)
    expected = mktime(12, 0, 0, 1, 29, 2026)

    assert_time_equals(expected, ts, "Fraction 0.5 equals noon", 60)


def test_fraction_to_timestamp_midnight() -> None:
    # Test fraction 0.0 = midnight
    ts = fraction_to_timestamp(2026, 1, 29, 0.0)
    expected = mktime(0, 0, 0, 1, 29, 2026)

    assert_time_equals(expected, ts, "Fraction 0.0 equals midnight", 60)


def test_fraction_to_timestamp_quarter_day() -> None:
    # Test fraction 0.25 = 6:00 AM
    ts = fraction_to_timestamp(2026, 1, 29, 0.25)
    expected = mktime(6, 0, 0, 1, 29, 2026)

    assert_time_equals(expected, ts, "Fraction 0.25 equals 6 AM", 60)


def test_solar_noon_calculations() -> None:
    # Solar noon should be close to 12:00 for longitude 0°
    result = calculate_sun_times(2026, 1, 29, 45.0, 0.0, 0)

    solar_noon = fraction_to_timestamp(2026, 1, 29, result["solar_noon_frac"])
    expected_noon = mktime(12, 0, 0, 1, 29, 2026)

    # Within 30 minutes of local noon
    assert_time_equals(expected_noon, solar_noon, "Solar noon near longitude 0°", 1800)


def test_negative_longitudes_western_hemisphere() -> None:
    # Test negative longitude (Western Hemisphere)
    result = calculate_sun_times(2026, 1, 29, 40.0, -100.0, -6)

    # Should have valid sunrise/sunset
    assert result["sunrise_frac"] > 0, "Western hemisphere has valid sunrise"
    assert result["sunrise_frac"] < 1, "Sunrise fraction < 1.0"
    assert result["sunset_frac"] > result["sunrise_frac"], "Sunset after sunrise"


def test_southern_hemisphere_sydney() -> None:
    # Sydney, Australia (33.9°S, 151.2°E)
    # In June (winter), days should be shorter
    winter = calculate_sun_times(2026, 6, 21, -33.9, 151.2, 10)

    # In December (summer), days should be longer
    summer = calculate_sun_times(2026, 12, 21, -33.9, 151.2, 10)

    # Southern hemisphere has reversed seasons
    assert summer["daylength_h"] > winter["daylength_h"], "Southern summer longer than winter"


def test_edge_case_latitude_north_pole() -> None:
    # Near North Pole (89°N)
    result = calculate_sun_times(2026, 6, 21, 89.0, 0.0, 0)

    # Should have very long day (close to 24h) in summer
    assert result["daylength_h"] > 20.0, "Near North Pole summer has very long day"


def test_edge_case_latitude_south_pole() -> None:
    # Near South Pole (89°S)
    result = calculate_sun_times(2026, 6, 21, -89.0, 0.0, 0)

    # Should have very short day (close to 0h) in June
    assert result["daylength_h"] < 4.0, "Near South Pole winter has very short day"


def test_consistent_calculations_across_dates() -> None:
    # Test that calculations are consistent when run multiple times
    result1 = calculate_sun_times(2026, 1, 29, 45.0, 0.0, 0)
    result2 = calculate_sun_times(2026, 1, 29, 45.0, 0.0, 0)

    assert_float_equals(result1["sunrise_frac"], result2["sunrise_frac"], "Consistent sunrise calculation", 0.0001)

    assert_float_equals(result1["daylength_h"], result2["daylength_h"], "Consistent day length calculation", 0.0001)
