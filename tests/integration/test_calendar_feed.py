"""End-to-end tests of generate_calendar() output."""

from __future__ import annotations

import re
from datetime import UTC, datetime, timedelta

import pytest

from sunrise_sunset_calendar import php
from sunrise_sunset_calendar.feed import Options, generate_calendar
from sunrise_sunset_calendar.helpers import format_duration, year_summary
from tests.helpers import date_sun_info_sunrise, ical_timestamp

ALL_TYPES = {"civil": True, "nautical": True, "astro": True, "daylight": True}

DEFAULTS = {
    "lat": 41.9028,
    "lon": 12.4964,
    "timezone": "Europe/Rome",
    "location": "Rome",
    "description": "",
    "rise_offset": 0,
    "set_offset": 0,
    "enabled": ALL_TYPES,
    "past_days": 0,
    "window_days": 365,
    "refresh_seconds": 86400,
    "today": "2026-10-15",
}

# PHP's trim() characters
_TRIM = " \t\n\r\0\x0b"


def feed(**overrides) -> str:
    """The feed for the default options, with overrides replacing them."""
    return generate_calendar(Options(**(DEFAULTS | overrides)))


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
    """An iCalendar UTC date-time in a time zone, formatted as PHP's date() would."""
    return php.format_datetime(datetime.fromtimestamp(ical_timestamp(value), php.zone(zone)), fmt)


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
    on the day the feed was generated. Cross-check against PHP's own solar model
    (date_sun_info(), ported in tests/helpers.py).
    """
    ics = feed(lat=lat, lon=lon, timezone=zone)
    tz = php.zone(zone)

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
    assert "→ [Civil] → Solar noon" in dawn["DESCRIPTION"]
    assert (
        r"Polar night: today the sun sinks from 3° below the horizon at solar noon\, its highest today\, to 6° below."
        in dusk["DESCRIPTION"]
    )
    assert "Solar noon → [Civil] →" in dusk["DESCRIPTION"]

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

    assert "Sunset → Civil → [Nautical] → Solar midnight" in dusk["DESCRIPTION"]
    assert (
        r"Today the sun sinks from 6° below the horizon to 7° below at solar midnight\, its lowest tonight."
        in dusk["DESCRIPTION"]
    )
    assert "from 6° to 12° below" not in dusk["DESCRIPTION"]
    assert "Today the sun climbs from 7° below the horizon at solar midnight" in next_dawn["DESCRIPTION"]
    assert "the sun never gets 18° below the horizon" in ics.replace("\r\n ", "")


def test_southern_hemisphere_shortest_day_is_in_june() -> None:
    ics = feed(lat=-33.8688, lon=151.2093, timezone="Australia/Sydney", window_days=0)

    daylight = events_of_type(ics, "daylight")[0]
    assert re.search(
        r"more than the shortest day \(Jun 2\d\)\\, [^\\]+ less than the longest \(Dec 2\d\)", daylight["DESCRIPTION"]
    )


def test_offsets_shift_morning_and_evening_events() -> None:
    base = events_of_type(feed(window_days=0), "daylight")[0]
    shifted = events_of_type(feed(window_days=0, rise_offset=-600, set_offset=900), "daylight")[0]

    assert ical_timestamp(shifted["DTSTART"]) - ical_timestamp(base["DTSTART"]) == -600
    assert ical_timestamp(shifted["DTEND"]) - ical_timestamp(base["DTEND"]) == 900


def test_night_duration_matches_event_span() -> None:
    night = events_of_type(feed(window_days=0), "night")[0]
    minutes = php.round_int((ical_timestamp(night["DTEND"]) - ical_timestamp(night["DTSTART"])) / 60)

    assert " Night · " + format_duration(minutes * 60) in night["SUMMARY"]


def test_supplemental_schedule_lists_only_disabled_types() -> None:
    ics = feed(window_days=0, enabled={"civil": True, "nautical": False, "astro": False, "daylight": False})
    dawn = text(events_of_type(ics, "morning-civil-twilight")[0])

    # Time first, in order, the daylight as the last entry
    assert re.search(r"^\d\d:\d\d–\d\d:\d\d 🌌 Morning Astronomical Twilight \(\d+m\)$", dawn, re.M)
    assert re.search(r"^\d\d:\d\d–\d\d:\d\d ⚓ Morning Nautical Twilight \(\d+m\)$", dawn, re.M)
    assert re.search(
        r"^\d\d:\d\d–\d\d:\d\d ☀️ Daylight \(\d+h \d\dm\)\n\n☀️ Daylight\n\d+m \d\ds shorter than yesterday\nSun highest",
        dawn,
        re.M,
    )
    assert "🌅 Morning Civil Twilight (" not in dawn, "The event itself is not in its schedule"
    assert events_of_type(ics, "daylight") == []


def test_daylight_only_calendar_still_lists_every_twilight() -> None:
    ics = feed(window_days=1, enabled={"civil": False, "nautical": False, "astro": False, "daylight": True})
    daylight_event = events_of_type(ics, "daylight")[0]
    night_event = events_of_type(ics, "night")[0]
    daylight = text(daylight_event)
    night = text(night_event)

    # This evening's twilights follow Daylight, which ends at sunset
    assert re.search(
        r"🕒 This evening\n\S+ 🌇 Evening Civil Twilight .+\n.+\n\S+ ⚓ Evening Nautical Twilight .+\n.+\n"
        r"\S+ 🌌 Evening Astronomical Twilight ",
        daylight,
    )
    assert "Morning Civil Twilight" not in daylight, "Morning twilights are over before Daylight starts"

    # Tomorrow morning's twilights follow the Night, which ends where they start
    morning = re.search(r"🕒 Tomorrow morning\n(\d\d:\d\d)–\d\d:\d\d 🌌 Morning Astronomical Twilight", night)
    assert morning is not None
    ends = local_time(night_event["DTEND"], "Europe/Rome", "H:i")
    assert morning[1] == ends
    assert "Evening Civil Twilight" not in night

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
    assert re.search(
        r"🕒 Earlier today\n\S+ 🌇 Evening Civil Twilight .+🕒 Tomorrow morning\n.+ 🌅 Morning Civil Twilight ",
        night,
        re.S,
    )


def test_week_summary_on_sundays_only() -> None:
    summaries = events_of_type(feed(window_days=13), "week")

    assert len(summaries) == 2
    for summary in summaries:
        # All-day from Sunday through Saturday: DTEND is the following Sunday
        start = datetime.fromtimestamp(ical_timestamp(summary["DTSTART"]), UTC)
        assert php.format_datetime(start, "w") == "0"
        assert php.format_datetime(start + timedelta(days=7), "Ymd") == summary["DTEND"]
    assert re.search(r"^📉 \d+m less daylight this week$", summaries[0]["SUMMARY"]), "Days shorten in October"
    assert re.search(r"Sunrise \d\d:\d\d → \d\d:\d\d · Sunset \d\d:\d\d → \d\d:\d\d", summaries[0]["DESCRIPTION"])


def test_descriptions_dont_repeat_the_event_times_or_name_instants() -> None:
    timed = [e for e in events(feed(window_days=6)) if "T" in e["DTSTART"]]

    assert timed
    for event in timed:
        assert "From " not in event["DESCRIPTION"], event["UID"]
        assert not re.search(r"\b(civil|nautical|astronomical) (dawn|dusk)\b", event["DESCRIPTION"], re.I), event["UID"]


def test_daylight_shows_change_and_place_in_the_year() -> None:
    description = text(events_of_type(feed(today="2026-11-15", window_days=0), "daylight")[0])

    # What changed since yesterday, including on the clock, where the sun peaks, and the year
    assert re.search(
        r"^2m 0\ds shorter than yesterday: sunrise 1m \d\ds later, sunset \d+s earlier\n"
        r"Sun highest at 11:54, 30° above the horizon\n"
        r"Over the year: 9h 08m ▕█░░░░░░░░░▏ 15h 14m\n"
        r"42m more than the shortest day \(Dec 21\), 5h 24m less than the longest \(Jun 21\)\n",
        description,
    )
    assert "📅" not in description
    assert "%" not in description


def test_twilight_length_sits_in_the_years_range() -> None:
    dawn = events_of_type(feed(today="2026-11-15", window_days=0), "morning-civil-twilight")[0]

    assert dawn["DESCRIPTION"].startswith("Length: 30m · over the year: 28m ▕███░░░░░░░▏ 34m")


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
    solstice = events_of_type(feed(today="2026-12-21", window_days=0), "winter-solstice")[0]

    assert "The shortest day of the year: 9h 08m of daylight." in solstice["DESCRIPTION"]
    assert "The noon sun is at its lowest of the year: 25°" in solstice["DESCRIPTION"]
    # Facts about other days go on those days, not here
    assert not re.search(r"\b(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) \d", solstice["DESCRIPTION"])


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
    assert ends["DTSTART"] == "20270116", "The first day the sun rises: 11:15 on Jan 16, 2027"
    assert re.search(r"^The first sunrise after about 4\d days of polar night\.$", ends["DESCRIPTION"])


def test_no_meteorological_seasons_in_the_tropics() -> None:
    ics = feed(lat=-0.1807, lon=-78.4678, timezone="America/Guayaquil", today="2026-11-30", window_days=3)

    assert [e for e in events(ics) if e["UID"].startswith("meteorological-")] == []


def test_twilight_data_comes_before_the_explanation() -> None:
    dawn = events_of_type(
        feed(window_days=0, enabled={"civil": True, "nautical": False, "astro": False, "daylight": True}),
        "morning-civil-twilight",
    )[0]
    description = dawn["DESCRIPTION"]

    assert re.search(r"^Length: \d+m · over the year: 2\dm ▕[█░]{10}▏ 3\dm\\n", description)
    assert description.find("☀️ Today") < description.find("ℹ️ About")


def test_night_gives_the_moonless_window() -> None:
    def night(today: str) -> str:
        return events_of_type(feed(today=today, window_days=0), "night")[0]["DESCRIPTION"]

    # The moon sets at 21:45 (USNO) and the night ends at astronomical dawn
    assert re.search(r"Moonless sky: 21:45–05:2\d \(7h \d\dm\)", night("2026-11-15"))
    # The Dec 24 full moon at 02:28 falls in the night of Dec 23
    assert "· Full Moon at 02:28" in night("2026-12-23")
    assert "Next:" not in night("2026-11-15")
    assert r"Moonless sky: none\, the moon is up all night" in night("2026-11-24")
    assert "Moonless sky: all night" in night("2026-11-10")
    assert "While up" not in night("2026-11-10"), "No brightness note when the moon never rises"


def test_moon_emoji_is_mirrored_south_of_the_equator() -> None:
    # Waxing crescent on Nov 15, 2026: lit on the right from Rome, on the left from Sydney
    def night(**place) -> dict[str, str]:
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

    # Morning and evening, each in time order
    daylight = events_of_type(ics, "daylight")[0]
    assert re.search(
        r"📷 Morning: blue hour \d\d:\d\d–(\d\d:\d\d)\\, golden hour \1–\d\d:\d\d", daylight["DESCRIPTION"]
    )
    assert re.search(
        r"📷 Evening: golden hour \d\d:\d\d–(\d\d:\d\d)\\, blue hour \1–\d\d:\d\d", daylight["DESCRIPTION"]
    )
    assert "photographers' rules of thumb" in daylight["DESCRIPTION"]


def test_blue_and_golden_hour_come_with_the_daylight_block_without_a_daylight_event() -> None:
    civil_only = {"civil": True, "nautical": False, "astro": False, "daylight": False}
    ics = feed(today="2026-11-15", window_days=0, enabled=civil_only)
    dawn = text(events_of_type(ics, "morning-civil-twilight")[0])
    dusk = text(events_of_type(ics, "evening-civil-twilight")[0])

    # Morning light in the morning schedule, between the twilights and the daylight
    assert re.search(
        r"⚓ Morning Nautical Twilight .+\n.+\n\d\d:\d\d–(\d\d:\d\d) 📷 Blue hour \(\d+m\)\n"
        r"\1–\d\d:\d\d 📷 Golden hour \(.+\)\n\d\d:\d\d–\d\d:\d\d ☀️ Daylight ",
        dawn,
    )
    daylight_at = dawn.find("☀️ Daylight (")
    assert "📷 Golden hour" not in dawn[max(daylight_at, 0) :], "Only the morning"
    assert re.search(r"^Length: .+\nSunrise at \d\d:\d\d, .+ than yesterday\n", dawn)

    # Evening light opens the evening schedule, before nautical twilight
    assert re.search(
        r"^Length: .+\nSunset at \d\d:\d\d, .+ than yesterday\n\n🕒 This evening\n"
        r"\d\d:\d\d–(\d\d:\d\d) 📷 Golden hour \(.+\)\n\1–(\d\d:\d\d) 📷 Blue hour \(\d+m\)\n"
        r"\2–\d\d:\d\d ⚓ Evening Nautical Twilight",
        dusk,
    )

    # Without evening civil twilight, the evening light stays in the morning schedule: nautical starts after it
    nautical = {"civil": False, "nautical": True, "astro": False, "daylight": False}
    nautical_dawn = text(
        events_of_type(feed(today="2026-11-15", window_days=0, enabled=nautical), "morning-nautical-twilight")[0]
    )
    assert re.search(r"📷 Golden hour .+\n.+ ☀️ Daylight .+\n.+📷 Golden hour .+\n.+📷 Blue hour ", nautical_dawn, re.S)
    assert ": sunrise " in nautical_dawn, "Clock changes stay in the daylight line without civil events"


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
        return text(events_of_type(ics, kind)[0]).count(needle)

    # Daylight on the last twilight before sunrise, Night on the last one before night
    assert count("morning-civil-twilight", "\n☀️ Daylight\n") == 1
    assert count("morning-nautical-twilight", "☀️ Daylight") == 0
    assert count("morning-astronomical-twilight", "☀️ Daylight") == 0
    assert count("evening-astronomical-twilight", "\n🌙 Night\n") == 1
    assert count("evening-nautical-twilight", "🌙 Night") == 0
    assert count("evening-civil-twilight", "🌙 Night") == 0


def test_offsets_are_explained() -> None:
    daylight = events_of_type(feed(window_days=0, rise_offset=-600, set_offset=900), "daylight")[0]

    assert r"Calendar times shifted from the actual times: start -10m\, end +15m." in daylight["DESCRIPTION"]


def test_deep_polar_night_lasts_around_the_clock() -> None:
    # At 88°S the noon sun stays more than 18° below the horizon from May 21
    ics = feed(lat=-88.0, lon=0.0, timezone="Antarctica/McMurdo", today="2027-05-19", window_days=4)
    nights = events_of_type(ics, "night")

    assert len(nights) == 5
    # From May 20's night on, solar noon joins each night to the next
    for i in range(2, len(nights)):
        assert nights[i - 1]["DTEND"] == nights[i]["DTSTART"], "Nights join up without gaps"
    assert r"all day tomorrow\, so this night lasts until solar noon." in nights[1]["DESCRIPTION"]
    assert nights[2]["DESCRIPTION"].startswith("Polar night: the sun stays more than 18° below the horizon all day")
    assert "this year's nights" not in nights[2]["DESCRIPTION"]
    assert " Night · 24h 00m" in nights[3]["SUMMARY"]


def test_night_length_sits_between_the_years_shortest_and_longest() -> None:
    solstice = events_of_type(feed(today="2026-12-21", window_days=0), "night")[0]
    november = events_of_type(feed(today="2026-11-15", window_days=0), "night")[0]

    assert re.search(
        r"The longest night of the year: \d+h \d\dm more than the shortest \(Jun 2\d\)", solstice["DESCRIPTION"]
    )
    assert re.search(
        r"more than the shortest night \(Jun 2\d\)\\, \d+m less than the longest \(Dec 2\d\)", november["DESCRIPTION"]
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
        with php.default_timezone_set(zone):
            offset = php.LocalDate.parse(f"{y}-01-15", php.zone(zone)).set_time(12, 0).offset() / 3600
            year = year_summary(lat, lon, y, offset)
            short = php.php_date("M j", year["daylight_min"][1])
            long = php.php_date("M j", year["daylight_max"][1])
        winter, summer = (r"^Dec 2[0-2]$", r"^Jun 2[0-2]$") if lat > 0 else (r"^Jun 2[0-2]$", r"^Dec 2[0-2]$")
        assert re.search(winter, short), f"{zone} {y} shortest day"
        assert re.search(summer, long), f"{zone} {y} longest day"


def test_default_timezone_is_restored() -> None:
    feed(window_days=0)

    assert php.default_timezone() == "UTC"
