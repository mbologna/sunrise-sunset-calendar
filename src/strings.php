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
        'first_light' => 'First Light',
        'last_light' => 'Last Light',
        'of_day' => 'of the day',
    ],

    // Week summary
    'week_summary' => [
        'title_format' => '📅 Week Summary (%s - %s)', // %s = start date, end date
        'header' => '📊 Week Overview',
    ],

    // Event descriptions - concise, practical context for each phase
    'twilight_descriptions' => [
        'civil_dawn' => 'The sky transitions from dim twilight to bright enough for outdoor activities '
            . 'without artificial light. Driving, walking, and reading outside are comfortable. '
            . 'By the end, full sunrise light arrives.',

        'nautical_dawn' => 'The sky transitions as the horizon becomes clearly visible — traditionally '
            . 'when sailors could navigate by stars. Still too dim for most outdoor activities without '
            . 'artificial light, but stars are fading as brightness increases.',

        'astronomical_dawn' => 'The sky transitions from true darkness as first sunlight traces appear '
            . 'on the eastern horizon. The faintest stars begin to fade as the sky subtly brightens. '
            . 'Ideal for deep-sky observation before brightness increases further.',

        'civil_dusk' => 'The sky transitions from bright to dimmer as the sun sinks below the horizon. '
            . 'Still bright enough for outdoor activities without artificial light, but gradually '
            . 'darkening. Headlights become useful toward the end of this period.',

        'nautical_dusk' => 'The sky transitions as the horizon fades and darkness deepens. Stars become '
            . 'clearly visible and celestial navigation by stars becomes possible. Most outdoor '
            . 'activities now require artificial light.',

        'astronomical_dusk' => 'The sky transitions as the last sunlight fades and true darkness '
            . 'approaches. Conditions improve rapidly for deep-sky observation, with the very best '
            . 'views arriving once this period ends.',

        'daylight' => 'The sun rises above the horizon, providing full natural illumination for all '
            . 'outdoor activities, until it sets again.',

        'night' => 'Complete astronomical darkness — no solar illumination remains. Optimal conditions '
            . 'for stargazing and deep-sky observation of faint objects.',
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
        'night' => 'Complete astronomical darkness, optimal for stargazing and deep-sky observation',
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
