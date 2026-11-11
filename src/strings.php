<?php

/**
 * Strings Configuration File
 * All user-facing text for the Sun & Twilight Calendar.
 *
 * Every event is a period, titled with its name (Civil Twilight, Daylight,
 * Night). Descriptions don't repeat what the calendar already shows (the start
 * and end times), and explain each name through the period itself rather than
 * through the instants that bound it.
 *
 * Edit this file to customize event text without touching the main code.
 */

declare(strict_types=1);

return [
    // Calendar metadata
    'calendar_prodid' => '-//Sun & Twilight Calendar//EN',
    'calendar_name_format' => '🌅 %s · Sun & Twilight', // %s = location name, or coordinates
    'calendar_description_format' => 'Sunrise, sunset and twilight for %1$s, times in %2$s. Sunrise and '
        . 'sunset computed with the NREL Solar Position Algorithm; twilight and the moon with standard '
        . 'astronomical formulas.', // %1$s = place, %2$s = time zone
    'place_named' => '%1$s (%2$s, %3$s)', // name, latitude, longitude
    'place_coordinates' => '%2$s, %3$s',

    // Date and time formats (PHP date())
    'formats' => [
        'time' => 'H:i',
        'date_short' => 'M j',
        'datetime' => 'D, M j, H:i',
    ],
    'none' => '—', // a time that does not occur today

    // Duration units: "9h 05m", "32m", "2m 05s", "45s"
    'units' => [
        'hours_minutes' => '%dh %02dm',
        'minutes' => '%dm',
        'minutes_seconds' => '%dm %02ds',
        'seconds' => '%ds',
    ],
    'date_range' => '%s to %s',
    'range_join' => ' and from ',

    // Event titles
    'summaries' => [
        // Morning or evening shows from the time of day; the description names the instants
        'morning_astronomical' => '🌌 Astronomical Twilight',
        'morning_nautical' => '⚓ Nautical Twilight',
        'morning_civil' => '🌅 Civil Twilight',
        'evening_civil' => '🌇 Civil Twilight',
        'evening_nautical' => '⚓ Nautical Twilight',
        'evening_astronomical' => '🌌 Astronomical Twilight',
        'daylight' => '☀️ Daylight · %s', // %s = length
        'night' => '%s Night · %s', // %s = moon phase emoji, length
    ],

    // Weekly summary titles, by daylight trend (%s = change over the week)
    'week_titles' => [
        'increasing' => '📈 %s more daylight this week',
        'decreasing' => '📉 %s less daylight this week',
        'stable' => '➡️ Day length steady this week',
    ],

    // Period names in running text ("About morning civil twilight")
    'names' => [
        'morning_astronomical' => 'morning astronomical twilight',
        'morning_nautical' => 'morning nautical twilight',
        'morning_civil' => 'morning civil twilight',
        'evening_civil' => 'evening civil twilight',
        'evening_nautical' => 'evening nautical twilight',
        'evening_astronomical' => 'evening astronomical twilight',
        'daylight' => 'daylight',
        'night' => 'night',
    ],

    // The sun's path sentence on days a twilight misses a boundary ("climbs … to sunrise")
    'instants' => [
        'sunrise' => 'sunrise',
        'sunset' => 'sunset',
    ],

    // Today's sequence of twilights; the current one is bracketed
    'sequence' => [
        'night' => 'Night',
        'sunrise' => 'Sunrise',
        'sunset' => 'Sunset',
        'solar_noon' => 'Solar noon',
        'solar_midnight' => 'Solar midnight',
        'morning_astronomical' => 'Astronomical',
        'morning_nautical' => 'Nautical',
        'morning_civil' => 'Civil',
        'evening_civil' => 'Civil',
        'evening_nautical' => 'Nautical',
        'evening_astronomical' => 'Astronomical',
    ],

    // Section headers
    'headers' => [
        'about' => 'ℹ️ About %s',
        'year' => '📅 Through the year',
        'moon' => '%s Moon', // %s = moon phase emoji
        'today' => '☀️ Today',
        'tonight' => '🌙 Tonight',
        'twilight' => '🌗 Twilight today',
    ],

    // Data lines
    'labels' => [
        'length' => 'Length: %s',
        'length_range' => 'Length: %s · over the year: %s', // today, range bar
        'clock' => ': sunrise %s, sunset %s', // appended to the change since yesterday
        'sun_peak' => 'Sun highest at %s, %d° above the horizon',
        'sun_peak_midnight_sun' => 'Sun highest at %s, %d° up; lowest at %s, still %d° up',
        'midnight_sun' => 'Midnight sun: the sun does not set today.',
        'night_all_day' => 'Polar night: the sun stays more than 18° below the horizon all day, so night lasts '
            . 'around the clock.',
        'all_night' => 'Over the year it also lasts all night, from %s.',
        'week_daylight' => 'Daylight: %s on Sunday → %s on Saturday',
        'week_sun' => 'Sunrise %s → %s · Sunset %s → %s', // Sunday → Saturday
        'week_moon' => 'Moon: %s',
        'offset_note' => 'Calendar times shifted from the actual times: start %s, end %s.',
        'good_for' => 'Good for: %s',
    ],

    // How far sunrise or sunset moved on the clock since yesterday
    'clock' => [
        'later' => '%s later',
        'earlier' => '%s earlier',
        'same' => 'unchanged',
    ],

    // Today between the year's shortest and longest day or night (lengths, dates)
    'year' => [
        'daylight' => [
            'between' => '%s more than the shortest day (%s), %s less than the longest (%s)',
            'is_shortest' => 'The shortest day of the year: %s less than the longest (%s)',
            'is_longest' => 'The longest day of the year: %s more than the shortest (%s)',
        ],
        'night' => [
            'between' => '%s more than the shortest night (%s), %s less than the longest (%s)',
            'is_shortest' => 'The shortest night of the year: %s less than the longest (%s)',
            'is_longest' => 'The longest night of the year: %s more than the shortest (%s)',
        ],
    ],

    // Blue hour (sun 6° to 4° below) and golden hour (4° below to 6° above), on the Daylight event
    'light' => [
        'morning' => '📷 Morning: %s',
        'midday' => '📷 Around noon: %s',
        'evening' => '📷 Evening: %s',
        'item' => '%s %s', // name, time
        'blue' => 'blue hour',
        'golden' => 'golden hour',
        'until' => 'until %s',
        'from' => 'from %s',
        'all_day' => '📷 Golden hour all day: the sun stays between 4° below and 6° above the horizon',
    ],

    // Length comparisons: "2m 07s shorter than yesterday"
    'comparisons' => [
        'longer' => '%s longer than %s',
        'shorter' => '%s shorter than %s',
        'same' => 'Same length as %s',
        'yesterday' => 'yesterday',
        'last_night' => 'last night',
    ],

    // What each period is, in plain language. 'angles' describes the sun's path on a
    // normal day; when solar noon or midnight stands in for a boundary, the
    // about_dynamic 'path_*' sentence describes that day's path instead.
    'about' => [
        'morning_astronomical' => [
            'angles' => "The sun climbs from 18° to 12° below the horizon (nearly two fists to just over one, "
                . "at arm's length).",
            'text' => 'It is named for astronomers: the faintest galaxies and nebulae need the sun at least 18° '
                . 'down, so they begin to fade as this twilight starts. To the eye the sky still looks like '
                . 'night, especially under light pollution; under a dark sky a faint glow grows where the sun '
                . 'will rise, and the faintest stars fade first.',
            'good_for' => 'the last dark-sky observing; planets and bright stars are still easy to see.',
        ],
        'morning_nautical' => [
            'angles' => "The sun climbs from 12° to 6° below the horizon (just over one fist to about three "
                . "fingers, at arm's length).",
            'text' => 'It is named for navigation at sea: the horizon becomes visible while bright stars are '
                . 'still out, so sailors could measure star altitudes with a sextant. On land, trees and '
                . 'buildings show as silhouettes against a deep blue sky, but most tasks still need a light.',
            'good_for' => 'bright stars and planets against a blue sky; silhouette photos.',
        ],
        'morning_civil' => [
            'angles' => "The sun climbs from 6° below the horizon (about three fingers at arm's length) to "
                . 'sunrise.',
            'text' => 'It is named for everyday ("civil") activity: there is enough light to be outdoors '
                . 'without lamps under a clear sky. Colours return, only the brightest stars and planets '
                . 'remain, and streetlights typically switch off.',
            'good_for' => 'early walks and runs; seeing the first colours of the day.',
        ],
        'evening_civil' => [
            'angles' => "The sun sinks from sunset to 6° below the horizon (about three fingers at arm's length).",
            'text' => 'It is named for everyday ("civil") activity: until the sun is 6° down there is still '
                . 'enough light to be outdoors without lamps. Colours fade, the brightest stars and planets '
                . 'appear, and streetlights typically switch on.',
            'good_for' => 'evening walks; the first bright stars and planets.',
        ],
        'evening_nautical' => [
            'angles' => "The sun sinks from 6° to 12° below the horizon (about three fingers to just over one "
                . "fist, at arm's length).",
            'text' => 'It is named for navigation at sea: the horizon is still visible while bright stars are '
                . 'out, so sailors could take star sights with a sextant. Most tasks now need a light. '
                . 'Satellites, including the International Space Station, are often visible: they are still '
                . 'sunlit high overhead.',
            'good_for' => 'bright stars and planets; spotting satellites.',
        ],
        'evening_astronomical' => [
            'angles' => "The sun sinks from 12° to 18° below the horizon (just over one fist to nearly two, at "
                . "arm's length).",
            'text' => 'The last sunlight fades from the sky low where the sun set. Most people already see full '
                . 'night, but the sky is not at its darkest until this twilight ends with the sun 18° down. '
                . 'It is named for astronomers, who need that darkness for the faintest galaxies and nebulae.',
            'good_for' => 'setting up a telescope; under a dark, moonless sky the Milky Way emerges once the sun '
                . 'is 18° down.',
        ],
        'daylight' => [
            'text' => 'Daylight runs from sunrise to sunset, while any part of the sun is above the horizon. '
                . 'Times assume a flat, open horizon; hills and buildings change what you see.',
        ],
        'night' => [
            'text' => 'This is astronomical night: the sun is more than 18° below the horizon, so no sunlight '
                . 'brightens the sky. What glow remains comes from the moon, light pollution and natural airglow.',
        ],
    ],

    // Sentences that depend on the day
    'about_dynamic' => [
        // The sun's path on a day when solar noon or midnight bounds the twilight
        'path_morning' => 'Today the sun climbs from %s to %s.',
        'path_evening' => 'Today the sun sinks from %s to %s.',
        'from_angle' => '%d° below the horizon',
        'from_noon' => '%d° below the horizon at solar noon, its highest today,',
        'from_midnight' => '%d° below the horizon at solar midnight, its lowest,',
        'to_angle' => '%d° below',
        'to_noon' => '%d° below at solar noon, its highest today',
        'to_midnight' => '%d° below at solar midnight, its lowest tonight',
        'polar_night' => 'Polar night: ',
        // What the photo-light times mean
        'light' => 'Blue hour (sun 6° to 4° below) and golden hour (4° below to 6° above) are photographers\' '
            . 'rules of thumb, not fixed boundaries: a deep, even blue sky, then warm, low light with long '
            . 'shadows, if the weather allows.',
        // Why the length in the "Length" line changes over the year
        'length_morning' => 'Its length changes over the year because the sun rises at a different angle: '
            . 'the more gently it rises, the longer twilight lasts.',
        'length_evening' => 'Its length changes over the year because the sun sets at a different angle: '
            . 'the more gently it sets, the longer twilight lasts.',
    ],

    // When the sun stays more than 18° below the horizon all day, solar noon bounds the night
    'no_crossing' => [
        'night_from_noon' => 'The sun stays more than 18° below the horizon all day today, so this night starts '
            . 'at solar noon.',
        'night_to_noon' => 'The sun stays more than 18° below the horizon all day tomorrow, so this night lasts '
            . 'until solar noon.',
    ],

    // One-line notes in the Today/Tonight schedule
    'supplemental' => [
        'morning_astronomical' => 'First trace of sunlight; the faintest stars fade',
        'morning_nautical' => 'Horizon visible at sea while bright stars are out',
        'morning_civil' => 'Enough light to be outdoors without lamps',
        'evening_civil' => 'Enough light to be outdoors without lamps',
        'evening_nautical' => 'Bright stars out while the horizon is still visible at sea',
        'evening_astronomical' => 'Last trace of sunlight; the faintest stars appear',
    ],
    'schedule_labels' => [
        'daylight' => '☀️ Daylight',
        'night' => '🌙 Night',
    ],

    // Moon phase emojis (as seen from the northern hemisphere, then the southern) and
    // names, keyed by the names the astronomy code uses. South of the equator the
    // moon looks mirrored: a waxing crescent is lit on the left.
    'moon_phases' => [
        'New Moon' => ['🌑', '🌑', 'New Moon'],
        'Waxing Crescent' => ['🌒', '🌘', 'Waxing Crescent'],
        'First Quarter' => ['🌓', '🌗', 'First Quarter'],
        'Waxing Gibbous' => ['🌔', '🌖', 'Waxing Gibbous'],
        'Full Moon' => ['🌕', '🌕', 'Full Moon'],
        'Waning Gibbous' => ['🌖', '🌔', 'Waning Gibbous'],
        'Last Quarter' => ['🌗', '🌓', 'Last Quarter'],
        'Waning Crescent' => ['🌘', '🌒', 'Waning Crescent'],
    ],
    'moon_phase_unknown' => ['🌙', '🌙', 'Moon'],

    // Moon
    'moon' => [
        'current' => '%s, %d%% lit', // phase name, illumination
        'moonless' => 'Moonless sky: %s', // time windows
        'moonless_all' => 'Moonless sky: all night',
        'moonless_none' => 'Moonless sky: none, the moon is up all night',
        // How much the moon brightens the sky while up, by illumination (under 25%, up to 75%, above)
        'impact' => [
            'dark' => 'Too thin to brighten the sky much, even while up.',
            'partial' => 'While up, its light washes out the faintest stars and galaxies.',
            'bright' => 'While up, it washes out all but the brighter stars: a good night to look at the moon itself.',
        ],
        'next' => 'Next: %s %s, %s', // emoji, phase name, date
        'phase_at' => '%s %s, %s', // emoji, phase name, date (weekly summary)
    ],


    // Equinoxes and solstices, named by the season they start at the feed's location
    'seasons' => [
        'spring_equinox' => ['title' => '🌱 Spring Equinox', 'season' => 'Spring'],
        'summer_solstice' => ['title' => '☀️ Summer Solstice', 'season' => 'Summer'],
        'autumn_equinox' => ['title' => '🍂 Autumn Equinox', 'season' => 'Autumn'],
        'winter_solstice' => ['title' => '❄️ Winter Solstice', 'season' => 'Winter'],
    ],
    'season_text' => [
        'moment' => 'The %s is at %s.', // event name, local time
        'moment_names' => [
            'march_equinox' => 'March equinox',
            'june_solstice' => 'June solstice',
            'september_equinox' => 'September equinox',
            'december_solstice' => 'December solstice',
        ],
        'sun' => [
            'march_equinox' => 'The sun is directly over the equator, heading north.',
            'june_solstice' => 'The sun is directly over the Tropic of Cancer, its farthest point north.',
            'september_equinox' => 'The sun is directly over the equator, heading south.',
            'december_solstice' => 'The sun is directly over the Tropic of Capricorn, its farthest point south.',
        ],
        'begins' => '%1$s begins here by the astronomical calendar; by the weather (meteorological) calendar '
            . 'it began on %2$s.', // season, date
        'east_west' => 'The sun rises almost due east and sets almost due west.',
        'daylight' => 'Daylight here: %s.',
        'near_12' => 'A few minutes over 12 hours, so day and night are not quite equal today: sunrise and '
            . "sunset count the sun's top edge, and the air bends its light over the horizon.",
        'equilux' => 'Day and night are closest to equal on %s, when daylight crosses 12 hours.',
        'longest_day' => 'The longest day of the year: %s of daylight.',
        'shortest_day' => 'The shortest day of the year: %s of daylight.',
        'noon_highest' => 'The noon sun is at its highest of the year: %d° above the horizon.',
        'noon_lowest' => 'The noon sun is at its lowest of the year: %d° above the horizon.',
        'noon_tropics' => 'Noon sun: %d°. In the tropics the sun is highest on two other days of the year, '
            . 'when it passes straight overhead.',
        'noon_below' => 'The sun stays %d° below the horizon even at noon.',
        'midnight_sun' => 'Midnight sun here from %s.',
        'polar_night' => 'Polar night here from %s.',
        'summer_extremes' => 'Earliest sunrise of the year: around %s; latest sunset: around %s.',
        'winter_extremes' => 'Earliest sunset of the year: around %s; latest sunrise: around %s.',
        'not_on_solstice' => 'Neither falls on the solstice itself.',
    ],

    // Facts about the location, in the calendar description
    'location_facts' => [
        'range' => 'Daylight here ranges from %s (%s) to %s (%s) over the year.',
        'midnight_sun' => 'Midnight sun from %s.',
        'polar_night' => 'Polar night from %s.',
        'white_nights' => 'From %s the sun never gets 18° below the horizon, so there is no '
            . 'astronomical night and the calendar has no Night events.',
    ],

    // Responses of the HTTP entry point
    'errors' => [
        'missing_token' => 'Error: Please set AUTH_TOKEN via environment variable or config/config.php',
        'invalid_token' => 'Invalid authentication token',
        'invalid_password' => 'Invalid password',
    ],

    // Web page for generating subscription URLs
    'web' => [
        'title' => 'Sun & Twilight Calendar',
        'heading' => '🌅 Sun & Twilight Calendar',
        'today_heading' => "Today in Rome",
        'today_date' => 'Date: %s',
        'today_date_format' => 'l, F j, Y',
        'today_times' => [
            'astro_begin' => 'Astronomical dawn',
            'nautical_begin' => 'Nautical dawn',
            'civil_begin' => 'Civil dawn',
            'sunrise' => 'Sunrise',
            'solar_noon' => 'Solar noon',
            'sunset' => 'Sunset',
            'civil_end' => 'Civil dusk',
            'nautical_end' => 'Nautical dusk',
            'astro_end' => 'Astronomical dusk',
        ],
        'day_length' => 'Day length: %s',
        'error' => 'Error:',
        'generated' => '✅ Subscription URL generated',
        'subscription_url' => 'Your calendar subscription URL:',
        'copy_url' => '📋 Copy URL',
        'webcal_url' => 'Webcal URL (recommended):',
        'copy_webcal' => '📋 Copy webcal URL',
        'google_heading' => 'Adding it to Google Calendar',
        'google_link' => 'Google Calendar',
        'google_steps' => [
            'Copy the webcal URL above.',
            'Open %s.', // %s = link to Google Calendar
            'Click + next to "Other calendars".',
            'Choose "From URL".',
            'Paste the URL.',
            'Click "Add calendar".',
        ],
        'auth_heading' => '🔒 Password required',
        'auth_text' => 'Generating a calendar feed needs the password.',
        'use_location' => '📍 Use my current location',
        'search_address' => '🔍 Search for an address',
        'address' => 'Address',
        'address_placeholder' => 'e.g. Rome, Italy',
        'search_location' => 'Search',
        'password' => 'Password',
        'location' => 'Location name',
        'location_placeholder' => 'e.g. Rome',
        'location_help' => 'Calendar name: "🌅 Your location · Sun & Twilight"',
        'latitude' => 'Latitude',
        'latitude_help' => 'Decimal degrees, e.g. 41.9028',
        'longitude' => 'Longitude',
        'longitude_help' => 'Decimal degrees, e.g. 12.4964',
        'timezone' => 'Time zone',
        'event_types' => 'Event types',
        'event_types_help' => '💡 Each adds a morning and an evening event. The times of types you leave out '
            . 'are still listed in the notes of the others.',
        'types' => [
            'civil' => '🌅 Civil twilight: the sun between 6° below the horizon and sunrise, and between '
                . 'sunset and 6° below',
            'nautical' => '⚓ Nautical twilight: the sun 6° to 12° below the horizon, morning and evening',
            'astro' => '🌌 Astronomical twilight: the sun 12° to 18° below the horizon, morning and evening',
            'daynight' => '☀️🌙 Daylight & night: sunrise to sunset, and night with the sun more than 18° below '
                . '(with day length, the moon and a weekly summary)',
        ],
        'rise_offset' => 'Morning offset (minutes)',
        'set_offset' => 'Evening offset (minutes)',
        'offset_help' => 'Negative shifts events earlier, positive later',
        'note' => 'Custom note (optional)',
        'note_placeholder' => 'Added to daylight and night events',
        'submit' => 'Generate subscription URL',
        'footer' => [
            ['How it is calculated', 'Sunrise and sunset come from the NREL Solar Position Algorithm, which allows '
                . 'for refraction; twilight and the moon from standard astronomical formulas.'],
            ['Twilight', 'Civil: from 6° below the horizon to sunrise or sunset, enough light to be outdoors '
                . 'without lamps. Nautical: 6° to 12° below, the horizon visible at sea while bright stars are '
                . 'out. Astronomical: 12° to 18° below, the last trace of sunlight before full darkness.'],
            ['Tip', 'Choose only Daylight & night for an uncluttered calendar: the Daylight event still lists '
                . 'every twilight time.'],
        ],
        // Messages shown by assets/script.js
        'js' => [
            'enter_address' => 'Please enter an address.',
            'searching' => 'Searching…',
            'found' => '✓ Location found: ',
            'not_found' => '✗ Location not found',
            'search_error' => '✗ Error searching for the location',
            'no_geolocation' => 'Your browser does not support geolocation.',
            'located' => '✓ Location found',
            'locate_failed' => 'Unable to get your location: ',
            'permission_denied' => 'permission denied.',
            'unavailable' => 'location unavailable.',
            'timeout' => 'the request timed out.',
            'select_type' => 'Please select at least one event type.',
            'enter_password' => 'Please enter the password.',
            'copied' => '✓ Copied',
            'copy_failed' => 'Failed to copy: ',
        ],
    ],
];
