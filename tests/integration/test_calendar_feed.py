"""End-to-end tests of generate_calendar() output."""

from __future__ import annotations

import dataclasses
import re
from datetime import UTC, datetime, timedelta
from typing import Any

import pytest

from sunrise_sunset_calendar import clock
from sunrise_sunset_calendar.feed import Options, generate_calendar
from sunrise_sunset_calendar.helpers import format_duration, year_summary
from sunrise_sunset_calendar.numbers import round_int
from tests.helpers import date_sun_info_sunrise, ical_timestamp

ALL_TYPES = {"civil": True, "nautical": True, "astro": True, "daylight": True}

DEFAULTS = Options(
    lat=41.9028,
    lon=12.4964,
    timezone="Europe/Rome",
    location="Rome",
    description="",
    rise_offset=0,
    set_offset=0,
    enabled=ALL_TYPES,
    past_days=0,
    window_days=365,
    refresh_seconds=86400,
    today="2026-10-15",
)

# The whitespace trimmed around event text
_TRIM = " \t\n\r\0\x0b"


def feed(**overrides: Any) -> str:
    """The feed for the default options, with overrides replacing them."""
    return generate_calendar(dataclasses.replace(DEFAULTS, **overrides))


def events(ics: str) -> list[dict[str, str]]:
    """Parse VEVENTs into property maps (unfolded, keyed by name without parameters)."""
    unfolded = ics.replace("\r\n ", "")
    blocks = re.findall(r"BEGIN:VEVENT\r\n(.*?)END:VEVENT", unfolded, re.S)

    parsed = []
    for block in blocks:
        props = {}
        for line in block.strip(_TRIM).split("\r\n"):
            name, value = line.split(":", 1)
            props[name.split(";")[0]] = value
        parsed.append(props)
    return parsed


def events_of_type(ics: str, prefix: str) -> list[dict[str, str]]:
    """Events whose UID starts with the prefix."""
    return [e for e in events(ics) if e["UID"].startswith(prefix + "-")]


def text(event: dict[str, str]) -> str:
    """An event's description as plain text, with the iCalendar escapes undone."""
    result = event["DESCRIPTION"]
    for escaped, plain in (("\\n", "\n"), ("\\,", ","), ("\\;", ";"), ("\\\\", "\\")):
        result = result.replace(escaped, plain)
    return result


def is_valid_utf8(line: str) -> bool:
    """mb_check_encoding($line, 'UTF-8'): no undecodable bytes (surrogate escapes) in the line."""
    try:
        line.encode("utf-8")
    except UnicodeEncodeError:
        return False
    return True


def local_time(value: str, zone: str, fmt: str) -> str:
    """An iCalendar UTC date-time in a time zone, through a date template."""
    return clock.format_datetime(datetime.fromtimestamp(ical_timestamp(value), clock.zone(zone)), fmt)


def test_feed_is_well_formed() -> None:
    ics = feed(location="Città di Roma, Lazio; Italia")

    assert ics.startswith("BEGIN:VCALENDAR\r\n")
    assert ics.endswith("END:VCALENDAR\r\n")
    assert re.search(r"(?<!\r)\n", ics) is None, "Every line ends with CRLF"

    for line in ics.split("\r\n"):
        assert len(line.encode("utf-8", "surrogateescape")) <= 75, f"Line exceeds 75 octets: {line}"
        assert is_valid_utf8(line), "Folding must not split UTF-8 characters"

    parsed = events(ics)
    assert len(parsed) == ics.count("BEGIN:VEVENT")
    uids = [e["UID"] for e in parsed]
    assert len(set(uids)) == len(uids), "UIDs are unique"


def test_calendar_name_is_escaped() -> None:
    ics = feed(location="Foo, Bar; Baz").replace("\r\n ", "")

    assert r"X-WR-CALNAME:🌅 Foo\, Bar\; Baz · Sun & Twilight" in ics


def test_every_event_ends_after_it_starts() -> None:
    for lat, lon, zone in [
        (41.9028, 12.4964, "Europe/Rome"),
        (69.65, 18.96, "Europe/Oslo"),
        (-77.85, 166.67, "Antarctica/McMurdo"),
    ]:
        ics = feed(lat=lat, lon=lon, timezone=zone)
        for event in events(ics):
            assert event["DTEND"] > event["DTSTART"], f"{zone}: {event['UID']}"


@pytest.mark.parametrize(
    ("lat", "lon", "zone"),
    [
        pytest.param(41.9028, 12.4964, "Europe/Rome", id="Rome"),
        pytest.param(40.7128, -74.0060, "America/New_York", id="New York"),
        pytest.param(-33.8688, 151.2093, "Australia/Sydney", id="Sydney"),
    ],
)
def test_daylight_starts_at_sunrise_across_dst_changes(lat: float, lon: float, zone: str) -> None:
    """Sunrise must track the zone's real UTC offset on each date, not the offset
    on the day the feed was generated. Cross-check against an independent solar
    model (Paul Schlyter's sunriset, in tests/helpers.py).
    """
    ics = feed(lat=lat, lon=lon, timezone=zone)
    tz = clock.zone(zone)

    daylight = events_of_type(ics, "daylight")
    assert len(daylight) == 366

    for event in daylight:
        ymd = event["UID"][9:17]
        date = datetime(int(ymd[:4]), int(ymd[4:6]), int(ymd[6:8]), 12, 0, tzinfo=tz)
        expected = date_sun_info_sunrise(int(date.timestamp()), lat, lon)
        actual = ical_timestamp(event["DTSTART"])

        assert abs(expected - actual) <= 120, f"Sunrise on {date:%Y-%m-%d} in {zone}"


def test_equinoxes_and_solstices_for_every_year_in_window() -> None:
    ics = feed(past_days=280, window_days=450)

    special = [
        e["DTSTART"] + " " + e["SUMMARY"]
        for e in events(ics)
        if re.search(r"^(spring|autumn)-equinox-|^(summer|winter)-solstice-", e["UID"])
    ]

    assert special == [
        "20260320 🌱 Spring Equinox",
        "20260621 ☀️ Summer Solstice",
        "20260923 🍂 Autumn Equinox",
        "20261221 ❄️ Winter Solstice",
        "20270320 🌱 Spring Equinox",
        "20270621 ☀️ Summer Solstice",
        "20270923 🍂 Autumn Equinox",
        "20271222 ❄️ Winter Solstice",
    ]


def test_polar_night_has_midday_twilight_and_no_daylight() -> None:
    ics = feed(lat=69.65, lon=18.96, timezone="Europe/Oslo", today="2026-12-21", window_days=0)

    assert events_of_type(ics, "daylight") == []

    # The sun peaks inside civil twilight, so morning twilight runs up to solar noon and evening starts there
    dawn = events_of_type(ics, "morning-civil-twilight")[0]
    dusk = events_of_type(ics, "evening-civil-twilight")[0]
    assert dawn["DTEND"] == dusk["DTSTART"]
    assert (
        r"Polar night: today the sun climbs from 6° below the horizon to 3° below at solar noon\, its highest today."
        in dawn["DESCRIPTION"]
    )
    assert "to sunrise" not in dawn["DESCRIPTION"]
    assert "Astro → Nautical → [Civil] → Noon\\n" in dawn["DESCRIPTION"]
    assert (
        r"Polar night: today the sun sinks from 3° below the horizon at solar noon\, its highest today\, to 6° below."
        in dusk["DESCRIPTION"]
    )
    assert "Noon → [Civil] → Nautical → Astro\\n" in dusk["DESCRIPTION"]

    solstice = events_of_type(ics, "winter-solstice")[0]
    assert "The sun stays 3° below the horizon even at noon." in solstice["DESCRIPTION"]


def test_midnight_sun_has_all_day_daylight_and_no_twilight() -> None:
    ics = feed(lat=69.65, lon=18.96, timezone="Europe/Oslo", today="2027-06-21", window_days=0)

    daylight = events_of_type(ics, "daylight")[0]
    length = ical_timestamp(daylight["DTEND"]) - ical_timestamp(daylight["DTSTART"])
    assert length == 86400
    assert daylight["DESCRIPTION"].startswith("Midnight sun: the sun does not set today.")
    assert "of the year" not in daylight["DESCRIPTION"], "No rank among tied 24-hour days"

    types = [
        "morning-civil-twilight",
        "morning-nautical-twilight",
        "morning-astronomical-twilight",
        "evening-civil-twilight",
        "evening-nautical-twilight",
        "evening-astronomical-twilight",
        "night",
    ]
    for kind in types:
        assert events_of_type(ics, kind) == [], f"No {kind} under the midnight sun"


def test_white_night_twilight_runs_through_solar_midnight() -> None:
    # Stockholm in June: the sun dips below the horizon but never below -12°
    ics = feed(lat=59.33, lon=18.07, timezone="Europe/Stockholm", today="2027-06-21", window_days=1)

    dusk = events_of_type(ics, "evening-nautical-twilight")[0]
    next_dawn = events_of_type(ics, "morning-nautical-twilight")[1]
    assert abs(ical_timestamp(dusk["DTEND"]) - ical_timestamp(next_dawn["DTSTART"])) <= 120
    assert events_of_type(ics, "evening-astronomical-twilight") == []
    assert events_of_type(ics, "night") == []

    # The sequence stops at the twilight; the data line says the sun turns at solar midnight
    assert "Sunset → Civil → [Nautical]\\n" in dusk["DESCRIPTION"]
    assert (
        r"Today the sun sinks from 6° below the horizon to 7° below at solar midnight\, its lowest tonight."
        in dusk["DESCRIPTION"]
    )
    assert "from 6° to 12° below" not in dusk["DESCRIPTION"]
    assert "Today the sun climbs from 7° below the horizon at solar midnight" in next_dawn["DESCRIPTION"]
    assert "the sun never gets 18° below the horizon" in ics.replace("\r\n ", "")


def test_southern_hemisphere_shortest_day_is_in_june() -> None:
    ics = feed(lat=-33.8688, lon=151.2093, timezone="Australia/Sydney", window_days=0)

    daylight = text(events_of_type(ics, "daylight")[0])
    assert re.search(r"^vs shortest \(Jun 2\d\): \+.+\nvs longest \(Dec 2\d\): −", daylight, re.M)


def test_offsets_shift_morning_and_evening_events() -> None:
    base = events_of_type(feed(window_days=0), "daylight")[0]
    shifted = events_of_type(feed(window_days=0, rise_offset=-600, set_offset=900), "daylight")[0]

    assert ical_timestamp(shifted["DTSTART"]) - ical_timestamp(base["DTSTART"]) == -600
    assert ical_timestamp(shifted["DTEND"]) - ical_timestamp(base["DTEND"]) == 900


def test_night_duration_matches_event_span() -> None:
    night = events_of_type(feed(window_days=0), "night")[0]
    minutes = round_int((ical_timestamp(night["DTEND"]) - ical_timestamp(night["DTSTART"])) / 60)

    assert " Night · " + format_duration(minutes * 60) in night["SUMMARY"]


def test_supplemental_schedule_lists_only_disabled_types() -> None:
    ics = feed(window_days=0, enabled={"civil": True, "nautical": False, "astro": False, "daylight": False})
    dawn = text(events_of_type(ics, "morning-civil-twilight")[0])

    # Time first, in order, each twilight with a note, then the daylight under its own header
    assert re.search(
        r"^\d\d:\d\d–\d\d:\d\d 🌌 Astronomical\n\u00a0\u00a0Full dark gives way to a faint glow$", dawn, re.M
    )
    assert re.search(r"^\d\d:\d\d–\d\d:\d\d ⚓ Nautical\n\u00a0\u00a0Horizon appears as stars fade$", dawn, re.M)
    assert re.search(
        r"\n\n🔆 Daylight \d\d:\d\d–\d\d:\d\d \(\d+h \d\dm\)\nvs yesterday: −\d+m \d\ds \(−0\.\d%\)\nYear: ", dawn
    )
    assert not re.search(r"^\S+ 🌅 Civil$", dawn, re.M), "The event itself is not in its schedule"
    assert events_of_type(ics, "daylight") == []


def test_daylight_only_calendar_still_lists_every_twilight() -> None:
    ics = feed(window_days=1, enabled={"civil": False, "nautical": False, "astro": False, "daylight": True})
    daylight_event = events_of_type(ics, "daylight")[0]
    night_event = events_of_type(ics, "night")[0]
    daylight = text(daylight_event)
    night = text(night_event)

    # This evening's twilights follow Daylight, which ends at sunset: civil twilight runs to last light
    assert re.search(
        r"🕒 This evening\n\S+ 🌇 Civil\n\u00a0\u00a0No lamps needed until last light\n\S+ ⚓ Nautical\n.+\n"
        r"\S+ 🌌 Astronomical\n",
        daylight,
    )
    assert "🌅 Civil" not in daylight, "Morning twilights are over before Daylight starts"

    # Tomorrow morning's twilights follow the Night, which ends where they start
    morning = re.search(r"🕒 Tomorrow morning\n(\d\d:\d\d)–\d\d:\d\d 🌌 Astronomical", night)
    assert morning is not None
    ends = local_time(night_event["DTEND"], "Europe/Rome", "{hour:02d}:{minute:02d}")
    assert morning[1] == ends
    assert "No lamps needed from first light" in night
    assert "🌇 Civil" not in night

    # With civil twilight subscribed, its events carry the rest instead
    daylight_event = events_of_type(
        feed(window_days=0, enabled={"civil": True, "nautical": False, "astro": False, "daylight": True}), "daylight"
    )[0]
    assert "🕒 This evening" not in text(daylight_event)


def test_polar_night_carries_twilights_on_the_night() -> None:
    ics = feed(
        lat=69.65,
        lon=18.96,
        timezone="Europe/Oslo",
        today="2026-12-21",
        window_days=0,
        enabled={"civil": False, "nautical": False, "astro": False, "daylight": True},
    )
    night = text(events_of_type(ics, "night")[0])

    # No Daylight event: the Night carries the day's evening twilights, then tomorrow morning's
    assert re.search(r"🕒 Earlier today\n\S+ 🌇 Civil\n.+🕒 Tomorrow morning\n.+ 🌅 Civil\n", night, re.S)


def test_week_summary_on_sundays_only() -> None:
    summaries = events_of_type(feed(window_days=13), "week")

    assert len(summaries) == 2
    for summary in summaries:
        # All-day from Sunday through Saturday: DTEND is the following Sunday
        start = datetime.fromtimestamp(ical_timestamp(summary["DTSTART"]), UTC)
        assert start.weekday() == 6, "Weeks start on Sunday"
        assert clock.format_datetime(start + timedelta(days=7), clock.BASIC_DATE) == summary["DTEND"]
    assert re.search(r"^📉 \d+m less daylight this week$", summaries[0]["SUMMARY"]), "Days shorten in October"
    # The sections the other events use: the sun, the daylight with its change, the moon
    assert re.search(
        r"^Sunday → Saturday\n\n☀️ Sun\nSunrise: \d\d:\d\d → \d\d:\d\d\nSunset: \d\d:\d\d → \d\d:\d\d\n\n"
        r"🔆 Daylight\n\d+h \d\dm → \d+h \d\dm \(−\d\.\d%\)\n\n\S+ Moon\n",
        text(summaries[0]),
    )


def test_descriptions_dont_repeat_the_event_times_or_name_instants() -> None:
    timed = [e for e in events(feed(window_days=6)) if "T" in e["DTSTART"]]

    assert timed
    for event in timed:
        assert not re.search(r"\bFrom \d", event["DESCRIPTION"]), event["UID"]
        assert not re.search(r"\b(civil|nautical|astronomical) (dawn|dusk)\b", event["DESCRIPTION"], re.I), event["UID"]


def test_daylight_shows_change_and_place_in_the_year() -> None:
    description = text(events_of_type(feed(today="2026-11-15", window_days=0), "daylight")[0])

    # What changed since yesterday and where the day sits in the year, then what the sun does
    assert description.startswith(
        "vs yesterday: −2m 07s (−0.4%)\n"
        "Year: 9h 08m ▕█░░░░░░░░░▏ 15h 14m\n"
        "Longer than 72 of 365 days (20%)\n"
        "vs shortest (Dec 21): +42m (+7.7%)\n"
        "vs longest (Jun 21): −5h 24m (−35%)\n\n"
        "☀️ Sun\n"
        "Sunrise: 1m 13s later than yesterday\n"
        "Highest at 11:54, 30° up\n"
        "Sunset: 53s earlier than yesterday\n\n"
    )
    assert "📅" not in description


def test_civil_twilight_runs_from_first_light_and_to_last_light() -> None:
    """First and last light as weather apps use the terms: the start of morning and the end of evening
    civil twilight, with the sun 6° below the horizon."""
    ics = feed(today="2026-11-15", window_days=0)

    # Every twilight event opens with how it changes the sky; the civil ones name first and last light
    firsts = {
        "morning-astronomical-twilight": "Full dark gives way to a faint glow",
        "morning-nautical-twilight": "Horizon appears as stars fade",
        "morning-civil-twilight": "No lamps needed from first light",
        "evening-civil-twilight": "No lamps needed until last light",
        "evening-nautical-twilight": "Horizon fades as more stars appear",
        "evening-astronomical-twilight": "The last glow fades to full dark",
    }
    for kind, line in firsts.items():
        assert text(events_of_type(ics, kind)[0]).startswith(line + "\n"), kind
    assert "first light" not in text(events_of_type(ics, "daylight")[0]).lower()
    assert "Weather apps call its start first light." in text(events_of_type(ics, "morning-civil-twilight")[0])
    assert "first light" not in text(events_of_type(ics, "morning-nautical-twilight")[0]).lower()

    # In a schedule, the civil twilight that starts at first light and the one that ends at last light
    first = local_time(
        events_of_type(ics, "morning-civil-twilight")[0]["DTSTART"], "Europe/Rome", "{hour:02d}:{minute:02d}"
    )
    last = local_time(
        events_of_type(ics, "evening-civil-twilight")[0]["DTEND"], "Europe/Rome", "{hour:02d}:{minute:02d}"
    )
    nautical = feed(
        today="2026-11-15", window_days=0, enabled={"civil": False, "nautical": True, "astro": False, "daylight": False}
    )
    dawn = text(events_of_type(nautical, "morning-nautical-twilight")[0])
    assert re.search(rf"^{first}–\d\d:\d\d 🌅 Civil\n\u00a0\u00a0No lamps needed from first light$", dawn, re.M)
    daylight_only = feed(
        today="2026-11-15", window_days=0, enabled={"civil": False, "nautical": False, "astro": False, "daylight": True}
    )
    evening = text(events_of_type(daylight_only, "daylight")[0])
    assert re.search(rf"^\d\d:\d\d–{last} 🌇 Civil\n\u00a0\u00a0No lamps needed until last light$", evening, re.M)


def test_first_and_last_light_without_astronomical_night() -> None:
    """London in June never gets 18° dark, but civil twilight still starts and ends 6° below."""
    ics = feed(lat=51.5074, lon=-0.1278, timezone="Europe/London", today="2026-06-21", window_days=0)
    assert text(events_of_type(ics, "morning-civil-twilight")[0]).startswith("No lamps needed from first light")
    assert text(events_of_type(ics, "evening-civil-twilight")[0]).startswith("No lamps needed until last light")


def test_no_first_or_last_light_when_the_sun_stays_above_6_below() -> None:
    """Murmansk in June: the sun dips below the horizon but not 6° down, so civil twilight runs through
    solar midnight without a first or last light."""
    ics = feed(lat=68.9585, lon=33.0827, timezone="Europe/Moscow", today="2026-07-25", window_days=1)
    dusk = text(events_of_type(ics, "evening-civil-twilight")[0])
    dawn = text(events_of_type(ics, "morning-civil-twilight")[1])
    assert dusk.startswith("Enough light without lamps\n")
    assert dawn.startswith("Enough light without lamps\n")


def test_no_first_or_last_light_under_the_midnight_sun() -> None:
    ics = feed(lat=78.2232, lon=15.6267, timezone="Arctic/Longyearbyen", today="2026-06-21", window_days=0)
    daylight = text(events_of_type(ics, "daylight")[0])
    assert "First light" not in daylight and "Last light" not in daylight


def test_twilights_leave_their_length_to_the_time_slot() -> None:
    """The calendar shows when a twilight starts and ends: no length line, and no lengths in schedules."""
    for enabled in (ALL_TYPES, {"civil": True, "nautical": False, "astro": False, "daylight": False}):
        for event in events(feed(today="2026-11-15", window_days=0, enabled=enabled)):
            if "twilight" in event["UID"]:
                description = text(event)
                assert "Length" not in description, event["UID"]
                assert not re.search(r"^\d\d:\d\d–\d\d:\d\d .+ \(\d+(h \d\d)?m\)$", description, re.M), event["UID"]


def test_calendar_description_has_location_facts() -> None:
    ics = feed(window_days=0).replace("\r\n ", "")

    assert re.search(
        r"X-WR-CALDESC:Sunrise\\, sunset and twilight for Rome \(41\.9028\\, 12\.4964\)\\, times in Europe/Rome\..*"
        r"Daylight here ranges from 9h 0\dm \(Dec 2\d\) to 15h 1\dm \(Jun 2\d\) over the year\.",
        ics,
    )


def test_southern_hemisphere_season_names() -> None:
    # The March equinox falls on Mar 21 in Sydney's time zone
    ics = feed(lat=-33.8688, lon=151.2093, timezone="Australia/Sydney", today="2027-03-21", window_days=0)

    equinox = events_of_type(ics, "autumn-equinox")[0]
    assert equinox["SUMMARY"] == "🍂 Autumn Equinox"
    assert equinox["DESCRIPTION"].startswith("The March equinox is at 07:24.")
    assert "Astronomical autumn begins." in equinox["DESCRIPTION"]


def test_season_uids_depend_on_location() -> None:
    def uid(lat: float, lon: float) -> str:
        return events_of_type(feed(lat=lat, lon=lon, today="2026-06-21", window_days=0), "summer-solstice")[0]["UID"]

    assert uid(41.9028, 12.4964) != uid(45.4642, 9.19)


def test_tropics_skip_solstice_claims_that_do_not_hold() -> None:
    quito = {"lat": -0.1807, "lon": -78.4678, "timezone": "America/Guayaquil", "window_days": 0}

    # Day length varies by seconds here, and the year's shortest day is not the solstice
    june = events_of_type(feed(**quito, today="2027-06-21"), "winter-solstice")[0]
    assert "shortest day" not in june["DESCRIPTION"]

    # Inside the tropics the noon sun is highest when it passes overhead, not at the solstice
    december = events_of_type(feed(**quito, today="2026-12-21"), "summer-solstice")[0]
    assert "In the tropics the sun is highest on two other days" in december["DESCRIPTION"]
    assert "at its highest of the year" not in december["DESCRIPTION"]


def test_mid_latitude_solstice_claims() -> None:
    solstice = text(events_of_type(feed(today="2026-12-21", window_days=0), "winter-solstice")[0])

    assert "☀️ Sun\nAt noon: 25° up, lowest of the year\n\n" in solstice
    assert solstice.endswith("🔆 Daylight\nThe shortest day: 9h 08m\nvs longest (Jun 21): −6h 06m (−40%)")
    # Facts about other days go on those days; the one other date is the extreme it's compared with
    assert re.findall(r"\b(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) \d+", solstice) == ["Jun 21"]


def test_sunrise_and_sunset_turning_points_get_their_own_days() -> None:
    # Bergamo: sunsets turn later on Dec 10, sunrises turn earlier on Jan 2
    ics = feed(
        lat=45.6894,
        lon=9.5694,
        today="2026-11-30",
        window_days=40,
        enabled={"civil": True, "nautical": False, "astro": False, "daylight": False},
    )

    sunset = events_of_type(ics, "sunset-earliest")[0]
    assert sunset["DTSTART"] == "20261210", "All day, on its own date"
    assert sunset["SUMMARY"] == "🌇 Sunsets start getting later"
    assert re.search(r"^Sunset at 16:3\d is the earliest it gets for months", sunset["DESCRIPTION"])
    assert "The days still get shorter for a while" in sunset["DESCRIPTION"]

    sunrise = events_of_type(ics, "sunrise-latest")[0]
    assert sunrise["DTSTART"] == "20270102"
    assert "The days are already getting longer" in sunrise["DESCRIPTION"]

    winter = events_of_type(ics, "meteorological-winter")[0]
    assert winter["DTSTART"] == "20261201"
    assert winter["SUMMARY"] == "🌡️ Meteorological winter begins"


def test_twelve_hours_of_daylight_near_the_equinoxes() -> None:
    ics = feed(
        today="2027-03-01",
        window_days=220,
        enabled={"civil": False, "nautical": False, "astro": False, "daylight": False},
    )

    spring = events_of_type(ics, "twelve-hours-rising")[0]
    autumn = events_of_type(ics, "twelve-hours-falling")[0]
    assert re.search(r"^202703(1\d)$", spring["DTSTART"]), "Before the Mar 20 equinox"
    assert re.search(r"^202709(2[4-9])$", autumn["DTSTART"]), "After the Sep 23 equinox"
    assert re.search(r"^Daylight is 1[12]h \d\dm today\\, the closest it gets to 12 hours", spring["DESCRIPTION"])
    assert r"before the spring equinox\, not on it" in spring["DESCRIPTION"]


def test_polar_transitions_get_their_own_days() -> None:
    ics = feed(lat=69.65, lon=18.96, timezone="Europe/Oslo", today="2026-11-25", window_days=60)

    begins = events_of_type(ics, "polar-night-begins")[0]
    ends = events_of_type(ics, "polar-night-ends")[0]
    assert begins["DTSTART"] == "20261128"
    # timeanddate.com: down all day on Jan 14, up 11:34-12:13 on Jan 15
    assert ends["DTSTART"] == "20270115", "The first day the sun rises: 11:35 on Jan 15, 2027"
    assert re.search(r"^The first sunrise after about 4\d days of polar night\.$", ends["DESCRIPTION"])


def test_midnight_sun_runs_to_the_last_date_without_a_sunset() -> None:
    """timeanddate.com: the sun stays up from Apr 19 to Aug 24, 2026; it first sets at 00:28 on Aug 25.

    That sunset belongs to the solar day of Aug 24, which runs to solar midnight, about 01:00.
    """
    ics = feed(lat=78.2232, lon=15.6267, timezone="Arctic/Longyearbyen", today="2026-04-01", window_days=160)

    assert events_of_type(ics, "midnight-sun-begins")[0]["DTSTART"] == "20260419"
    ends = events_of_type(ics, "midnight-sun-ends")[0]
    assert ends["DTSTART"] == "20260825"
    assert ends["DESCRIPTION"] == "The first sunset after about 128 days of midnight sun."
    assert "Midnight sun from Apr 19 to Aug 24." in ics.replace("\r\n ", "")


def test_a_sunset_that_crosses_midnight_moves_the_short_way() -> None:
    """Longyearbyen's sunset moves from 23:44 on Apr 16, 2026 to 00:12 the next night: 28 minutes later."""
    ics = feed(lat=78.2232, lon=15.6267, timezone="Arctic/Longyearbyen", today="2026-04-17", window_days=0)

    daylight = text(events_of_type(ics, "daylight")[0])
    assert daylight.startswith("vs yesterday: +48m 16s (+3.8%)\n")
    # From ten minutes, to the minute
    assert "Sunrise: 20m earlier than yesterday\n" in daylight
    assert "Sunset: 28m later than yesterday\n" in daylight


def test_a_sun_that_only_skims_the_horizon_says_so() -> None:
    """Refraction keeps the sun in view with its centre a little below the horizon: no "-1° up"."""
    ics = feed(lat=78.2232, lon=15.6267, timezone="Arctic/Longyearbyen", today="2026-04-19", window_days=0)
    assert re.search(
        r"☀️ Sun\n(.+\n)?Highest at \d\d:\d\d, \d+° up\nLowest at 00:56, skimming the horizon\n",
        text(events_of_type(ics, "daylight")[0]),
    )

    pole = feed(lat=90.0, lon=0.0, timezone="UTC", today="2026-03-19", window_days=0)
    assert "The sun circles the sky skimming the horizon all day" in text(events_of_type(pole, "daylight")[0])


def test_a_day_with_one_sunrise_or_sunset_keeps_it() -> None:
    """The sun rises at 01:39 on Apr 18, 2026 and stays up; on Aug 24 it sets at 00:29 the next morning."""
    ics = feed(lat=78.2232, lon=15.6267, timezone="Arctic/Longyearbyen", today="2026-04-17", window_days=130)
    daylight = {e["DTSTART"]: e for e in events_of_type(ics, "daylight")}

    last_sunrise = daylight["20260417T233922Z"]
    assert last_sunrise["DTEND"] == "20260418T225649Z", "Up until solar midnight"
    assert last_sunrise["SUMMARY"] == "🔆 Daylight · 23h 17m"
    first_sunset = next(e for e in daylight.values() if e["DTEND"] == "20260824T222904Z")
    assert first_sunset["SUMMARY"] == "🔆 Daylight · 23h 29m", "Up since solar midnight"
    assert [e["DTSTART"] for e in events_of_type(ics, "evening-civil-twilight") if e["DTSTART"].startswith("20260824")]


def test_the_last_fully_dark_night_is_kept() -> None:
    """On Mar 4, 2026 the sun rises through -18° just after midnight, and does not sink below it again."""
    ics = feed(lat=78.2232, lon=15.6267, timezone="Arctic/Longyearbyen", today="2026-03-03", window_days=2)

    nights = events_of_type(ics, "night")
    assert [(n["DTSTART"], n["DTEND"]) for n in nights][-1] == ("20260303T221613Z", "20260304T000001Z")
    assert events_of_type(ics, "white-nights-begins")[0]["DTSTART"] == "20260304"


def test_the_sun_rises_once_a_year_at_the_north_pole() -> None:
    """It rises at 12:21 UTC on Mar 18, 2026, after that day's solar noon (12:07): the day keeps it."""
    ics = feed(lat=90.0, lon=0.0, timezone="UTC", today="2026-03-17", window_days=3)

    assert events_of_type(ics, "polar-night-ends")[0]["DTSTART"] == "20260318"
    assert events_of_type(ics, "midnight-sun-begins")[0]["DTSTART"] == "20260319"
    assert any(e["DTSTART"] == "20260318T122104Z" for e in events_of_type(ics, "daylight"))


@pytest.mark.parametrize(("lat", "zone"), [(90.0, "UTC"), (-90.0, "Antarctica/McMurdo")])
def test_no_event_at_a_pole_ends_before_it_starts(lat: float, zone: str) -> None:
    """The year's one sunrise or sunset can fall after solar noon there; twilight keeps to its side of noon."""
    ics = feed(lat=lat, lon=0.0, timezone=zone, today="2026-01-01", window_days=365)

    timed = [e for e in events(ics) if e["DTSTART"].endswith("Z")]
    assert timed
    assert all(e["DTSTART"] < e["DTEND"] for e in timed)


def test_no_meteorological_seasons_in_the_tropics() -> None:
    ics = feed(lat=-0.1807, lon=-78.4678, timezone="America/Guayaquil", today="2026-11-30", window_days=3)

    assert [e for e in events(ics) if e["UID"].startswith("meteorological-")] == []


def test_twilight_data_comes_before_the_explanation() -> None:
    dawn = text(
        events_of_type(
            feed(window_days=0, enabled={"civil": True, "nautical": False, "astro": False, "daylight": True}),
            "morning-civil-twilight",
        )[0]
    )

    assert dawn.startswith("No lamps needed from first light\n\n🕒 This morning\n")
    assert dawn.find("🕒 This morning") < dawn.find("ℹ️ About")
    nautical = text(events_of_type(feed(window_days=0), "morning-nautical-twilight")[0])
    assert nautical.startswith("Horizon appears as stars fade\n\nℹ️ About\n")


def test_night_gives_the_moonless_window() -> None:
    def night(today: str) -> str:
        return events_of_type(feed(today=today, window_days=0), "night")[0]["DESCRIPTION"]

    # The moon sets at 21:44:50 (USNO rounds it to 21:45) and the night ends at astronomical dawn
    assert re.search(r"Moonless sky: 21:44–05:2\d \(7h \d\dm\)", night("2026-11-15"))
    # The Dec 24 full moon at 02:28 falls in the night of Dec 23: a line of its own, after the phase
    assert re.search(r"Moon\\n\w+ \w+\\, \d+% lit\\nFull Moon at 02:28\\n", night("2026-12-23"))
    assert "Next:" not in night("2026-11-15")
    assert r"Moonless sky: none\, moon up all night" in night("2026-11-24")
    assert "Moonless sky: all night" in night("2026-11-10")
    assert "While up" not in night("2026-11-10"), "No brightness note when the moon never rises"


def test_moon_emoji_is_mirrored_south_of_the_equator() -> None:
    # Waxing crescent on Nov 15, 2026: lit on the right from Rome, on the left from Sydney
    def night(**place: float | str) -> dict[str, str]:
        return events_of_type(feed(**place, today="2026-11-15", window_days=0), "night")[0]

    rome = night()
    sydney = night(lat=-33.8688, lon=151.2093, timezone="Australia/Sydney")
    assert rome["SUMMARY"].startswith("🌒 Night")
    assert sydney["SUMMARY"].startswith("🌘 Night")
    assert r"🌘 Moon\nWaxing Crescent" in sydney["DESCRIPTION"]


def test_blue_and_golden_hour_appear_only_on_daylight() -> None:
    ics = feed(today="2026-11-15", window_days=0)

    for kind in ["morning-civil-twilight", "evening-civil-twilight"]:
        twilight = events_of_type(ics, kind)[0]
        assert "golden hour" not in twilight["DESCRIPTION"].lower(), kind
        assert "blue hour" not in twilight["DESCRIPTION"].lower(), kind

    # A schedule under its own header: morning, then evening, each in time order
    daylight = text(events_of_type(ics, "daylight")[0])
    assert re.search(
        r"📷 Blue and golden hour\n\d\d:\d\d–(\d\d:\d\d) Blue hour\n\1–\d\d:\d\d Golden hour\n"
        r"\d\d:\d\d–(\d\d:\d\d) Golden hour\n\2–\d\d:\d\d Blue hour\n",
        daylight,
    )
    assert "photographers' rules of thumb" in daylight


def test_blue_and_golden_hour_come_with_the_daylight_block_without_a_daylight_event() -> None:
    civil_only = {"civil": True, "nautical": False, "astro": False, "daylight": False}
    ics = feed(today="2026-11-15", window_days=0, enabled=civil_only)
    dawn = text(events_of_type(ics, "morning-civil-twilight")[0])
    dusk = text(events_of_type(ics, "evening-civil-twilight")[0])

    # Morning light ends the morning schedule, each with its note; then the sun, then the daylight
    assert re.search(
        r"⚓ Nautical\n.+\n\d\d:\d\d–(\d\d:\d\d) 📷 Blue hour\n\u00a0\u00a0Deep, even blue sky\n"
        r"\1–\d\d:\d\d 📷 Golden hour\n\u00a0\u00a0Warm, low light, long shadows\n\n"
        r"☀️ Sun\nSunrise: .+ than yesterday\nHighest at .+\n\n🔆 Daylight ",
        dawn,
    )
    assert "📷 Golden hour" not in dawn[dawn.find("🔆 Daylight ") :], "Only the morning"

    # Evening light opens the evening schedule, before nautical twilight; the sunset follows it
    assert re.search(
        r"^No lamps needed until last light\n\n🕒 This evening\n\d\d:\d\d–(\d\d:\d\d) 📷 Golden hour\n.+\n\1–(\d\d:\d\d) 📷 Blue hour\n.+\n"
        r"\2–\d\d:\d\d ⚓ Nautical\n",
        dusk,
    )
    assert re.search(r"\n\n☀️ Sun\nSunset: .+ than yesterday\n\n🌙 Night ", dusk)

    # Without evening civil twilight, the evening light stays in the morning schedule, and the
    # nautical twilights say how sunrise and sunset moved
    nautical = {"civil": False, "nautical": True, "astro": False, "daylight": False}
    nautical_ics = feed(today="2026-11-15", window_days=0, enabled=nautical)
    nautical_dawn = text(events_of_type(nautical_ics, "morning-nautical-twilight")[0])
    assert re.search(r"📷 Golden hour\n.+\n\S+ 📷 Golden hour\n.+\n\S+ 📷 Blue hour\n", nautical_dawn)
    assert "☀️ Sun\nSunrise: " in nautical_dawn
    assert "☀️ Sun\nSunset: " in text(events_of_type(nautical_ics, "evening-nautical-twilight")[0])


def test_schedules_are_in_time_order_without_nested_sections() -> None:
    ics = feed(window_days=0, enabled={"civil": True, "nautical": False, "astro": False, "daylight": False})
    for kind in ["morning-civil-twilight", "evening-civil-twilight"]:
        description = text(events_of_type(ics, kind)[0])
        starts = re.findall(r"^(\d\d:\d\d)–\d\d:\d\d ", description, re.M)
        assert sorted(starts) == starts, f"{kind}: schedule in time order"
        # Each section starts after a blank line: a heading never follows a schedule line directly
        assert not re.search(r"^\d\d:\d\d–.+\n(☀️|🌙|🌒|🕒) ", description, re.M), f"{kind}: no nested sections"


def test_daylight_and_night_blocks_appear_once_on_the_nearest_twilight() -> None:
    ics = feed(window_days=0, enabled={"civil": True, "nautical": True, "astro": True, "daylight": False})

    def count(kind: str, needle: str) -> int:
        """Lines of the event's description that start with the needle."""
        return sum(1 for line in text(events_of_type(ics, kind)[0]).split("\n") if line.startswith(needle))

    # Daylight on the last twilight before sunrise, Night on the last one before night
    assert count("morning-civil-twilight", "🔆 Daylight ") == 1
    assert count("morning-nautical-twilight", "🔆 Daylight") == 0
    assert count("morning-astronomical-twilight", "🔆 Daylight") == 0
    assert count("evening-astronomical-twilight", "🌙 Night ") == 1
    assert count("evening-nautical-twilight", "🌙 Night") == 0
    assert count("evening-civil-twilight", "🌙 Night") == 0
    # Sunrise on the twilight nearest it, sunset on the one nearest it
    assert count("morning-civil-twilight", "Sunrise: ") == 1
    assert count("evening-civil-twilight", "Sunset: ") == 1
    for kind in ["morning-nautical-twilight", "evening-nautical-twilight", "evening-astronomical-twilight"]:
        assert count(kind, "☀️ Sun") == 0, kind


def test_offsets_are_explained() -> None:
    daylight = events_of_type(feed(window_days=0, rise_offset=-600, set_offset=900), "daylight")[0]

    assert r"Calendar times shifted from the actual times: start -10m\, end +15m." in daylight["DESCRIPTION"]


def test_deep_polar_night_lasts_around_the_clock() -> None:
    # At 88°S the noon sun stays more than 18° below the horizon from May 21 (UTC). Solar noon falls
    # at local midnight in New Zealand time: each date takes the one at 23:56 the evening before.
    ics = feed(lat=-88.0, lon=0.0, timezone="Antarctica/McMurdo", today="2027-05-19", window_days=4)
    nights = events_of_type(ics, "night")

    assert len(nights) == 5
    assert nights[1]["DTEND"] == "20270520T113327Z", "The sun climbs past 18° below for 38 minutes"
    assert nights[2]["DTSTART"] == "20270520T121155Z"
    # From then on, solar noon joins each night to the next
    for i in range(3, len(nights)):
        assert nights[i - 1]["DTEND"] == nights[i]["DTSTART"], "Nights join up without gaps"
    assert r"all day tomorrow\, so this night lasts until solar noon." in nights[2]["DESCRIPTION"]
    assert nights[3]["DESCRIPTION"].startswith("Polar night: the sun stays more than 18° below the horizon all day")
    assert "this year's nights" not in nights[3]["DESCRIPTION"]
    assert " Night · 24h 00m" in nights[4]["SUMMARY"]


def test_night_length_sits_between_the_years_shortest_and_longest() -> None:
    solstice = text(events_of_type(feed(today="2026-12-21", window_days=0), "night")[0])
    november = text(events_of_type(feed(today="2026-11-15", window_days=0), "night")[0])

    assert re.search(
        r"^The longest night of the year\nvs shortest \(Jun 2\d\): \+\d+h \d\dm \(\+\d+%\)$", solstice, re.M
    )
    assert re.search(
        r"^Longer than \d+ of 365 nights \(\d+%\)\nvs shortest \(Jun 2\d\): \+.+\nvs longest \(Dec 2\d\): −\d+m \(−\d\.\d%\)$",
        november,
        re.M,
    )


def test_year_extremes_fall_on_the_solstices() -> None:
    # Rome, New York, Tokyo and Sydney, plus a leap year: shortest and longest day at the solstices
    places = [
        (41.9028, 12.4964, "Europe/Rome", 2026),
        (40.7128, -74.006, "America/New_York", 2026),
        (35.6762, 139.6503, "Asia/Tokyo", 2026),
        (-33.8688, 151.2093, "Australia/Sydney", 2026),
        (41.9028, 12.4964, "Europe/Rome", 2028),
    ]
    for lat, lon, zone, y in places:
        with clock.use_zone(zone):
            offset = clock.LocalDate.parse(f"{y}-01-15", clock.zone(zone)).set_time(12, 0).offset() / 3600
            year = year_summary(lat, lon, y, offset)
            short = clock.format_local_time(year["daylight_min"][1], "{month_short} {day}")
            long = clock.format_local_time(year["daylight_max"][1], "{month_short} {day}")
        winter, summer = (r"^Dec 2[0-2]$", r"^Jun 2[0-2]$") if lat > 0 else (r"^Jun 2[0-2]$", r"^Dec 2[0-2]$")
        assert re.search(winter, short), f"{zone} {y} shortest day"
        assert re.search(summer, long), f"{zone} {y} longest day"


def test_the_zone_is_restored() -> None:
    feed(window_days=0)

    assert clock.current_zone() == "UTC"
