"""Shared assertions and helpers for the solar calculation tests (the PHP suite's BaseTest)."""

from __future__ import annotations

import calendar
import math
from datetime import UTC, datetime

from sunrise_sunset_calendar import astronomy, php


def assert_float_equals(expected: float, actual: float, message: str = "", tolerance: float = 0.01) -> None:
    """Assert two floats are equal within tolerance."""
    diff = abs(expected - actual)
    assert diff <= tolerance, (
        f"{message}\n    Expected: {expected:.6f}\n    Actual:   {actual:.6f}\n"
        f"    Difference: {diff:.6f} (tolerance: {tolerance:.6f})"
    )


def assert_time_equals(expected: int | str, actual: int, message: str = "", tolerance_seconds: int = 120) -> None:
    """Assert a timestamp equals an expected time within tolerance (seconds).

    The expectation is a timestamp or an "HH:MM[:SS]" string, read on the
    actual timestamp's date in the default time zone.
    """
    if isinstance(expected, str) and ":" in expected:
        parts = expected.split(":")
        ref = php.local(actual)
        expected = php.mktime(
            int(parts[0]), int(parts[1]), int(parts[2]) if len(parts) > 2 else 0, ref.month, ref.day, ref.year
        )

    diff = abs(expected - actual)
    assert diff <= tolerance_seconds, (
        f"{message}\n    Expected: {php.php_date('Y-m-d H:i:s', expected)}\n"
        f"    Actual:   {php.php_date('Y-m-d H:i:s', actual)}\n"
        f"    Difference: {diff / 60:.2f} minutes ({diff} seconds, tolerance: {tolerance_seconds} seconds)"
    )


def assert_duration_equals(
    expected_seconds: int, actual_seconds: int, message: str = "", tolerance_seconds: int = 60
) -> None:
    """Assert a duration in seconds within tolerance."""
    diff = abs(expected_seconds - actual_seconds)
    assert diff <= tolerance_seconds, (
        f"{message}\n    Expected: {php.gmdate('H:i:s', expected_seconds)} ({expected_seconds} seconds)\n"
        f"    Actual:   {php.gmdate('H:i:s', actual_seconds)} ({actual_seconds} seconds)\n"
        f"    Difference: {diff} seconds (tolerance: {tolerance_seconds} seconds)"
    )


def assert_in_range(value: float, low: float, high: float, message: str = "") -> None:
    """Assert a value is within [low, high]."""
    assert value >= low, f"{message}\n    Value {value:.6f} below minimum {low:.6f}"
    assert value <= high, f"{message}\n    Value {value:.6f} above maximum {high:.6f}"


def assert_percentile(expected: float, actual: float, message: str = "", tolerance: float = 2.0) -> None:
    """Assert a percentile value (0-100) within tolerance."""
    diff = abs(expected - actual)
    assert diff <= tolerance, (
        f"{message}\n    Expected: {expected:.2f}%\n    Actual:   {actual:.2f}%\n"
        f"    Difference: {diff:.2f}% (tolerance: {tolerance:.2f}%)"
    )


def calculate_sun_times(year: int, month: int, day: int, lat: float, lon: float, utc_offset: float) -> dict:
    """Calculate sun times (reduces boilerplate)."""
    return astronomy.calculate_sun_times(year, month, day, lat, lon, utc_offset)


def fraction_to_timestamp(year: int, month: int, day: int, fraction: float) -> int:
    """Convert a fraction to a timestamp, reading the local wall-clock time as UTC.

    Tests run in UTC, so this matches mktime()-built expectations.
    """
    return astronomy.fraction_to_timestamp(year, month, day, fraction, 0.0)


def utc(text: str) -> int:
    """strtotime('YYYY-MM-DD HH:MM[:SS] UTC'): a UTC date and time as a timestamp."""
    fmt = "%Y-%m-%d %H:%M:%S" if text.count(":") == 2 else "%Y-%m-%d %H:%M"
    return calendar.timegm(datetime.strptime(text, fmt).timetuple())


def ical_timestamp(value: str) -> int:
    """strtotime() of an iCalendar DTSTART/DTEND: UTC date-times, or all-day dates at midnight UTC."""
    if "T" in value:
        return calendar.timegm(datetime.strptime(value, "%Y%m%dT%H%M%SZ").replace(tzinfo=UTC).timetuple())
    return calendar.timegm(datetime.strptime(value, "%Y%m%d").timetuple())


# ----------------------------------------------------------------------------
# PHP's date_sun_info() sunrise: an independent solar model to cross-check against
# ----------------------------------------------------------------------------
# A port of timelib's astro.c (Paul Schlyter's sunriset.c) as PHP 8.5's
# date_sun_info() calls it, for sunrise with the default time zone UTC:
# timelib_astro_rise_set_altitude(t, lon, lat, -35/60, upper_limb=1). It
# reproduces PHP 8.5.12's timestamps to the second.

_RADEG = 180.0 / math.pi
_DEGRAD = math.pi / 180.0


def _sind(x: float) -> float:
    return math.sin(x * _DEGRAD)


def _cosd(x: float) -> float:
    return math.cos(x * _DEGRAD)


def _atan2d(y: float, x: float) -> float:
    return _RADEG * math.atan2(y, x)


def _revolution(x: float) -> float:
    return x - 360.0 * math.floor(x * (1.0 / 360.0))


def _rev180(x: float) -> float:
    return x - 360.0 * math.floor(x * (1.0 / 360.0) + 0.5)


def _sun_ra_dec(d: float) -> tuple[float, float, float]:
    """The sun's right ascension, declination (degrees) and distance (AU), d days after 2000 Jan 0.0."""
    m = _revolution(356.0470 + 0.9856002585 * d)
    w = 282.9404 + 4.70935e-5 * d
    e = 0.016709 - 1.151e-9 * d
    ecc = m + e * _RADEG * _sind(m) * (1.0 + e * _cosd(m))
    x = _cosd(ecc) - e
    y = math.sqrt(1.0 - e * e) * _sind(ecc)
    r = math.sqrt(x * x + y * y)
    lon = _atan2d(y, x) + w
    if lon >= 360.0:
        lon -= 360.0
    x = r * _cosd(lon)
    y = r * _sind(lon)
    obliquity = 23.4393 - 3.563e-7 * d
    z = y * _sind(obliquity)
    y = y * _cosd(obliquity)
    return _atan2d(y, x), _atan2d(z, math.sqrt(x * x + y * y)), r


def date_sun_info_sunrise(timestamp: int, lat: float, lon: float) -> int | bool:
    """date_sun_info($timestamp, $lat, $lon)['sunrise'] with the default time zone UTC.

    True when the sun stays up all day, False when it stays down.
    """
    midnight = timestamp - timestamp % 86400  # UTC 00:00 of the timestamp's UTC date
    d = (midnight / 86400 + 2440587.5 - 2451545) + 2 - lon / 360.0
    gmst0 = _revolution((180.0 + 356.0470 + 282.9404) + (0.9856002585 + 4.70935e-5) * d)
    sidtime = _revolution(gmst0 + 180.0 + lon)
    ra, dec, r = _sun_ra_dec(d)
    tsouth = 12.0 - _rev180(sidtime - ra) / 15.0
    altitude = -35.0 / 60 - 0.2666 / r
    cost = (_sind(altitude) - _sind(lat) * _sind(dec)) / (_cosd(lat) * _cosd(dec))
    if cost >= 1.0:
        return False
    if cost <= -1.0:
        return True
    t = _RADEG * math.acos(cost) / 15.0
    return int((tsouth - t) * 3600 + midnight)
