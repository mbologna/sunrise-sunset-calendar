"""Format and helper functions test suite: formatting, validation, and utility functions."""

from __future__ import annotations

from sunrise_sunset_calendar import app, php
from sunrise_sunset_calendar.helpers import (
    day_runs,
    format_clock_shift,
    format_comparison,
    format_duration,
    format_extremes,
    format_range_bar,
    format_signed_duration,
    moonless_windows,
)


def test_duration_hours_pad_minutes() -> None:
    assert format_duration(9 * 3600 + 5 * 60) == "9h 05m"


def test_duration_under_an_hour_rounds_to_minutes() -> None:
    assert format_duration(32 * 60 + 20) == "32m"


def test_duration_with_seconds_under_an_hour() -> None:
    assert format_duration(125, True) == "2m 05s"
    assert format_duration(45, True) == "45s"


def test_duration_with_seconds_drops_them_from_an_hour() -> None:
    assert format_duration(5 * 3600 + 25 * 60 + 20, True) == "5h 25m"


def test_duration_ignores_sign() -> None:
    assert format_duration(-5400) == "1h 30m"


def test_signed_duration() -> None:
    assert format_signed_duration(-600) == "-10m"
    assert format_signed_duration(900) == "+15m"
    assert format_signed_duration(-20) == "+0m", "No negative zero"


def test_comparison_longer() -> None:
    assert format_comparison(150, "yesterday") == "2m 30s longer than yesterday"


def test_comparison_shorter() -> None:
    assert format_comparison(-105, "last night") == "1m 45s shorter than last night"


def test_comparison_same() -> None:
    assert format_comparison(0, "yesterday") == "Same length as yesterday"


def test_clock_shift() -> None:
    assert format_clock_shift(73) == "1m 13s later"
    assert format_clock_shift(-53) == "53s earlier"
    assert format_clock_shift(0) == "unchanged"


def test_range_bar_is_linear_between_labelled_ends() -> None:
    assert format_range_bar(12 * 3600, 9 * 3600, 15 * 3600) == "9h 00m ▕█████░░░░░▏ 15h 00m"
    assert format_range_bar(9 * 3600, 9 * 3600, 15 * 3600) == "9h 00m ▕░░░░░░░░░░▏ 15h 00m"
    assert format_range_bar(15 * 3600, 9 * 3600, 15 * 3600) == "9h 00m ▕██████████▏ 15h 00m"


def test_extremes_name_the_end_today() -> None:
    shortest = (9 * 3600, php.mktime(12, 0, 0, 12, 21, 2026))
    longest = (15 * 3600, php.mktime(12, 0, 0, 6, 21, 2026))

    assert (
        format_extremes("daylight", 10 * 3600, shortest, longest)
        == "1h 00m more than the shortest day (Dec 21), 5h 00m less than the longest (Jun 21)"
    )
    assert (
        format_extremes("daylight", 9 * 3600 + 1, shortest, longest)
        == "The shortest day of the year: 6h 00m less than the longest (Jun 21)"
    )
    # A day seconds from the shortest is not the shortest, and says by how much
    assert (
        format_extremes("daylight", 9 * 3600 + 30, shortest, longest)
        == "30s more than the shortest day (Dec 21), 6h 00m less than the longest (Jun 21)"
    )


def test_moonless_windows_follow_rises_and_sets() -> None:
    night = (1000, 9000)
    assert moonless_windows({"up_at_start": False, "events": []}, *night) == [[1000, 9000]]
    assert moonless_windows({"up_at_start": True, "events": []}, *night) == []
    assert moonless_windows({"up_at_start": True, "events": [["set", 3000]]}, *night) == [[3000, 9000]]
    assert moonless_windows({"up_at_start": True, "events": [["set", 3000], ["rise", 6000]]}, *night) == [[3000, 6000]]


def test_day_runs_merge_across_new_year() -> None:
    flags = [True, True, False, False, True, False, True]
    runs = day_runs(flags, list(range(7)))

    assert runs == [[4, 4], [6, 1]]


def test_sanitize_float_valid_latitude() -> None:
    assert app.sanitize_float(b"45.5", 0.0, -90, 90) == 45.5


def test_sanitize_float_invalid_returns_default() -> None:
    assert app.sanitize_float(b"invalid", 0.0, -90, 90) == 0.0


def test_sanitize_float_out_of_range_returns_default() -> None:
    assert app.sanitize_float(b"100", 0.0, -90, 90) == 0.0


def test_sanitize_float_negative_valid() -> None:
    assert app.sanitize_float(b"-45.5", 0.0, -90, 90) == -45.5


def test_sanitize_int_valid_offset() -> None:
    assert app.sanitize_int(b"30", 0, -1440, 1440) == 30


def test_sanitize_int_invalid_returns_default() -> None:
    assert app.sanitize_int(b"invalid", 0, -1440, 1440) == 0


def test_sanitize_int_out_of_range_returns_default() -> None:
    assert app.sanitize_int(b"2000", 0, -1440, 1440) == 0


def test_sanitize_int_negative_valid() -> None:
    assert app.sanitize_int(b"-60", 0, -1440, 1440) == -60


def test_sanitize_timezone_valid() -> None:
    assert app.sanitize_timezone(b"Europe/Rome") == "Europe/Rome"


def test_sanitize_timezone_invalid_returns_default() -> None:
    assert app.sanitize_timezone(b"Invalid/Timezone") == "Europe/Rome"


def test_sanitize_timezone_us_timezone() -> None:
    assert app.sanitize_timezone(b"America/New_York") == "America/New_York"


def test_sanitize_text_clean() -> None:
    assert app.sanitize_text(b"Hello World") == "Hello World"


def test_sanitize_text_strips_html_tags() -> None:
    assert app.sanitize_text(b"<script>Hello World</script>") == "Hello World"


def test_sanitize_text_removes_newlines() -> None:
    assert app.sanitize_text(b"Hello\nWorld") == "Hello World"


def test_sanitize_text_truncates_long_text() -> None:
    long_text = b"a" * 600
    sanitized = app.sanitize_text(long_text, 500)
    assert len(sanitized.encode("utf-8")) == 500
