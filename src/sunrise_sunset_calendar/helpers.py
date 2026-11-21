"""Helper functions for calendar generation.

iCalendar formatting, formatting of times and lengths, year facts, and the
text of each event description.
"""

from __future__ import annotations

import math
import re
from collections.abc import Callable
from typing import TypedDict

from . import cache
from .astronomy import calculate_equinox_solstice, moon_phases_between
from .clock import (
    ISO_DATE,
    LocalDate,
    current_zone,
    days_later,
    format_local_time,
    local,
    local_timestamp,
    utc_timestamp,
)
from .numbers import round_int
from .strings import STRINGS, DayFactKind

S = STRINGS

# A calendar period: start, end, and the instants that bound it ("sunrise", "solar_noon", ...)
Span = tuple[int, int, str, str]


class Carriers(TypedDict):
    """The twilight events that carry the Daylight and Night blocks, when those aren't events."""

    morning: str | None
    evening: str | None
    # The evening twilight nearest sunset, which says how sunset moved
    sunset: str | None


class DaylightInfo(TypedDict):
    seconds: int
    change: int
    # Both None when sunrise or sunset is missing today or yesterday
    sunrise_shift: int | None
    sunset_shift: int | None


class NightInfo(TypedDict):
    seconds: int
    comparison: str | None
    moon: dict


class DayContext(TypedDict):
    """One day of the feed, as the event descriptions read it."""

    ev: dict
    spans: dict[str, Span | None]
    enabled: dict[str, bool]
    year: dict
    carriers: Carriers
    spans_tomorrow: dict[str, Span | None] | None
    daylight: DaylightInfo
    night: NightInfo


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
            return units["seconds"].format(seconds)
        return units["minutes_seconds"].format(seconds // 60, seconds % 60)
    minutes = round_int(seconds / 60)
    if minutes < 60:
        return units["minutes"].format(minutes)
    return units["hours_minutes"].format(minutes // 60, minutes % 60)


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
    return format_local_time(timestamp, S["formats"][fmt])


def format_time(timestamp: int | None) -> str:
    """A time of day, or a dash when the event does not occur."""
    return S["none"] if timestamp is None else format_local(timestamp)


def format_range_bar(seconds: int, shortest: int, longest: int, width: int = 10) -> str:
    """Where a length sits in the year's range: "9h 08m ▕██░░░░░░░░▏ 15h 14m"."""
    span = longest - shortest
    filled = round_int((seconds - shortest) / span * width) if span > 0 else width // 2
    filled = max(0, min(width, filled))
    return format_duration(shortest) + " ▕" + "█" * filled + "░" * (width - filled) + "▏ " + format_duration(longest)


def format_extremes(
    kind: str, seconds: int, shortest: tuple[int, int], longest: tuple[int, int], lengths: list[int]
) -> list[str]:
    """Today among the year's days (or nights): its rank, then the shortest and the longest.

    "Longer than 58 of 365 days (16%)", "vs shortest (Dec 21): +29m (+5.2%)". On the extreme day itself, a
    line naming it stands in for the rank and for that extreme.
    """
    texts = S["year"][kind]

    def day(ts: int) -> str:
        return format_local(ts, "date_short")

    def versus(text: str, extreme: tuple[int, int]) -> str:
        # Seconds matter only for a gap under a minute
        diff = seconds - extreme[0]
        return text.format(day(extreme[1]), format_signed_length(diff, abs(diff) < 60, extreme[0]))

    # Only the extreme day itself, allowing for rounding
    if seconds - shortest[0] <= 2:
        return [texts["is_shortest"], versus(texts["longest"], longest)]
    if longest[0] - seconds <= 2:
        return [texts["is_longest"], versus(texts["shortest"], shortest)]
    shorter = sum(1 for length in lengths if length < seconds)
    rank = texts["rank"].format(shorter, len(lengths), f"{round_int(100 * shorter / len(lengths))}%")
    return [rank, versus(texts["shortest"], shortest), versus(texts["longest"], longest)]


def format_comparison(diff_seconds: int, referent: str, base: int) -> str:
    """A length against an earlier one: "vs yesterday: −1m 50s (−0.3%)", the percentage of base."""
    if diff_seconds == 0:
        return S["comparisons"]["same"].format(referent)
    return S["comparisons"]["vs"].format(referent, format_signed_length(diff_seconds, True, base))


def format_signed_length(diff_seconds: int, with_seconds: bool, base: int) -> str:
    """A change in length with its sign and, when it shows, its percentage of base: "+29m (+5.2%)"."""
    length = ("+" if diff_seconds > 0 else "−") + format_duration(diff_seconds, with_seconds)
    percent = format_percent(diff_seconds, base)
    return length if percent is None else S["comparisons"]["percent"].format(length, percent)


def format_percent(diff: float, base: float) -> str | None:
    """A signed percentage of base: "−0.3%", "+5.2%", "−37%".

    One decimal under 10%, whole numbers from there, and a true minus sign. None when there is
    nothing to compare against (a base of zero) or the change rounds to 0.0%.
    """
    if base <= 0:
        return None
    value = 100 * diff / base
    sign = "+" if value > 0 else "−"
    magnitude = round(abs(value), 1)
    if magnitude == 0:
        return None
    return sign + (f"{magnitude:.1f}" if magnitude < 10 else str(round_int(abs(value)))) + "%"


def format_clock_shift(shift_seconds: int) -> str:
    """How far an event moved on the clock since the day before: "1m 18s later than yesterday".

    From ten minutes (polar days, where sunrise can move by most of an hour), to the minute.
    """
    texts = S["clock"]
    if abs(shift_seconds) < 1:
        return texts["same"]
    length = format_duration(shift_seconds, abs(shift_seconds) < 600)
    return texts["later" if shift_seconds > 0 else "earlier"].format(length)


def schedule_entry(start: int | None, end: int | None, label: str, note: str | None) -> str:
    """One schedule entry, time first, then an optional indented note."""
    texts = S["schedule"]
    if start is None:
        when = texts["all_day"] if end is None else texts["until"].format(format_local(end))
    elif end is None:
        when = texts["from"].format(format_local(start))
    else:
        when = format_local(start) + "–" + format_local(end)
    line = texts["entry"].format(when, label)
    return line + "\n" + ("" if note is None else texts["indent"] + note + "\n")


def format_schedule_span(label: str, span: Span, note: str) -> str:
    """One twilight as a schedule entry."""
    return schedule_entry(span[0], span[1], label, note)


def format_span_range(span: Span) -> str:
    """A period's time range and length, for its details header: "07:07–16:43 (9h 36m)"."""
    return S["schedule"]["range"].format(
        format_local(span[0]), format_local(span[1]), format_duration(span[1] - span[0])
    )


def format_moon_section(moon: dict) -> str:
    """The moon during the night: phase, moonless window, brightness and a principal phase that night."""
    texts = S["moon"]
    lat = moon.get("lat", 0.0)
    lit = round_int(moon["illumination"])
    emoji = get_moon_phase_emoji(moon["phase_name"], lat)

    lines = [texts["current"].format(moon_phase_name(moon["phase_name"]), lit)]
    # A principal phase that happens during the night, to the minute
    if moon.get("night") is not None:
        for phase in moon_phases_between(moon["night"][0], moon["night"][1]):
            lines.append(texts["exact"].format(moon_phase_name(phase["name"]), format_local(phase["timestamp"])))
    if moon.get("rise_set") is not None and moon.get("night") is not None:
        windows = moonless_windows(moon["rise_set"], moon["night"][0], moon["night"][1])
        whole = moon["night"][1] - moon["night"][0]
        if not windows:
            lines.append(texts["moonless_none"])
        elif len(windows) == 1 and windows[0][1] - windows[0][0] >= whole:
            lines.append(texts["moonless_all"])
        else:
            # One window with its length; several only by their times, to fit one line
            def window(w: list[int]) -> str:
                times = format_local(w[0]) + "–" + format_local(w[1])
                return times + " (" + format_duration(w[1] - w[0]) + ")" if len(windows) == 1 else times

            lines.append(texts["moonless"].format(", ".join(window(w) for w in windows)))
        up_at_all = not windows or len(windows) > 1 or windows[0][1] - windows[0][0] < whole
        if up_at_all:
            lines.append(texts["impact"]["dark" if lit < 25 else ("partial" if lit <= 75 else "bright")])

    return S["headers"]["moon"].format(emoji) + "\n" + "\n".join(lines) + "\n"


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
    """Solar calculations for every day of a year at one fixed UTC offset.

    Each day also has its local noon ("timestamp") and the instant its fractions count from ("midnight").
    """
    from .astronomy import calculate_sun_times

    key = f"{lat:.4f}:{lon:.4f}:{year}:{utc_offset:.2f}"
    store = cache.get().year_profile
    if key not in store:
        profile = []
        days_in_year = 366 if _is_leap(year) else 365
        for day in range(1, days_in_year + 1):
            timestamp = local_timestamp(year, 1, day, 12, 0, 0)
            y, m, d = _local_parts(timestamp)
            midnight = utc_timestamp(y, m, d, 0, 0, 0) - round_int(utc_offset * 3600)
            profile.append(
                {"timestamp": timestamp, "midnight": midnight} | calculate_sun_times(y, m, d, lat, lon, utc_offset)
            )
        store[key] = profile
    return store[key]


def _is_leap(year: int) -> bool:
    return year % 4 == 0 and (year % 100 != 0 or year % 400 == 0)


def _local_parts(timestamp: int) -> tuple[int, int, int]:
    lt = local(timestamp)
    return lt.year, lt.month, lt.day


def get_week_summary(week_start: int, lat: float, lon: float, utc_offset: float) -> dict:
    """Daylight over the seven days from week_start: first and last lengths, change and trend."""
    from .astronomy import calculate_sun_times

    key = f"{week_start}:{lat:.4f}:{lon:.4f}:{utc_offset:.2f}"
    store = cache.get().week_summary
    if key in store:
        return store[key]

    lengths = []
    for i in range(7):
        parts = _local_parts(days_later(week_start, i))
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
        S["date_range"].format(format_local(run[0], "date_short"), format_local(run[1], "date_short")) for run in runs
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


def _pick_first(values: list[float], target: float) -> int:
    for i, v in enumerate(values):
        if v == target:
            return i
    raise ValueError(target)


def _sunlit_all_date(day: dict) -> bool:
    """Whether a day's one sunrise or sunset falls on another date, leaving the sun up all its date.

    A solar day runs from solar midnight to solar midnight, so the first sunset after the
    midnight sun can come after local midnight: the calendar date before it has no sunset.
    """
    rise, set_ = day["sunrise_frac"], day["sunset_frac"]
    if (rise is None) == (set_ is None):
        return False
    instant = day["midnight"] + round_int((set_ if rise is None else rise) * 86400)
    return format_local_time(instant, ISO_DATE) != format_local_time(day["timestamp"], ISO_DATE)


def year_summary(lat: float, lon: float, year: int, utc_offset: float) -> dict:
    """Facts about a location's year, derived from its year profile."""
    memo = cache.get().year_summary
    key = f"{lat:.4f}:{lon:.4f}:{year}:{utc_offset:.2f}:{current_zone()}"
    if key in memo:
        return memo[key]

    days = get_year_profile(lat, lon, year, utc_offset)
    timestamps = [d["timestamp"] for d in days]
    lengths = [d["daylength_h"] for d in days]

    def pick(values: list[float], choose: Callable[[list[float]], float]) -> list[float]:
        i = _pick_first(values, choose(values))
        return [values[i], timestamps[i]]

    polar_day = [d["polar"] == "day" or _sunlit_all_date(d) for d in days]
    polar_night = [d["polar"] == "night" for d in days]
    # No astronomical night: the sun never crosses -18° because it stays above it at midnight
    white_nights = [d["astro_begin_frac"] is None and abs(lat + d["declination_deg"]) - 90 > -18 for d in days]

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
        "nights": nights,
    }
    memo[key] = result
    return result


def year_day_facts(year: dict) -> dict[str, list[dict]]:
    """Days worth an all-day event because something changes that day, by local date."""
    days = year["days"]
    n = len(days)
    facts: dict[str, list[dict]] = {}

    def add(index: int, fact: dict) -> None:
        facts.setdefault(format_local_time(days[index]["timestamp"], ISO_DATE), []).append({"index": index} | fact)

    for event, field in (("sunrise", "sunrise_frac"), ("sunset", "sunset_frac")):
        values = [d[field] for d in days]
        for i in range(n):
            window = [values[(i + k + n) % n] for k in range(-20, 21)]
            if None in window:
                continue
            before = window[:20]
            after = window[21:]
            is_min = values[i] < min(before) and values[i] <= min(after)
            is_max = values[i] > max(before) and values[i] >= max(after)
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
    kind: DayFactKind = fact["kind"]

    m = _TURN.match(kind)
    if m:
        lines = [texts[kind]["lead"].format(format_local(ev[m.group(1)])), texts["stable"].format(fact["stable"])]
        # Say what the day length does meanwhile when it moves against the clock time
        hours = [d["daylength_h"] for d in year["days"]]
        lengthening = hours[(fact["index"] + 1) % len(hours)] > hours[fact["index"]]
        if (not lengthening) if m.group(1) == "sunset" else lengthening:
            lines.append(texts["trend"][kind])
        lines.append(texts["why"])
        return texts[kind]["title"], "\n".join(lines)

    value = format_duration(round_int(ev["daylength_h"] * 3600)) if kind.startswith("twelve_hours") else fact["days"]
    return texts[kind]["title"], texts[kind]["lead"].format(value)


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
        texts["title"].format(season),
        texts["lead"].format(season, texts["months"][month], texts["marks"][season]),
    )


def location_facts(year: dict) -> list[str]:
    """Facts about the location for the calendar description, one sentence each."""
    texts = S["location_facts"]
    facts = []
    if not year["polar"]:
        facts.append(
            texts["range"].format(
                format_duration(round_int(year["daylight_min"][0] * 3600)),
                format_local(year["daylight_min"][1], "date_short"),
                format_duration(round_int(year["daylight_max"][0] * 3600)),
                format_local(year["daylight_max"][1], "date_short"),
            )
        )
    for fact in ("midnight_sun", "polar_night", "white_nights"):
        if year[fact]:
            facts.append(texts[fact].format(format_runs(year[fact])))
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
    """An equinox or solstice at the feed's location, with facts about that day only.

    The moment and what it marks, then what the sun does (☀️ Sun) and the day's length (🔆 Daylight).
    """
    texts = S["season_text"]
    seconds = round_int(ev["daylength_h"] * 3600)

    lines = [
        texts["moment"].format(texts["moment_names"][event["key"]], format_local(event["date"])),
        texts["sun"][event["key"]],
        texts["begins"].format(S["seasons"][event["season"]]["season"].lower()),
    ]
    sun: list[str] = []
    day: list[str] = []

    def versus(kind: str, extreme: list) -> str:
        length = round_int(extreme[0] * 3600)
        diff = seconds - length
        return S["year"]["daylight"][kind].format(
            format_local(extreme[1], "date_short"), format_signed_length(diff, abs(diff) < 60, length)
        )

    if event["season"].endswith("equinox"):
        if ev["sunrise"] is not None and ev["sunset"] is not None:
            sun.append(texts["east_west"])
        day.append(texts["daylight"].format(format_duration(seconds)))
        if 12 * 3600 <= seconds <= 12 * 3600 + 20 * 60:
            day.append(texts["near_12"])
        return section_text(lines, sun, day)

    noon = round_int(ev["noon_elevation"])

    # The year's extreme must be today's length and fall near the solstice
    def is_extreme(extreme: list) -> bool:
        return abs(ev["daylength_h"] - extreme[0]) * 3600 < 30 and abs(extreme[1] - event["date"]) <= 3 * 86400

    if event["season"] == "summer_solstice":
        if ev["polar"] != "day" and is_extreme(year["daylight_max"]):
            day += [texts["longest_day"].format(format_duration(seconds)), versus("shortest", year["daylight_min"])]
        # Outside the tropics the noon sun peaks at the solstice; inside, it passes overhead twice a year
        sun.append(
            (texts["noon_highest"] if abs(lat) >= abs(ev["declination"]) else texts["noon_tropics"]).format(noon)
        )
    else:
        if ev["polar"] != "night" and is_extreme(year["daylight_min"]):
            day += [texts["shortest_day"].format(format_duration(seconds)), versus("longest", year["daylight_max"])]
        if ev["polar"] == "night":
            sun.append(texts["noon_below"].format(abs(noon)))
        elif ev["noon_elevation"] > 0:
            sun.append(texts["noon_lowest"].format(noon))

    return section_text(lines, sun, day)


def section_text(lines: list[str], sun: list[str], day: list[str]) -> str:
    """Lines, then a ☀️ Sun and a 🔆 Daylight section, each left out when empty."""
    parts = ["\n".join(lines)]
    if sun:
        parts.append(S["headers"]["sun"] + "\n" + "\n".join(sun))
    if day:
        parts.append(S["headers"]["daylight_plain"] + "\n" + "\n".join(day))
    return "\n\n".join(parts)


# ============================================================================
# EVENT DESCRIPTIONS
# ============================================================================


def missing_boundaries(key: str, span: Span) -> tuple[bool, bool]:
    """Which of a twilight's boundary angles the sun fails to reach: (deep missing, shallow missing)."""
    morning = _TWILIGHT_BANDS[key]["morning"]
    return span[2 if morning else 3] == "solar_midnight", span[3 if morning else 2] == "solar_noon"


def twilight_details(key: str, span: Span, ev: dict) -> str:
    """The data lines of a twilight event: first or last light on a nautical twilight, and the sun's path
    on a day it misses one of the boundaries. "" when there is neither."""
    lines = []
    crossing = light_crossing(key, span)
    if crossing is not None:
        lines.append(crossing)
    path = twilight_path(key, span, ev)
    if path is not None:
        lines.append(path)
    return "\n".join(lines)


def light_crossing(key: str, span: Span) -> str | None:
    """First light on a morning nautical twilight that starts 12° below, last light on an evening one that
    ends there; None otherwise (a twilight bounded by solar midnight never reaches 12° below)."""
    if key == "morning_nautical" and span[2] != "solar_midnight":
        return S["labels"]["first_light"]
    if key == "evening_nautical" and span[3] != "solar_midnight":
        return S["labels"]["last_light"]
    return None


def twilight_note(key: str, span: Span) -> str:
    """The note under a twilight in a schedule: first or last light where it applies."""
    return light_crossing(key, span) or S["supplemental"][key]


def light_periods(ev: dict) -> list[tuple[str, int | None, int | None]]:
    """Today's blue hours (6° to 4° below) and golden hours (4° below to 6° above), in time order."""
    periods = []
    if ev["civil_begin"] is not None and ev["minus4_begin"] is not None:
        periods.append(("blue", ev["civil_begin"], ev["minus4_begin"]))

    if ev["plus6_begin"] is None and ev["plus6_end"] is None and ev["noon_elevation"] < 6:
        # The sun never climbs past 6°: one golden period around noon, if it gets above -4° at all
        if ev["minus4_begin"] is not None or ev["minus4_end"] is not None:
            periods.append(("golden", ev["minus4_begin"], ev["minus4_end"]))
        elif ev["midnight_elevation"] > -4:
            periods.append(("golden", None, None))
    else:
        # Morning and evening apart: the day the sun starts or stops staying above 6°, only one exists
        if ev["plus6_begin"] is not None:
            periods.append(("golden", ev["minus4_begin"], ev["plus6_begin"]))
        if ev["plus6_end"] is not None:
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
    """Blue and golden hour for the Daylight event: a schedule under its header, or "" without either."""
    texts = S["light"]
    periods = light_periods(ev)
    if not periods:
        return ""
    if any(start is None and end is None for _, start, end in periods):
        body = texts["all_day"]
    else:
        body = "".join(schedule_entry(start, end, texts[kind], None) for kind, start, end in periods).rstrip("\n")
    return S["headers"]["light"] + "\n" + body


def light_entries(ev: dict, groups: list[str]) -> list[tuple[float, str]]:
    """Blue and golden hours as schedule entries (sort key, text), for calendars without Daylight."""
    texts = S["light"]
    entries = []
    for group, kind, start, end in grouped_light_periods(ev):
        if group in groups:
            entry = schedule_entry(start, end, texts[f"{kind}_entry"], texts[f"{kind}_note"])
            entries.append((start if start is not None else -math.inf, entry))
    return entries


def format_sequence(key: str, spans: dict[str, Span | None]) -> str:
    """Today's sequence with the current twilight bracketed: "Astro → Nautical → [Civil] → Sunrise".

    It runs from the night side, which every sequence reaches and so goes unsaid, to the sun: sunrise
    or sunset, or noon on a day the sun stays below the horizon. On a day solar noon or midnight stands
    in for a boundary, the data lines describe the sun's path.
    """
    texts = S["sequence"]
    morning = key.startswith("morning")
    keys = (
        ("morning_astronomical", "morning_nautical", "morning_civil")
        if morning
        else ("evening_civil", "evening_nautical", "evening_astronomical")
    )
    present = [(k, span) for k in keys if (span := spans[k]) is not None]
    items = [f"[{texts[k]}]" if k == key else texts[k] for k, _ in present]
    if morning:
        sun = "solar_noon" if present[-1][1][3] == "solar_noon" else "sunrise"
        return " → ".join([*items, texts[sun]])
    sun = "solar_noon" if present[0][1][2] == "solar_noon" else "sunset"
    return " → ".join([texts[sun], *items])


def twilight_path(key: str, span: Span, ev: dict) -> str | None:
    """The sun's path through a twilight on a day it misses one of the boundaries, or None."""
    deep_missing, high_missing = missing_boundaries(key, span)
    if not deep_missing and not high_missing:
        return None

    band = _TWILIGHT_BANDS[key]
    texts = S["about_dynamic"]
    noon = round_int(abs(ev["noon_elevation"]))
    midnight = round_int(abs(ev["midnight_elevation"]))
    civil = band["high"] == 0

    def at(key: str, angle: int) -> str:
        """A path's end at solar noon or midnight: "just below the horizon" where the angle rounds to 0°."""
        return texts[f"{key}_skimming"] if angle == 0 else texts[key].format(angle)

    if band["morning"]:
        start = at("from_midnight", midnight) if deep_missing else texts["from_angle"].format(band["deep"])
        if high_missing:
            end = at("to_noon", noon)
        elif civil:
            end = S["instants"]["sunrise"]
        else:
            end = texts["to_angle"].format(band["high"])
        path = texts["path_morning"].format(start, end)
    else:
        if high_missing:
            start = at("from_noon", noon)
        elif civil:
            start = S["instants"]["sunset"]
        else:
            start = texts["from_angle"].format(band["high"])
        end = at("to_midnight", midnight) if deep_missing else texts["to_angle"].format(band["deep"])
        path = texts["path_evening"].format(start, end)

    # A civil twilight that never reaches the horizon means the sun does not rise
    return texts["polar_night"] + path[:1].lower() + path[1:] if civil and high_missing else path


def twilight_about(key: str, span: Span, spans: dict[str, Span | None]) -> str:
    """The "About" section of a twilight: what is happening and what it is good for."""
    about = S["about"][key]
    deep_missing, high_missing = missing_boundaries(key, span)
    # The usual angles are wrong on a day the data lines describe a different path
    angles = "" if (deep_missing or high_missing) else about["angles"] + " "
    return (
        S["headers"]["about"]
        + "\n"
        + format_sequence(key, spans)
        + "\n\n"
        + angles
        + about["text"]
        + "\n\n"
        + S["about_dynamic"]["length_morning" if key.startswith("morning") else "length_evening"]
        + "\n\n"
        + S["labels"]["good_for"].format(about["good_for"])
    )


def daylight_details(c: DayContext) -> str:
    """Daylight in numbers: the change since yesterday, and where the day sits among the year's days."""
    ev = c["ev"]
    d = c["daylight"]
    year = c["year"]
    labels = S["labels"]

    lines = [labels["midnight_sun"]] if ev["polar"] == "day" else []
    lines.append(format_comparison(d["change"], S["comparisons"]["yesterday"], d["seconds"] - d["change"]))
    shortest = (round_int(year["daylight_min"][0] * 3600), year["daylight_min"][1])
    longest = (round_int(year["daylight_max"][0] * 3600), year["daylight_max"][1])
    lines.append(labels["over_year"].format(format_range_bar(d["seconds"], shortest[0], longest[0])))
    # With polar day or night the extremes last weeks, so a single date (or a rank among ties) would mislead
    if not year["polar"]:
        lengths = [round_int(day["daylength_h"] * 3600) for day in year["days"]]
        lines += format_extremes("daylight", d["seconds"], shortest, longest, lengths)
    return "\n".join(lines) + "\n"


def sun_section(c: DayContext, part: str) -> str:
    """☀️ Sun: the sun's own events. "morning" has sunrise and the sun's highest point, "evening" sunset,
    "day" (the Daylight event) all three. "" when there is nothing to say."""
    d = c["daylight"]
    labels = S["labels"]
    lines = []
    if part in ("morning", "day") and d["sunrise_shift"] is not None:
        lines.append(labels["sunrise_shift"].format(format_clock_shift(d["sunrise_shift"])))
    if part in ("morning", "day") and c["spans"]["daylight"] is not None:
        lines += sun_peak_lines(c["ev"])
    if part in ("evening", "day") and d["sunset_shift"] is not None:
        lines.append(labels["sunset_shift"].format(format_clock_shift(d["sunset_shift"])))
    return "" if not lines else S["headers"]["sun"] + "\n" + "\n".join(lines)


def sun_peak_lines(ev: dict) -> list[str]:
    """Where the sun peaks, and under the midnight sun where it is lowest."""
    labels = S["labels"]
    # Refraction keeps the sun in sight with its centre just below the horizon: an angle that rounds
    # to 0° or below reads as skimming the horizon, not "-1° up"
    noon = round_int(ev["noon_elevation"])
    midnight = round_int(ev["midnight_elevation"])
    if ev["polar"] == "day" and noon <= 0:
        return [labels["sun_skimming_all_day"]]
    if ev["polar"] == "day":
        lowest = labels["sun_low_up"].format(midnight) if midnight > 0 else labels["sun_low_skimming"]
        return [
            labels["sun_peak"].format(format_local(ev["solar_noon"]), noon),
            labels["sun_low"].format(format_local(ev["solar_noon"] + 43200), lowest),
        ]
    if noon <= 0:
        return [labels["sun_peak_skimming"].format(format_local(ev["solar_noon"]))]
    return [labels["sun_peak"].format(format_local(ev["solar_noon"]), noon)]


def night_details(c: DayContext) -> str:
    """Night facts with the moon, shared by the Night event and the evening schedule."""
    span = c["spans"]["night"]
    if span is None:
        raise ValueError("night_details() needs a day with a night")
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
        lines.append(labels["over_year"].format(format_range_bar(n["seconds"], shortest[0], longest[0])))
        # Polar or white-night years have no single shortest or longest night worth dating
        if not c["year"]["polar"] and not c["year"]["white_nights"]:
            lines += format_extremes("night", n["seconds"], shortest, longest, list(nights.values()))

    return ("" if not lines else "\n".join(lines) + "\n\n") + format_moon_section(n["moon"])


def daylight_about(ev: dict) -> str:
    """The "About" section of the Daylight event."""
    text = S["about"]["daylight"]["text"]
    if light_periods(ev):
        text += " " + S["about_dynamic"]["light"]
    return S["headers"]["about"] + "\n" + text


def night_about() -> str:
    """The "About" section of the Night event."""
    return S["headers"]["about"] + "\n" + S["about"]["night"]["text"]


def build_dawn_supplemental(c: DayContext, key: str) -> tuple[str, str]:
    """The morning schedule (morning periods not subscribed to as events, in time order) and the
    Daylight details, on the morning twilight nearest daylight."""
    twilights = ("morning_astronomical", "morning_nautical", "morning_civil")
    carrier = c["carriers"]["morning"] == key
    # Without a Daylight event the photo light joins the schedule; the evening part goes on the
    # evening civil twilight instead when there is one, since later evening events start after it
    light: list[str] = []
    if carrier and not c["enabled"]["daylight"]:
        evening_civil = c["enabled"]["civil"] and c["spans"]["evening_civil"] is not None
        light = ["morning", "midday"] if evening_civil else ["morning", "midday", "evening"]
    return build_supplemental(c, "today", twilights, "daylight", carrier, light)


def build_dusk_supplemental(c: DayContext, key: str) -> tuple[str, str]:
    """The evening schedule and the Night details (counterpart of build_dawn_supplemental())."""
    twilights = ("evening_civil", "evening_nautical", "evening_astronomical")
    light = ["evening"] if key == "evening_civil" and not c["enabled"]["daylight"] else []
    return build_supplemental(c, "tonight", twilights, "night", c["carriers"]["evening"] == key, light)


def build_twilight_schedule(c: DayContext, part: str, header: str | None = None) -> str:
    """Twilights for a calendar subscribed to no twilight events, on the event right before them; ""
    when there are none."""
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
        span = spans.get(key)
        if span is not None:
            info += format_schedule_span(S["schedule_names"][key], span, twilight_note(key, span))
    return "" if info == "" else S["headers"][header or part] + "\n" + info.rstrip("\n")


def build_supplemental(
    c: DayContext,
    header: str,
    twilights: tuple[str, ...],
    period: str,
    with_period: bool,
    light: list[str] | None = None,
) -> tuple[str, str]:
    """A schedule of times in order, and the details of the period it leads to, under a header with
    the period's times. Each is "" when empty."""
    entries = light_entries(c["ev"], light or [])
    for key in twilights:
        span = c["spans"][key]
        if not c["enabled"][_TWILIGHT_BANDS[key]["flag"]] and span is not None:
            entries.append((span[0], format_schedule_span(S["schedule_names"][key], span, twilight_note(key, span))))

    details = ""
    span = c["spans"][period]
    if with_period and not c["enabled"]["daylight"] and span is not None:
        text = daylight_details(c) if period == "daylight" else night_details(c)
        details = S["headers"][f"{period}_details"].format(format_span_range(span)) + "\n" + text.rstrip("\n")
    entries.sort(key=lambda e: e[0])
    schedule = "" if not entries else S["headers"][header] + "\n" + "".join(e[1] for e in entries).rstrip("\n")
    return schedule, details
