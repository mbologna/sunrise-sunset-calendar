"""Per-request memoization of the expensive astronomical calculations.

Each request starts with an empty cache, as each PHP request did, so entries
stay bounded by one feed's window.
"""

from __future__ import annotations

from contextvars import ContextVar


class Cache:
    """Solar calculations, year profiles, week summaries and moon phases for one request."""

    def __init__(self) -> None:
        self.solar_calc: dict[str, dict] = {}
        self.year_profile: dict[str, list[dict]] = {}
        self.week_summary: dict[str, dict] = {}
        self.moon_phase: dict[str, list[dict]] = {}
        self.year_summary: dict[str, dict] = {}


_current: ContextVar[Cache | None] = ContextVar("calendar_cache", default=None)


def get() -> Cache:
    cache = _current.get()
    if cache is None:
        cache = Cache()
        _current.set(cache)
    return cache


def reset() -> None:
    """Start a new request: forget everything cached so far."""
    _current.set(Cache())
