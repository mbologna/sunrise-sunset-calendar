"""Astronomical calculations, from the JPL DE442 ephemeris through skyfield.

The sun rises and sets when its centre is 0.8333° below the horizon (34′ of
refraction plus its 16′ radius, as the US Naval Observatory defines them).
Twilight and the golden and blue hours begin and end when its centre crosses
-18°, -12°, -6°, -4° and +6°, without refraction. A date's solar day is the
one whose transit falls nearest the date's local noon. Equinoxes, solstices,
moon phases, illumination and moonrise come from the same ephemeris.

Each search covers a UTC year and stays cached across requests: a feed reads
every day several times, and the same places come back.

The ephemeris is an excerpt of DE442 (JPL, 2024) with the bodies the
calculations use, the Sun, the Earth-Moon system, and Jupiter and Saturn
(whose gravity bends light), from 1999 to 2150: enough for feeds of years up
to 2148. To make it again, from https://ssd.jpl.nasa.gov/ftp/eph/planets/bsp/de442.bsp
(sha256 8d5001fab315eeff222cc51f7cf7ffcdb43fb38fb9ac73ff09e09a5b361fd388):

    uvx --from jplephem python -m jplephem excerpt --targets 3,5,6,10,399,301 \
        1999/01/01 2150/01/01 de442.bsp src/sunrise_sunset_calendar/data/de442-1999-2150.bsp
"""

from __future__ import annotations

import math
import threading
from collections.abc import Callable, Hashable
from datetime import UTC, datetime
from functools import lru_cache, wraps
from importlib import resources
from typing import Any, cast

import numpy as np
from skyfield import almanac
from skyfield.api import load, load_file, wgs84
from skyfield.nutationlib import iau2000b_radians
from skyfield.searchlib import find_discrete

from . import cache
from .php import LocalDate, gmmktime, local, mktime, php_round, round_int

_EPHEMERIS = load_file(str(resources.files(__package__).joinpath("data", "de442-1999-2150.bsp")))
_TS = load.timescale(builtin=True)
# The years a feed can cover: each search reaches up to 31 days beyond its year
_COVERAGE = [
    (jd - 2440587.5) * 86400
    for jd in (
        max(s.start_jd for s in _EPHEMERIS.spk.segments),
        min(s.end_jd for s in _EPHEMERIS.spk.segments),
    )
]
FIRST_YEAR = datetime.fromtimestamp(_COVERAGE[0] + 32 * 86400, UTC).year + 1
LAST_YEAR = datetime.fromtimestamp(_COVERAGE[1] - 32 * 86400, UTC).year - 1
_EARTH, _SUN, _MOON = _EPHEMERIS["earth"], _EPHEMERIS["sun"], _EPHEMERIS["moon"]
_MOON_HORIZON = almanac.build_horizon_function(_MOON)

SUNRISE_ALTITUDE = -0.8333
# The events that bound each band, by the altitude of the sun's centre at them
_BANDS = {
    ("sunrise", "sunset"): SUNRISE_ALTITUDE,
    ("civil_begin", "civil_end"): -6.0,
    ("nautical_begin", "nautical_end"): -12.0,
    ("astro_begin", "astro_end"): -18.0,
    # Blue hour ends and golden hour starts at -4°; golden hour ends at +6°
    ("minus4_begin", "minus4_end"): -4.0,
    ("plus6_begin", "plus6_end"): 6.0,
}
SEASONS = ("march_equinox", "june_solstice", "september_equinox", "december_solstice")
PHASES = ("New Moon", "First Quarter", "Full Moon", "Last Quarter")
DAY = 86400

# Unix timestamps of risings and of settings, each sorted
Crossings = tuple[np.ndarray, np.ndarray]

# One search at a time: they hold the GIL anyway, and each takes tens of megabytes while it runs
_SEARCH_LOCK = threading.Lock()


def _searched[F: Callable[..., Any]](maxsize: int) -> Callable[[F], F]:
    """lru_cache for a yearly search, run under _SEARCH_LOCK so concurrent misses don't pile up."""

    def decorate(search: F) -> F:
        cached = lru_cache(maxsize=maxsize)(search)

        @wraps(search)
        def locked(*args: Hashable) -> Any:
            with _SEARCH_LOCK:
                return cached(*args)

        return cast(F, locked)

    return decorate


# ============================================================================
# TIME CONVERSIONS
# ============================================================================


def _times(unix: float | list[float] | np.ndarray):
    """Unix timestamps as a skyfield Time (whole days and seconds, so leap seconds land right)."""
    days, seconds = np.divmod(np.asarray(unix, dtype=float), DAY)
    return _TS.utc(1970, 1, 1 + days, 0, 0, seconds)


def _unix(t) -> np.ndarray:
    """A skyfield Time array as Unix timestamps."""
    if not len(t):
        return np.empty(0)
    return np.array([d.timestamp() for d in t.utc_datetime()])


def _utc_year(timestamp: float) -> int:
    return datetime.fromtimestamp(timestamp, UTC).year


def _year_span(year: int, margin: int) -> tuple[int, int]:
    """A UTC year widened by margin seconds on both sides, as Unix timestamps."""
    return gmmktime(0, 0, 0, 1, 1, year) - margin, gmmktime(0, 0, 0, 1, 1, year + 1) + margin


# ============================================================================
# RISINGS AND SETTINGS
# ============================================================================

# skyfield's rise and set search predicts each crossing from the hour angle,
# which loses crossings where a body skims the horizon: the moon's from 70°
# of latitude (its declination reaches 28.6°, so it can skim above 60°), the
# sun's from 88°. Beyond these limits the altitude is sampled every 10
# minutes and each crossing refined instead: it works at any latitude, and
# agrees with the hour-angle search to a millisecond where both work.
_MOON_SEARCH_LIMIT = 60.0
_SUN_SEARCH_LIMIT = 85.0
_SAMPLE = 600
# Samples per skyfield call. A year's 61,000 at once take over 100 MB, and
# larger chunks leave more freed memory with the allocator: 256 keeps the
# peak near 70 MB for a little more time.
_CHUNK = 256


def _hour_angle_crossings(observer, body, start: int, stop: int, horizon: float | None) -> Crossings:
    """Risings and settings through a horizon altitude (None: the body's own), by skyfield's search."""
    t = _times([start, stop])
    rises, up = almanac.find_risings(observer, body, t[0], t[1], horizon)
    sets, down = almanac.find_settings(observer, body, t[0], t[1], horizon)
    return _unix(rises[up]), _unix(sets[down])


def _sampled_crossings(height: Callable[[np.ndarray], np.ndarray], grid: np.ndarray, sampled: np.ndarray) -> Crossings:
    """Where height(unix times) turns positive and negative, from samples on a grid.

    Each crossing is refined by the Illinois variant of regula falsi: an endpoint kept twice in
    a row has its value halved, so the search doesn't stall where the body skims the altitude.
    """
    i = np.nonzero(np.signbit(sampled[:-1]) != np.signbit(sampled[1:]))[0]
    a, b, fa, fb = grid[i], grid[i + 1], sampled[i], sampled[i + 1]
    rising = np.signbit(fa)
    replaced_a = np.zeros(len(i), dtype=bool)
    replaced_b = np.zeros(len(i), dtype=bool)
    for _ in range(8):
        x = a - fa * (b - a) / (fb - fa)
        fx = height(x)
        on_a = np.signbit(fx) == np.signbit(fa)
        fb = np.where(on_a & replaced_a, fb / 2, fb)
        fa = np.where(~on_a & replaced_b, fa / 2, fa)
        a, fa = np.where(on_a, x, a), np.where(on_a, fx, fa)
        b, fb = np.where(on_a, b, x), np.where(on_a, fb, fx)
        replaced_a, replaced_b = on_a, ~on_a
    x = a - fa * (b - a) / (fb - fa)
    return x[rising], x[~rising]


# Sampling skips what moves a rise or set by far less than a millisecond, as
# skyfield's own search does: light bending by the Sun, Jupiter and Saturn
# (deflectors), and the IAU 2000A nutation series for 2000B (1 milliarcsecond).
def _sampled_times(unix: np.ndarray):
    """Times for sampling, with the IAU 2000B nutation."""
    t = _times(unix)
    t._nutation_angles_radians = iau2000b_radians(t)
    return t


def _in_chunks(f: Callable[[np.ndarray], np.ndarray]) -> Callable[[np.ndarray], np.ndarray]:
    """f over Unix times, _CHUNK of them at a time."""

    def chunked(unix: np.ndarray) -> np.ndarray:
        return np.concatenate([f(unix[i : i + _CHUNK]) for i in range(0, len(unix), _CHUNK)] or [np.empty(0)])

    return chunked


def _sun_height(observer, altitude: float) -> Callable[[np.ndarray], np.ndarray]:
    """Degrees of the sun's centre above an altitude."""

    def height(unix: np.ndarray) -> np.ndarray:
        return observer.at(_sampled_times(unix)).observe(_SUN).apparent(deflectors=()).altaz()[0].degrees - altitude

    return _in_chunks(height)


def _moon_height(observer) -> Callable[[np.ndarray], np.ndarray]:
    """Degrees of the moon's upper limb above the horizon, refraction included."""

    def height(unix: np.ndarray) -> np.ndarray:
        altitude, _, distance = observer.at(_sampled_times(unix)).observe(_MOON).apparent(deflectors=()).altaz()
        return np.degrees(altitude.radians - _MOON_HORIZON(distance))

    return _in_chunks(height)


# ============================================================================
# THE SUN
# ============================================================================


# About 45 kB a year and place: 256 of them stay under 12 MB
@_searched(maxsize=256)
def _solar_year(lat: float, lon: float, year: int) -> tuple[np.ndarray, np.ndarray, dict[float, Crossings]]:
    """The sun's transits and its declination at each, and every band altitude's risings and settings.

    Covers a UTC year and three days either side, so every day of the year
    finds its transit and its crossings in one search.
    """
    observer = _EARTH + wgs84.latlon(lat, lon)
    start, stop = _year_span(year, 3 * DAY)
    span = _times([start, stop])
    transits = almanac.find_transits(observer, _SUN, span[0], span[1])
    _, dec, _ = observer.at(transits).observe(_SUN).apparent().radec("date")
    altitudes = set(_BANDS.values())
    if abs(lat) < _SUN_SEARCH_LIMIT:
        crossings = {alt: _hour_angle_crossings(observer, _SUN, start, stop, alt) for alt in altitudes}
    else:
        grid = np.arange(start, stop + _SAMPLE, _SAMPLE, dtype=float)
        sampled = _sun_height(observer, 0.0)(grid)
        crossings = {alt: _sampled_crossings(_sun_height(observer, alt), grid, sampled - alt) for alt in altitudes}
    return _unix(transits), dec.degrees, crossings


def calculate_sun_times(y: int, m: int, d: int, lat: float, lon: float, utc_offset: float) -> dict:
    """Sun times for one date as day fractions in a fixed UTC offset, plus day length and declination."""
    key = f"{y}-{m}-{d}:{lat:.4f}:{lon:.4f}:{utc_offset:.2f}"
    store = cache.get().solar_calc
    cached = store.get(key)
    if cached is not None:
        return cached

    midnight = gmmktime(0, 0, 0, m, d, y) - utc_offset * 3600
    noon = midnight + DAY / 2
    # The date's solar day is the one around the mean solar noon nearest its local noon. Mean noon
    # advances exactly a day a day, unlike the transit, which the equation of time moves by up to
    # 16 minutes: where solar noon is near local midnight, the nearest transit could serve two
    # dates. A tie (solar noon at local midnight) goes to the earlier: daylight saving only moves
    # local noon earlier, so the choice holds across it.
    mean_noon = DAY / 2 - lon * 240
    target = mean_noon + math.ceil((noon - mean_noon) / DAY - 0.5) * DAY
    transits, declinations, crossings = _solar_year(float(lat), float(lon), _utc_year(target))
    i = int(np.searchsorted(transits, target))
    if i == len(transits) or (i > 0 and target - transits[i - 1] < transits[i] - target):
        i -= 1
    transit, decl = float(transits[i]), float(declinations[i])
    # The solar day runs from the solar midnight before the transit to the one after, halfway to
    # the neighbouring transits: each crossing belongs to exactly one day
    day_start = (transits[i - 1] + transit) / 2 if i else transit - DAY / 2
    day_end = (transit + transits[i + 1]) / 2 if i + 1 < len(transits) else transit + DAY / 2

    def frac(t: float | None) -> float | None:
        return None if t is None else (t - midnight) / DAY

    result: dict = {"declination_deg": decl, "solar_noon_frac": frac(transit)}
    for (begin, end), altitude in _BANDS.items():
        rises, sets = crossings[altitude]
        first_rise, noon_rise, last_rise = np.searchsorted(rises, [day_start, transit, day_end], "right")
        first_set, noon_set, last_set = np.searchsorted(sets, [day_start, transit, day_end])
        # The rise before solar noon and the set after it. Each side stands alone: the day a polar
        # day or night starts or ends, the sun crosses once. At a pole, where the sun barely moves
        # in altitude through a day, the year's one sunrise or sunset can fall on the other side
        # of noon (elsewhere it can't, the sun being lower at midnight than at noon); it is kept
        # for the day's daylight. Twilight keeps to its side of noon, where its spans are.
        rise = float(rises[noon_rise - 1]) if noon_rise > first_rise else None
        set_ = float(sets[noon_set]) if last_set > noon_set else None
        if altitude == SUNRISE_ALTITUDE:
            if rise is None and last_rise > noon_rise:
                rise = float(rises[noon_rise])
            if set_ is None and noon_set > first_set:
                set_ = float(sets[noon_set - 1])
        result[f"{begin}_frac"] = frac(rise)
        result[f"{end}_frac"] = frac(set_)

    rise, set_, noon_frac = result["sunrise_frac"], result["sunset_frac"], result["solar_noon_frac"]
    if rise is None and set_ is None:
        polar = "day" if 90 - abs(lat - decl) > SUNRISE_ALTITUDE else "night"
        result["daylength_h"] = 24.0 if polar == "day" else 0.0
    else:
        # A missing sunrise or sunset: the sun is up from or until solar midnight
        polar = None
        start = rise if rise is not None else noon_frac - 0.5
        result["daylength_h"] = ((set_ if set_ is not None else noon_frac + 0.5) - start) * 24
    result["polar"] = polar
    store[key] = result
    return result


def fraction_to_timestamp(y: int, m: int, d: int, frac: float, utc_offset: float) -> int:
    """A day fraction in a fixed UTC offset as a Unix timestamp, anchored on UTC midnight."""
    return gmmktime(0, 0, 0, m, d, y) + round_int((frac * 24 - utc_offset) * 3600)


_EVENT_FIELDS = ("solar_noon", *(field for band in _BANDS for field in band))


def sun_events(day: LocalDate, lat: float, lon: float) -> dict:
    """Solar event timestamps for one local calendar date, in the UTC offset in effect at its noon."""
    y, m, d = day.year, day.month, day.day
    offset = day.set_time(12, 0).offset() / 3600
    calc = calculate_sun_times(y, m, d, lat, lon, offset)
    events: dict = {}
    for field in _EVENT_FIELDS:
        frac = calc[f"{field}_frac"]
        events[field] = None if frac is None else fraction_to_timestamp(y, m, d, frac, offset)
    events.update(
        daylength_h=calc["daylength_h"],
        polar=calc["polar"],
        declination=calc["declination_deg"],
        noon_elevation=90 - abs(lat - calc["declination_deg"]),
        midnight_elevation=abs(lat + calc["declination_deg"]) - 90,
        utc_offset=offset,
    )
    return events


# ============================================================================
# EQUINOXES AND SOLSTICES
# ============================================================================


@_searched(maxsize=64)
def _seasons(year: int) -> tuple[tuple[str, int], ...]:
    span = _times(_year_span(year, 0))
    t, which = find_discrete(span[0], span[1], almanac.seasons(_EPHEMERIS))
    return tuple((SEASONS[w], round_int(float(u))) for u, w in zip(_unix(t), which, strict=True))


def calculate_equinox_solstice(year: int) -> dict[str, int]:
    """Equinoxes and solstices of a year as Unix timestamps."""
    return dict(_seasons(year))


# ============================================================================
# MOON PHASES AND ILLUMINATION
# ============================================================================


@_searched(maxsize=16)
def _moon_phases_year(year: int) -> tuple[tuple[int, str], ...]:
    """Principal moon phases of a UTC year and a day either side (moon_phases_between() joins years)."""
    span = _times(_year_span(year, DAY))
    t, which = find_discrete(span[0], span[1], almanac.moon_phases(_EPHEMERIS))
    return tuple((round_int(float(u)), PHASES[w]) for u, w in zip(_unix(t), which, strict=True))


def moon_phases_between(start: int, stop: int) -> list[dict]:
    """Principal moon phases in [start, stop), in chronological order."""
    found = {}
    for year in range(_utc_year(start), _utc_year(stop) + 1):
        for timestamp, name in _moon_phases_year(year):
            if start <= timestamp < stop:
                found[timestamp] = name
    return [{"name": found[t], "timestamp": t} for t in sorted(found)]


@_searched(maxsize=8)
def _illumination_year(year: int) -> tuple[int, np.ndarray]:
    """The moon's illuminated fraction, in percent, every hour of a UTC year and the hour after it."""
    start, stop = _year_span(year, 0)
    hours = np.arange(start, stop + 3601, 3600)
    return start, almanac.fraction_illuminated(_EPHEMERIS, "moon", _times(hours)) * 100


def moon_illumination(timestamp: int) -> float:
    """Illuminated fraction of the moon's disk, in percent.

    Interpolated between hourly values, which keeps it within 0.001
    percentage points of the exact value with one search a year.
    """
    start, values = _illumination_year(_utc_year(timestamp))
    hour, fraction = divmod((timestamp - start) / 3600, 1)
    i = int(hour)
    return float(values[i] + (values[i + 1] - values[i]) * fraction)


_INTERMEDIATE = {
    "New Moon": {"First Quarter": "Waxing Crescent"},
    "First Quarter": {"Full Moon": "Waxing Gibbous"},
    "Full Moon": {"Last Quarter": "Waning Gibbous"},
    "Last Quarter": {"New Moon": "Waning Crescent"},
}


def get_accurate_moon_phase(timestamp: int) -> dict:
    """The moon's phase name and illumination at a timestamp, with the principal phases around it."""
    lt = local(timestamp)
    phases = moon_phases_between(timestamp - 45 * DAY, timestamp + 45 * DAY)

    prev = None
    nxt = None
    for phase in phases:
        if phase["timestamp"] > timestamp:
            nxt = phase
            break
        prev = phase

    intermediate = _INTERMEDIATE.get(prev["name"], {}).get(nxt["name"], "Unknown") if prev and nxt else "Unknown"

    # A named phase on the same calendar day takes over; the intermediate name otherwise
    day_start = mktime(0, 0, 0, lt.month, lt.day, lt.year)
    day_end = day_start + 86399
    current = intermediate
    for p in phases:
        if day_start <= p["timestamp"] <= day_end:
            current = p["name"]
            break

    # A next phase on the same day is already "current": advance to the one after it
    if nxt and day_start <= nxt["timestamp"] <= day_end:
        same_day = nxt["timestamp"]
        nxt = None
        for p in phases:
            if p["timestamp"] > same_day:
                nxt = p
                break

    return {
        "phase_name": current,
        "illumination": php_round(moon_illumination(timestamp), 1),
        "prev_phase": prev,
        "next_phase": nxt,
    }


# ============================================================================
# MOONRISE AND MOONSET
# ============================================================================


@_searched(maxsize=256)
def _moon_year(lat: float, lon: float, year: int) -> tuple[np.ndarray, np.ndarray]:
    """Moonrises and moonsets of a UTC year and 31 days either side: their times, in order, and which are rises."""
    observer = _EARTH + wgs84.latlon(lat, lon)
    start, stop = _year_span(year, 31 * DAY)
    if abs(lat) < _MOON_SEARCH_LIMIT:
        rises, sets = _hour_angle_crossings(observer, _MOON, start, stop, None)
    else:
        height = _moon_height(observer)
        grid = np.arange(start, stop + _SAMPLE, _SAMPLE, dtype=float)
        rises, sets = _sampled_crossings(height, grid, height(grid))
    times = np.concatenate([rises, sets])
    order = np.argsort(times)
    return times[order], (np.arange(len(times)) < len(rises))[order]


def moon_rise_set(start: int, stop: int, lat: float, lon: float) -> dict:
    """Moonrises and moonsets in (start, stop]: whether the moon is up at start, then events in order.

    For spans of up to 31 days, which the search around start's year covers.
    """
    lat, lon = float(lat), float(lon)
    times, is_rise = _moon_year(lat, lon, _utc_year(start))
    i, j = (int(k) for k in np.searchsorted(times, [start, stop], "right"))
    if i:
        up_at_start = bool(is_rise[i - 1])
    else:
        # No crossing in the month before start: whether the moon is up then still holds
        up_at_start = bool(_moon_height(_EARTH + wgs84.latlon(lat, lon))(np.array([start]))[0] > 0)
    events = [("rise" if is_rise[k] else "set", round_int(float(times[k]))) for k in range(i, j)]
    return {"up_at_start": up_at_start, "events": events}
