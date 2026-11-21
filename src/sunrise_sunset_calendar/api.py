"""The JSON endpoint: the sun and the moon at a moment, for a place."""

from __future__ import annotations

from datetime import datetime, tzinfo

from . import cache
from .astronomy import (
    SUNRISE_ALTITUDE,
    get_accurate_moon_phase,
    moon_position,
    moon_rise_set,
    sun_events,
    sun_position,
)
from .clock import LocalDate, use_zone, zone
from .helpers import year_summary
from .numbers import round_int

# The day's sun events in time order, by their name in the JSON
_SUN_EVENTS = {
    "astronomical_dawn": "astro_begin",
    "nautical_dawn": "nautical_begin",
    "civil_dawn": "civil_begin",
    "sunrise": "sunrise",
    "solar_noon": "solar_noon",
    "sunset": "sunset",
    "civil_dusk": "civil_end",
    "nautical_dusk": "nautical_end",
    "astronomical_dusk": "astro_end",
}
# Where the sun is, by the lowest altitude of its centre in each period
_PERIODS = (
    (SUNRISE_ALTITUDE, "daylight"),
    (-6.0, "civil_twilight"),
    (-12.0, "nautical_twilight"),
    (-18.0, "astronomical_twilight"),
)


def _period(altitude: float) -> str:
    return next((name for floor, name in _PERIODS if altitude > floor), "night")


def _day_length(lat: float, lon: float, day: LocalDate, today: dict, tz: tzinfo) -> dict:
    """The day's length against yesterday's and among the year's days, as the feed's Daylight reports it.

    Percentages keep two decimals (rounding for display is the client's); null with nothing to compare
    against. In polar years many days tie at 0 or 24 hours: the extremes are the first such days.
    """
    length = round_int(today["daylength_h"] * 3600)
    before = round_int(sun_events(day.add_days(-1), lat, lon)["daylength_h"] * 3600)
    year = year_summary(lat, lon, day.year, today["utc_offset"])
    lengths = [round_int(d["daylength_h"] * 3600) for d in year["days"]]

    def extreme(pick: list) -> dict:
        return {
            "date": datetime.fromtimestamp(pick[1], tz).date().isoformat(),
            "day_length": round_int(pick[0] * 3600),
        }

    return {
        "vs_yesterday": {
            "seconds": length - before,
            "percent": round(100 * (length - before) / before, 2) if before > 0 else None,
        },
        "year": {
            "shortest": extreme(year["daylight_min"]),
            "longest": extreme(year["daylight_max"]),
            "shorter_days": sum(1 for other in lengths if other < length),
            "days": len(lengths),
        },
    }


def now_json(lat: float, lon: float, zone_name: str, ts: int) -> dict:
    """The sun and the moon at a Unix time: positions, the day's sun events, and what comes next."""
    tz = zone(zone_name)

    def iso(t: int | None) -> str | None:
        return None if t is None else datetime.fromtimestamp(t, tz).isoformat()

    cache.reset()
    with use_zone(zone_name):
        day = LocalDate.from_timestamp(ts, tz)
        today = sun_events(day, lat, lon)
        tomorrow = sun_events(day.add_days(1), lat, lon)
        day_length = _day_length(lat, lon, day, today, tz)
        phase = get_accurate_moon_phase(ts)
        moon_events = moon_rise_set(ts, ts + 2 * 86400, lat, lon)["events"]

    upcoming = sorted(
        (at, name)
        for events in (today, tomorrow)
        for name, field in _SUN_EVENTS.items()
        if (at := events[field]) is not None and at > ts
    )
    sun_alt, sun_az, sun_up = sun_position(ts, lat, lon)
    moon_alt, moon_az, moon_up = moon_position(ts, lat, lon)
    next_phase = phase["next_phase"]
    return {
        "at": iso(ts),
        "location": {"lat": lat, "lon": lon, "zone": zone_name},
        "sun": {
            "altitude": round(sun_alt, 2),
            "azimuth": round(sun_az, 2),
            "up": sun_up,
            "period": _period(sun_alt),
            "today": {name: iso(today[field]) for name, field in _SUN_EVENTS.items()}
            | {"day_length": round_int(today["daylength_h"] * 3600), "polar": today["polar"]}
            | day_length,
            "next": {"event": upcoming[0][1], "at": iso(upcoming[0][0])} if upcoming else None,
        },
        "moon": {
            "altitude": round(moon_alt, 2),
            "azimuth": round(moon_az, 2),
            "up": moon_up,
            "illumination": phase["illumination"],
            "phase": phase["phase_name"],
            "next_phase": {"name": next_phase["name"], "at": iso(next_phase["timestamp"])} if next_phase else None,
            "next_rise": iso(next((at for kind, at in moon_events if kind == "rise"), None)),
            "next_set": iso(next((at for kind, at in moon_events if kind == "set"), None)),
        },
    }
