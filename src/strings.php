<?php

/**
 * Strings Configuration File
 * All user-facing text for the Sun, Twilight & Moon Calendar.
 *
 * Edit this file to customize event descriptions without touching the main code.
 */

declare(strict_types=1);

return [
    // Calendar metadata
    'calendar_name_suffix' => 'Sun, Twilight & Moon',
    'calendar_prodid' => '-//Sun\, Twilight & Moon Calendar//EN',
    'calendar_name_format' => '🌅☀️🌙 Sun, Twilight & Moon - %s', // %s = location name

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
        'civil_twilight' => '🌅 Civil Twilight',
        'nautical_twilight' => '⚓ Nautical Twilight',
        'astronomical_twilight' => '🌌 Astronomical Twilight',
        'daylight' => '☀️ Daylight',
        'night' => '🌙 Night',
        'moon_phase' => '🌙 Moon Phase',
        'week_overview' => '📊 Week Overview',
        'location_notes' => '📍 Location Notes',
        'astronomical_event' => '🌍 Astronomical Event',
        'daytime_schedule' => '☀️ Daytime Schedule',
        'nighttime_schedule' => '🌙 Nighttime Schedule',
        'at_a_glance' => '📋 At a Glance',
        'comparisons' => '📊 Comparisons',
        'details' => '📝 Details',
        'context' => '🔍 Context',
    ],

    // Field labels (using full words, no abbreviations)
    'labels' => [
        'time' => 'Time',
        'duration' => 'Duration',
        'solar_noon' => 'Solar Noon',
        'solar_midnight' => 'Solar Midnight',
        'period' => 'Period',
        'percentile' => 'Percentile',
        'progress' => 'Percentile',
        'vs_yesterday' => 'Compared to Yesterday',
        'vs_winter_solstice' => 'Compared to Winter Solstice',
        'vs_summer_solstice' => 'Compared to Summer Solstice',
        'vs_last_year' => 'Compared to Last Year',
        'trend' => 'Trend',
        'average' => 'Average Duration',
        'change' => 'Weekly Change',
        'shortest' => 'Shortest Day',
        'longest' => 'Longest Day',
        'moon' => 'Moon Phase',
        'current' => 'Current',
        'previous' => 'Previous',
        'next' => 'Next',
        'event' => 'Event',
        'date' => 'Date',
        'day_of_year' => 'Day of Year',
        'sunrise' => 'Sunrise',
        'sunset' => 'Sunset',
        'illumination' => 'Illumination',
        'of_day' => 'of the day',
    ],

    // Week summary
    'week_summary' => [
        'title_format' => '📅 Week Summary (%s - %s)', // %s = start date, end date
        'header' => '📊 Week Overview',
    ],

    // Event descriptions - concise, practical context for each phase
    'twilight_descriptions' => [
        'civil_dawn' => 'Bright enough for most outdoor activities without artificial light — '
            . 'driving, walking, and reading outside. The sky lightens rapidly as sunrise approaches. '
            . '(Sun 0°–6° below the horizon)',

        'nautical_dawn' => 'The horizon becomes visible against the brightening sky — historically the '
            . 'beginning of safe celestial navigation for sailors. Still too dim for most outdoor '
            . 'activities without artificial light; stars are fading but still visible. '
            . '(Sun 6°–12° below the horizon)',

        'astronomical_dawn' => 'The first faint traces of sunlight appear on the eastern horizon, ending '
            . 'true darkness. Only the subtlest brightening of the sky is visible — the faintest stars '
            . 'begin to fade. Good for deep-sky observation before the sky brightens further. '
            . '(Sun 12°–18° below the horizon)',

        'civil_dusk' => 'Still bright enough for most outdoor activities without artificial light — '
            . 'driving, walking, and reading outside remain comfortable. The sky gradually deepens after '
            . 'sunset; headlights become useful toward the end of this period. '
            . '(Sun 0°–6° below the horizon)',

        'nautical_dusk' => 'The horizon fades as the sky darkens. Stars become clearly visible and '
            . 'celestial navigation by stars is possible. Most outdoor activities now require '
            . 'artificial light. (Sun 6°–12° below the horizon)',

        'astronomical_dusk' => 'The last faint traces of sunlight fade as the sky approaches true '
            . 'darkness. Conditions are improving for deep-sky observation, with the best views coming '
            . 'once this period ends. (Sun 12°–18° below the horizon)',

        'daylight' => 'The sun is above the horizon. Full natural illumination for all outdoor activities.',

        'night' => 'Complete astronomical darkness — the sun is more than 18° below the horizon and '
            . 'no solar illumination remains. Optimal conditions for stargazing and deep-sky observation.',
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
        'daylight' => '%s%% of days this year have less daylight than today (0%% = shortest, 100%% = longest day)',
        'night' => '%s%% of nights this year are shorter than tonight (0%% = shortest night, 100%% = longest night)',
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
        'arctic' => '⚠️ Arctic: Midnight sun in summer, polar night in winter.',
        'antarctic' => '⚠️ Antarctic: Midnight sun in summer, polar night in winter.',
        'high_latitude' => 'ℹ️ High latitude: Extreme day length variations throughout the year.',
        'tropical' => 'ℹ️ Tropical: Minimal day length variation year-round.',
        'equatorial' => 'ℹ️ Equatorial: Nearly equal day and night year-round (~12 hours each).',
    ],

    // Special astronomical events
    'astronomical_events' => [
        'march_equinox' => [
            'name' => 'March Equinox',
            'emoji' => '⚖️',
            'description' => 'Day and night are approximately equal worldwide. '
                . 'Spring begins in the Northern Hemisphere, autumn in the Southern Hemisphere.',
        ],
        'june_solstice' => [
            'name' => 'June Solstice',
            'emoji' => '☀️',
            'description' => 'Longest day of the year in the Northern Hemisphere, shortest in the Southern. '
                . 'Summer begins in the north, winter in the south.',
        ],
        'september_equinox' => [
            'name' => 'September Equinox',
            'emoji' => '⚖️',
            'description' => 'Day and night are approximately equal worldwide. '
                . 'Autumn begins in the Northern Hemisphere, spring in the Southern Hemisphere.',
        ],
        'december_solstice' => [
            'name' => 'December Solstice',
            'emoji' => '🌙',
            'description' => 'Shortest day of the year in the Northern Hemisphere, longest in the Southern. '
                . 'Winter begins in the north, summer in the south.',
        ],
    ],
];
