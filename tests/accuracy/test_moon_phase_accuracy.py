"""Moon phase accuracy tests.

Tests the Meeus Chapter 49 algorithm implementation against authoritative
reference data from timeanddate.com and USNO.

Requirement: ±3 minutes accuracy (180 seconds tolerance)
Note: Moon phase calculations are inherently less precise than solar due to
lunar orbital perturbations. Meeus algorithm achieves ±2-3 min typical accuracy.
"""

from __future__ import annotations

import pytest

from sunrise_sunset_calendar.astronomy import (
    calculate_moon_phases_for_month,
    get_accurate_moon_phase,
    moon_illumination,
    moon_phases_between,
)
from sunrise_sunset_calendar.php import PHP_INT_MAX, gmdate, local, php_date
from tests.fixtures.astronomical_reference_data import date_to_timestamp, get_moon_phases_2026, normalize_phase_name
from tests.helpers import utc


def moon_phase_2026_cases() -> list[tuple[str, str]]:
    """All 2026 moon phase test cases."""
    return [(phase["phase"], phase["date"]) for phase in get_moon_phases_2026()]


@pytest.mark.parametrize(("expected_phase", "expected_datestring"), moon_phase_2026_cases())
def test_moon_phase_2026_accuracy(expected_phase: str, expected_datestring: str) -> None:
    """Accuracy for all 2026 moon phases."""
    # Parse expected date
    expected_timestamp = date_to_timestamp(expected_datestring)
    date_parts = local(expected_timestamp)

    # Calculate phases for this month
    phases = calculate_moon_phases_for_month(date_parts.year, date_parts.month)

    # Find the phase closest to expected
    closest_phase = None
    min_difference = PHP_INT_MAX

    for phase in phases:
        phase_name_normalized = normalize_phase_name(phase["phase_name"])
        expected_normalized = normalize_phase_name(expected_phase)

        if phase_name_normalized == expected_normalized:
            difference = abs(phase["timestamp"] - expected_timestamp)
            if difference < min_difference:
                min_difference = difference
                closest_phase = phase

    assert closest_phase is not None, f"Could not find calculated {expected_phase} near {expected_datestring}"

    # Assert accuracy within ±180 seconds (3 minutes)
    assert min_difference <= 180, (
        f"{expected_phase}: Expected {php_date('Y-m-d H:i:s', expected_timestamp)} UTC, "
        f"got {php_date('Y-m-d H:i:s', closest_phase['timestamp'])} UTC (difference: {min_difference} seconds)\n"
        "Reference: timeanddate.com\n"
        "Algorithm: Meeus Chapter 49"
    )


def test_february_2026_phases() -> None:
    """February 2026 phases specifically (user's test case).

    User reported incorrect phases, let's verify our implementation is correct.
    """
    # Old code showed:
    # - Feb 1: Full Moon 10:12 (WRONG!)
    # - Feb 8: Last Quarter 19:22 (WRONG!)
    #
    # Actual (per timeanddate.com):
    # - Feb 1: Full Moon 22:09
    # - Feb 9: Last Quarter 12:43
    # - Feb 17: New Moon 12:01
    # - Feb 24: First Quarter 12:27

    phases = calculate_moon_phases_for_month(2026, 2)

    # Find each major phase
    full_moon = None
    last_quarter = None
    new_moon = None
    first_quarter = None

    for phase in phases:
        ts = phase["timestamp"]
        day = int(php_date("j", ts))

        # Filter to February phases only
        if int(php_date("n", ts)) != 2:
            continue

        if phase["phase_name"] == "Full Moon" and day == 1:
            full_moon = phase
        elif phase["phase_name"] == "Last Quarter" and day == 9:
            last_quarter = phase
        elif phase["phase_name"] == "New Moon" and day == 17:
            new_moon = phase
        elif phase["phase_name"] == "First Quarter" and day == 24:
            first_quarter = phase

    # Test Full Moon: Feb 1, 22:09 UTC
    assert full_moon is not None, "Should find Full Moon on Feb 1"
    expected_full = utc("2026-02-01 22:09:00")
    assert abs(expected_full - full_moon["timestamp"]) <= 180, (
        "Full Moon Feb 1 should be 22:09 UTC, not 10:12!\n"
        f"Calculated: {php_date('Y-m-d H:i', full_moon['timestamp'])} UTC"
    )

    # Test Last Quarter: Feb 9, 12:43 UTC
    assert last_quarter is not None, "Should find Last Quarter on Feb 9"
    expected_last = utc("2026-02-09 12:43:00")
    assert abs(expected_last - last_quarter["timestamp"]) <= 180, (
        f"Last Quarter Feb 9 should be 12:43 UTC\nCalculated: {php_date('Y-m-d H:i', last_quarter['timestamp'])} UTC"
    )

    # Test New Moon: Feb 17, 12:01 UTC
    assert new_moon is not None, "Should find New Moon on Feb 17"
    expected_new = utc("2026-02-17 12:01:00")
    assert abs(expected_new - new_moon["timestamp"]) <= 180, (
        f"New Moon Feb 17 should be 12:01 UTC\nCalculated: {php_date('Y-m-d H:i', new_moon['timestamp'])} UTC"
    )

    # Test First Quarter: Feb 24, 12:27 UTC
    assert first_quarter is not None, "Should find First Quarter on Feb 24"
    expected_first = utc("2026-02-24 12:27:00")
    assert abs(expected_first - first_quarter["timestamp"]) <= 180, (
        f"First Quarter Feb 24 should be 12:27 UTC\nCalculated: {php_date('Y-m-d H:i', first_quarter['timestamp'])} UTC"
    )


def test_get_accurate_moon_phase_for_february_1_2026() -> None:
    """get_accurate_moon_phase() for a specific date.

    February 1, 2026 at 22:09 UTC is Full Moon.
    A timestamp on the same calendar day (20:00 UTC) should recognise the
    current phase as "Full Moon" via the same-day detection, and report the
    *next* named phase (Last Quarter, ~Feb 9) rather than duplicating the
    same-day Full Moon in the "next" slot.
    """
    # 20:00 UTC — 2 hours before the Full Moon on the same calendar day
    test_timestamp = utc("2026-02-01 20:00:00")

    moon_info = get_accurate_moon_phase(test_timestamp)

    assert moon_info is not None

    # Current phase should be "Full Moon" via same-day detection
    assert moon_info["phase_name"] == "Full Moon"

    # Next phase must NOT be the same-day Full Moon — it should be Last Quarter (~Feb 9)
    assert moon_info["next_phase"] is not None
    assert moon_info["next_phase"]["name"] == "Last Quarter"

    next_ts = moon_info["next_phase"]["timestamp"]
    expected = utc("2026-02-09 12:43:00")

    assert abs(expected - next_ts) <= 300, "Next phase after same-day Full Moon should be Last Quarter ~Feb 9"


def test_prev_next_phase_detection() -> None:
    """Prev/next phase detection works correctly."""
    # Test at a time between two phases
    # February 10, 2026 - between Last Quarter (Feb 9) and New Moon (Feb 17)
    test_timestamp = utc("2026-02-10 12:00:00")

    moon_info = get_accurate_moon_phase(test_timestamp)

    # Previous should be Last Quarter on Feb 9
    assert moon_info["prev_phase"] is not None
    assert moon_info["prev_phase"]["name"] == "Last Quarter"

    prev_expected = utc("2026-02-09 12:43:00")
    assert abs(prev_expected - moon_info["prev_phase"]["timestamp"]) <= 120

    # Next should be New Moon on Feb 17
    assert moon_info["next_phase"] is not None
    assert moon_info["next_phase"]["name"] == "New Moon"

    next_expected = utc("2026-02-17 12:01:00")
    assert abs(next_expected - moon_info["next_phase"]["timestamp"]) <= 120


def test_illumination_percentage() -> None:
    """Illumination at each principal phase of 2026.

    0% at new moon, 50% at the quarters, 100% at full moon. Quarters fall
    6.5 to 8.3 days after new moon, so a uniform-motion approximation would
    miss them by up to ~10 points.
    """
    expected = {"New Moon": 0, "First Quarter": 50, "Full Moon": 100, "Last Quarter": 50}
    phases = moon_phases_between(utc("2026-01-01 00:00"), utc("2027-01-01 00:00"))

    assert len(phases) >= 48
    for phase in phases:
        assert abs(expected[phase["name"]] - moon_illumination(phase["timestamp"])) <= 1.0, (
            f"{phase['name']} on {gmdate('Y-m-d H:i', phase['timestamp'])}"
        )


def test_all_2026_phases_within_tolerance() -> None:
    """All 2026 phases meet the accuracy requirement."""
    max_error = 0
    worst_case = ""
    tested_count = 0

    for ref in get_moon_phases_2026():
        expected_timestamp = date_to_timestamp(ref["date"])
        date_parts = local(expected_timestamp)

        calculated_phases = calculate_moon_phases_for_month(date_parts.year, date_parts.month)

        # Find matching phase
        found = False
        for calc in calculated_phases:
            phase_match = normalize_phase_name(calc["phase_name"]) == normalize_phase_name(ref["phase"])

            if phase_match:
                difference = abs(calc["timestamp"] - expected_timestamp)

                if difference < 7200:  # Within 2 hours (to find correct instance)
                    tested_count += 1
                    found = True

                    if difference > max_error:
                        max_error = difference
                        worst_case = f"{ref['phase']} on {ref['date']}: {difference} seconds off"
                    break

        assert found, f"Should find calculated phase for {ref['phase']} on {ref['date']}"

    assert tested_count > 40, "Should test all moon phases for 2026"

    assert max_error <= 180, (
        f"Maximum error across all 2026 phases: {max_error} seconds\n"
        f"Worst case: {worst_case}\n"
        "Requirement: ±180 seconds (3 minutes)"
    )


def test_month_boundary_phases() -> None:
    """Month boundary handling."""
    # Test phases that occur near month boundaries
    # December 30, 2026: Last Quarter at 18:59 UTC (updated from timeanddate.com)

    phases = calculate_moon_phases_for_month(2026, 12)

    found_dec30 = False
    for phase in phases:
        if phase["phase_name"] == "Last Quarter":
            day = int(php_date("j", phase["timestamp"]))
            month = int(php_date("n", phase["timestamp"]))

            if month == 12 and day == 30:
                found_dec30 = True

                expected = utc("2026-12-30 18:59:00")
                assert abs(expected - phase["timestamp"]) <= 180, "Should correctly handle phase near end of year"

    assert found_dec30, "Should find Last Quarter on Dec 30, 2026"


def test_phases_chronological_order() -> None:
    """Phases are in chronological order."""
    phases = calculate_moon_phases_for_month(2026, 2)

    prev_timestamp = 0
    for phase in phases:
        assert phase["timestamp"] > prev_timestamp, "Phases should be in chronological order"
        prev_timestamp = phase["timestamp"]


def test_synodic_month_variation() -> None:
    """Synodic month length variation."""
    # Calculate several consecutive New Moons
    new_moons = []

    for month in range(1, 7):
        phases = calculate_moon_phases_for_month(2026, month)

        for phase in phases:
            if phase["phase_name"] == "New Moon" and int(php_date("n", phase["timestamp"])) == month:
                new_moons.append(phase["timestamp"])
                break

    # Calculate synodic month lengths
    synodic_lengths = [(new_moons[i] - new_moons[i - 1]) / 86400 for i in range(1, len(new_moons))]

    # Synodic month varies from 29.18 to 29.93 days
    # Check that our calculations show this variation (not fixed 29.53)
    min_length = min(synodic_lengths)
    max_length = max(synodic_lengths)

    assert min_length > 29.0, "Minimum synodic month should be > 29 days"

    assert max_length < 30.0, "Maximum synodic month should be < 30 days"

    # There should be variation (not all the same)
    length_range = max_length - min_length
    assert length_range > 0.1, "Synodic month length should vary (not fixed 29.53 days)"
