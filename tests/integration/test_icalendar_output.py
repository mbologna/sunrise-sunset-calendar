"""Integration tests for iCalendar output generation: RFC 5545 compliance and formatting."""

from __future__ import annotations

import time

from sunrise_sunset_calendar.astronomy import calculate_equinox_solstice, calculate_sun_times, get_accurate_moon_phase
from sunrise_sunset_calendar.helpers import format_ical_description


def is_valid_utf8(text: str) -> bool:
    """mb_check_encoding($text, 'UTF-8'): no undecodable bytes (surrogate escapes) in the text."""
    try:
        text.encode("utf-8")
    except UnicodeEncodeError:
        return False
    return True


def test_calculate_sun_times_return_structure() -> None:
    """calculate_sun_times returns the expected keys."""
    result = calculate_sun_times(2026, 1, 29, 41.9, 12.5, 1)

    expected_keys = [
        "declination_deg",
        "equation_of_time_min",
        "sunrise_frac",
        "sunset_frac",
        "solar_noon_frac",
        "daylength_h",
        "civil_begin_frac",
        "civil_end_frac",
        "nautical_begin_frac",
        "nautical_end_frac",
        "astro_begin_frac",
        "astro_end_frac",
    ]

    for key in expected_keys:
        assert key in result, f"Missing key: {key}"


def test_sunrise_before_sunset() -> None:
    result = calculate_sun_times(2026, 6, 21, 41.9, 12.5, 2)

    assert result["sunrise_frac"] < result["sunset_frac"], "Sunrise should be before sunset"


def test_twilight_ordering() -> None:
    result = calculate_sun_times(2026, 1, 29, 41.9, 12.5, 1)

    # Morning: astro < nautical < civil < sunrise
    assert result["astro_begin_frac"] < result["nautical_begin_frac"], "Astronomical dawn before nautical"
    assert result["nautical_begin_frac"] < result["civil_begin_frac"], "Nautical dawn before civil"
    assert result["civil_begin_frac"] < result["sunrise_frac"], "Civil dawn before sunrise"

    # Evening: sunset < civil < nautical < astro
    assert result["sunset_frac"] < result["civil_end_frac"], "Sunset before civil dusk"
    assert result["civil_end_frac"] < result["nautical_end_frac"], "Civil dusk before nautical"
    assert result["nautical_end_frac"] < result["astro_end_frac"], "Nautical dusk before astronomical"


def test_moon_phase_info_structure() -> None:
    result = get_accurate_moon_phase(int(time.time()))

    assert "phase_name" in result
    assert "illumination" in result
    assert "prev_phase" in result
    assert "next_phase" in result


def test_equinox_solstice_structure() -> None:
    result = calculate_equinox_solstice(2026)

    assert "march_equinox" in result
    assert "june_solstice" in result
    assert "september_equinox" in result
    assert "december_solstice" in result

    # All should be valid timestamps
    for event, timestamp in result.items():
        assert timestamp > 0, f"{event} should be positive timestamp"


def test_ical_description_escaping() -> None:
    """format_ical_description escapes per RFC 5545."""
    # Test that special characters are escaped
    text = "Line 1\nLine 2, with comma; and semicolon\\backslash"
    result = format_ical_description(text)

    # Newlines should become \n (literal backslash-n)
    assert "\\n" in result

    # Commas should be escaped
    assert "\\," in result

    # Semicolons should be escaped
    assert "\\;" in result

    # Backslashes should be escaped (doubled)
    assert "\\\\" in result

    # Should start with DESCRIPTION:
    assert result.startswith("DESCRIPTION:")

    # Should end with CRLF
    assert result.endswith("\r\n")


def test_ical_description_line_folding() -> None:
    """format_ical_description folds lines at 75 octets."""
    # Create a long string that will need folding
    text = "A" * 100
    result = format_ical_description(text)

    # Split into lines
    lines = result.strip().split("\r\n")

    # First line: DESCRIPTION: (12 chars) + content
    # Should fold at 75 octets
    for i, line in enumerate(lines):
        byte_length = len(line.encode("utf-8"))
        assert byte_length <= 75, f"Line {i} exceeds 75 octets: {byte_length} bytes"

    # Continuation lines should start with space
    for line in lines[1:]:
        assert line.startswith(" "), "Continuation line must start with space"


def test_ical_description_utf8_handling() -> None:
    """format_ical_description with UTF-8 multibyte characters.

    Line folding must not split multibyte characters.
    """
    # Create string with multibyte characters (emojis are 4 bytes each)
    text = "☀️🌙" * 20
    result = format_ical_description(text)

    # Should still be valid UTF-8
    assert is_valid_utf8(result), "Result should be valid UTF-8"

    # Each line should be valid UTF-8 (no split characters)
    lines = result.strip().split("\r\n")
    for i, line in enumerate(lines):
        assert is_valid_utf8(line), f"Line {i} should be valid UTF-8"
