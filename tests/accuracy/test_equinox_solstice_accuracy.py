"""Equinox and solstice accuracy tests.

Tests the skyfield (JPL DE442) equinoxes and solstices against authoritative
reference data from timeanddate.com and USNO.

Requirement: within the references' own resolution, one minute (60 seconds).
"""

from __future__ import annotations

import pytest

from sunrise_sunset_calendar import clock
from sunrise_sunset_calendar.astronomy import calculate_equinox_solstice
from tests.fixtures.astronomical_reference_data import date_to_timestamp, get_equinoxes_solstices
from tests.helpers import utc


def equinox_solstice_cases() -> list[tuple[int, str, str]]:
    """All equinox/solstice test cases for 2024-2030."""
    return [
        (year, event, expected)
        for year, events in get_equinoxes_solstices().items()
        for event, expected in events.items()
    ]


@pytest.mark.parametrize(("year", "event", "expected_datestring"), equinox_solstice_cases())
def test_equinox_solstice_accuracy(year: int, event: str, expected_datestring: str) -> None:
    """Accuracy for all equinoxes and solstices 2024-2030."""
    calculated = calculate_equinox_solstice(year)

    # Get expected timestamp from reference data
    expected_timestamp = date_to_timestamp(expected_datestring)
    calculated_timestamp = calculated[event]

    # Calculate difference in seconds
    difference = abs(calculated_timestamp - expected_timestamp)

    # The references give the minute
    assert difference <= 60, (
        f"{year} {event}: Expected {clock.format_local_time(expected_timestamp, '{year}-{month:02d}-{day:02d} {hour:02d}:{minute:02d}:{second:02d}')} UTC, "
        f"got {clock.format_local_time(calculated_timestamp, '{year}-{month:02d}-{day:02d} {hour:02d}:{minute:02d}:{second:02d}')} UTC (difference: {difference} seconds)\n"
        "Reference: timeanddate.com\n"
        "Algorithm: skyfield, JPL DE442"
    )


def test_2026_march_equinox() -> None:
    """2026 March Equinox specifically (user's test case)."""
    result = calculate_equinox_solstice(2026)

    # Expected: March 20, 2026 at 14:46 UTC (per timeanddate.com)
    expected = utc("2026-03-20 14:46:00")
    actual = result["march_equinox"]

    assert abs(expected - actual) <= 60, (
        f"March Equinox 2026 should be March 20, 14:46 UTC\nCalculated: {clock.format_local_time(actual, '{month_short} {day}, {hour:02d}:{minute:02d}')} UTC"
    )


def test_2026_june_solstice() -> None:
    """2026 June Solstice specifically (user's test case)."""
    result = calculate_equinox_solstice(2026)

    # Expected: June 21, 2026 at 08:24 UTC (per timeanddate.com)
    # User reported seeing "Jun 20, 12:23" - which was wrong!
    expected = utc("2026-06-21 08:24:00")
    actual = result["june_solstice"]

    assert abs(expected - actual) <= 60, (
        f"June Solstice 2026 should be June 21, 08:24 UTC (not Jun 20!)\nCalculated: {clock.format_local_time(actual, '{month_short} {day}, {hour:02d}:{minute:02d}')} UTC"
    )


def test_2026_september_equinox() -> None:
    """2026 September Equinox."""
    result = calculate_equinox_solstice(2026)

    # Expected: September 23, 2026 at 00:05 UTC
    expected = utc("2026-09-23 00:05:00")
    actual = result["september_equinox"]

    assert abs(expected - actual) <= 60


def test_2026_december_solstice() -> None:
    """2026 December Solstice specifically (user's test case)."""
    result = calculate_equinox_solstice(2026)

    # Expected: December 21, 2026 at 20:50 UTC (per timeanddate.com)
    # User reported seeing "Dec 21, 12:19" - which was wrong!
    expected = utc("2026-12-21 20:50:00")
    actual = result["december_solstice"]

    assert abs(expected - actual) <= 60, (
        "December Solstice 2026 should be December 21, 20:50 UTC (not 12:19!)\n"
        f"Calculated: {clock.format_local_time(actual, '{month_short} {day}, {hour:02d}:{minute:02d}')} UTC\n"
        "Error in old code: 8h 30m off!"
    )


def test_all_years_within_tolerance() -> None:
    """All years 2024-2030 meet the accuracy requirement."""
    max_error = 0
    worst_case = ""

    for year, events in get_equinoxes_solstices().items():
        calculated = calculate_equinox_solstice(year)

        for event, expected_datestring in events.items():
            expected_timestamp = date_to_timestamp(expected_datestring)
            calculated_timestamp = calculated[event]
            difference = abs(calculated_timestamp - expected_timestamp)

            if difference > max_error:
                max_error = difference
                worst_case = f"{year} {event}: {difference} seconds off"

    assert max_error <= 60, (
        f"Maximum error across all test cases: {max_error} seconds\n"
        f"Worst case: {worst_case}\n"
        "Requirement: ±60 seconds (the references give the minute)"
    )


def test_leap_year_handling() -> None:
    """Edge cases."""
    # 2024 is a leap year, 2026 is not
    result_2024 = calculate_equinox_solstice(2024)
    result_2026 = calculate_equinox_solstice(2026)

    # Both should return valid timestamps
    assert result_2024["march_equinox"] > 0
    assert result_2026["march_equinox"] > 0

    # March equinox 2026 should be later in the day than 2024
    assert int(clock.format_local_time(result_2026["march_equinox"], "{hour:02d}")) > int(
        clock.format_local_time(result_2024["march_equinox"], "{hour:02d}")
    ), "Equinox times should progress through leap year cycle"


def test_century_boundaries() -> None:
    """Years across the ephemeris excerpt, which serves feeds up to 2148."""
    years = [2000, 2025, 2050, 2100, 2148]

    for year in years:
        result = calculate_equinox_solstice(year)

        # All events should return valid timestamps
        assert result["march_equinox"] > 0
        assert result["june_solstice"] > 0
        assert result["september_equinox"] > 0
        assert result["december_solstice"] > 0

        # Events should be in chronological order
        assert result["march_equinox"] < result["june_solstice"]
        assert result["june_solstice"] < result["september_equinox"]
        assert result["september_equinox"] < result["december_solstice"]
