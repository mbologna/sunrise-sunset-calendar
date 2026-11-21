"""iCalendar feed generation."""

from __future__ import annotations

import time
from collections.abc import Iterable
from dataclasses import dataclass, field

from . import cache
from .astronomy import get_accurate_moon_phase, moon_phases_between, moon_rise_set, sun_events
from .clock import BASIC_DATE, ICAL_UTC, ISO_DATE, LocalDate, format_local_time, format_utc_time, use_zone, zone
from .helpers import (
    DayContext,
    Span,
    build_dawn_supplemental,
    build_dusk_supplemental,
    build_twilight_schedule,
    day_fact_text,
    daylight_about,
    daylight_details,
    format_comparison,
    format_duration,
    format_light_block,
    format_local,
    format_percent,
    format_signed_duration,
    get_moon_phase_emoji,
    get_special_astronomical_events,
    get_week_summary,
    ical_text_property,
    location_facts,
    meteorological_fact,
    moon_phase_name,
    night_about,
    night_details,
    season_description,
    sun_section,
    twilight_about,
    twilight_bands,
    twilight_details,
    year_day_facts,
    year_summary,
)
from .numbers import format_coordinate, round_int
from .strings import STRINGS as S


@dataclass
class Options:
    """What a feed contains: location, offsets in seconds, and the subscribed event types."""

    lat: float
    lon: float
    timezone: str
    location: str = ""
    description: str = ""
    rise_offset: int = 0
    set_offset: int = 0
    enabled: dict[str, bool] = field(
        default_factory=lambda: {"civil": False, "nautical": False, "astro": False, "daylight": False}
    )
    past_days: int = 30
    window_days: int = 400
    refresh_seconds: int = 86400
    # 'YYYY-MM-DD'; today in the feed's time zone when None
    today: str | None = None
    # Seconds since the epoch for DTSTAMP and "today"; the current time when None
    now: int | None = None


def solar_spans(ev: dict, nxt: dict) -> dict[str, Span | None]:
    """Split one day into calendar periods, from morning astronomical twilight to night.

    When the sun never crosses one of a twilight's altitudes, solar noon or
    solar midnight stands in, so polar days and white nights yield
    continuous, well-ordered periods. Each period also names the instants
    that bound it: (start, end, start instant, end instant).
    """
    noon = ev["solar_noon"]
    # At a pole the year's one sunrise or sunset can come on the other side of noon: it bounds the
    # day's daylight, but not civil twilight, which keeps to its side
    sunrise = ev["sunrise"] if ev["sunrise"] is not None and ev["sunrise"] <= noon else None
    sunset = ev["sunset"] if ev["sunset"] is not None and ev["sunset"] >= noon else None
    crossings = (
        (-18.0, ev["astro_begin"], ev["astro_end"], "astronomical_dawn", "astronomical_dusk"),
        (-12.0, ev["nautical_begin"], ev["nautical_end"], "nautical_dawn", "nautical_dusk"),
        (-6.0, ev["civil_begin"], ev["civil_end"], "civil_dawn", "civil_dusk"),
        (0.0, sunrise, sunset, "sunrise", "sunset"),
    )

    spans: dict[str, Span | None] = {}
    for i, band in enumerate(("astronomical", "nautical", "civil")):
        low_alt, low_rise, low_set, low_dawn, low_dusk = crossings[i]
        high_alt, high_rise, high_set, high_dawn, high_dusk = crossings[i + 1]
        # A half-day that crosses neither altitude has the sun inside the band throughout, or never.
        # The halves are separate: the day a polar day or night starts or ends, only one crosses.
        in_band = ev["midnight_elevation"] > low_alt and ev["noon_elevation"] < high_alt
        if low_rise is None and high_rise is None:
            spans[f"morning_{band}"] = (noon - 43200, noon, "solar_midnight", "solar_noon") if in_band else None
        else:
            spans[f"morning_{band}"] = (
                low_rise if low_rise is not None else noon - 43200,
                high_rise if high_rise is not None else noon,
                "solar_midnight" if low_rise is None else low_dawn,
                "solar_noon" if high_rise is None else high_dawn,
            )
        if high_set is None and low_set is None:
            spans[f"evening_{band}"] = (noon, noon + 43200, "solar_noon", "solar_midnight") if in_band else None
        else:
            spans[f"evening_{band}"] = (
                high_set if high_set is not None else noon,
                low_set if low_set is not None else noon + 43200,
                "solar_noon" if high_set is None else high_dusk,
                "solar_midnight" if low_set is None else low_dusk,
            )

    daylight: Span | None = None
    if ev["sunrise"] is not None or ev["sunset"] is not None:
        # Without a sunrise (or a sunset) the sun is up from (or until) solar midnight
        daylight = (
            ev["sunrise"] if ev["sunrise"] is not None else noon - 43200,
            ev["sunset"] if ev["sunset"] is not None else noon + 43200,
            "sunrise" if ev["sunrise"] is not None else "solar_midnight",
            "sunset" if ev["sunset"] is not None else "solar_midnight",
        )
    elif ev["polar"] == "day":
        daylight = (noon - 43200, noon + 43200, "solar_midnight", "solar_midnight")

    # Night runs from astronomical dusk to the next astronomical dawn. On a day the sun stays
    # more than 18° below the horizon, solar noon stands in, so nights join up in polar night.
    def all_dark(e: dict) -> bool:
        return e["astro_begin"] is None and e["noon_elevation"] < -18

    night_start = ev["astro_end"] if ev["astro_end"] is not None else (noon if all_dark(ev) else None)
    night_end = nxt["astro_begin"] if nxt["astro_begin"] is not None else (nxt["solar_noon"] if all_dark(nxt) else None)
    night = (
        (
            night_start,
            night_end,
            "astronomical_dusk" if ev["astro_end"] is not None else "solar_noon",
            "astronomical_dawn" if nxt["astro_begin"] is not None else "solar_noon",
        )
        if night_start is not None and night_end is not None
        else None
    )
    spans["daylight"] = daylight
    spans["night"] = night
    return spans


def ical_duration(seconds: int) -> str:
    """An iCalendar duration, in hours when whole."""
    return f"PT{seconds // 3600}H" if seconds % 3600 == 0 else f"PT{seconds}S"


def ical_event(uid: str, dates: list[str], summary: str, description: str, dtstamp: str) -> str:
    """One VEVENT."""
    return (
        f"BEGIN:VEVENT\r\nUID:{uid}\r\nDTSTAMP:{dtstamp}\r\n"
        + "\r\n".join(dates)
        + "\r\n"
        + ical_text_property("SUMMARY", summary)
        + ical_text_property("DESCRIPTION", description)
        + "TRANSP:TRANSPARENT\r\nEND:VEVENT\r\n"
    )


def generate_calendar(o: Options) -> str:
    """The iCalendar feed: event times in UTC, descriptions in the feed's local time."""
    return render_feed(o).decode("utf-8", "surrogateescape")


def render_feed(o: Options) -> bytes:
    """generate_calendar() as UTF-8 bytes, built without holding the whole feed as text.

    Python stores text with emoji at four bytes a character, so each event
    is encoded as soon as it is written: a year's feed then takes a quarter
    of the memory.
    """
    cache.reset()
    now = int(time.time()) if o.now is None else o.now
    with use_zone(o.timezone):
        return _render(o, now)


def _render(o: Options, now: int) -> bytes:
    tz = zone(o.timezone)
    lat = o.lat
    lon = o.lon
    enabled = o.enabled
    uid_suffix = f"{format_coordinate(lat)}-{format_coordinate(lon)}@sun-calendar"
    dtstamp = format_utc_time(now, ICAL_UTC)
    refresh = ical_duration(o.refresh_seconds)

    def timed(start: int, end: int) -> list[str]:
        return ["DTSTART:" + format_utc_time(start, ICAL_UTC), "DTEND:" + format_utc_time(end, ICAL_UTC)]

    def all_day(day: LocalDate, days: int = 1) -> list[str]:
        return [
            "DTSTART;VALUE=DATE:" + day.format(BASIC_DATE),
            "DTEND;VALUE=DATE:" + day.add_days(days).format(BASIC_DATE),
        ]

    # Offsets move an event away from the instants its description names; say so
    def offset_note(start: int, end: int) -> str:
        if start == 0 and end == 0:
            return ""
        return "\n\n" + S["labels"]["offset_note"].format(format_signed_duration(start), format_signed_duration(end))

    custom_note = "\n\n" + o.description if o.description != "" else ""

    today = LocalDate.parse(o.today, tz) if o.today is not None else LocalDate.today(tz, now)
    today_year = year_summary(lat, lon, today.year, today.set_time(12, 0).offset() / 3600)
    place = (S["place_named"] if o.location != "" else S["place_coordinates"]).format(
        o.location, format_coordinate(lat), format_coordinate(lon)
    )
    calendar_description = " ".join(
        [S["calendar_description_format"].format(place, o.timezone), *location_facts(today_year)]
    )
    name = o.location if o.location != "" else f"{format_coordinate(lat)}, {format_coordinate(lon)}"

    out = _Output()
    out.extend(
        [
            f"BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:{S['calendar_prodid']}\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\n",
            ical_text_property("X-WR-CALNAME", S["calendar_name_format"].format(name)),
            ical_text_property("X-WR-CALDESC", calendar_description),
            f"X-WR-TIMEZONE:{o.timezone}\r\n",
            f"X-PUBLISHED-TTL:{refresh}\r\nREFRESH-INTERVAL;VALUE=DURATION:{refresh}\r\n",
        ]
    )

    day_facts: dict[int, dict] = {}
    first = today.add_days(-o.past_days)
    last = today.add_days(o.window_days)

    # Equinoxes and solstices by local date, for every year the window touches
    special_events: dict[str, dict] = {}
    for y in range(first.year, last.year + 1):
        for event in get_special_astronomical_events(y, lat):
            special_events[format_local_time(event["date"], ISO_DATE)] = event

    bands = twilight_bands()
    no_twilight_events = not (enabled["civil"] or enabled["nautical"] or enabled["astro"])

    day = first
    while day.ts <= last.ts:
        year = day.year
        date_str = day.format(BASIC_DATE)
        ev = sun_events(day, lat, lon)
        prev = sun_events(day.add_days(-1), lat, lon)
        nxt = sun_events(day.add_days(1), lat, lon)
        spans = solar_spans(ev, nxt)
        year_info = year_summary(lat, lon, year, ev["utc_offset"])

        daylight_seconds = round_int(ev["daylength_h"] * 3600)

        # Clock time of day, so a sunrise that moves with a DST change reads as it does on the clock
        def clock(ts: int) -> int:
            return ts - LocalDate.from_timestamp(ts, tz).set_time(0, 0).ts

        def shift(today: int, yesterday: int) -> int:
            """How far a clock time moved, the short way round midnight: 23:44 to 00:12 is 28 minutes later."""
            return (clock(today) - clock(yesterday) + 43200) % 86400 - 43200

        clock_shifts: tuple[int, int] | tuple[None, None]
        if ev["sunrise"] and ev["sunset"] and prev["sunrise"] and prev["sunset"]:
            clock_shifts = (shift(ev["sunrise"], prev["sunrise"]), shift(ev["sunset"], prev["sunset"]))
        else:
            clock_shifts = (None, None)

        night_seconds = spans["night"][1] - spans["night"][0] if spans["night"] else 0
        last_night_span = solar_spans(prev, ev)["night"]
        last_night = last_night_span[1] - last_night_span[0] if last_night_span else None
        moon = get_accurate_moon_phase(ev["solar_noon"] + 43200) | {"lat": lat}
        if spans["night"] is not None:
            moon["night"] = [spans["night"][0], spans["night"][1]]
            moon["rise_set"] = moon_rise_set(spans["night"][0], spans["night"][1], lat, lon)

        # The twilight nearest to daylight and to night carry those periods' blocks
        # when they aren't events of their own
        def nearest(keys: tuple[str, ...], spans: dict[str, Span | None] = spans) -> str | None:
            return next((k for k in keys if enabled[bands[k]["flag"]] and spans[k] is not None), None)

        c: DayContext = {
            "ev": ev,
            "spans": spans,
            "enabled": enabled,
            "year": year_info,
            "carriers": {
                "morning": nearest(("morning_civil", "morning_nautical", "morning_astronomical")),
                "evening": nearest(("evening_astronomical", "evening_nautical", "evening_civil")),
                # Sunset goes on the evening twilight nearest it, as sunrise goes on the morning carrier
                "sunset": nearest(("evening_civil", "evening_nautical", "evening_astronomical")),
            },
            # Tomorrow morning's twilights go on tonight's Night when no twilight is an event
            "spans_tomorrow": solar_spans(nxt, sun_events(day.add_days(2), lat, lon))
            if enabled["daylight"] and no_twilight_events
            else None,
            "daylight": {
                "seconds": daylight_seconds,
                "change": daylight_seconds - round_int(prev["daylength_h"] * 3600),
                "sunrise_shift": clock_shifts[0],
                "sunset_shift": clock_shifts[1],
            },
            "night": {
                "seconds": night_seconds,
                "comparison": format_comparison(night_seconds - last_night, S["comparisons"]["last_night"], last_night)
                if spans["night"] and last_night is not None
                else None,
                "moon": moon,
            },
        }

        # Weekly summary: an all-day event spanning Sunday through Saturday, in the sections the
        # other events use: the sun's times, the daylight, and the moon's principal phases
        if enabled["daylight"] and day.wall.weekday() == 6:  # Sunday
            week = get_week_summary(day.ts, lat, lon, ev["utc_offset"])
            texts = S["week"]
            phases = moon_phases_between(day.ts, day.add_days(7).ts)
            if phases:
                emoji = get_moon_phase_emoji(phases[0]["name"], lat)
                moon_lines = [
                    S["moon"]["phase_at"].format(moon_phase_name(p["name"]), format_local(p["timestamp"], "datetime"))
                    for p in phases
                ]
            else:
                phase_name = get_accurate_moon_phase(day.ts)["phase_name"]
                emoji = get_moon_phase_emoji(phase_name, lat)
                moon_lines = [S["moon"]["phase_now"].format(moon_phase_name(phase_name))]

            sections = [texts["range"]]
            # Clock times people notice: in December sunsets stop getting earlier while sunrises keep getting later
            saturday = sun_events(day.add_days(6), lat, lon)
            if None not in (ev["sunrise"], ev["sunset"], saturday["sunrise"], saturday["sunset"]):
                sections.append(
                    S["headers"]["sun"]
                    + "\n"
                    + texts["sunrise"].format(format_local(ev["sunrise"]), format_local(saturday["sunrise"]))
                    + "\n"
                    + texts["sunset"].format(format_local(ev["sunset"]), format_local(saturday["sunset"]))
                )
            start_length, end_length = round_int(week["start_length"]), round_int(week["end_length"])
            lengths = texts["daylight"].format(format_duration(start_length), format_duration(end_length))
            percent = format_percent(end_length - start_length, start_length)
            if percent is not None:
                lengths = S["comparisons"]["percent"].format(lengths, percent)
            sections.append(S["headers"]["daylight_plain"] + "\n" + lengths)
            sections.append(S["headers"]["moon"].format(emoji) + "\n" + "\n".join(moon_lines))
            desc = "\n\n".join(sections)

            summary = (S["week_titles"][week["trend"]]).format(format_duration(round_int(week["total_change"])))
            out.append(ical_event(f"week-{date_str}-{uid_suffix}", all_day(day, 7), summary, desc, dtstamp))

        # Days when something changes: all day, so they show without opening anything
        if year not in day_facts:
            day_facts[year] = year_day_facts(year_info)
        for fact in day_facts[year].get(day.format(ISO_DATE), []):
            title, text = day_fact_text(fact, ev, year_info)
            slug = fact["kind"].replace("_", "-")
            out.append(ical_event(f"{slug}-{date_str}-{uid_suffix}", all_day(day), title, text, dtstamp))
        meteorological = meteorological_fact(day, lat)
        if meteorological is not None:
            season, title, text = meteorological
            uid = f"meteorological-{season}-{date_str}-{uid_suffix}"
            out.append(ical_event(uid, all_day(day), title, text, dtstamp))

        # Equinoxes and solstices: all day on the local date, exact moment in the description
        special = special_events.get(day.format(ISO_DATE))
        if special:
            out.append(
                ical_event(
                    special["season"].replace("_", "-") + f"-{date_str}-{uid_suffix}",
                    all_day(day),
                    S["seasons"][special["season"]]["title"],
                    season_description(special, ev, year_info, lat),
                    dtstamp,
                )
            )

        # Twilights
        for key, band in bands.items():
            span = spans[key]
            if not enabled[band["flag"]] or span is None:
                continue
            offset = o.rise_offset if band["morning"] else o.set_offset

            # Data first, then the schedule, the sun, the period it leads to, and the explanation.
            # Without a Daylight event, the twilight nearest sunrise or sunset says how that moved.
            schedule, period = build_dawn_supplemental(c, key) if band["morning"] else build_dusk_supplemental(c, key)
            sun = ""
            if not enabled["daylight"]:
                if key == c["carriers"]["morning"]:
                    sun = sun_section(c, "morning")
                elif key == c["carriers"]["sunset"]:
                    sun = sun_section(c, "evening")
            parts = [twilight_details(key, span, ev), schedule, sun, period, twilight_about(key, span, spans)]
            desc = "\n\n".join(part for part in parts if part) + offset_note(offset, offset)

            out.append(
                ical_event(
                    f"{band['uid']}-{date_str}-{uid_suffix}",
                    timed(span[0] + offset, span[1] + offset),
                    S["summaries"][key],
                    desc,
                    dtstamp,
                )
            )

        # Daylight
        if enabled["daylight"] and spans["daylight"] is not None:
            start, end = spans["daylight"][0], spans["daylight"][1]
            # This evening's twilights follow Daylight; tomorrow morning's too when no Night comes between
            parts = [
                daylight_details(c).rstrip(),
                sun_section(c, "day"),
                format_light_block(ev),
                build_twilight_schedule(c, "this_evening"),
                build_twilight_schedule(c, "tomorrow_morning") if spans["night"] is None else "",
                daylight_about(ev),
            ]
            desc = "\n\n".join(part for part in parts if part) + offset_note(o.rise_offset, o.set_offset) + custom_note
            out.append(
                ical_event(
                    f"daylight-{date_str}-{uid_suffix}",
                    timed(start + o.rise_offset, end + o.set_offset),
                    S["summaries"]["daylight"].format(format_duration(daylight_seconds)),
                    desc,
                    dtstamp,
                )
            )

        # Night
        if enabled["daylight"] and spans["night"] is not None:
            start, end = spans["night"][0], spans["night"][1]
            # Tomorrow morning's twilights follow the night; this evening's go here only when
            # there is no Daylight event today to carry them (polar night)
            earlier = build_twilight_schedule(c, "this_evening", "earlier_today") if spans["daylight"] is None else ""
            parts = [night_details(c).rstrip(), earlier, build_twilight_schedule(c, "tomorrow_morning"), night_about()]
            desc = "\n\n".join(part for part in parts if part) + offset_note(o.set_offset, o.rise_offset) + custom_note
            out.append(
                ical_event(
                    f"night-{date_str}-{uid_suffix}",
                    timed(start + o.set_offset, end + o.rise_offset),
                    S["summaries"]["night"].format(
                        get_moon_phase_emoji(moon["phase_name"], lat), format_duration(night_seconds)
                    ),
                    desc,
                    dtstamp,
                )
            )

        day = day.add_days(1)

    out.append("END:VCALENDAR\r\n")
    return b"".join(out.parts)


class _Output:
    """Feed parts, each stored as UTF-8 bytes."""

    def __init__(self) -> None:
        self.parts: list[bytes] = []

    def append(self, text: str) -> None:
        self.parts.append(text.encode("utf-8", "surrogateescape"))

    def extend(self, texts: Iterable[str]) -> None:
        for text in texts:
            self.append(text)
