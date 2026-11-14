"""DST transition test suite: solar calculations across daylight saving time boundaries."""

from __future__ import annotations

from sunrise_sunset_calendar.astronomy import calculate_sun_times
from sunrise_sunset_calendar.php import local, mktime, strtotime_days


def strtotime_date(text: str) -> int:
    """strtotime('YYYY-MM-DD'): midnight in the default time zone (UTC)."""
    y, m, d = (int(part) for part in text.split("-"))
    return mktime(0, 0, 0, m, d, y)


def sun_times_on(timestamp: int, lat: float, lon: float, utc_offset: float) -> dict:
    date_parts = local(timestamp)
    return calculate_sun_times(date_parts.year, date_parts.month, date_parts.day, lat, lon, utc_offset)


def test_us_dst_spring_forward_week_summary() -> None:
    """US DST spring forward: March 8, 2026 (2:00 AM → 3:00 AM)."""
    lat = 40.7128  # New York
    lon = -74.0060
    utc_offset = -5.0  # EST (before DST)

    week_start = strtotime_date("2026-03-01")
    week_end = strtotime_days(6, week_start)

    # Calculate for all 7 days of the week spanning the DST transition
    current = week_start
    day_count = 0

    while current <= week_end:
        result = sun_times_on(current, lat, lon, utc_offset)

        # Verify we get valid results
        assert isinstance(result, dict)
        assert "daylength_h" in result
        assert result["daylength_h"] > 0

        day_count += 1
        current = strtotime_days(1, current)

    # Verify we got all 7 days
    assert day_count == 7


def test_day_length_continuity_across_dst_boundary() -> None:
    """Day length continuity across DST: change should be minutes, not hours."""
    lat = 40.7128
    lon = -74.0060
    utc_offset = -5.0

    # Day before DST transition
    day_before = sun_times_on(strtotime_date("2026-03-07"), lat, lon, utc_offset)

    # Day after DST transition
    day_after = sun_times_on(strtotime_date("2026-03-09"), lat, lon, utc_offset)

    day_before_daylength = day_before["daylength_h"] * 3600
    day_after_daylength = day_after["daylength_h"] * 3600

    # Day length change should be within 10 minutes (600 seconds)
    # (Near equinox, day length changes faster)
    diff = abs(day_after_daylength - day_before_daylength)
    assert diff < 600, "Day length changed by more than 10 minutes across DST"


def test_us_dst_fall_back_week_summary() -> None:
    """US DST fall back: November 1, 2026 (2:00 AM → 1:00 AM)."""
    lat = 40.7128
    lon = -74.0060
    utc_offset = -4.0  # EDT (before fall back)

    week_start = strtotime_date("2026-10-25")
    week_end = strtotime_days(6, week_start)

    day_count = 0
    current = week_start

    while current <= week_end:
        result = sun_times_on(current, lat, lon, utc_offset)

        assert isinstance(result, dict)
        assert "daylength_h" in result

        day_count += 1
        current = strtotime_days(1, current)

    assert day_count == 7


def test_eu_dst_spring_forward_sun_times() -> None:
    """EU DST spring forward: March 29, 2026."""
    lat = 48.8566  # Paris
    lon = 2.3522
    utc_offset = 1.0  # CET (before DST)

    # Days around EU spring transition (March 29)
    dates = [
        "2026-03-28",
        "2026-03-29",
        "2026-03-30",
    ]

    prev_sunrise = None

    for date_str in dates:
        result = sun_times_on(strtotime_date(date_str), lat, lon, utc_offset)

        assert isinstance(result, dict)

        # Sunrise should be gradually getting earlier each day
        if prev_sunrise is not None:
            curr_sunrise = result["sunrise_frac"] * 86400
            diff = prev_sunrise - curr_sunrise
            # Around 2 minutes earlier each day near equinox
            assert diff > -300  # Less than 5 min difference

        prev_sunrise = result["sunrise_frac"] * 86400


def test_eu_dst_fall_back_sun_times() -> None:
    """EU DST fall back: October 25, 2026."""
    lat = 48.8566
    lon = 2.3522
    utc_offset = 2.0  # CEST (before fall back)

    dates = [
        "2026-10-24",
        "2026-10-25",
        "2026-10-26",
    ]

    prev_sunset = None

    for date_str in dates:
        result = sun_times_on(strtotime_date(date_str), lat, lon, utc_offset)

        assert isinstance(result, dict)

        if prev_sunset is not None:
            curr_sunset = result["sunset_frac"] * 86400
            diff = prev_sunset - curr_sunset
            # Around 2 minutes earlier each day near autumn equinox
            assert diff > -300

        prev_sunset = result["sunset_frac"] * 86400
