"""Format and helper functions test suite: formatting, validation, and utility functions."""

from __future__ import annotations

from sunrise_sunset_calendar import app, clock
from sunrise_sunset_calendar.helpers import (
    day_runs,
    format_clock_shift,
    format_comparison,
    format_duration,
    format_extremes,
    format_percent,
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
    assert format_comparison(150, "yesterday", 9 * 3600) == "vs yesterday: +2m 30s (+0.5%)"


def test_comparison_shorter() -> None:
    assert format_comparison(-105, "last night", 11 * 3600) == "vs last night: −1m 45s (−0.3%)"


def test_comparison_same() -> None:
    assert format_comparison(0, "yesterday", 9 * 3600) == "Same length as yesterday"


def test_comparison_leaves_out_a_percentage_that_rounds_to_zero() -> None:
    # Near the solstices the day changes by seconds: 2s of 9h is 0.006%
    assert format_comparison(2, "yesterday", 9 * 3600) == "vs yesterday: +2s"
    # Nothing to compare against after a day without daylight
    assert format_comparison(1800, "yesterday", 0) == "vs yesterday: +30m 00s"


def test_percent_rounding() -> None:
    assert format_percent(-110, 34675) == "−0.3%", "True minus sign"
    assert format_percent(1709, 32856) == "+5.2%"
    assert format_percent(-20269, 54834) == "−37%", "Whole numbers from 10%"
    assert format_percent(24480, 15720) == "+156%"
    assert format_percent(997, 10000) == "+10%", "9.97% rounds to 10.0, so it's a whole number"
    assert format_percent(0, 100) is None
    assert format_percent(1, 100000) is None, "0.001% rounds to 0.0%"
    assert format_percent(5, 0) is None


def test_clock_shift() -> None:
    assert format_clock_shift(73) == "1m 13s later than yesterday"
    assert format_clock_shift(-53) == "53s earlier than yesterday"
    assert format_clock_shift(0) == "the same time as yesterday"


def test_range_bar_is_linear_between_labelled_ends() -> None:
    assert format_range_bar(12 * 3600, 9 * 3600, 15 * 3600) == "9h 00m ▕█████░░░░░▏ 15h 00m"
    assert format_range_bar(9 * 3600, 9 * 3600, 15 * 3600) == "9h 00m ▕░░░░░░░░░░▏ 15h 00m"
    assert format_range_bar(15 * 3600, 9 * 3600, 15 * 3600) == "9h 00m ▕██████████▏ 15h 00m"


def test_extremes_rank_the_day_and_compare_it_with_both_ends() -> None:
    shortest = (9 * 3600, clock.local_timestamp(2026, 12, 21, 12, 0, 0))
    longest = (15 * 3600, clock.local_timestamp(2026, 6, 21, 12, 0, 0))
    # A year of 9h to 15h days, a quarter of them shorter than 10h
    lengths = [9 * 3600] * 50 + [9 * 3600 + 1800] * 41 + [10 * 3600] * 10 + [12 * 3600] * 264

    assert format_extremes("daylight", 10 * 3600, shortest, longest, lengths) == [
        "Longer than 91 of 365 days (25%)",
        "vs shortest (Dec 21): +1h 00m (+11%)",
        "vs longest (Jun 21): −5h 00m (−33%)",
    ]
    # The extreme day itself: no rank, and the other end
    assert format_extremes("daylight", 9 * 3600 + 1, shortest, longest, lengths) == [
        "The shortest day of the year",
        "vs longest (Jun 21): −6h 00m (−40%)",
    ]
    # A day seconds from the shortest is not the shortest, and says by how much
    assert format_extremes("daylight", 9 * 3600 + 30, shortest, longest, lengths)[1] == (
        "vs shortest (Dec 21): +30s (+0.1%)"
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
