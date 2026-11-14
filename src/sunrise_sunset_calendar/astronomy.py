"""Astronomical calculations.

NREL SPA solar positions (see spa.py) for sunrise, sunset and solar noon,
the analytic hour angle for twilight, and Meeus algorithms for equinoxes,
solstices and moon phases.

References:
- NREL SPA: Reda & Andreas, 2008 (±0.0003° precision)
- Meeus: "Astronomical Algorithms" 2nd ed., 1998 (±1 minute for equinox/solstice)
"""

from __future__ import annotations

import math

from . import cache, spa
from .php import (
    LocalDate,
    add_month,
    array_sum,
    deg2rad,
    first_of_month,
    gmmktime,
    local,
    mktime,
    php_round,
    rad2deg,
    round_int,
)

# ============================================================================
# SOLAR POSITION (NREL SPA)
# ============================================================================


def calculate_sun_times(y: int, m: int, d: int, lat: float, lon: float, utc_offset: float) -> dict:
    """Sun times for one date as day fractions in a fixed UTC offset, plus day length and declination."""
    key = f"{y}-{m}-{d}:{lat:.4f}:{lon:.4f}:{utc_offset:.2f}"
    store = cache.get().solar_calc
    cached = store.get(key)
    if cached is not None:
        return cached

    utc_offset = float(utc_offset)
    rise, transit, set_ = spa.rise_transit_set(y, m, d, float(lat), float(lon))
    sunrise_hours = spa.day_fraction_to_hours(rise, utc_offset)
    sunset_hours = spa.day_fraction_to_hours(set_, utc_offset)
    transit_hours = spa.day_fraction_to_hours(transit, utc_offset)
    solar_noon = transit_hours / 24.0
    decl = spa.declination(y, m, d, utc_offset)

    # Polar day/night: the sun never crosses the horizon. Near the polar-day
    # threshold the library still interpolates spurious crossings, so the
    # analytic hour angle decides whether crossings exist at all.
    no_rise_set = rise is None or set_ is None
    if no_rise_set or sunrise_hour_angle(lat, decl, -0.8333) is None:
        polar = "day" if (90 - abs(lat - decl)) > 0 else "night"
        daylength_h = 24.0 if polar == "day" else 0.0
        sunrise_frac = None
        sunset_frac = None
    else:
        polar = None
        # Undo the 0-24 wrap: sunrise precedes transit and sunset follows it
        if sunrise_hours > transit_hours:
            sunrise_hours -= 24
        if sunset_hours < transit_hours:
            sunset_hours += 24
        sunrise_frac = sunrise_hours / 24.0
        sunset_frac = sunset_hours / 24.0
        daylength_h = sunset_hours - sunrise_hours

    def begin(ha):
        return None if ha is None else solar_noon - ha / 360

    def end(ha):
        return None if ha is None else solar_noon + ha / 360

    ha_civil = sunrise_hour_angle(lat, decl, -6.0)
    ha_nautical = sunrise_hour_angle(lat, decl, -12.0)
    ha_astro = sunrise_hour_angle(lat, decl, -18.0)
    # Blue hour ends and golden hour starts at -4°; golden hour ends at +6°
    ha_minus4 = sunrise_hour_angle(lat, decl, -4.0)
    ha_plus6 = sunrise_hour_angle(lat, decl, 6.0)

    result = {
        "declination_deg": decl,
        "equation_of_time_min": spa.equation_of_time(y, m, d, utc_offset),
        "sunrise_frac": sunrise_frac,
        "sunset_frac": sunset_frac,
        "solar_noon_frac": solar_noon,
        "daylength_h": daylength_h,
        "polar": polar,
        "civil_begin_frac": begin(ha_civil),
        "civil_end_frac": end(ha_civil),
        "nautical_begin_frac": begin(ha_nautical),
        "nautical_end_frac": end(ha_nautical),
        "astro_begin_frac": begin(ha_astro),
        "astro_end_frac": end(ha_astro),
        "minus4_begin_frac": begin(ha_minus4),
        "minus4_end_frac": end(ha_minus4),
        "plus6_begin_frac": begin(ha_plus6),
        "plus6_end_frac": end(ha_plus6),
    }
    store[key] = result
    return result


def sunrise_hour_angle(lat: float, decl: float, alt: float) -> float | None:
    """Hour angle (degrees) at which the sun crosses the given altitude, or None if it never does."""
    latr = deg2rad(lat)
    declr = deg2rad(decl)
    cos_ha = (math.sin(deg2rad(alt)) - math.sin(latr) * math.sin(declr)) / (math.cos(latr) * math.cos(declr))
    return None if abs(cos_ha) > 1 else rad2deg(math.acos(cos_ha))


def fraction_to_timestamp(y: int, m: int, d: int, frac: float, utc_offset: float) -> int:
    """A day fraction in a fixed UTC offset as a Unix timestamp, anchored on UTC midnight."""
    return gmmktime(0, 0, 0, m, d, y) + round_int((frac * 24 - utc_offset) * 3600)


_EVENT_FIELDS = (
    "sunrise",
    "sunset",
    "solar_noon",
    "civil_begin",
    "civil_end",
    "nautical_begin",
    "nautical_end",
    "astro_begin",
    "astro_end",
    "minus4_begin",
    "minus4_end",
    "plus6_begin",
    "plus6_end",
)


def sun_events(day: LocalDate, lat: float, lon: float) -> dict:
    """Solar event timestamps for one local calendar date, in the UTC offset in effect at its noon."""
    y, m, d = day.year, day.month, day.day
    offset = day.set_time(12, 0).offset() / 3600
    calc = calculate_sun_times(y, m, d, lat, lon, offset)
    base = gmmktime(0, 0, 0, m, d, y)
    events: dict = {}
    for field in _EVENT_FIELDS:
        frac = calc[f"{field}_frac"]
        events[field] = None if frac is None else base + round_int((frac * 24 - offset) * 3600)
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
# EQUINOXES AND SOLSTICES (Meeus Chapter 27)
# ============================================================================


def calculate_equinox_solstice(year: int) -> dict[str, int]:
    """Equinoxes and solstices of a year as Unix timestamps (±1 minute)."""
    if -1000 <= year <= 1000:
        y = year / 1000.0
        march = 1721139.29189 + 365242.13740 * y + 0.06134 * y * y + 0.00111 * y * y * y - 0.00071 * y * y * y * y
        june = 1721233.25401 + 365241.72562 * y - 0.05323 * y * y + 0.00907 * y * y * y + 0.00025 * y * y * y * y
        sept = 1721325.70455 + 365242.49558 * y - 0.11677 * y * y - 0.00297 * y * y * y + 0.00074 * y * y * y * y
        dec = 1721414.39987 + 365242.88257 * y - 0.00769 * y * y - 0.00933 * y * y * y - 0.00006 * y * y * y * y
    else:
        y = (year - 2000) / 1000.0
        march = 2451623.80984 + 365242.37404 * y + 0.05169 * y * y - 0.00411 * y * y * y - 0.00057 * y * y * y * y
        june = 2451716.56767 + 365241.62603 * y + 0.00325 * y * y + 0.00888 * y * y * y - 0.00030 * y * y * y * y
        sept = 2451810.21715 + 365242.01767 * y - 0.11575 * y * y + 0.00337 * y * y * y + 0.00078 * y * y * y * y
        dec = 2451900.05952 + 365242.74049 * y - 0.06223 * y * y - 0.00823 * y * y * y + 0.00032 * y * y * y * y

    return {
        "march_equinox": jde_to_unix(apply_periodic_corrections(march), year),
        "june_solstice": jde_to_unix(apply_periodic_corrections(june), year),
        "september_equinox": jde_to_unix(apply_periodic_corrections(sept), year),
        "december_solstice": jde_to_unix(apply_periodic_corrections(dec), year),
    }


_PERIODIC = (
    (485, 324.96, 1934.136),
    (203, 337.23, 32964.467),
    (199, 342.08, 20.186),
    (182, 27.85, 445267.112),
    (156, 73.14, 45036.886),
    (136, 171.52, 22518.443),
    (77, 222.54, 65928.934),
    (74, 296.72, 3034.906),
    (70, 243.58, 9037.513),
    (58, 119.81, 33718.147),
    (52, 297.17, 150.678),
    (50, 21.02, 2281.226),
    (45, 247.54, 29929.562),
    (44, 325.15, 31555.956),
    (29, 60.93, 4443.417),
    (18, 155.12, 67555.328),
    (17, 288.79, 4562.452),
    (16, 198.04, 62894.029),
    (14, 199.76, 31436.921),
    (12, 95.39, 14577.848),
    (12, 287.11, 31931.756),
    (12, 320.81, 34777.259),
    (9, 227.73, 1222.114),
    (8, 15.45, 16859.074),
)


def apply_periodic_corrections(jde0: float) -> float:
    """Periodic corrections to equinox/solstice times (Meeus Table 27.C)."""
    t = (jde0 - 2451545.0) / 36525.0
    s = array_sum(a * math.cos(deg2rad(b + c * t)) for a, b, c in _PERIODIC)
    w = 35999.373 * t - 2.47
    dl = 1 + 0.0334 * math.cos(deg2rad(w)) + 0.0007 * math.cos(deg2rad(2 * w))
    return jde0 + ((0.00001 * s) / dl)


# ============================================================================
# MOON PHASES (Meeus Chapter 49)
# ============================================================================


def calculate_moon_phases_for_month(year: int, month: int) -> list[dict]:
    """Principal moon phases from 15 days before a month to 15 days after it, by time."""
    key = f"{year}-{month}"
    store = cache.get().moon_phase
    cached = store.get(key)
    if cached is not None:
        return cached

    k = math.floor((year + (month - 0.5) / 12 - 2000) * 12.3685)
    phases = []
    for i in (-1, 0, 1):
        for phase in (0.0, 0.25, 0.5, 0.75):
            phases.append(calculate_specific_moon_phase(k + i, phase))
    phases.sort(key=lambda p: p["timestamp"])

    target_start = mktime(0, 0, 0, month, 1, year)
    target_end = mktime(23, 59, 59, month + 1, 0, year)
    early_start = target_start - (15 * 86400)
    late_end = target_end + (15 * 86400)
    phases = [p for p in phases if early_start <= p["timestamp"] <= late_end]
    store[key] = phases
    return phases


def calculate_specific_moon_phase(k: int, phase: float) -> dict:
    """A specific moon phase (Meeus formulas 49.1-49.4)."""
    ka = k + phase
    t = ka / 1236.85
    pw = math.pow

    jde = 2451550.09766 + 29.530588861 * ka + 0.00015437 * t * t - 0.000000150 * pw(t, 3) + 0.00000000073 * pw(t, 4)

    e = 1 - 0.002516 * t - 0.0000074 * t * t
    m = deg2rad(2.5534 + 29.10535670 * ka - 0.0000014 * t * t - 0.00000011 * pw(t, 3))
    mp = deg2rad(201.5643 + 385.81693528 * ka + 0.0107582 * t * t + 0.00001238 * pw(t, 3) - 0.000000058 * pw(t, 4))
    f = deg2rad(160.7108 + 390.67050284 * ka - 0.0016118 * t * t - 0.00000227 * pw(t, 3) + 0.000000011 * pw(t, 4))
    omega = deg2rad(124.7746 - 1.56375588 * ka + 0.0020672 * t * t + 0.00000215 * pw(t, 3))

    if abs(phase) < 0.01 or abs(phase - 0.5) < 0.01:
        corrections = _new_full_corrections(m, mp, f, omega, e, phase)
    else:
        corrections = _quarter_corrections(m, mp, f, omega, e, phase)
    jde += corrections

    if abs(phase) < 0.01:
        name = "New Moon"
    elif abs(phase - 0.25) < 0.01:
        name = "First Quarter"
    elif abs(phase - 0.5) < 0.01:
        name = "Full Moon"
    elif abs(phase - 0.75) < 0.01:
        name = "Last Quarter"
    else:
        name = "Unknown"

    return {"phase_name": name, "jde": jde, "timestamp": jde_to_unix(jde, jde_to_year(jde)), "k": ka}


def _new_full_corrections(m: float, mp: float, f: float, omega: float, e: float, phase: float) -> float:
    """Corrections for New Moon and Full Moon (Meeus Tables 49.I/II)."""
    sin = math.sin
    c = (
        -0.40720 * sin(mp)
        + 0.17241 * e * sin(m)
        + 0.01608 * sin(2 * mp)
        + 0.01039 * sin(2 * f)
        + 0.00739 * e * sin(mp - m)
        - 0.00514 * e * sin(mp + m)
        + 0.00208 * e * e * sin(2 * m)
        - 0.00111 * sin(mp - 2 * f)
        - 0.00057 * sin(mp + 2 * f)
        + 0.00056 * e * sin(2 * mp + m)
        - 0.00042 * sin(3 * mp)
        + 0.00042 * e * sin(m + 2 * f)
        + 0.00038 * e * sin(m - 2 * f)
        - 0.00024 * e * sin(2 * mp - m)
    )
    if abs(phase - 0.5) < 0.01:
        c -= 0.00017 * sin(omega)
    a1 = 299.77 + 0.107408 * rad2deg(mp) - 0.009173 * math.pow(rad2deg(mp), 2)
    c += 0.000325 * sin(deg2rad(a1))
    return c


def _quarter_corrections(m: float, mp: float, f: float, omega: float, e: float, phase: float) -> float:
    """Corrections for First/Last Quarter (Meeus Table 49.III)."""
    sin = math.sin
    cos = math.cos
    c = (
        -0.62801 * sin(mp)
        + 0.17172 * e * sin(m)
        - 0.01183 * e * sin(mp + m)
        + 0.00862 * sin(2 * mp)
        + 0.00804 * sin(2 * f)
        + 0.00454 * e * sin(mp - m)
        + 0.00204 * e * e * sin(2 * m)
        - 0.00180 * sin(mp - 2 * f)
        - 0.00070 * sin(mp + 2 * f)
        - 0.00040 * sin(3 * mp)
        - 0.00034 * e * sin(2 * mp - m)
        + 0.00032 * e * sin(m + 2 * f)
    )
    w = (
        0.00306
        - 0.00038 * e * cos(m)
        + 0.00026 * cos(mp)
        - 0.00002 * cos(mp - m)
        + 0.00002 * cos(mp + m)
        + 0.00002 * cos(2 * f)
    )
    return c + (w if abs(phase - 0.25) < 0.01 else -w)


def moon_illumination(timestamp: int) -> float:
    """Illuminated fraction of the moon's disk, in percent (Meeus 48.1 and 48.4)."""
    t = (timestamp / 86400 + 2440587.5 - 2451545.0) / 36525
    pw = math.pow
    d = deg2rad(297.8501921 + 445267.1114034 * t - 0.0018819 * pw(t, 2) + pw(t, 3) / 545868 - pw(t, 4) / 113065000)
    m = deg2rad(357.5291092 + 35999.0502909 * t - 0.0001536 * pw(t, 2) + pw(t, 3) / 24490000)
    mp = deg2rad(134.9633964 + 477198.8675055 * t + 0.0087414 * pw(t, 2) + pw(t, 3) / 69699 - pw(t, 4) / 14712000)
    sin = math.sin
    i = (
        180
        - rad2deg(d)
        - 6.289 * sin(mp)
        + 2.100 * sin(m)
        - 1.274 * sin(2 * d - mp)
        - 0.658 * sin(2 * d)
        - 0.214 * sin(2 * mp)
        - 0.110 * sin(d)
    )
    return (1 + math.cos(deg2rad(i))) / 2 * 100


def moon_altitude(timestamp: int, lat: float, lon: float) -> float:
    """The moon's geocentric altitude above its rising threshold, in degrees.

    Astronomical Almanac low-precision formulas (about 0.3°); the threshold
    h0 = 0.7275 π - 0.5667° (Meeus ch. 15) allows for parallax, refraction
    and the moon's radius.
    """
    d = timestamp / 86400 + 2440587.5 - 2451545.0
    t = d / 36525

    def sin(deg: float) -> float:
        return math.sin(deg2rad(deg))

    def cos(deg: float) -> float:
        return math.cos(deg2rad(deg))

    lam = (
        218.32
        + 481267.881 * t
        + 6.29 * sin(135.0 + 477198.87 * t)
        - 1.27 * sin(259.3 - 413335.36 * t)
        + 0.66 * sin(235.7 + 890534.22 * t)
        + 0.21 * sin(269.9 + 954397.70 * t)
        - 0.19 * sin(357.5 + 35999.05 * t)
        - 0.11 * sin(186.6 + 966404.05 * t)
    )
    beta = (
        5.13 * sin(93.3 + 483202.03 * t)
        + 0.28 * sin(228.2 + 960400.87 * t)
        - 0.28 * sin(318.3 + 6003.18 * t)
        - 0.17 * sin(217.6 - 407332.20 * t)
    )
    parallax = (
        0.9508
        + 0.0518 * cos(135.0 + 477198.87 * t)
        + 0.0095 * cos(259.3 - 413335.38 * t)
        + 0.0078 * cos(235.7 + 890534.23 * t)
        + 0.0028 * cos(269.9 + 954397.70 * t)
    )
    eps = 23.439 - 0.013 * t

    # Ecliptic to equatorial coordinates
    el = cos(beta) * cos(lam)
    em = cos(eps) * cos(beta) * sin(lam) - sin(eps) * sin(beta)
    en = sin(eps) * cos(beta) * sin(lam) + cos(eps) * sin(beta)
    ra = rad2deg(math.atan2(em, el))
    dec = rad2deg(math.asin(en))

    hour_angle = 280.46061837 + 360.98564736629 * d + lon - ra
    altitude = rad2deg(math.asin(sin(lat) * sin(dec) + cos(lat) * cos(dec) * cos(hour_angle)))
    return altitude - (0.7275 * parallax - 0.5667)


def moon_rise_set(start: int, stop: int, lat: float, lon: float) -> dict:
    """Moonrises and moonsets in [start, stop]: whether the moon is up at start, then events in order."""
    step = 600
    events = []
    previous = moon_altitude(start, lat, lon)
    up_at_start = previous > 0
    t = start + step
    while t - step < stop:
        t = min(t, stop)
        altitude = moon_altitude(t, lat, lon)
        if (previous > 0) != (altitude > 0):
            # Bisect to within 30 seconds
            lo, hi = t - step, t
            while hi - lo > 30:
                mid = (lo + hi) // 2
                if (moon_altitude(mid, lat, lon) > 0) == (altitude > 0):
                    hi = mid
                else:
                    lo = mid
            events.append(("rise" if altitude > 0 else "set", (lo + hi) // 2))
        previous = altitude
        if t == stop:
            break
        t += step
    return {"up_at_start": up_at_start, "events": events}


def moon_phases_between(start: int, stop: int) -> list[dict]:
    """Principal moon phases in [start, stop), in chronological order."""
    phases: dict[int, dict] = {}
    t = first_of_month(start)
    while t < stop:
        lt = local(t)
        for phase in calculate_moon_phases_for_month(lt.year, lt.month):
            if start <= phase["timestamp"] < stop:
                phases[phase["timestamp"]] = {"name": phase["phase_name"], "timestamp": phase["timestamp"]}
        t = add_month(t)
    return [phases[k] for k in sorted(phases)]


_INTERMEDIATE = {
    "New Moon": {"First Quarter": "Waxing Crescent"},
    "First Quarter": {"Full Moon": "Waxing Gibbous"},
    "Full Moon": {"Last Quarter": "Waning Gibbous"},
    "Last Quarter": {"New Moon": "Waning Crescent"},
}


def get_accurate_moon_phase(timestamp: int) -> dict:
    """The moon's phase name and illumination at a timestamp, with the principal phases around it."""
    lt = local(timestamp)
    year, month = lt.year, lt.month
    phases = (
        calculate_moon_phases_for_month(year, month - 1)
        + calculate_moon_phases_for_month(year, month)
        + calculate_moon_phases_for_month(year, month + 1)
    )

    prev = None
    nxt = None
    for phase in phases:
        if phase["timestamp"] > timestamp:
            nxt = phase
            break
        prev = phase

    intermediate = (
        _INTERMEDIATE.get(prev["phase_name"], {}).get(nxt["phase_name"], "Unknown") if prev and nxt else "Unknown"
    )

    # A named phase on the same calendar day takes over; the intermediate name otherwise
    day_start = mktime(0, 0, 0, lt.month, lt.day, lt.year)
    day_end = day_start + 86399
    current = intermediate
    for p in phases:
        if day_start <= p["timestamp"] <= day_end:
            current = p["phase_name"]
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
        "prev_phase": {"name": prev["phase_name"], "timestamp": prev["timestamp"]} if prev else None,
        "next_phase": {"name": nxt["phase_name"], "timestamp": nxt["timestamp"]} if nxt else None,
    }


# ============================================================================
# TIME CONVERSIONS
# ============================================================================


def jde_to_year(jde: float) -> int:
    """Approximate year of a Julian Ephemeris Day."""
    return round_int(2000 + (jde - 2451545.0) / 365.25)


def calculate_delta_t(year: int) -> float:
    """DeltaT (TD - UT) in seconds."""
    if 2005 <= year <= 2050:
        t = year - 2000
        return 62.92 + 0.32217 * t + 0.005589 * t * t
    if 1986 <= year < 2005:
        t = year - 2000
        return 63.86 + 0.3345 * t - 0.060374 * t * t + 0.0017275 * t**3 + 0.000651814 * t**4 + 0.00002373599 * t**5
    if 1961 <= year < 1986:
        t = year - 1975
        return 45.45 + 1.067 * t - t * t / 260.0 - t**3 / 718.0
    if 1941 <= year < 1961:
        t = year - 1950
        return 29.07 + 0.407 * t - t * t / 233.0 + t**3 / 2547.0
    if 1920 <= year < 1941:
        t = year - 1920
        return 21.20 + 0.84493 * t - 0.076100 * t * t + 0.0020936 * t**3
    if 1900 <= year < 1920:
        t = year - 1900
        return -2.79 + 1.494119 * t - 0.0598939 * t * t + 0.0061966 * t**3 - 0.000197 * t**4
    t = (year - 1820) / 100.0
    return -20 + 32 * t * t


def jde_to_unix(jde: float, year: int | None = None) -> int:
    """A Julian Ephemeris Day as a Unix timestamp, less DeltaT when the year is given."""
    unix_td = (jde - 2440587.5) * 86400
    if year is not None:
        return round_int(unix_td - calculate_delta_t(year))
    return round_int(unix_td)
