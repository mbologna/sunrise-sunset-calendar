"""Helper functions for calendar generation.

iCalendar formatting, formatting of times and lengths, year facts, and the
text of each event description.
"""

from __future__ import annotations

import re

from . import cache
from .astronomy import calculate_equinox_solstice, moon_phases_between
from .php import (
    PHP_INT_MIN,
    LocalDate,
    default_timezone,
    intdiv,
    lcfirst,
    mktime,
    php_date,
    round_int,
    sprintf,
    strtolower,
)
from .strings import STRINGS

S = STRINGS

# ============================================================================
# DURATIONS
# ============================================================================


def format_duration(seconds: int, with_seconds: bool = False) -> str:
    """A duration compactly: "9h 05m", "32m", or with seconds "2m 05s".

    Seconds are shown only when asked for and under an hour; otherwise the
    value is rounded to the minute. The sign is ignored.
    """
    units = S["units"]
    seconds = abs(seconds)
    if with_seconds and seconds < 3600:
        if seconds < 60:
            return sprintf(units["seconds"], seconds)
        return sprintf(units["minutes_seconds"], intdiv(seconds, 60), seconds % 60)
    minutes = round_int(seconds / 60)
    if minutes < 60:
        return sprintf(units["minutes"], minutes)
    return sprintf(units["hours_minutes"], intdiv(minutes, 60), minutes % 60)


def format_signed_duration(seconds: int) -> str:
    """A signed duration rounded to the minute: "+15m", "-1h 05m"."""
    return ("-" if seconds <= -30 else "+") + format_duration(seconds)


# ============================================================================
# ICALENDAR
# ============================================================================


def ical_text_property(name: str, text: str) -> str:
    """A TEXT property line per RFC 5545: escaped, then folded at 75 octets.

    Folding never splits a multibyte UTF-8 character: a line ends before the
    first character that would take it past 75 octets, and each continuation
    line starts with a space.
    """
    # Backslashes first, so the escapes added below are not doubled
    text = text.replace("\\", "\\\\")
    text = text.replace("\r\n", "\\n").replace("\r", "\\n").replace("\n", "\\n")
    text = text.replace(",", "\\,").replace(";", "\\;")

    line = name + ":" + text
    data = line.encode("utf-8", "surrogateescape")
    n = len(data)
    if n <= 75:
        return line + "\r\n"
    parts = []
    start = 0
    room = 75
    while n - start > room:
        cut = start + room
        # Never cut inside a character: back up over UTF-8 continuation bytes
        while 0x80 <= data[cut] <= 0xBF:
            cut -= 1
        parts.append(data[start:cut])
        start = cut
        room = 74
    parts.append(data[start:])
    return b"\r\n ".join(parts).decode("utf-8", "surrogateescape") + "\r\n"


def format_ical_description(text: str) -> str:
    return ical_text_property("DESCRIPTION", text)


# ============================================================================
# MOON NAMES
# ============================================================================


def get_moon_phase_emoji(phase_name: str, lat: float = 0.0) -> str:
    """The moon phase emoji as the moon looks from the observer's hemisphere."""
    return S["moon_phases"].get(phase_name, S["moon_phase_unknown"])[1 if lat < 0 else 0]


def moon_phase_name(phase_name: str) -> str:
    return S["moon_phases"].get(phase_name, S["moon_phase_unknown"])[2]


# ============================================================================
# FORMATTING
# ============================================================================


def format_local(timestamp: int, fmt: str = "time") -> str:
    """A timestamp in one of the strings' date formats, in the default time zone."""
    return php_date(S["formats"][fmt], timestamp)


def format_time(timestamp: int | None) -> str:
    """A time of day, or a dash when the event does not occur."""
    return S["none"] if timestamp is None else format_local(timestamp)


def format_range_bar(seconds: int, shortest: int, longest: int, width: int = 10) -> str:
    """Where a length sits in the year's range: "9h 08m ▕██░░░░░░░░▏ 15h 14m"."""
    span = longest - shortest
    filled = round_int((seconds - shortest) / span * width) if span > 0 else intdiv(width, 2)
    filled = max(0, min(width, filled))
    return format_duration(shortest) + " ▕" + "█" * filled + "░" * (width - filled) + "▏ " + format_duration(longest)


def format_extremes(kind: str, seconds: int, shortest: tuple[int, int], longest: tuple[int, int]) -> str:
    """Today against the year's shortest and longest day (or night), in words."""
    texts = S["year"][kind]

    def day(ts: int) -> str:
        return format_local(ts, "date_short")

    def gap(diff: int) -> str:
        # Seconds matter only for a gap under a minute
        return format_duration(diff, diff < 60)

    # Only the extreme day itself, allowing for rounding
    if seconds - shortest[0] <= 2:
        return sprintf(texts["is_shortest"], gap(longest[0] - seconds), day(longest[1]))
    if longest[0] - seconds <= 2:
        return sprintf(texts["is_longest"], gap(seconds - shortest[0]), day(shortest[1]))
    return sprintf(
        texts["between"], gap(seconds - shortest[0]), day(shortest[1]), gap(longest[0] - seconds), day(longest[1])
    )


def format_comparison(diff_seconds: int, referent: str) -> str:
    """Two lengths compared in words: "2m 07s shorter than yesterday"."""
    if diff_seconds == 0:
        return sprintf(S["comparisons"]["same"], referent)
    return sprintf(
        S["comparisons"]["longer" if diff_seconds > 0 else "shorter"], format_duration(diff_seconds, True), referent
    )


def format_clock_shift(shift_seconds: int, alone: bool = False) -> str:
    """How far an event moved on the clock since the day before: "1m 18s later"."""
    texts = S["clock"]
    suffix = "_alone" if alone else ""
    if abs(shift_seconds) < 1:
        return texts[f"same{suffix}"]
    return sprintf(texts[("later" if shift_seconds > 0 else "earlier") + suffix], format_duration(shift_seconds, True))


def schedule_entry(start: int | None, end: int | None, label: str, note: str | None) -> str:
    """One schedule entry, time first, then an optional indented note."""
    texts = S["schedule"]
    if start is None and end is None:
        when = texts["all_day"]
    elif start is None:
        when = sprintf(texts["until"], format_local(end))
    elif end is None:
        when = sprintf(texts["from"], format_local(start))
    else:
        when = format_local(start) + "–" + format_local(end)
    line = sprintf(texts["entry"], when, label)
    if start is not None and end is not None:
        line += sprintf(texts["length"], format_duration(end - start))
    return line + "\n" + ("" if note is None else f"  {note}\n")


def format_schedule_span(label: str, span, note: str) -> str:
    """One twilight as a schedule entry."""
    return schedule_entry(span[0], span[1], label, note)


def format_moon_section(moon: dict) -> str:
    """The moon during the night: phase, moonless window, brightness and the next principal phase."""
    texts = S["moon"]
    lat = moon.get("lat", 0.0)
    lit = round_int(moon["illumination"])
    emoji = get_moon_phase_emoji(moon["phase_name"], lat)

    lines = [sprintf(texts["current"], moon_phase_name(moon["phase_name"]), lit)]
    if moon.get("rise_set") is not None and moon.get("night") is not None:
        windows = moonless_windows(moon["rise_set"], moon["night"][0], moon["night"][1])
        whole = moon["night"][1] - moon["night"][0]
        if not windows:
            lines.append(texts["moonless_none"])
        elif len(windows) == 1 and windows[0][1] - windows[0][0] >= whole:
            lines.append(texts["moonless_all"])
        else:
            lines.append(
                sprintf(
                    texts["moonless"],
                    ", ".join(
                        format_local(w[0]) + "–" + format_local(w[1]) + " (" + format_duration(w[1] - w[0]) + ")"
                        for w in windows
                    ),
                )
            )
        up_at_all = not windows or len(windows) > 1 or windows[0][1] - windows[0][0] < whole
        if up_at_all:
            lines.append(texts["impact"]["dark" if lit < 25 else ("partial" if lit <= 75 else "bright")])
    # A principal phase that happens during the night, to the minute
    if moon.get("night") is not None:
        for phase in moon_phases_between(moon["night"][0], moon["night"][1]):
            exact = sprintf(texts["exact"], moon_phase_name(phase["name"]), format_local(phase["timestamp"]))
            lines[0] += f" · {exact}"

    return sprintf(S["headers"]["moon"], emoji) + "\n" + "\n".join(lines) + "\n"


def moonless_windows(rise_set: dict, start: int, stop: int) -> list[list[int]]:
    """The parts of [start, stop] when the moon is below the horizon."""
    windows = []
    down_since = None if rise_set["up_at_start"] else start
    for kind, at in rise_set["events"]:
        if kind == "set":
            down_since = at
        elif down_since is not None:
            windows.append([down_since, at])
            down_since = None
    if down_since is not None:
        windows.append([down_since, stop])
    return windows


# ============================================================================
# YEAR PROFILE
# ============================================================================


def get_year_profile(lat: float, lon: float, year: int, utc_offset: float) -> list[dict]:
    """Solar calculations for every day of a year at one fixed UTC offset, with each day's local noon."""
    from .astronomy import calculate_sun_times

    key = f"{lat:.4f}:{lon:.4f}:{year}:{utc_offset:.2f}"
    store = cache.get().year_profile
    if key not in store:
        profile = []
        days_in_year = 366 if _is_leap(year) else 365
        for day in range(1, days_in_year + 1):
            timestamp = mktime(12, 0, 0, 1, day, year)
            parts = _local_parts(timestamp)
            profile.append({"timestamp": timestamp} | calculate_sun_times(*parts, lat, lon, utc_offset))
        store[key] = profile
    return store[key]


def _is_leap(year: int) -> bool:
    return year % 4 == 0 and (year % 100 != 0 or year % 400 == 0)


def _local_parts(timestamp: int) -> tuple[int, int, int]:
    from .php import local

    lt = local(timestamp)
    return lt.year, lt.month, lt.day


def get_week_summary(week_start: int, lat: float, lon: float, utc_offset: float) -> dict:
    """Daylight over the seven days from week_start: first and last lengths, change and trend."""
    from .astronomy import calculate_sun_times
    from .php import strtotime_days

    key = f"{week_start}:{lat:.4f}:{lon:.4f}:{utc_offset:.2f}"
    store = cache.get().week_summary
    if key in store:
        return store[key]

    lengths = []
    for i in range(7):
        parts = _local_parts(strtotime_days(i, week_start))
        result = calculate_sun_times(*parts, lat, lon, utc_offset)
        lengths.append(result["daylength_h"] * 3600)

    total = lengths[-1] - lengths[0]
    trend = "increasing" if total > 300 else ("decreasing" if total < -300 else "stable")
    summary = {"start_length": lengths[0], "end_length": lengths[-1], "total_change": total, "trend": trend}
    store[key] = summary
    return summary


def day_runs(flags: list[bool], timestamps: list[int]) -> list[list[int]]:
    """Runs of consecutive true flags; a run spanning New Year stays whole."""
    n = len(flags)
    if True not in flags:
        return []
    if False not in flags:
        return [[timestamps[0], timestamps[n - 1]]]

    # Start scanning just after a false day, so no run is cut at the year boundary
    offset = flags.index(False) + 1
    runs = []
    run_start = None
    for i in range(n + 1):
        idx = (offset + i) % n
        if i < n and flags[idx]:
            if run_start is None:
                run_start = idx
        elif run_start is not None:
            runs.append([timestamps[run_start], timestamps[(idx - 1 + n) % n]])
            run_start = None
    runs.sort(key=lambda r: r[0])
    return runs


def format_runs(runs: list[list[int]]) -> str:
    """Date runs: "May 18 to Jul 25 and from Nov 28 to Jan 14"."""
    return S["range_join"].join(
        sprintf(S["date_range"], format_local(run[0], "date_short"), format_local(run[1], "date_short")) for run in runs
    )


def twilight_bands() -> dict[str, dict]:
    """The six twilights: subscription flag, depression angles bounding them, and UID prefix."""
    return _TWILIGHT_BANDS


def _band(flag: str, deep: int, high: int, morning: bool, uid: str) -> dict:
    return {"flag": flag, "deep": deep, "high": high, "morning": morning, "uid": uid}


_TWILIGHT_BANDS = {
    "morning_astronomical": _band("astro", 18, 12, True, "morning-astronomical-twilight"),
    "morning_nautical": _band("nautical", 12, 6, True, "morning-nautical-twilight"),
    "morning_civil": _band("civil", 6, 0, True, "morning-civil-twilight"),
    "evening_civil": _band("civil", 6, 0, False, "evening-civil-twilight"),
    "evening_nautical": _band("nautical", 12, 6, False, "evening-nautical-twilight"),
    "evening_astronomical": _band("astro", 18, 12, False, "evening-astronomical-twilight"),
}

# Twilight boundaries in a year-profile record: [deep crossing, shallow crossing]
_TWILIGHT_PROFILE_FIELDS = {
    "morning_astronomical": ("astro_begin_frac", "nautical_begin_frac"),
    "morning_nautical": ("nautical_begin_frac", "civil_begin_frac"),
    "morning_civil": ("civil_begin_frac", "sunrise_frac"),
    "evening_civil": ("civil_end_frac", "sunset_frac"),
    "evening_nautical": ("nautical_end_frac", "civil_end_frac"),
    "evening_astronomical": ("astro_end_frac", "nautical_end_frac"),
}


def _pick_first(values: list, target) -> int:
    for i, v in enumerate(values):
        if v == target:
            return i
    raise ValueError(target)


def year_summary(lat: float, lon: float, year: int, utc_offset: float) -> dict:
    """Facts about a location's year, derived from its year profile."""
    memo = cache.get().year_summary
    key = f"{lat:.4f}:{lon:.4f}:{year}:{utc_offset:.2f}:{default_timezone()}"
    if key in memo:
        return memo[key]

    days = get_year_profile(lat, lon, year, utc_offset)
    timestamps = [d["timestamp"] for d in days]
    lengths = [d["daylength_h"] for d in days]

    def pick(values: list, choose) -> list:
        i = _pick_first(values, choose(values))
        return [values[i], timestamps[i]]

    polar_day = [d["polar"] == "day" for d in days]
    polar_night = [d["polar"] == "night" for d in days]
    # No astronomical night: the sun never crosses -18° because it stays above it at midnight
    white_nights = [d["astro_begin_frac"] is None and abs(lat + d["declination_deg"]) - 90 > -18 for d in days]

    # Twilight lengths by day, with the same solar noon and midnight stand-ins as solar_spans()
    bands = {}
    for band, (deep, shallow) in _TWILIGHT_PROFILE_FIELDS.items():
        info = _TWILIGHT_BANDS[band]
        deep_angle, high_angle, morning = info["deep"], info["high"], info["morning"]
        by_day: dict[int, int] = {}
        for i, d in enumerate(days):
            noon = d["solar_noon_frac"]
            if d[deep] is None and d[shallow] is None:
                in_band = (
                    abs(lat + d["declination_deg"]) - 90 > -deep_angle
                    and 90 - abs(lat - d["declination_deg"]) < -high_angle
                )
                if in_band:
                    by_day[i] = 43200
                continue
            if morning:
                span = (d[shallow] if d[shallow] is not None else noon) - (
                    d[deep] if d[deep] is not None else noon - 0.5
                )
            else:
                span = (d[deep] if d[deep] is not None else noon + 0.5) - (
                    d[shallow] if d[shallow] is not None else noon
                )
            by_day[i] = round_int(span * 86400)
        if not by_day:
            bands[band] = None
        else:
            lo = min(by_day.values())
            hi = max(by_day.values())
            bands[band] = {
                "min": [lo, timestamps[next(k for k, v in by_day.items() if v == lo)]],
                "max": [hi, timestamps[next(k for k, v in by_day.items() if v == hi)]],
            }

    # Night lengths by day, with the solar-noon stand-in for days the sun stays more than
    # 18° below the horizon. December's last night borrows January 1 for its morning.
    all_dark = [d["astro_begin_frac"] is None and 90 - abs(lat - d["declination_deg"]) < -18 for d in days]
    nights: list[int | None] = []
    count = len(days)
    for i, d in enumerate(days):
        j = (i + 1) % count
        start = d["astro_end_frac"]
        if start is None:
            start = d["solar_noon_frac"] if all_dark[i] else None
        end = days[j]["astro_begin_frac"]
        if end is None:
            end = days[j]["solar_noon_frac"] if all_dark[j] else None
        nights.append(round_int((end + 1 - start) * 86400) if start is not None and end is not None else None)

    polar = True in polar_day or True in polar_night
    result = {
        "days": days,
        "polar": polar,
        "daylight_min": pick(lengths, min),
        "daylight_max": pick(lengths, max),
        "midnight_sun": day_runs(polar_day, timestamps),
        "polar_night": day_runs(polar_night, timestamps),
        "white_nights": day_runs(white_nights, timestamps),
        "bands": bands,
        "nights": nights,
    }
    memo[key] = result
    return result


def _php_min(values: list[float]) -> float:
    best = values[0]
    for v in values[1:]:
        if v < best:
            best = v
    return best


def _php_max(values: list[float]) -> float:
    best = values[0]
    for v in values[1:]:
        if v > best:
            best = v
    return best


def year_day_facts(year: dict) -> dict[str, list[dict]]:
    """Days worth an all-day event because something changes that day, by local date."""
    days = year["days"]
    n = len(days)
    facts: dict[str, list[dict]] = {}

    def add(index: int, fact: dict) -> None:
        facts.setdefault(php_date("Y-m-d", days[index]["timestamp"]), []).append({"index": index} | fact)

    for event, field in (("sunrise", "sunrise_frac"), ("sunset", "sunset_frac")):
        values = [d[field] for d in days]
        for i in range(n):
            window = [values[(i + k + n) % n] for k in range(-20, 21)]
            if None in window:
                continue
            before = window[:20]
            after = window[21:]
            is_min = values[i] < _php_min(before) and values[i] <= _php_min(after)
            is_max = values[i] > _php_max(before) and values[i] >= _php_max(after)
            if not is_min and not is_max:
                continue
            # How many days around the turn stay within a minute of it
            stable = 1
            for direction in (1, -1):
                for distance in range(1, 60):
                    other = values[(i + direction * distance + n) % n]
                    if other is None or abs(other - values[i]) * 86400 > 60:
                        break
                    stable += 1
            add(i, {"kind": event + ("_earliest" if is_min else "_latest"), "stable": stable})

    hours = [d["daylength_h"] for d in days]
    for i in range(n - 1):
        if (hours[i] - 12) * (hours[i + 1] - 12) <= 0 and hours[i] != hours[i + 1]:
            closer = i if abs(hours[i] - 12) <= abs(hours[i + 1] - 12) else i + 1
            kind = "twelve_hours_rising" if hours[i + 1] > hours[i] else "twelve_hours_falling"
            add(closer, {"kind": kind})

    timestamps = [d["timestamp"] for d in days]
    for period in ("midnight_sun", "polar_night", "white_nights"):
        for start, end in year[period]:
            first = timestamps.index(start)
            last = timestamps.index(end)
            length = (last - first + n) % n + 1
            if length >= n:
                continue
            # A Night event belongs to its evening, so the first night without full darkness
            # is the evening before the first morning without astronomical dawn
            begins = (first - 1 + n) % n if period == "white_nights" else first
            add(begins, {"kind": f"{period}_begins", "days": length})
            add((last + 1) % n, {"kind": f"{period}_ends", "days": length})

    return facts


_TURN = re.compile(r"^(sunrise|sunset)_(earliest|latest)$")


def day_fact_text(fact: dict, ev: dict, year: dict) -> tuple[str, str]:
    """Title and description of a day fact from year_day_facts()."""
    texts = S["day_facts"]
    kind = fact["kind"]

    m = _TURN.match(kind)
    if m:
        lines = [sprintf(texts[kind]["lead"], format_local(ev[m.group(1)])), sprintf(texts["stable"], fact["stable"])]
        # Say what the day length does meanwhile when it moves against the clock time
        hours = [d["daylength_h"] for d in year["days"]]
        lengthening = hours[(fact["index"] + 1) % len(hours)] > hours[fact["index"]]
        if (not lengthening) if m.group(1) == "sunset" else lengthening:
            lines.append(texts["trend"][kind])
        lines.append(texts["why"])
        return texts[kind]["title"], "\n".join(lines)

    value = format_duration(round_int(ev["daylength_h"] * 3600)) if kind.startswith("twelve_hours") else fact["days"]
    return texts[kind]["title"], sprintf(texts[kind]["lead"], value)


def meteorological_fact(day: LocalDate, lat: float) -> tuple[str, str, str] | None:
    """The start of a meteorological season on the 1st of March, June, September and December.

    Outside the tropics only, where the four seasons by temperature apply.
    """
    texts = S["day_facts"]["meteorological"]
    month = day.month
    if day.day != 1 or month not in texts["seasons"] or abs(lat) < 23.44:
        return None
    season = texts["seasons"][month] if lat >= 0 else texts["opposite"][texts["seasons"][month]]
    return (
        season,
        sprintf(texts["title"], season),
        sprintf(texts["lead"], season, texts["months"][month], texts["marks"][season]),
    )


def location_facts(year: dict) -> list[str]:
    """Facts about the location for the calendar description, one sentence each."""
    texts = S["location_facts"]
    facts = []
    if not year["polar"]:
        facts.append(
            sprintf(
                texts["range"],
                format_duration(round_int(year["daylight_min"][0] * 3600)),
                format_local(year["daylight_min"][1], "date_short"),
                format_duration(round_int(year["daylight_max"][0] * 3600)),
                format_local(year["daylight_max"][1], "date_short"),
            )
        )
    for fact in ("midnight_sun", "polar_night", "white_nights"):
        if year[fact]:
            facts.append(sprintf(texts[fact], format_runs(year[fact])))
    return facts


# ============================================================================
# EQUINOXES AND SOLSTICES
# ============================================================================

_SEASONS_NORTH = {
    "march_equinox": "spring_equinox",
    "june_solstice": "summer_solstice",
    "september_equinox": "autumn_equinox",
    "december_solstice": "winter_solstice",
}
_SEASONS_SOUTH = {
    "march_equinox": "autumn_equinox",
    "june_solstice": "winter_solstice",
    "september_equinox": "spring_equinox",
    "december_solstice": "summer_solstice",
}


def get_special_astronomical_events(year: int, lat: float) -> list[dict]:
    """Equinoxes and solstices of a year, named by the season they start at the latitude."""
    seasons = _SEASONS_NORTH if lat >= 0 else _SEASONS_SOUTH
    return [
        {"key": key, "season": seasons[key], "date": timestamp}
        for key, timestamp in calculate_equinox_solstice(year).items()
    ]


def season_description(event: dict, ev: dict, year: dict, lat: float) -> str:
    """An equinox or solstice at the feed's location, with facts about that day only."""
    texts = S["season_text"]
    seconds = round_int(ev["daylength_h"] * 3600)

    lines = [
        sprintf(texts["moment"], texts["moment_names"][event["key"]], format_local(event["date"])),
        texts["sun"][event["key"]],
        sprintf(texts["begins"], strtolower(S["seasons"][event["season"]]["season"])),
    ]

    if event["season"].endswith("equinox"):
        if ev["sunrise"] is not None and ev["sunset"] is not None:
            lines.append(texts["east_west"])
        line = sprintf(texts["daylight"], format_duration(seconds))
        if 12 * 3600 <= seconds <= 12 * 3600 + 20 * 60:
            line += " " + texts["near_12"]
        lines.append(line)
        return "\n".join(lines)

    noon = round_int(ev["noon_elevation"])

    # The year's extreme must be today's length and fall near the solstice
    def is_extreme(extreme: list) -> bool:
        return abs(ev["daylength_h"] - extreme[0]) * 3600 < 30 and abs(extreme[1] - event["date"]) <= 3 * 86400

    if event["season"] == "summer_solstice":
        if ev["polar"] != "day" and is_extreme(year["daylight_max"]):
            lines.append(sprintf(texts["longest_day"], format_duration(seconds)))
        # Outside the tropics the noon sun peaks at the solstice; inside, it passes overhead twice a year
        lines.append(
            sprintf(texts["noon_highest"] if abs(lat) >= abs(ev["declination"]) else texts["noon_tropics"], noon)
        )
    else:
        if ev["polar"] != "night" and is_extreme(year["daylight_min"]):
            lines.append(sprintf(texts["shortest_day"], format_duration(seconds)))
        if ev["polar"] == "night":
            lines.append(sprintf(texts["noon_below"], abs(noon)))
        elif ev["noon_elevation"] > 0:
            lines.append(sprintf(texts["noon_lowest"], noon))

    return "\n".join(lines)


# ============================================================================
# EVENT DESCRIPTIONS
# ============================================================================


def missing_boundaries(key: str, span) -> tuple[bool, bool]:
    """Which of a twilight's boundary angles the sun fails to reach: (deep missing, shallow missing)."""
    morning = _TWILIGHT_BANDS[key]["morning"]
    return span[2 if morning else 3] == "solar_midnight", span[3 if morning else 2] == "solar_noon"


def twilight_details(key: str, span, ev: dict, year: dict, clock: str | None = None) -> str:
    """The data lines of a twilight event: its length within the year's range, and the sun's path."""
    labels = S["labels"]
    length = format_duration(span[1] - span[0])
    band = year["bands"][key]
    lines = [
        sprintf(labels["length"], length)
        if band is None
        else sprintf(
            labels["length_range"], length, format_range_bar(span[1] - span[0], band["min"][0], band["max"][0])
        )
    ]
    path = twilight_path(key, span, ev)
    if path is not None:
        lines.append(path)
    if clock is not None:
        lines.append(clock)
    return "\n".join(lines)


def light_periods(ev: dict) -> list[tuple[str, int | None, int | None]]:
    """Today's blue hours (6° to 4° below) and golden hours (4° below to 6° above), in time order."""
    periods = []
    if ev["civil_begin"] is not None and ev["minus4_begin"] is not None:
        periods.append(("blue", ev["civil_begin"], ev["minus4_begin"]))

    sun_below_six_all_day = ev["plus6_begin"] is None and ev["noon_elevation"] < 6
    sun_above_minus_four_all_day = ev["minus4_begin"] is None and ev["midnight_elevation"] > -4
    if sun_below_six_all_day:
        # The sun never climbs past 6°: one golden period around noon, if it gets above -4° at all
        if ev["minus4_begin"] is not None:
            periods.append(("golden", ev["minus4_begin"], ev["minus4_end"]))
        elif sun_above_minus_four_all_day:
            periods.append(("golden", None, None))
    elif ev["plus6_begin"] is not None:
        periods.append(("golden", ev["minus4_begin"], ev["plus6_begin"]))
        periods.append(("golden", ev["plus6_end"], ev["minus4_end"]))

    if ev["minus4_end"] is not None and ev["civil_end"] is not None:
        periods.append(("blue", ev["minus4_end"], ev["civil_end"]))
    return periods


def grouped_light_periods(ev: dict) -> list[tuple[str, str, int | None, int | None]]:
    """Blue and golden hours with their part of the day: 'morning', 'evening' or 'midday'."""
    noon = ev["solar_noon"]
    grouped = []
    for kind, start, end in light_periods(ev):
        if end is not None and end <= noon:
            group = "morning"
        elif start is not None and start >= noon:
            group = "evening"
        else:
            group = "midday"
        grouped.append((group, kind, start, end))
    return grouped


def format_light_block(ev: dict) -> str:
    """Blue and golden hour for the Daylight event, one line per part of the day."""
    texts = S["light"]
    groups: dict[str, list[str]] = {"morning": [], "midday": [], "evening": []}
    for group, kind, start, end in grouped_light_periods(ev):
        if start is None and end is None:
            return texts["all_day"]
        if start is None:
            when = sprintf(texts["until"], format_local(end))
        elif end is None:
            when = sprintf(texts["from"], format_local(start))
        else:
            when = format_local(start) + "–" + format_local(end)
        groups[group].append(sprintf(texts["item"], texts[kind], when))

    return "\n".join(sprintf(texts[group], ", ".join(items)) for group, items in groups.items() if items)


def light_entries(ev: dict, groups: list[str]) -> list[tuple[int, str]]:
    """Blue and golden hours as schedule entries (sort key, text), for calendars without Daylight."""
    texts = S["light"]
    entries = []
    for group, kind, start, end in grouped_light_periods(ev):
        if group in groups:
            entries.append(
                (start if start is not None else PHP_INT_MIN, schedule_entry(start, end, texts[f"{kind}_entry"], None))
            )
    return entries


def format_sequence(key: str, spans: dict) -> str:
    """Today's sequence with the current twilight bracketed: "Night → [Astronomical] → …"."""
    texts = S["sequence"]
    morning = key.startswith("morning")
    keys = (
        ("morning_astronomical", "morning_nautical", "morning_civil")
        if morning
        else ("evening_civil", "evening_nautical", "evening_astronomical")
    )
    present = [k for k in keys if spans[k] is not None]
    first = spans[present[0]]
    last = spans[present[-1]]
    if morning:
        opening = "solar_midnight" if first[2] == "solar_midnight" else "night"
        closing = "solar_noon" if last[3] == "solar_noon" else "sunrise"
    else:
        opening = "solar_noon" if first[2] == "solar_noon" else "sunset"
        closing = "solar_midnight" if last[3] == "solar_midnight" else "night"
    items = [f"[{texts[k]}]" if k == key else texts[k] for k in present]
    return " → ".join([texts[opening], *items, texts[closing]])


def twilight_path(key: str, span, ev: dict) -> str | None:
    """The sun's path through a twilight on a day it misses one of the boundaries, or None."""
    deep_missing, high_missing = missing_boundaries(key, span)
    if not deep_missing and not high_missing:
        return None

    band = _TWILIGHT_BANDS[key]
    texts = S["about_dynamic"]
    noon = round_int(abs(ev["noon_elevation"]))
    midnight = round_int(abs(ev["midnight_elevation"]))
    civil = band["high"] == 0

    if band["morning"]:
        start = (
            sprintf(texts["from_midnight"], midnight) if deep_missing else sprintf(texts["from_angle"], band["deep"])
        )
        if high_missing:
            end = sprintf(texts["to_noon"], noon)
        elif civil:
            end = S["instants"]["sunrise"]
        else:
            end = sprintf(texts["to_angle"], band["high"])
        path = sprintf(texts["path_morning"], start, end)
    else:
        if high_missing:
            start = sprintf(texts["from_noon"], noon)
        elif civil:
            start = S["instants"]["sunset"]
        else:
            start = sprintf(texts["from_angle"], band["high"])
        end = sprintf(texts["to_midnight"], midnight) if deep_missing else sprintf(texts["to_angle"], band["deep"])
        path = sprintf(texts["path_evening"], start, end)

    # A civil twilight that never reaches the horizon means the sun does not rise
    return texts["polar_night"] + lcfirst(path) if civil and high_missing else path


def twilight_about(key: str, span, spans: dict) -> str:
    """The "About" section of a twilight: what is happening and what it is good for."""
    about = S["about"][key]
    deep_missing, high_missing = missing_boundaries(key, span)
    # The usual angles are wrong on a day the data lines describe a different path
    angles = "" if (deep_missing or high_missing) else about["angles"] + " "
    return (
        sprintf(S["headers"]["about"], S["names"][key])
        + "\n"
        + format_sequence(key, spans)
        + "\n\n"
        + angles
        + about["text"]
        + "\n\n"
        + S["about_dynamic"]["length_morning" if key.startswith("morning") else "length_evening"]
        + "\n\n"
        + sprintf(S["labels"]["good_for"], about["good_for"])
    )


def daylight_details(c: dict, with_clock: bool = True) -> str:
    """Daylight facts: how the day is changing, where the sun peaks, and where the day sits in the year."""
    ev = c["ev"]
    d = c["daylight"]
    year = c["year"]
    labels = S["labels"]

    lines = [labels["midnight_sun"]] if ev["polar"] == "day" else []
    trend = format_comparison(d["change"], S["comparisons"]["yesterday"])
    if with_clock and d["sunrise_shift"] is not None:
        trend += sprintf(labels["clock"], format_clock_shift(d["sunrise_shift"]), format_clock_shift(d["sunset_shift"]))
    lines.append(trend)
    if ev["polar"] == "day":
        lines.append(
            sprintf(
                labels["sun_peak_midnight_sun"],
                format_local(ev["solar_noon"]),
                round_int(ev["noon_elevation"]),
                format_local(ev["solar_noon"] + 43200),
                round_int(ev["midnight_elevation"]),
            )
        )
    else:
        lines.append(sprintf(labels["sun_peak"], format_local(ev["solar_noon"]), round_int(ev["noon_elevation"])))

    shortest = (round_int(year["daylight_min"][0] * 3600), year["daylight_min"][1])
    longest = (round_int(year["daylight_max"][0] * 3600), year["daylight_max"][1])
    lines.append(sprintf(labels["over_year"], format_range_bar(d["seconds"], shortest[0], longest[0])))
    # With polar day or night the extremes last weeks, so a single date would mislead
    if not year["polar"]:
        lines.append(format_extremes("daylight", d["seconds"], shortest, longest))
    return "\n".join(lines) + "\n"


def night_details(c: dict) -> str:
    """Night facts with the moon, shared by the Night event and the evening schedule."""
    span = c["spans"]["night"]
    n = c["night"]
    labels = S["labels"]

    # Deep polar night: solar noon stands in for the start or end of the night
    noon_start = span[2] == "solar_noon"
    noon_end = span[3] == "solar_noon"
    lines = []
    if noon_start and noon_end:
        lines.append(labels["night_all_day"])
    elif noon_start or noon_end:
        lines.append(S["no_crossing"]["night_from_noon" if noon_start else "night_to_noon"])
    # Round-the-clock nights differ only by seconds of solar-noon drift: no change to give
    if not (noon_start and noon_end) and n["comparison"] is not None:
        lines.append(n["comparison"])

    nights = {i: length for i, length in enumerate(c["year"]["nights"]) if length is not None}
    if nights and not (noon_start and noon_end):
        days = [d["timestamp"] for d in c["year"]["days"]]
        lo = min(nights.values())
        hi = max(nights.values())
        shortest = (lo, days[next(k for k, v in nights.items() if v == lo)])
        longest = (hi, days[next(k for k, v in nights.items() if v == hi)])
        lines.append(sprintf(labels["over_year"], format_range_bar(n["seconds"], shortest[0], longest[0])))
        # Polar or white-night years have no single shortest or longest night worth dating
        if not c["year"]["polar"] and not c["year"]["white_nights"]:
            lines.append(format_extremes("night", n["seconds"], shortest, longest))

    return ("" if not lines else "\n".join(lines) + "\n\n") + format_moon_section(n["moon"])


def daylight_about(ev: dict) -> str:
    """The "About" section of the Daylight event."""
    text = S["about"]["daylight"]["text"]
    if light_periods(ev):
        text += " " + S["about_dynamic"]["light"]
    return sprintf(S["headers"]["about"], S["names"]["daylight"]) + "\n" + text


def night_about() -> str:
    """The "About" section of the Night event."""
    return sprintf(S["headers"]["about"], S["names"]["night"]) + "\n" + S["about"]["night"]["text"]


def build_dawn_supplemental(c: dict, key: str) -> str:
    """The Today schedule: morning periods not subscribed to as events, in time order."""
    twilights = ("morning_astronomical", "morning_nautical", "morning_civil")
    carrier = c["carriers"]["morning"] == key
    # Without a Daylight event the photo light joins the schedule; the evening part goes on the
    # evening civil twilight instead when there is one, since later evening events start after it
    light: list[str] = []
    if carrier and not c["enabled"]["daylight"]:
        evening_civil = c["enabled"]["civil"] and c["spans"]["evening_civil"] is not None
        light = ["morning", "midday"] if evening_civil else ["morning", "midday", "evening"]
    return build_supplemental(c, "today", twilights, "daylight", carrier, light)


def build_dusk_supplemental(c: dict, key: str) -> str:
    """The evening schedule (counterpart of build_dawn_supplemental())."""
    twilights = ("evening_civil", "evening_nautical", "evening_astronomical")
    light = ["evening"] if key == "evening_civil" and not c["enabled"]["daylight"] else []
    return build_supplemental(c, "tonight", twilights, "night", c["carriers"]["evening"] == key, light)


def build_twilight_schedule(c: dict, part: str, header: str | None = None) -> str:
    """Twilights for a calendar subscribed to no twilight events, on the event right before them."""
    enabled = c["enabled"]
    if enabled["civil"] or enabled["nautical"] or enabled["astro"]:
        return ""
    if part == "this_evening":
        spans = c["spans"]
        keys = ("evening_civil", "evening_nautical", "evening_astronomical")
    else:
        spans = c["spans_tomorrow"] or {}
        keys = ("morning_astronomical", "morning_nautical", "morning_civil")
    info = ""
    for key in keys:
        if spans.get(key) is not None:
            info += format_schedule_span(S["summaries"][key], spans[key], S["supplemental"][key])
    return "" if info == "" else "\n\n" + S["headers"][header or part] + "\n" + info.rstrip("\n")


def build_supplemental(
    c: dict, header: str, twilights, period: str, with_period: bool, light: list[str] | None = None
) -> str:
    """A schedule of times in order, then the details of the period it leads to as a section of its own."""
    entries = light_entries(c["ev"], light or [])
    for key in twilights:
        if not c["enabled"][_TWILIGHT_BANDS[key]["flag"]] and c["spans"][key] is not None:
            span = c["spans"][key]
            entries.append((span[0], format_schedule_span(S["summaries"][key], span, S["supplemental"][key])))

    details = ""
    if with_period and not c["enabled"]["daylight"] and c["spans"][period] is not None:
        span = c["spans"][period]
        entries.append((span[0], schedule_entry(span[0], span[1], S["schedule_labels"][period], None)))
        # With civil twilight subscribed, its morning and evening events say how sunrise and sunset moved
        text = daylight_details(c, not c["enabled"]["civil"]) if period == "daylight" else night_details(c)
        details = "\n\n" + S["headers"][f"{period}_details"] + "\n" + text.rstrip("\n")
    if not entries:
        return details
    entries.sort(key=lambda e: e[0])
    return "\n\n" + S["headers"][header] + "\n" + "".join(e[1] for e in entries).rstrip("\n") + details
