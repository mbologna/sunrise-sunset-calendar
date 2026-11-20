"""Dates and times in a time zone, on datetime and zoneinfo.

A feed works in one zone, set for the length of a request with use_zone();
the functions that take no zone read it. A wall time a DST change skips
counts with the offset before the change, so 02:30 on the spring-forward day
is 03:30; one it repeats is its first occurrence.

Date formats are str.format() templates over the fields of format_fields(),
with English names: strftime()'s names depend on the locale.
"""

from __future__ import annotations

import calendar
from collections.abc import Iterator
from contextlib import contextmanager
from contextvars import ContextVar
from datetime import UTC, date, datetime, timedelta
from functools import cache
from zoneinfo import ZoneInfo

ISO_DATE = "{year:04d}-{month:02d}-{day:02d}"
BASIC_DATE = "{year:04d}{month:02d}{day:02d}"
ICAL_UTC = "{year:04d}{month:02d}{day:02d}T{hour:02d}{minute:02d}{second:02d}Z"

_WEEKDAYS = ("Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday")
_MONTHS = (
    "January",
    "February",
    "March",
    "April",
    "May",
    "June",
    "July",
    "August",
    "September",
    "October",
    "November",
    "December",
)

_zone_name: ContextVar[str] = ContextVar("zone", default="UTC")


@cache
def zone(name: str) -> ZoneInfo:
    return ZoneInfo(name)


def current_zone() -> str:
    """The name of the zone use_zone() set, UTC outside one."""
    return _zone_name.get()


@contextmanager
def use_zone(name: str) -> Iterator[None]:
    """Work in a time zone for the duration of a block."""
    token = _zone_name.set(name)
    try:
        yield
    finally:
        _zone_name.reset(token)


def _normalised(year: int, month: int, day: int, hour: int, minute: int, second: int) -> datetime:
    """A naive datetime from fields that may overflow: month 13, day 0, hour 25."""
    year += (month - 1) // 12
    month = (month - 1) % 12 + 1
    return datetime(year, month, 1) + timedelta(days=day - 1, hours=hour, minutes=minute, seconds=second)


def resolve(wall: datetime, tz: ZoneInfo) -> int:
    """The Unix time of a naive wall time in a zone (see the module docstring for DST changes)."""
    return int(wall.replace(tzinfo=tz, fold=0).timestamp())


def wall_clock(ts: int, tz: ZoneInfo) -> datetime:
    """The naive wall time of a Unix time in a zone."""
    return datetime.fromtimestamp(ts, tz).replace(tzinfo=None)


def local(ts: int) -> datetime:
    """The naive wall time of a Unix time in the current zone."""
    return wall_clock(ts, zone(current_zone()))


def local_timestamp(year: int, month: int, day: int, hour: int = 0, minute: int = 0, second: int = 0) -> int:
    """The Unix time of a wall time in the current zone; fields may overflow (day 0 is the last of the month before)."""
    return resolve(_normalised(year, month, day, hour, minute, second), zone(current_zone()))


def utc_timestamp(year: int, month: int, day: int, hour: int = 0, minute: int = 0, second: int = 0) -> int:
    """The Unix time of a UTC date and time; fields may overflow."""
    return calendar.timegm(_normalised(year, month, day, hour, minute, second).timetuple())


def days_later(ts: int, days: int) -> int:
    """The same wall time a number of days later, in the current zone."""
    tz = zone(current_zone())
    return resolve(wall_clock(ts, tz) + timedelta(days=days), tz)


def format_fields(dt: datetime) -> dict:
    """The fields date templates use."""
    return {
        "year": dt.year,
        "month": dt.month,
        "day": dt.day,
        "hour": dt.hour,
        "minute": dt.minute,
        "second": dt.second,
        "weekday": _WEEKDAYS[dt.weekday()],
        "weekday_short": _WEEKDAYS[dt.weekday()][:3],
        "month_name": _MONTHS[dt.month - 1],
        "month_short": _MONTHS[dt.month - 1][:3],
    }


def format_datetime(dt: datetime, template: str) -> str:
    return template.format(**format_fields(dt))


def format_local_time(ts: int, template: str) -> str:
    """A Unix time in the current zone, through a date template."""
    return format_datetime(local(ts), template)


def format_utc_time(ts: int, template: str) -> str:
    """A Unix time in UTC, through a date template."""
    return format_datetime(datetime.fromtimestamp(ts, UTC).replace(tzinfo=None), template)


class LocalDate:
    """A wall time in a zone, with day and time arithmetic on the wall clock.

    The wall time is kept as it resolves: one a DST change skips moves forward
    by the gap, so later changes start from the time that exists.
    """

    __slots__ = ("ts", "tz", "wall")

    def __init__(self, wall: datetime, tz: ZoneInfo) -> None:
        self.tz = tz
        self.ts = resolve(wall, tz)
        self.wall = wall_clock(self.ts, tz)

    @classmethod
    def today(cls, tz: ZoneInfo, now: int) -> LocalDate:
        """Midnight of the date it is at a Unix time."""
        day = wall_clock(now, tz).date()
        return cls(datetime(day.year, day.month, day.day), tz)

    @classmethod
    def parse(cls, text: str, tz: ZoneInfo) -> LocalDate:
        """Midnight of a 'YYYY-MM-DD' date."""
        d = date.fromisoformat(text)
        return cls(datetime(d.year, d.month, d.day), tz)

    @classmethod
    def from_timestamp(cls, ts: int, tz: ZoneInfo) -> LocalDate:
        obj = cls.__new__(cls)
        obj.tz = tz
        obj.ts = ts
        obj.wall = wall_clock(ts, tz)
        return obj

    def add_days(self, days: int) -> LocalDate:
        return LocalDate(self.wall + timedelta(days=days), self.tz)

    def set_time(self, hour: int, minute: int) -> LocalDate:
        return LocalDate(self.wall.replace(hour=hour, minute=minute, second=0, microsecond=0), self.tz)

    def offset(self) -> int:
        """The UTC offset in effect, in seconds."""
        offset = datetime.fromtimestamp(self.ts, self.tz).utcoffset()
        return 0 if offset is None else int(offset.total_seconds())

    def format(self, template: str) -> str:
        return format_datetime(self.wall, template)

    @property
    def year(self) -> int:
        return self.wall.year

    @property
    def month(self) -> int:
        return self.wall.month

    @property
    def day(self) -> int:
        return self.wall.day
