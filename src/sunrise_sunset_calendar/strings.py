"""All user-facing text for the Sun & Twilight Calendar.

Every event is a period, titled with its name (Civil Twilight, Daylight,
Night). Descriptions don't repeat what the calendar already shows (the start
and end times), and explain each name through the period itself rather than
through the instants that bound it.

Edit this file to customise event text without touching the main code. The
placeholders are str.format() fields ({}, {:d}, {:02d}, positional {0});
date and time formats are templates over clock.format_fields().
"""

from __future__ import annotations

from typing import Literal, TypedDict

# The shape of STRINGS. Sections the code looks up by a computed key are
# plain mappings; sections that mix kinds of value are TypedDicts.


class MoonStrings(TypedDict):
    current: str
    moonless: str
    moonless_all: str
    moonless_none: str
    impact: dict[str, str]
    exact: str
    phase_at: str


class SeasonText(TypedDict):
    moment: str
    moment_names: dict[str, str]
    sun: dict[str, str]
    begins: str
    east_west: str
    daylight: str
    near_12: str
    longest_day: str
    shortest_day: str
    noon_highest: str
    noon_lowest: str
    noon_tropics: str
    noon_below: str


class DayFact(TypedDict):
    title: str
    lead: str


# The day facts with a title and a lead of their own
DayFactKind = Literal[
    "sunrise_latest",
    "sunrise_earliest",
    "sunset_earliest",
    "sunset_latest",
    "twelve_hours_rising",
    "twelve_hours_falling",
    "midnight_sun_begins",
    "midnight_sun_ends",
    "polar_night_begins",
    "polar_night_ends",
    "white_nights_begins",
    "white_nights_ends",
]


class Meteorological(TypedDict):
    title: str
    lead: str
    seasons: dict[int, str]
    opposite: dict[str, str]
    months: dict[int, str]
    marks: dict[str, str]


class DayFacts(TypedDict):
    sunrise_latest: DayFact
    sunrise_earliest: DayFact
    sunset_earliest: DayFact
    sunset_latest: DayFact
    stable: str
    trend: dict[str, str]
    why: str
    twelve_hours_rising: DayFact
    twelve_hours_falling: DayFact
    midnight_sun_begins: DayFact
    midnight_sun_ends: DayFact
    polar_night_begins: DayFact
    polar_night_ends: DayFact
    white_nights_begins: DayFact
    white_nights_ends: DayFact
    meteorological: Meteorological


class WebStrings(TypedDict):
    title: str
    heading: str
    today_heading: str
    today_date: str
    today_date_format: str
    today_times: dict[str, str]
    day_length: str
    generated: str
    subscription_url: str
    copy_url: str
    webcal_url: str
    copy_webcal: str
    google_heading: str
    google_link: str
    google_steps: list[str]
    use_location: str
    search_address: str
    address: str
    address_placeholder: str
    search_location: str
    location: str
    location_placeholder: str
    location_help: str
    latitude: str
    latitude_help: str
    longitude: str
    longitude_help: str
    timezone: str
    event_types: str
    event_types_help: str
    types: dict[str, str]
    rise_offset: str
    set_offset: str
    offset_help: str
    note: str
    note_placeholder: str
    submit: str
    footer: list[tuple[str, str]]
    js: dict[str, str]


class Strings(TypedDict):
    calendar_prodid: str
    calendar_name_format: str
    calendar_description_format: str
    place_named: str
    place_coordinates: str
    formats: dict[str, str]
    none: str
    units: dict[str, str]
    date_range: str
    range_join: str
    summaries: dict[str, str]
    week_titles: dict[str, str]
    names: dict[str, str]
    instants: dict[str, str]
    sequence: dict[str, str]
    headers: dict[str, str]
    schedule: dict[str, str]
    labels: dict[str, str]
    clock: dict[str, str]
    year: dict[str, dict[str, str]]
    light: dict[str, str]
    comparisons: dict[str, str]
    about: dict[str, dict[str, str]]
    about_dynamic: dict[str, str]
    no_crossing: dict[str, str]
    supplemental: dict[str, str]
    schedule_labels: dict[str, str]
    # Northern-hemisphere emoji, southern-hemisphere emoji, name
    moon_phases: dict[str, tuple[str, str, str]]
    moon_phase_unknown: tuple[str, str, str]
    moon: MoonStrings
    seasons: dict[str, dict[str, str]]
    season_text: SeasonText
    day_facts: DayFacts
    location_facts: dict[str, str]
    errors: dict[str, str]
    web: WebStrings


STRINGS: Strings = {
    # Calendar metadata
    "calendar_prodid": "-//Sun & Twilight Calendar//EN",
    "calendar_name_format": "🌅 {} · Sun & Twilight",  # {} = location name, or coordinates
    "calendar_description_format": "Sunrise, sunset and twilight for {0}, times in {1}. The sun and "
    "the moon computed from the JPL DE442 ephemeris.",  # {0} = place, {1} = time zone
    "place_named": "{0} ({1}, {2})",  # name, latitude, longitude
    "place_coordinates": "{1}, {2}",
    # Date and time formats (templates over clock.format_fields())
    "formats": {
        "time": "{hour:02d}:{minute:02d}",
        "date_short": "{month_short} {day}",
        "datetime": "{weekday_short}, {month_short} {day}, {hour:02d}:{minute:02d}",
    },
    "none": "—",  # a time that does not occur today
    # Duration units: "9h 05m", "32m", "2m 05s", "45s"
    "units": {
        "hours_minutes": "{:d}h {:02d}m",
        "minutes": "{:d}m",
        "minutes_seconds": "{:d}m {:02d}s",
        "seconds": "{:d}s",
    },
    "date_range": "{} to {}",
    "range_join": " and from ",
    # Event titles
    "summaries": {
        # Morning or evening is in the title because titles are often seen without their time
        # slot (agenda lists, notifications, search), and the emojis alone can't tell them apart
        "morning_astronomical": "🌌 Morning Astronomical Twilight",
        "morning_nautical": "⚓ Morning Nautical Twilight",
        "morning_civil": "🌅 Morning Civil Twilight",
        "evening_civil": "🌇 Evening Civil Twilight",
        "evening_nautical": "⚓ Evening Nautical Twilight",
        "evening_astronomical": "🌌 Evening Astronomical Twilight",
        "daylight": "☀️ Daylight · {}",  # {} = length
        "night": "{} Night · {}",  # {} = moon phase emoji, length
    },
    # Weekly summary titles, by daylight trend ({} = change over the week)
    "week_titles": {
        "increasing": "📈 {} more daylight this week",
        "decreasing": "📉 {} less daylight this week",
        "stable": "➡️ Day length steady this week",
    },
    # Period names in running text ("About morning civil twilight")
    "names": {
        "morning_astronomical": "morning astronomical twilight",
        "morning_nautical": "morning nautical twilight",
        "morning_civil": "morning civil twilight",
        "evening_civil": "evening civil twilight",
        "evening_nautical": "evening nautical twilight",
        "evening_astronomical": "evening astronomical twilight",
        "daylight": "daylight",
        "night": "night",
    },
    # The sun's path sentence on days a twilight misses a boundary ("climbs … to sunrise")
    "instants": {
        "sunrise": "sunrise",
        "sunset": "sunset",
    },
    # Today's sequence of twilights; the current one is bracketed
    "sequence": {
        "night": "Night",
        "sunrise": "Sunrise",
        "sunset": "Sunset",
        "solar_noon": "Solar noon",
        "solar_midnight": "Solar midnight",
        "morning_astronomical": "Astronomical",
        "morning_nautical": "Nautical",
        "morning_civil": "Civil",
        "evening_civil": "Civil",
        "evening_nautical": "Nautical",
        "evening_astronomical": "Astronomical",
    },
    # Section headers
    "headers": {
        "about": "ℹ️ About {}",
        "moon": "{} Moon",  # {} = moon phase emoji
        # Schedules (times only, in time order) carry 🕒; details sections carry their period's emoji
        "today": "🕒 Today",  # on the morning twilight carrying the daylight
        "tonight": "🕒 This evening",  # on the evening twilights
        # Twilights on a calendar without twilight events, on the event right before them
        "this_evening": "🕒 This evening",
        "earlier_today": "🕒 Earlier today",  # the evening twilights on a Night event (no Daylight that day)
        "tomorrow_morning": "🕒 Tomorrow morning",
        # Details of a period that isn't an event of its own, after the schedule
        "daylight_details": "☀️ Daylight",
        "night_details": "🌙 Night",
    },
    # Schedule entries: "06:49–07:01 📷 Blue hour (12m)", then an optional indented note
    "schedule": {
        "entry": "{} {}",  # time range, label
        "length": " ({})",
        "until": "until {}",
        "from": "from {}",
        "all_day": "All day",
    },
    # Data lines
    "labels": {
        "length": "Length: {}",
        "length_range": "Length: {} · over the year: {}",  # today, range bar
        "over_year": "Over the year: {}",  # range bar
        "clock": ": sunrise {}, sunset {}",  # appended to the change since yesterday
        # On the civil twilight that ends at sunrise or starts at sunset, when there is no Daylight event
        "sunrise_shift": "Sunrise at {}, {}",
        "sunset_shift": "Sunset at {}, {}",
        "sun_peak": "Sun highest at {}, {:d}° above the horizon",
        "sun_peak_midnight_sun": "Sun highest at {}, {:d}° up; lowest at {}, {}",
        "sun_low_up": "still {:d}° up",
        "sun_low_skimming": "skimming the horizon",
        "sun_peak_skimming": "Sun highest at {}, skimming the horizon",
        "sun_skimming_all_day": "The sun circles the sky skimming the horizon all day",
        # First and last light in the military sense: the start and end of nautical twilight
        "first_last_light": "First light {}, last light {} (sun 12° below the horizon)",
        "first_light_only": "First light {} (sun 12° below the horizon)",
        "last_light_only": "Last light {} (sun 12° below the horizon)",
        "midnight_sun": "Midnight sun: the sun does not set today.",
        "night_all_day": "Polar night: the sun stays more than 18° below the horizon all day, so night lasts "
        "around the clock.",
        "week_daylight": "Daylight: {} on Sunday → {} on Saturday",
        "week_sun": "Sunrise {} → {} · Sunset {} → {}",  # Sunday → Saturday
        "week_moon": "Moon: {}",
        "offset_note": "Calendar times shifted from the actual times: start {}, end {}.",
        "good_for": "Good for: {}",
    },
    # How far sunrise or sunset moved on the clock since yesterday
    "clock": {
        "later": "{} later",
        "earlier": "{} earlier",
        "same": "unchanged",
        # Standing alone, in the sunrise_shift and sunset_shift lines
        "later_alone": "{} later than yesterday",
        "earlier_alone": "{} earlier than yesterday",
        "same_alone": "the same time as yesterday",
    },
    # Today between the year's shortest and longest day or night (lengths, dates)
    "year": {
        "daylight": {
            "between": "{} more than the shortest day ({}), {} less than the longest ({})",
            "is_shortest": "The shortest day of the year: {} less than the longest ({})",
            "is_longest": "The longest day of the year: {} more than the shortest ({})",
        },
        "night": {
            "between": "{} more than the shortest night ({}), {} less than the longest ({})",
            "is_shortest": "The shortest night of the year: {} less than the longest ({})",
            "is_longest": "The longest night of the year: {} more than the shortest ({})",
        },
    },
    # Blue hour (sun 6° to 4° below) and golden hour (4° below to 6° above), on the Daylight event
    "light": {
        "morning": "📷 Morning: {}",
        "midday": "📷 Around noon: {}",
        "evening": "📷 Evening: {}",
        "item": "{} {}",  # name, time
        "blue": "blue hour",
        "golden": "golden hour",
        "until": "until {}",
        "from": "from {}",
        "all_day": "📷 Golden hour all day: the sun stays between 4° below and 6° above the horizon",
        # As schedule entries, on calendars without a Daylight event
        "blue_entry": "📷 Blue hour",
        "golden_entry": "📷 Golden hour",
    },
    # Length comparisons: "2m 07s shorter than yesterday"
    "comparisons": {
        "longer": "{} longer than {}",
        "shorter": "{} shorter than {}",
        "same": "Same length as {}",
        "yesterday": "yesterday",
        "last_night": "last night",
    },
    # What each period is, in plain language. 'angles' describes the sun's path on a
    # normal day; when solar noon or midnight stands in for a boundary, the
    # about_dynamic 'path_*' sentence describes that day's path instead.
    "about": {
        "morning_astronomical": {
            "angles": "The sun climbs from 18° to 12° below the horizon (nearly two fists to just over one, "
            "at arm's length).",
            "text": "It is named for astronomers: the faintest galaxies and nebulae need the sun at least 18° "
            "down, so they begin to fade as this twilight starts. To the eye the sky still looks like "
            "night, especially under light pollution; under a dark sky a faint glow grows where the sun "
            "will rise, and the faintest stars fade first.",
            "good_for": "the last dark-sky observing; planets and bright stars are still easy to see.",
        },
        "morning_nautical": {
            "angles": "The sun climbs from 12° to 6° below the horizon (just over one fist to about three "
            "fingers, at arm's length).",
            "text": "It is named for navigation at sea: the horizon becomes visible while bright stars are "
            "still out, so sailors could measure star altitudes with a sextant. On land, trees and "
            "buildings show as silhouettes against a deep blue sky, but most tasks still need a light. "
            "In military use its start is called first light.",
            "good_for": "bright stars and planets against a blue sky; silhouette photos.",
        },
        "morning_civil": {
            "angles": "The sun climbs from 6° below the horizon (about three fingers at arm's length) to sunrise.",
            "text": 'It is named for everyday ("civil") activity: there is enough light to be outdoors '
            "without lamps under a clear sky. Colours return, only the brightest stars and planets "
            "remain, and streetlights typically switch off. Aviation rules in many countries count the night "
            "as ending when this twilight begins.",
            "good_for": "early walks and runs; seeing the first colours of the day.",
        },
        "evening_civil": {
            "angles": "The sun sinks from sunset to 6° below the horizon (about three fingers at arm's length).",
            "text": 'It is named for everyday ("civil") activity: until the sun is 6° down there is still '
            "enough light to be outdoors without lamps. Colours fade, the brightest stars and planets "
            "appear, and streetlights typically switch on. Aviation rules in many countries count the night "
            "as starting when this twilight ends.",
            "good_for": "evening walks; the first bright stars and planets.",
        },
        "evening_nautical": {
            "angles": "The sun sinks from 6° to 12° below the horizon (about three fingers to just over one "
            "fist, at arm's length).",
            "text": "It is named for navigation at sea: the horizon is still visible while bright stars are "
            "out, so sailors could take star sights with a sextant. Most tasks now need a light. "
            "Satellites, including the International Space Station, are often visible: they are still "
            "sunlit high overhead. In military use its end is called last light.",
            "good_for": "bright stars and planets; spotting satellites.",
        },
        "evening_astronomical": {
            "angles": "The sun sinks from 12° to 18° below the horizon (just over one fist to nearly two, at "
            "arm's length).",
            "text": "The last sunlight fades from the sky low where the sun set. Most people already see full "
            "night, but the sky is not at its darkest until this twilight ends with the sun 18° down. "
            "It is named for astronomers, who need that darkness for the faintest galaxies and nebulae.",
            "good_for": "setting up a telescope; under a dark, moonless sky the Milky Way emerges once the sun "
            "is 18° down.",
        },
        "daylight": {
            "text": "Daylight runs from sunrise to sunset, while any part of the sun is above the horizon. "
            "Times assume a flat, open horizon at sea level; hills, buildings and your own height change "
            "what you see.",
        },
        "night": {
            "text": "This is astronomical night: the sun is more than 18° below the horizon, too deep for the air "
            "to scatter its light into the sky. What glow remains comes from the moon, light pollution, natural "
            "airglow and, under the darkest skies, zodiacal light: sunlight scattered by dust between the planets.",
        },
    },
    # Sentences that depend on the day
    "about_dynamic": {
        # The sun's path on a day when solar noon or midnight bounds the twilight
        "path_morning": "Today the sun climbs from {} to {}.",
        "path_evening": "Today the sun sinks from {} to {}.",
        "from_angle": "{:d}° below the horizon",
        "from_noon": "{:d}° below the horizon at solar noon, its highest today,",
        "from_noon_skimming": "just below the horizon at solar noon, its highest today,",
        "from_midnight": "{:d}° below the horizon at solar midnight, its lowest,",
        "from_midnight_skimming": "just below the horizon at solar midnight, its lowest,",
        "to_angle": "{:d}° below",
        "to_noon": "{:d}° below at solar noon, its highest today",
        "to_noon_skimming": "just below the horizon at solar noon, its highest today",
        "to_midnight": "{:d}° below at solar midnight, its lowest tonight",
        "to_midnight_skimming": "just below the horizon at solar midnight, its lowest tonight",
        "polar_night": "Polar night: ",
        # What the photo-light times mean
        "light": "Blue hour (sun 6° to 4° below) and golden hour (4° below to 6° above) are photographers' "
        "rules of thumb, not fixed boundaries: a deep, even blue sky, then warm, low light with long "
        "shadows, if the weather allows.",
        # Why the length in the "Length" line changes over the year
        "length_morning": "Its length changes over the year because the sun rises at a different angle: "
        "the more gently it rises, the longer twilight lasts. It is shortest a little before the spring "
        "equinox and a little after the autumn one, and longest in midsummer.",
        "length_evening": "Its length changes over the year because the sun sets at a different angle: "
        "the more gently it sets, the longer twilight lasts. It is shortest a little before the spring "
        "equinox and a little after the autumn one, and longest in midsummer.",
    },
    # When the sun stays more than 18° below the horizon all day, solar noon bounds the night
    "no_crossing": {
        "night_from_noon": "The sun stays more than 18° below the horizon all day today, so this night starts "
        "at solar noon.",
        "night_to_noon": "The sun stays more than 18° below the horizon all day tomorrow, so this night lasts "
        "until solar noon.",
    },
    # One-line notes in the Today/Tonight schedule
    "supplemental": {
        "morning_astronomical": "First trace of sunlight; the faintest stars fade",
        "morning_nautical": "Horizon visible at sea while bright stars are out",
        "morning_civil": "Enough light to be outdoors without lamps",
        "evening_civil": "Enough light to be outdoors without lamps",
        "evening_nautical": "Bright stars out while the horizon is still visible at sea",
        "evening_astronomical": "Last trace of sunlight; the faintest stars appear",
    },
    "schedule_labels": {
        "daylight": "☀️ Daylight",
        "night": "🌙 Night",
    },
    # Moon phase emojis (as seen from the northern hemisphere, then the southern) and
    # names, keyed by the names the astronomy code uses. South of the equator the
    # moon looks mirrored: a waxing crescent is lit on the left.
    "moon_phases": {
        "New Moon": ("🌑", "🌑", "New Moon"),
        "Waxing Crescent": ("🌒", "🌘", "Waxing Crescent"),
        "First Quarter": ("🌓", "🌗", "First Quarter"),
        "Waxing Gibbous": ("🌔", "🌖", "Waxing Gibbous"),
        "Full Moon": ("🌕", "🌕", "Full Moon"),
        "Waning Gibbous": ("🌖", "🌔", "Waning Gibbous"),
        "Last Quarter": ("🌗", "🌓", "Last Quarter"),
        "Waning Crescent": ("🌘", "🌒", "Waning Crescent"),
    },
    "moon_phase_unknown": ("🌙", "🌙", "Moon"),
    # Moon
    "moon": {
        "current": "{}, {:d}% lit",  # phase name, illumination
        "moonless": "Moonless sky: {}",  # time windows
        "moonless_all": "Moonless sky: all night",
        "moonless_none": "Moonless sky: none, the moon is up all night",
        # How much the moon brightens the sky while up, by illumination (under 25%, up to 75%, above)
        "impact": {
            "dark": "Too thin to brighten the sky much, even while up.",
            "partial": "While up, its light washes out the faintest stars and galaxies.",
            "bright": "While up, it washes out all but the brighter stars: a good night to look at the moon itself.",
        },
        "exact": "{} at {}",  # phase name, time: on the night a principal phase happens
        "phase_at": "{} {}, {}",  # emoji, phase name, date (weekly summary)
    },
    # Equinoxes and solstices, named by the season they start at the feed's location
    "seasons": {
        "spring_equinox": {"title": "🌱 Spring Equinox", "season": "Spring"},
        "summer_solstice": {"title": "☀️ Summer Solstice", "season": "Summer"},
        "autumn_equinox": {"title": "🍂 Autumn Equinox", "season": "Autumn"},
        "winter_solstice": {"title": "❄️ Winter Solstice", "season": "Winter"},
    },
    "season_text": {
        "moment": "The {} is at {}.",  # event name, local time
        "moment_names": {
            "march_equinox": "March equinox",
            "june_solstice": "June solstice",
            "september_equinox": "September equinox",
            "december_solstice": "December solstice",
        },
        "sun": {
            "march_equinox": "The sun is directly over the equator, heading north.",
            "june_solstice": "The sun is directly over the Tropic of Cancer, its farthest point north.",
            "september_equinox": "The sun is directly over the equator, heading south.",
            "december_solstice": "The sun is directly over the Tropic of Capricorn, its farthest point south.",
        },
        "begins": "Astronomical {} begins.",  # season, lowercase
        "east_west": "The sun rises almost due east and sets almost due west.",
        "daylight": "Daylight here: {}.",
        "near_12": "A few minutes over 12 hours, so day and night are not quite equal today: sunrise and "
        "sunset count the sun's top edge, and the air bends its light over the horizon.",
        "longest_day": "The longest day of the year: {} of daylight.",
        "shortest_day": "The shortest day of the year: {} of daylight.",
        "noon_highest": "The noon sun is at its highest of the year: {:d}° above the horizon.",
        "noon_lowest": "The noon sun is at its lowest of the year: {:d}° above the horizon.",
        "noon_tropics": "Noon sun: {:d}°. In the tropics the sun is highest on two other days of the year, "
        "when it passes straight overhead.",
        "noon_below": "The sun stays {:d}° below the horizon even at noon.",
    },
    # All-day events for days when something changes, each told on its own day
    "day_facts": {
        "sunrise_latest": {
            "title": "🌅 Sunrises start getting earlier",
            "lead": "Sunrise at {} is the latest it gets for months: from tomorrow, sunrises slowly get earlier.",
        },
        "sunrise_earliest": {
            "title": "🌅 Sunrises start getting later",
            "lead": "Sunrise at {} is the earliest it gets for months: from tomorrow, sunrises slowly get later.",
        },
        "sunset_earliest": {
            "title": "🌇 Sunsets start getting later",
            "lead": "Sunset at {} is the earliest it gets for months: from tomorrow, sunsets slowly get later.",
        },
        "sunset_latest": {
            "title": "🌇 Sunsets start getting earlier",
            "lead": "Sunset at {} is the latest it gets for months: from tomorrow, sunsets slowly get earlier.",
        },
        "stable": "It moves by less than a minute over about {:d} days around today, so the change is slow at first.",
        # What the day length is doing meanwhile, when it moves against the clock times (turning point, trend)
        "trend": {
            "sunset_earliest": "The days still get shorter for a while: sunrises are getting later faster "
            "than sunsets.",
            "sunrise_earliest": "The days still get longer for a while: sunsets are getting later faster "
            "than sunrises.",
            "sunrise_latest": "The days are already getting longer: sunsets are getting later faster than sunrises.",
            "sunset_latest": "The days are already getting shorter: sunrises are getting later faster than sunsets.",
        },
        "why": "Sunrise and sunset don't turn around together with the day length: the moment the sun is highest "
        "drifts over a range of about half an hour through the year, which shifts both.",
        "twelve_hours_rising": {
            "title": "⚖️ 12 hours of daylight",
            "lead": "Daylight is {} today, the closest it gets to 12 hours, and the days are getting longer. "
            "This happens before the spring equinox, not on it: sunrise and sunset count the sun's top edge, "
            "and the air bends its light over the horizon, which adds a few minutes of daylight.",
        },
        "twelve_hours_falling": {
            "title": "⚖️ 12 hours of daylight",
            "lead": "Daylight is {} today, the closest it gets to 12 hours, and the days are getting shorter. "
            "This happens after the autumn equinox, not on it: sunrise and sunset count the sun's top edge, "
            "and the air bends its light over the horizon, which adds a few minutes of daylight.",
        },
        "midnight_sun_begins": {
            "title": "☀️ Midnight sun begins",
            "lead": "The sun stays above the horizon around the clock, for about {:d} days.",
        },
        "midnight_sun_ends": {
            "title": "🌇 The sun sets again",
            "lead": "The first sunset after about {:d} days of midnight sun.",
        },
        "polar_night_begins": {
            "title": "🌑 Polar night begins",
            "lead": "The sun stays below the horizon all day, for about {:d} days.",
        },
        "polar_night_ends": {
            "title": "🌅 The sun rises again",
            "lead": "The first sunrise after about {:d} days of polar night.",
        },
        "white_nights_begins": {
            "title": "🌌 Nights stop getting fully dark",
            "lead": "From tonight the sun doesn't get 18° below the horizon even at midnight, so the sky never "
            "gets fully dark and there are no Night events, for about {:d} nights.",
        },
        "white_nights_ends": {
            "title": "🌌 Fully dark nights return",
            "lead": "Tonight the sun gets 18° below the horizon again, for the first time in about {:d} nights: "
            "astronomical night, and the Night events, are back.",
        },
        "meteorological": {
            "title": "🌡️ Meteorological {} begins",  # season, lowercase
            "lead": "Meteorologists count {0} as {1}: whole months that follow the year's temperature cycle "
            "and keep seasonal statistics comparable. Astronomical {0}, set by the sun, begins at the {2}.",
            # Northern-hemisphere season starting on the 1st of each month (south: the opposite season)
            "seasons": {3: "spring", 6: "summer", 9: "autumn", 12: "winter"},
            "opposite": {"spring": "autumn", "summer": "winter", "autumn": "spring", "winter": "summer"},
            "months": {
                3: "March, April and May",
                6: "June, July and August",
                9: "September, October and November",
                12: "December, January and February",
            },
            "marks": {"spring": "equinox", "summer": "solstice", "autumn": "equinox", "winter": "solstice"},
        },
    },
    # Facts about the location, in the calendar description
    "location_facts": {
        "range": "Daylight here ranges from {} ({}) to {} ({}) over the year.",
        "midnight_sun": "Midnight sun from {}.",
        "polar_night": "Polar night from {}.",
        "white_nights": "From {} the sun never gets 18° below the horizon, so there is no "
        "astronomical night and the calendar has no Night events.",
    },
    # Responses of the HTTP entry point
    "errors": {
        "missing_token": "Error: set the AUTH_TOKEN environment variable",  # nosec B105: an error message, not a credential
        "invalid_token": "Invalid authentication token",  # nosec B105: an error message, not a credential
    },
    # Web page for generating subscription URLs
    "web": {
        "title": "Sun & Twilight Calendar",
        "heading": "🌅 Sun & Twilight Calendar",
        "today_heading": "Today in Rome",
        "today_date": "Date: {}",
        "today_date_format": "{weekday}, {month_name} {day}, {year}",
        "today_times": {
            "astro_begin": "Astronomical dawn",
            "nautical_begin": "Nautical dawn",
            "civil_begin": "Civil dawn",
            "sunrise": "Sunrise",
            "solar_noon": "Solar noon",
            "sunset": "Sunset",
            "civil_end": "Civil dusk",
            "nautical_end": "Nautical dusk",
            "astro_end": "Astronomical dusk",
        },
        "day_length": "Day length: {}",
        "generated": "✅ Subscription URL generated",
        "subscription_url": "Your calendar subscription URL:",
        "copy_url": "📋 Copy URL",
        "webcal_url": "Webcal URL (recommended):",
        "copy_webcal": "📋 Copy webcal URL",
        "google_heading": "Adding it to Google Calendar",
        "google_link": "Google Calendar",
        "google_steps": [
            "Copy the webcal URL above.",
            "Open {}.",  # {} = link to Google Calendar
            'Click + next to "Other calendars".',
            'Choose "From URL".',
            "Paste the URL.",
            'Click "Add calendar".',
        ],
        "use_location": "📍 Use my current location",
        "search_address": "🔍 Search for an address",
        "address": "Address",
        "address_placeholder": "e.g. Rome, Italy",
        "search_location": "Search",
        "location": "Location name",
        "location_placeholder": "e.g. Rome",
        "location_help": 'Calendar name: "🌅 Your location · Sun & Twilight"',
        "latitude": "Latitude",
        "latitude_help": "Decimal degrees, e.g. 41.9028",
        "longitude": "Longitude",
        "longitude_help": "Decimal degrees, e.g. 12.4964",
        "timezone": "Time zone",
        "event_types": "Event types",
        "event_types_help": "💡 Each adds a morning and an evening event. The times of types you leave out "
        "are still listed in the notes of the others.",
        "types": {
            "civil": "🌅 Civil twilight: the sun between 6° below the horizon and sunrise, and between "
            "sunset and 6° below",
            "nautical": "⚓ Nautical twilight: the sun 6° to 12° below the horizon, morning and evening",
            "astro": "🌌 Astronomical twilight: the sun 12° to 18° below the horizon, morning and evening",
            "daynight": "☀️🌙 Daylight & night: sunrise to sunset, and night with the sun more than 18° below "
            "(with day length, the moon and a weekly summary)",
        },
        "rise_offset": "Morning offset (minutes)",
        "set_offset": "Evening offset (minutes)",
        "offset_help": "Negative shifts events earlier, positive later",
        "note": "Custom note (optional)",
        "note_placeholder": "Added to daylight and night events",
        "submit": "Generate subscription URL",
        "footer": [
            (
                "How it is calculated",
                "The sun and the moon come from NASA JPL's DE442 ephemeris, through the skyfield "
                "library. Sunrise, sunset, moonrise and moonset allow for refraction.",
            ),
            (
                "Twilight",
                "Civil: from 6° below the horizon to sunrise or sunset, enough light to be outdoors "
                "without lamps. Nautical: 6° to 12° below, the horizon visible at sea while bright stars are "
                "out. Astronomical: 12° to 18° below, the last trace of sunlight before full darkness.",
            ),
            (
                "Tip",
                "Choose only Daylight & night for an uncluttered calendar: the Daylight event still lists "
                "every twilight time.",
            ),
        ],
        # Messages shown by assets/script.js
        "js": {
            "enter_address": "Please enter an address.",
            "searching": "Searching…",
            "found": "✓ Location found: ",
            "not_found": "✗ Location not found",
            "search_error": "✗ Error searching for the location",
            "no_geolocation": "Your browser does not support geolocation.",
            "located": "✓ Location found",
            "locate_failed": "Unable to get your location: ",
            "permission_denied": "permission denied.",
            "unavailable": "location unavailable.",
            "timeout": "the request timed out.",
            "select_type": "Please select at least one event type.",
            "copied": "✓ Copied",
            "copy_failed": "Failed to copy: ",
        },
    },
}
