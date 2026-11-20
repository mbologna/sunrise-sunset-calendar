"""The JSON endpoint: the sun and the moon at a moment, for a place."""

from __future__ import annotations

from datetime import datetime

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
from .numbers import round_int

# The day's sun events in time order, by their name in the JSON
_SUN_EVENTS = {
    "astronomical_dawn": "astro_begin",
    "first_light": "nautical_begin",
    "civil_dawn": "civil_begin",
    "sunrise": "sunrise",
    "solar_noon": "solar_noon",
    "sunset": "sunset",
    "civil_dusk": "civil_end",
    "last_light": "nautical_end",
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
            | {"day_length": round_int(today["daylength_h"] * 3600), "polar": today["polar"]},
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
