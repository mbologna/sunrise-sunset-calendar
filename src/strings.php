<?php

/**
 * Strings Configuration File
 * All user-facing text for the Sun & Twilight Calendar.
 *
 * Edit this file to customize event descriptions without touching the main code.
 */

return [
    // Calendar metadata
    'calendar_name_suffix' => 'Sun & Twilight',
    'calendar_prodid' => '-//Sun & Twilight Calendar//EN',
    'calendar_name_format' => '☀️🌅 Sun & Twilight - %s', // %s = location name

    // Event summaries (used in event titles)
    'summaries' => [
        'civil_dawn' => '🌅 Civil Dawn',
        'nautical_dawn' => '⚓ Nautical Dawn',
        'astronomical_dawn' => '🌌 Astronomical Dawn',
        'daylight' => '☀️ Daylight',
        'civil_dusk' => '🌇 Civil Dusk',
        'nautical_dusk' => '⚓ Nautical Dusk',
        'astronomical_dusk' => '🌌 Astronomical Dusk',
        'night' => '🌙 Night',
        'week_summary' => '📅 Week Summary',
    ],

    // Section headers
    'headers' => [
        'civil_twilight' => '🌅 CIVIL TWILIGHT',
        'nautical_twilight' => '⚓ NAUTICAL TWILIGHT',
        'astronomical_twilight' => '🌌 ASTRONOMICAL TWILIGHT',
        'daylight' => '☀️ DAYLIGHT',
        'night' => '🌙 NIGHT',
        'moon_phase' => '🌙 MOON PHASE',
        'week_overview' => '📊 WEEK OVERVIEW',
        'location_notes' => '📍 LOCATION NOTES',
        'astronomical_event' => '🌍 ASTRONOMICAL EVENT',
        'daytime_schedule' => '☀️ DAYTIME SCHEDULE',
        'nighttime_schedule' => '🌙 NIGHTTIME SCHEDULE',
    ],

    // Field labels
    'labels' => [
        'time' => 'Time',
        'duration' => 'Duration',
        'solar_noon' => 'Solar Noon',
        'solar_midnight' => 'Solar Midnight',
        'period' => 'Period',
        'percentile' => 'Percentile',
        'vs_yesterday' => 'vs Yesterday',
        'vs_winter_solstice' => 'vs Winter Solstice',
        'vs_summer_solstice' => 'vs Summer Solstice',
        'trend' => 'Trend',
        'average' => 'Average',
        'change' => 'Change',
        'shortest' => 'Shortest',
        'longest' => 'Longest',
        'moon' => 'Moon',
        'current' => 'Current',
        'previous' => 'Previous',
        'next' => 'Next',
        'event' => 'Event',
        'date' => 'Date',
    ],

    // Week summary
    'week_summary' => [
        'title_format' => '📅 Week Summary (%s - %s)', // %s = start date, end date
        'header' => '📊 WEEK OVERVIEW',
    ],

    // Twilight definitions - detailed scientific descriptions
    'twilight_descriptions' => [
        'civil_dawn' => 'Civil dawn begins at "first light" when the sun is 6° below the horizon. This is the brightest twilight phase, with enough natural light that artificial lighting is typically not needed. The horizon becomes clearly visible and terrestrial objects are easily distinguishable. Civil dawn ends at sunrise when the sun\'s upper limb breaks the horizon.',

        'nautical_dawn' => 'Nautical dawn begins when the sun is 12° below the horizon. The horizon is visible at sea, allowing sailors to take reliable star sights for navigation. General outlines of ground objects are distinguishable, but detailed outdoor activities are not possible. Ends when civil dawn begins (sun at 6° below horizon).',

        'astronomical_dawn' => 'Astronomical dawn begins when the sun is 18° below the horizon and the first traces of sunlight appear. The sky is dark enough for astronomical observation of stars and planets, except where affected by light pollution or moonlight. Ends when nautical dawn begins (sun at 12° below horizon).',

        'civil_dusk' => 'Civil dusk begins at sunset when the sun\'s upper limb disappears below the horizon. This is the brightest twilight phase, with enough natural light that artificial lighting may not be needed. The horizon remains visible and terrestrial objects are easily distinguishable. Civil dusk ends at "last light" when the sun reaches 6° below the horizon.',

        'nautical_dusk' => 'Nautical dusk begins when the sun is 6° below the horizon. The horizon becomes difficult to see at sea and navigation by stars becomes possible. General outlines of ground objects are still visible but detailed activities require artificial light. Ends when the sun reaches 12° below the horizon.',

        'astronomical_dusk' => 'Astronomical dusk begins when the sun is 12° below the horizon. By its end (sun at 18° below), the sun ceases to provide any illumination. The sky reaches true darkness and conditions become ideal for astronomical observations of faint celestial objects.',

        'daylight' => 'The period between sunrise and sunset when the sun is above the horizon. Full daylight enables all outdoor activities without artificial lighting.',

        'night' => 'True night begins when the sun is more than 18° below the horizon. Complete darkness with no solar illumination whatsoever. Optimal conditions for stargazing and deep sky observations.',
    ],

    // Practical activity guides
    'practical_notes' => [
        'civil_dawn' => 'From first light to sunrise. Most outdoor activities can be performed without artificial lighting. Horizon clearly visible, terrestrial objects easily distinguishable.',

        'nautical_dawn' => 'Horizon visible at sea for navigation by stars. General outlines of ground objects distinguishable but detailed outdoor operations not possible.',

        'astronomical_dawn' => 'First traces of sunlight appear. Too dark for outdoor activities. Stars clearly visible. Final window for astronomical observations before brightening sky.',

        'civil_dusk' => 'From sunset to last light. Most outdoor activities can still be performed without artificial lighting. Horizon visible, terrestrial objects distinguishable.',

        'nautical_dusk' => 'Horizon difficult to see at sea. General outlines of objects still visible. Navigation by stars becomes possible.',

        'astronomical_dusk' => 'Sky dark enough for astronomical observations. Stars and planets visible except where affected by light pollution or moonlight.',

        'daylight' => 'Sun above the horizon from sunrise to sunset. Full natural illumination for all outdoor activities.',

        'night' => 'Sun more than 18° below horizon. Complete darkness with no solar illumination. Optimal conditions for astronomy and deep sky observations.',
    ],

    // Supplemental schedule descriptions (brief one-line summaries)
    'supplemental' => [
        'civil_dawn' => 'First light to sunrise – bright enough to see without artificial light',
        'nautical_dawn' => 'Horizon visible at sea for star navigation',
        'astronomical_dawn' => 'First traces of sunlight, stars still visible',
        'civil_dusk' => 'Sunset to last light – bright enough to see without artificial light',
        'nautical_dusk' => 'Horizon fading at sea, star navigation possible',
        'astronomical_dusk' => 'Fading to true darkness, stars becoming visible',
        'daylight' => 'Sun above the horizon, full natural illumination',
        'night' => 'True darkness (sun >18° below horizon), optimal for astronomy',
    ],

    // Percentile explanations
    // The percentile shows where this day/night ranks compared to all 365 days of the year.
    // 0th percentile = shortest daylight (winter solstice) / shortest night (summer solstice)
    // 50th percentile = median (middle of the year's range)
    // 100th percentile = longest daylight (summer solstice) / longest night (winter solstice)
    'percentile_explanation' => [
        'daylight' => 'Percentile rank: %s%% of all days in the year have less daylight than today. (0%% = winter solstice, 100%% = summer solstice)',
        'night' => 'Percentile rank: %s%% of all nights in the year are shorter than tonight. (0%% = summer solstice, 100%% = winter solstice)',
    ],

    // Comparison text templates
    'comparisons' => [
        'percentile_simple' => '%s%% percentile',
        'percentile_full' => '%s%% of days have less daylight (0%% = shortest, 100%% = longest)',
        'of_day' => '(%s%% of day)',
        'mon_to_sun' => '(Mon to Sun)',
        'lit' => '(%s%% lit)',
        'same_length' => 'same length as yesterday',
    ],

    // Trends
    'trends' => [
        'increasing' => 'Increasing',
        'decreasing' => 'Decreasing',
        'stable' => 'Stable',
    ],

    // Trend emojis
    'trend_emojis' => [
        'increasing' => '📈',
        'decreasing' => '📉',
        'stable' => '➡️',
    ],

    // Location notes
    'location_notes' => [
        'arctic' => '⚠️ ARCTIC: Midnight sun in summer, polar night in winter.',
        'antarctic' => '⚠️ ANTARCTIC: Midnight sun in summer, polar night in winter.',
        'high_latitude' => 'ℹ️ HIGH LATITUDE: Extreme day length variations throughout the year.',
        'tropical' => 'ℹ️ TROPICAL: Minimal day length variation year-round.',
        'equatorial' => 'ℹ️ EQUATORIAL: Nearly equal day and night year-round (~12 hours each).',
    ],

    // Special astronomical events
    'astronomical_events' => [
        'march_equinox' => [
            'name' => 'March Equinox',
            'emoji' => '⚖️',
            'description' => 'Day and night are approximately equal worldwide. Spring begins in the Northern Hemisphere, autumn in the Southern Hemisphere.',
        ],
        'june_solstice' => [
            'name' => 'June Solstice',
            'emoji' => '☀️',
            'description' => 'Longest day of the year in the Northern Hemisphere, shortest in the Southern Hemisphere. Summer begins in the north, winter in the south.',
        ],
        'september_equinox' => [
            'name' => 'September Equinox',
            'emoji' => '⚖️',
            'description' => 'Day and night are approximately equal worldwide. Autumn begins in the Northern Hemisphere, spring in the Southern Hemisphere.',
        ],
        'december_solstice' => [
            'name' => 'December Solstice',
            'emoji' => '🌙',
            'description' => 'Shortest day of the year in the Northern Hemisphere, longest in the Southern Hemisphere. Winter begins in the north, summer in the south.',
        ],
    ],
];
