<?php

/**
 * Helper functions for calendar generation.
 *
 * iCalendar formatting, formatting of times and lengths, year facts, and the
 * text of each event description.
 */

declare(strict_types=1);

use SunCalendar\Cache;

/**
 * Format a TEXT property line per RFC 5545: escape, then fold at 75 octets.
 *
 * Folding never splits a multibyte UTF-8 character.
 *
 * @param string $name Property name, optionally with parameters (e.g. "SUMMARY")
 * @param string $text Unescaped value; real newlines become literal \n
 * @return string Folded property line(s), terminated by CRLF
 */
function ical_text_property(string $name, string $text): string
{
    // Backslashes first, so the escapes added below are not doubled
    $text = str_replace('\\', '\\\\', $text);
    $text = str_replace(["\r\n", "\r", "\n"], '\n', $text);
    $text = str_replace([',', ';'], ['\\,', '\\;'], $text);

    $result = '';
    $line = '';
    foreach (mb_str_split($name . ':' . $text, 1, 'UTF-8') as $char) {
        if (strlen($line) + strlen($char) > 75) {
            $result .= $line . "\r\n";
            $line = ' ';
        }
        $line .= $char;
    }

    return $result . $line . "\r\n";
}

/**
 * Format description for iCalendar with proper escaping and line folding.
 *
 * @param string $text The text to format
 * @return string RFC 5545 compliant DESCRIPTION property
 */
function format_ical_description(string $text): string
{
    return ical_text_property('DESCRIPTION', $text);
}


/**
 * Get emoji for moon phase, as the moon looks from the observer's hemisphere.
 *
 * @param string $phaseName Phase name as get_accurate_moon_phase() reports it
 * @param float $lat Observer's latitude; south of the equator the lit side is mirrored
 * @return string Moon phase emoji
 */
function get_moon_phase_emoji(string $phaseName, float $lat = 0.0): string
{
    $S = get_strings();

    return ($S['moon_phases'][$phaseName] ?? $S['moon_phase_unknown'])[$lat < 0 ? 1 : 0];
}

/**
 * Display name of a moon phase, from strings['moon_phases'].
 *
 * @param string $phaseName Phase name as get_accurate_moon_phase() reports it
 */
function moon_phase_name(string $phaseName): string
{
    $S = get_strings();

    return ($S['moon_phases'][$phaseName] ?? $S['moon_phase_unknown'])[2];
}

// ============================================================================
// FORMATTING
// ============================================================================

/**
 * Format a timestamp with one of the strings['formats'] patterns.
 */
function format_local(int $timestamp, string $format = 'time'): string
{
    return date(get_strings()['formats'][$format], $timestamp);
}

/**
 * Where a length sits in the year's range, as a labelled bar with the shortest
 * on the left and the longest on the right: "9h 08m ▕██░░░░░░░░▏ 15h 14m".
 *
 * The bar is linear between the two ends, so it reads like a ruler, unlike a
 * rank among days. Each end is labelled because the bar does not start at zero.
 */
function format_range_bar(int $seconds, int $shortest, int $longest, int $width = 10): string
{
    $span = $longest - $shortest;
    $filled = $span > 0 ? (int) round(($seconds - $shortest) / $span * $width) : intdiv($width, 2);
    $filled = max(0, min($width, $filled));

    return format_duration($shortest) . ' ▕' . str_repeat('█', $filled) . str_repeat('░', $width - $filled)
        . '▏ ' . format_duration($longest);
}

/**
 * Today against the year's shortest and longest day (or night), in words.
 *
 * @param string $kind 'daylight' or 'night'
 * @param array{0: int, 1: int} $shortest Length in seconds and its day's timestamp
 * @param array{0: int, 1: int} $longest Length in seconds and its day's timestamp
 */
function format_extremes(string $kind, int $seconds, array $shortest, array $longest, array $S): string
{
    $Y = $S['year'][$kind];
    $date = fn(int $ts) => format_local($ts, 'date_short');
    // Seconds matter only for a gap under a minute (the days around a solstice, the equator)
    $gap = fn(int $diff) => format_duration($diff, $diff < 60);
    // Only the extreme day itself, allowing for rounding: near the equator the whole year spans minutes
    if ($seconds - $shortest[0] <= 2) {
        return sprintf($Y['is_shortest'], $gap($longest[0] - $seconds), $date($longest[1]));
    }
    if ($longest[0] - $seconds <= 2) {
        return sprintf($Y['is_longest'], $gap($seconds - $shortest[0]), $date($shortest[1]));
    }

    return sprintf(
        $Y['between'],
        $gap($seconds - $shortest[0]),
        $date($shortest[1]),
        $gap($longest[0] - $seconds),
        $date($longest[1])
    );
}
/**
 * Compare two lengths in words: "2m 07s shorter than yesterday".
 *
 * @param int $diffSeconds This length minus the other
 * @param string $referent What it is compared with, e.g. "yesterday"
 */
function format_comparison(int $diffSeconds, string $referent, array $S): string
{
    if ($diffSeconds === 0) {
        return sprintf($S['comparisons']['same'], $referent);
    }

    return sprintf(
        $S['comparisons'][$diffSeconds > 0 ? 'longer' : 'shorter'],
        format_duration($diffSeconds, true),
        $referent
    );
}

/**
 * How far an event moved on the clock since the day before: "1m 18s later".
 *
 * @param int $shiftSeconds Today's time minus yesterday's, a whole day removed
 */
function format_clock_shift(int $shiftSeconds, array $S): string
{
    $C = $S['clock'];
    if (abs($shiftSeconds) < 1) {
        return $C['same'];
    }

    return sprintf($C[$shiftSeconds > 0 ? 'later' : 'earlier'], format_duration($shiftSeconds, true));
}
/**
 * Format one twilight line for the Today/Tonight schedule.
 *
 * @param string $label Period title
 * @param array{0: int, 1: int} $span Start and end timestamps
 * @param string $note One-line description
 */
function format_schedule_span(string $label, array $span, string $note): string
{
    [$start, $end] = $span;

    return "{$label}: " . format_local($start) . '–' . format_local($end)
        . ' (' . format_duration($end - $start) . ")\n  {$note}\n";
}

/**
 * The moon during the night: its phase, when the sky is moonless, how much it
 * brightens the sky while up, and the next principal phase.
 *
 * The moonless window is what matters for stargazing; it needs 'rise_set'
 * (moon_rise_set() over the night) and 'night' ([start, end]) in $moon.
 *
 * @param array $moon Result of get_accurate_moon_phase(), plus 'rise_set', 'night' and the observer's 'lat'
 */
function format_moon_section(array $moon, array $S): string
{
    $M = $S['moon'];
    $lat = $moon['lat'] ?? 0.0;
    $lit = (int) round($moon['illumination']);
    $emoji = get_moon_phase_emoji($moon['phase_name'], $lat);

    $lines = [sprintf($M['current'], moon_phase_name($moon['phase_name']), $lit)];
    if (isset($moon['rise_set'], $moon['night'])) {
        $windows = moonless_windows($moon['rise_set'], $moon['night'][0], $moon['night'][1]);
        $whole = $moon['night'][1] - $moon['night'][0];
        $lines[] = match (true) {
            $windows === [] => $M['moonless_none'],
            count($windows) === 1 && $windows[0][1] - $windows[0][0] >= $whole => $M['moonless_all'],
            default => sprintf($M['moonless'], implode(', ', array_map(
                fn($w) => format_local($w[0]) . '–' . format_local($w[1]) . ' (' . format_duration($w[1] - $w[0]) . ')',
                $windows
            ))),
        };
        $upAtAll = $windows === [] || count($windows) > 1 || $windows[0][1] - $windows[0][0] < $whole;
        if ($upAtAll) {
            $lines[] = $M['impact'][$lit < 25 ? 'dark' : ($lit <= 75 ? 'partial' : 'bright')];
        }
    }
    if ($moon['next_phase']) {
        $lines[] = sprintf(
            $M['next'],
            get_moon_phase_emoji($moon['next_phase']['name'], $lat),
            moon_phase_name($moon['next_phase']['name']),
            format_local($moon['next_phase']['timestamp'], 'datetime')
        );
    }

    return sprintf($S['headers']['moon'], $emoji) . "\n" . implode("\n", $lines) . "\n";
}

/**
 * The parts of [$from, $to] when the moon is below the horizon.
 *
 * @param array{up_at_start: bool, events: array<array{0: string, 1: int}>} $riseSet From moon_rise_set()
 * @return array<array{0: int, 1: int}>
 */
function moonless_windows(array $riseSet, int $from, int $to): array
{
    $windows = [];
    $downSince = $riseSet['up_at_start'] ? null : $from;
    foreach ($riseSet['events'] as [$kind, $at]) {
        if ($kind === 'set') {
            $downSince = $at;
        } elseif ($downSince !== null) {
            $windows[] = [$downSince, $at];
            $downSince = null;
        }
    }
    if ($downSince !== null) {
        $windows[] = [$downSince, $to];
    }

    return $windows;
}
// ============================================================================
// YEAR PROFILE
// ============================================================================

/**
 * Runs of consecutive true flags; a run spanning New Year stays whole.
 *
 * @param array<bool> $flags One per day of the year
 * @param array<int> $timestamps Matching day timestamps
 * @return array<array{0: int, 1: int}> First and last day of each run, by first day
 */
function day_runs(array $flags, array $timestamps): array
{
    $n = count($flags);
    if (!in_array(true, $flags, true)) {
        return [];
    }
    if (!in_array(false, $flags, true)) {
        return [[$timestamps[0], $timestamps[$n - 1]]];
    }

    // Start scanning just after a false day, so no run is cut at the year boundary
    $offset = (int) array_search(false, $flags, true) + 1;
    $runs = [];
    $runStart = null;
    for ($i = 0; $i <= $n; $i++) {
        $idx = ($offset + $i) % $n;
        if ($i < $n && $flags[$idx]) {
            $runStart ??= $idx;
        } elseif ($runStart !== null) {
            $runs[] = [$timestamps[$runStart], $timestamps[($idx - 1 + $n) % $n]];
            $runStart = null;
        }
    }
    usort($runs, fn($a, $b) => $a[0] <=> $b[0]);

    return $runs;
}

/**
 * Format date runs: "May 18 to Jul 25 and from Nov 28 to Jan 14".
 *
 * @param array<array{0: int, 1: int}> $runs From day_runs()
 */
function format_runs(array $runs, array $S): string
{
    return implode($S['range_join'], array_map(
        fn($run) => sprintf($S['date_range'], format_local($run[0], 'date_short'), format_local($run[1], 'date_short')),
        $runs
    ));
}

/**
 * Twilight boundaries in a year-profile record: [deep crossing, shallow crossing] fields.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function twilight_profile_fields(): array
{
    return [
        'morning_astronomical' => ['astro_begin_frac', 'nautical_begin_frac'],
        'morning_nautical' => ['nautical_begin_frac', 'civil_begin_frac'],
        'morning_civil' => ['civil_begin_frac', 'sunrise_frac'],
        'evening_civil' => ['civil_end_frac', 'sunset_frac'],
        'evening_nautical' => ['nautical_end_frac', 'civil_end_frac'],
        'evening_astronomical' => ['astro_end_frac', 'nautical_end_frac'],
    ];
}

/**
 * Facts about a location's year, derived from Cache::getYearProfile().
 *
 * @return array{
 *     days: array<array>, polar: bool,
 *     daylight_min: array{0: float, 1: int}, daylight_max: array{0: float, 1: int},
 *     midnight_sun: array, polar_night: array, white_nights: array,
 *     bands: array<string, ?array{min: array{0: int, 1: int}, max: array{0: int, 1: int}, all_night: array}>,
 *     extremes: ?array{earliest_sunrise: int, latest_sunrise: int, earliest_sunset: int, latest_sunset: int},
 *     nights: array<?int>
 * } Lengths in hours (daylight) or seconds (bands), each paired with its day's timestamp
 */
function year_summary(float $lat, float $lon, int $year, float $utcOffset): array
{
    static $memo = [];
    $key = sprintf('%.4f:%.4f:%d:%.2f:%s', $lat, $lon, $year, $utcOffset, date_default_timezone_get());
    if (isset($memo[$key])) {
        return $memo[$key];
    }

    $days = Cache::getInstance()->getYearProfile($lat, $lon, $year, $utcOffset);
    $timestamps = array_column($days, 'timestamp');
    $lengths = array_column($days, 'daylength_h');
    // Extreme value of a per-day column, with the day it falls on
    $pick = function (array $values, callable $choose) use ($timestamps): array {
        $i = array_search($choose($values), $values, true);
        return [$values[$i], $timestamps[$i]];
    };

    $polarDay = array_map(fn($d) => $d['polar'] === 'day', $days);
    $polarNight = array_map(fn($d) => $d['polar'] === 'night', $days);
    // No astronomical night: the sun never crosses -18° because it stays above it at midnight
    $whiteNights = array_map(
        fn($d) => $d['astro_begin_frac'] === null && abs($lat + $d['declination_deg']) - 90 > -18,
        $days
    );

    // Twilight lengths by day, with the same solar noon and midnight stand-ins as
    // solar_spans(), so the range always contains the length an event shows
    $bands = [];
    foreach (twilight_profile_fields() as $band => [$deep, $shallow]) {
        ['deep' => $deepAngle, 'high' => $highAngle, 'morning' => $morning] = twilight_bands()[$band];
        $lengthsByDay = [];
        foreach ($days as $i => $d) {
            $noon = $d['solar_noon_frac'];
            if ($d[$deep] === null && $d[$shallow] === null) {
                // Neither boundary crossed: the sun is inside the band all day, or never
                $inBand = abs($lat + $d['declination_deg']) - 90 > -$deepAngle
                    && 90 - abs($lat - $d['declination_deg']) < -$highAngle;
                if ($inBand) {
                    $lengthsByDay[$i] = 43200;
                }
                continue;
            }
            $span = $morning
                ? ($d[$shallow] ?? $noon) - ($d[$deep] ?? $noon - 0.5)
                : ($d[$deep] ?? $noon + 0.5) - ($d[$shallow] ?? $noon);
            $lengthsByDay[$i] = (int) round($span * 86400);
        }
        $allNight = array_map(fn($d) => $d[$deep] === null && $d[$shallow] !== null, $days);
        $bands[$band] = $lengthsByDay === [] ? null : [
            'min' => [min($lengthsByDay), $timestamps[array_search(min($lengthsByDay), $lengthsByDay, true)]],
            'max' => [max($lengthsByDay), $timestamps[array_search(max($lengthsByDay), $lengthsByDay, true)]],
            'all_night' => day_runs($allNight, $timestamps),
        ];
    }

    // Night lengths by day, with the same solar-noon stand-in as solar_spans() for days
    // the sun stays more than 18° below the horizon. December's last night borrows
    // January 1 of the same year for its morning; the dates differ by a year, not the sun.
    $allDark = array_map(
        fn($d) => $d['astro_begin_frac'] === null && 90 - abs($lat - $d['declination_deg']) < -18,
        $days
    );
    $nights = [];
    foreach ($days as $i => $d) {
        $j = ($i + 1) % count($days);
        $start = $d['astro_end_frac'] ?? ($allDark[$i] ? $d['solar_noon_frac'] : null);
        $end = $days[$j]['astro_begin_frac'] ?? ($allDark[$j] ? $days[$j]['solar_noon_frac'] : null);
        $nights[$i] = ($start !== null && $end !== null) ? (int) round(($end + 1 - $start) * 86400) : null;
    }

    $polar = in_array(true, $polarDay, true) || in_array(true, $polarNight, true);
    $extremes = null;
    if (!$polar) {
        $sunrises = array_column($days, 'sunrise_frac');
        $sunsets = array_column($days, 'sunset_frac');
        $extremes = [
            'earliest_sunrise' => $pick($sunrises, 'min')[1],
            'latest_sunrise' => $pick($sunrises, 'max')[1],
            'earliest_sunset' => $pick($sunsets, 'min')[1],
            'latest_sunset' => $pick($sunsets, 'max')[1],
        ];
    }

    return $memo[$key] = [
        'days' => $days,
        'polar' => $polar,
        'daylight_min' => $pick($lengths, 'min'),
        'daylight_max' => $pick($lengths, 'max'),
        'midnight_sun' => day_runs($polarDay, $timestamps),
        'polar_night' => day_runs($polarNight, $timestamps),
        'white_nights' => day_runs($whiteNights, $timestamps),
        'bands' => $bands,
        'extremes' => $extremes,
        'nights' => $nights,
    ];
}

/**
 * Facts about the location for the calendar description.
 *
 * @param array $year Result of year_summary()
 * @return array<string> One sentence per fact
 */
function location_facts(array $year, array $S): array
{
    $F = $S['location_facts'];
    $date = fn(int $ts) => format_local($ts, 'date_short');
    $facts = [];

    if (!$year['polar']) {
        $facts[] = sprintf(
            $F['range'],
            format_duration((int) round($year['daylight_min'][0] * 3600)),
            $date($year['daylight_min'][1]),
            format_duration((int) round($year['daylight_max'][0] * 3600)),
            $date($year['daylight_max'][1])
        );
    }
    foreach (['midnight_sun', 'polar_night', 'white_nights'] as $fact) {
        if ($year[$fact] !== []) {
            $facts[] = sprintf($F[$fact], format_runs($year[$fact], $S));
        }
    }

    return $facts;
}

// ============================================================================
// EQUINOXES AND SOLSTICES
// ============================================================================

/**
 * Get equinoxes and solstices for a year, named by the season they start at $lat.
 *
 * @return array<array{key: string, season: string, date: int}> Key as in calculate_equinox_solstice()
 */
function get_special_astronomical_events(int $year, float $lat): array
{
    $seasons = $lat >= 0
        ? ['march_equinox' => 'spring_equinox', 'june_solstice' => 'summer_solstice',
            'september_equinox' => 'autumn_equinox', 'december_solstice' => 'winter_solstice']
        : ['march_equinox' => 'autumn_equinox', 'june_solstice' => 'winter_solstice',
            'september_equinox' => 'spring_equinox', 'december_solstice' => 'summer_solstice'];

    $events = [];
    foreach (calculate_equinox_solstice($year) as $key => $timestamp) {
        $events[] = ['key' => $key, 'season' => $seasons[$key], 'date' => $timestamp];
    }

    return $events;
}

/**
 * Describe an equinox or solstice for the feed's location.
 *
 * Each sentence is included only when the computed data makes it true here.
 *
 * @param array{key: string, season: string, date: int} $event
 * @param array $ev sun_events() for the event's local date
 * @param array $year year_summary() for the event's year
 */
function season_description(array $event, array $ev, array $year, float $lat, array $S): string
{
    $T = $S['season_text'];
    $date = fn(int $ts) => format_local($ts, 'date_short');
    $sameDay = fn(int $a, int $b) => date('Y-m-d', $a) === date('Y-m-d', $b);
    $seconds = (int) round($ev['daylength_h'] * 3600);
    $month = (int) date('n', $event['date']);

    $lines = [
        sprintf($T['moment'], $T['moment_names'][$event['key']], format_local($event['date'])),
        $T['sun'][$event['key']],
        sprintf(
            $T['begins'],
            $S['seasons'][$event['season']]['season'],
            $date(mktime(12, 0, 0, $month, 1, (int) date('Y', $event['date'])))
        ),
    ];

    if (str_ends_with($event['season'], 'equinox')) {
        if ($ev['sunrise'] !== null && $ev['sunset'] !== null) {
            $lines[] = $T['east_west'];
        }
        $line = sprintf($T['daylight'], format_duration($seconds));
        if ($seconds >= 12 * 3600 && $seconds <= 12 * 3600 + 20 * 60) {
            $line .= ' ' . $T['near_12'];
        }
        $lines[] = $line;

        // Nearest day to the equinox where daylight crosses 12 hours
        $hours = array_column($year['days'], 'daylength_h');
        $at = (int) date('z', $event['date']);
        $best = null;
        for ($i = max(0, $at - 20); $i < min(count($hours) - 1, $at + 20); $i++) {
            if (($hours[$i] - 12) * ($hours[$i + 1] - 12) <= 0) {
                $closer = abs($hours[$i] - 12) <= abs($hours[$i + 1] - 12) ? $i : $i + 1;
                if ($best === null || abs($closer - $at) < abs($best - $at)) {
                    $best = $closer;
                }
            }
        }
        if ($best !== null) {
            $lines[] = sprintf($T['equilux'], $date($year['days'][$best]['timestamp']));
        }

        return implode("\n", $lines);
    }

    $summer = $event['season'] === 'summer_solstice';
    $noon = (int) round($ev['noon_elevation']);
    // The year's extreme must be today's length and fall near the solstice: near the
    // equator day length varies by seconds and peaks on unrelated dates
    $isExtreme = fn(array $extreme) => abs($ev['daylength_h'] - $extreme[0]) * 3600 < 30
        && abs($extreme[1] - $event['date']) <= 3 * 86400;
    if ($summer) {
        if ($ev['polar'] !== 'day' && $isExtreme($year['daylight_max'])) {
            $lines[] = sprintf($T['longest_day'], format_duration($seconds));
        }
        // Outside the tropics the noon sun peaks at the solstice; inside, it passes overhead twice a year
        $lines[] = sprintf(abs($lat) >= abs($ev['declination']) ? $T['noon_highest'] : $T['noon_tropics'], $noon);
        if ($year['midnight_sun'] !== []) {
            $lines[] = sprintf($T['midnight_sun'], format_runs($year['midnight_sun'], $S));
        }
        $extremes = $year['extremes']
            ? [$year['extremes']['earliest_sunrise'], $year['extremes']['latest_sunset']]
            : null;
    } else {
        if ($ev['polar'] !== 'night' && $isExtreme($year['daylight_min'])) {
            $lines[] = sprintf($T['shortest_day'], format_duration($seconds));
        }
        if ($ev['polar'] === 'night') {
            $lines[] = sprintf($T['noon_below'], abs($noon));
        } elseif ($ev['noon_elevation'] > 0) {
            $lines[] = sprintf($T['noon_lowest'], $noon);
        }
        if ($year['polar_night'] !== []) {
            $lines[] = sprintf($T['polar_night'], format_runs($year['polar_night'], $S));
        }
        $extremes = $year['extremes']
            ? [$year['extremes']['earliest_sunset'], $year['extremes']['latest_sunrise']]
            : null;
    }

    if ($extremes !== null) {
        $line = sprintf($T[$summer ? 'summer_extremes' : 'winter_extremes'], $date($extremes[0]), $date($extremes[1]));
        if (!$sameDay($extremes[0], $event['date']) && !$sameDay($extremes[1], $event['date'])) {
            $line .= ' ' . $T['not_on_solstice'];
        }
        $lines[] = $line;
    }

    return implode("\n", $lines);
}

// ============================================================================
// EVENT DESCRIPTIONS
// ============================================================================

/**
 * Which of a twilight's boundary angles the sun fails to reach that day.
 *
 * @param array $span From solar_spans()
 * @return array{0: bool, 1: bool} Deep boundary missing (solar midnight stands in),
 *     shallow boundary missing (solar noon stands in)
 */
function missing_boundaries(string $key, array $span): array
{
    $morning = twilight_bands()[$key]['morning'];

    return [$span[$morning ? 2 : 3] === 'solar_midnight', $span[$morning ? 3 : 2] === 'solar_noon'];
}

/**
 * The data lines of a twilight event: its length within the year's range, the
 * sun's path when it misses a boundary, and blue and golden hour.
 *
 * The event's own start and end are not repeated: the calendar shows them.
 *
 * @param array $span From solar_spans()
 * @param array $ev sun_events() for the day
 * @param array $year year_summary() for the day's year
 */
function twilight_details(string $key, array $span, array $ev, array $year, array $S): string
{
    $L = $S['labels'];
    $length = format_duration($span[1] - $span[0]);
    $band = $year['bands'][$key];

    $lines = [$band === null
        ? sprintf($L['length'], $length)
        : sprintf(
            $L['length_range'],
            $length,
            format_range_bar($span[1] - $span[0], $band['min'][0], $band['max'][0])
        )];
    $path = twilight_path($key, $span, $ev, $S);
    if ($path !== null) {
        $lines[] = $path;
    }
    if ($band !== null && $band['all_night'] !== []) {
        $lines[] = sprintf($L['all_night'], format_runs($band['all_night'], $S));
    }

    $text = implode("\n", $lines);
    if (str_ends_with($key, '_civil')) {
        $light = format_light_block($ev, $S, $span[0], $span[1]);
        $text .= $light === '' ? '' : "\n\n" . $light;
    }

    return $text;
}
/**
 * Today's blue hours (sun 6° to 4° below) and golden hours (4° below to 6°
 * above), in time order.
 *
 * A null start or end means the period runs on past solar midnight (white
 * nights, midnight sun); both null means the sun stays in that band all day.
 *
 * @param array $ev sun_events() for the day
 * @return array<array{0: string, 1: ?int, 2: ?int}> ['blue'|'golden', start, end]
 */
function light_periods(array $ev): array
{
    $periods = [];
    if ($ev['civil_begin'] !== null && $ev['minus4_begin'] !== null) {
        $periods[] = ['blue', $ev['civil_begin'], $ev['minus4_begin']];
    }

    $sunBelowSixAllDay = $ev['plus6_begin'] === null && $ev['noon_elevation'] < 6;
    $sunAboveMinusFourAllDay = $ev['minus4_begin'] === null && $ev['midnight_elevation'] > -4;
    if ($sunBelowSixAllDay) {
        // The sun never climbs past 6°: one golden period around noon, if it gets above -4° at all
        if ($ev['minus4_begin'] !== null) {
            $periods[] = ['golden', $ev['minus4_begin'], $ev['minus4_end']];
        } elseif ($sunAboveMinusFourAllDay) {
            $periods[] = ['golden', null, null];
        }
    } elseif ($ev['plus6_begin'] !== null) {
        $periods[] = ['golden', $ev['minus4_begin'], $ev['plus6_begin']];
        $periods[] = ['golden', $ev['plus6_end'], $ev['minus4_end']];
    }

    if ($ev['minus4_end'] !== null && $ev['civil_end'] !== null) {
        $periods[] = ['blue', $ev['minus4_end'], $ev['civil_end']];
    }

    return $periods;
}

/**
 * The "Light for photos" block: blue and golden hours in time order, limited to
 * those overlapping [$from, $to] when given (a twilight shows its own).
 *
 * @param array $ev sun_events() for the day
 */
function format_light_block(array $ev, array $S, ?int $from = null, ?int $to = null): string
{
    $L = $S['light'];
    $lines = [];
    foreach (light_periods($ev) as [$kind, $start, $end]) {
        $overlaps = ($from === null || $end === null || $end > $from)
            && ($to === null || $start === null || $start < $to);
        if (!$overlaps) {
            continue;
        }
        $when = match (true) {
            $start === null && $end === null => $L['all_day'],
            $start === null => sprintf($L['until'], format_local($end)),
            $end === null => sprintf($L['from'], format_local($start)),
            default => format_local($start) . '–' . format_local($end),
        };
        $lines[] = "{$when} {$L[$kind]}";
    }

    return $lines === [] ? '' : $L['header'] . "\n" . implode("\n", $lines);
}
/**
 * Today's sequence with the current twilight bracketed: "Night → [Astronomical] → …".
 *
 * Only the twilights that occur today are listed, between the instants that
 * actually open and close them (solar noon or midnight when the sun misses one).
 *
 * @param array $spans From solar_spans()
 */
function format_sequence(string $key, array $spans, array $S): string
{
    $Q = $S['sequence'];
    $morning = str_starts_with($key, 'morning');
    $keys = $morning
        ? ['morning_astronomical', 'morning_nautical', 'morning_civil']
        : ['evening_civil', 'evening_nautical', 'evening_astronomical'];
    $present = array_values(array_filter($keys, fn($k) => $spans[$k] !== null));
    $first = $spans[$present[0]];
    $last = $spans[$present[count($present) - 1]];

    if ($morning) {
        $open = $first[2] === 'solar_midnight' ? 'solar_midnight' : 'night';
        $close = $last[3] === 'solar_noon' ? 'solar_noon' : 'sunrise';
    } else {
        $open = $first[2] === 'solar_noon' ? 'solar_noon' : 'sunset';
        $close = $last[3] === 'solar_midnight' ? 'solar_midnight' : 'night';
    }

    $items = array_map(fn($k) => $k === $key ? "[{$Q[$k]}]" : $Q[$k], $present);

    return implode(' → ', [$Q[$open], ...$items, $Q[$close]]);
}

/**
 * The sun's path through a twilight on a day it misses one of the boundaries, or null.
 *
 * @param array $span From solar_spans()
 * @param array $ev sun_events() for the day
 */
function twilight_path(string $key, array $span, array $ev, array $S): ?string
{
    [$deepMissing, $highMissing] = missing_boundaries($key, $span);
    if (!$deepMissing && !$highMissing) {
        return null;
    }

    $band = twilight_bands()[$key];
    $D = $S['about_dynamic'];
    $noon = (int) round(abs($ev['noon_elevation']));
    $midnight = (int) round(abs($ev['midnight_elevation']));
    $civil = $band['high'] === 0;

    if ($band['morning']) {
        $from = $deepMissing ? sprintf($D['from_midnight'], $midnight) : sprintf($D['from_angle'], $band['deep']);
        $to = match (true) {
            $highMissing => sprintf($D['to_noon'], $noon),
            $civil => $S['instants']['sunrise'],
            default => sprintf($D['to_angle'], $band['high']),
        };
        $path = sprintf($D['path_morning'], $from, $to);
    } else {
        $from = match (true) {
            $highMissing => sprintf($D['from_noon'], $noon),
            $civil => $S['instants']['sunset'],
            default => sprintf($D['from_angle'], $band['high']),
        };
        $to = $deepMissing ? sprintf($D['to_midnight'], $midnight) : sprintf($D['to_angle'], $band['deep']);
        $path = sprintf($D['path_evening'], $from, $to);
    }

    // A civil twilight that never reaches the horizon means the sun does not rise
    return $civil && $highMissing ? $D['polar_night'] . lcfirst($path) : $path;
}

/**
 * The "About" section of a twilight: what is happening and what it is good for.
 *
 * @param array $span From solar_spans()
 * @param array $spans All of today's spans, for the sequence
 */
function twilight_about(string $key, array $span, array $spans, array $S): string
{
    $about = $S['about'][$key];
    [$deepMissing, $highMissing] = missing_boundaries($key, $span);
    // The usual angles are wrong on a day the data lines describe a different path
    $angles = ($deepMissing || $highMissing) ? '' : $about['angles'] . ' ';

    // What is happening, then why its length and light vary, then what it is good for
    $light = str_ends_with($key, '_civil') ? ' ' . $S['about_dynamic']['light'] : '';

    return sprintf($S['headers']['about'], $S['names'][$key]) . "\n"
        . format_sequence($key, $spans, $S) . "\n\n"
        . $angles . $about['text'] . "\n\n"
        . $S['about_dynamic'][str_starts_with($key, 'morning') ? 'length_morning' : 'length_evening']
        . $light . "\n\n"
        . sprintf($S['labels']['good_for'], $about['good_for']);
}

/**
 * Daylight facts, shared by the Daylight event and the Today schedule: how the
 * day is changing, where the sun peaks, and where the day sits in the year.
 *
 * @param array $c Day context: ev, daylight stats, year
 */
function daylight_details(array $c, array $S): string
{
    $ev = $c['ev'];
    $d = $c['daylight'];
    $year = $c['year'];
    $L = $S['labels'];

    $lines = $ev['polar'] === 'day' ? [$L['midnight_sun']] : [];
    $trend = format_comparison($d['change'], $S['comparisons']['yesterday'], $S);
    if ($d['sunrise_shift'] !== null) {
        $trend .= sprintf(
            $L['clock'],
            format_clock_shift($d['sunrise_shift'], $S),
            format_clock_shift($d['sunset_shift'], $S)
        );
    }
    $lines[] = $trend;
    $lines[] = $ev['polar'] === 'day'
        ? sprintf(
            $L['sun_peak_midnight_sun'],
            format_local($ev['solar_noon']),
            round($ev['noon_elevation']),
            format_local($ev['solar_noon'] + 43200),
            round($ev['midnight_elevation'])
        )
        : sprintf($L['sun_peak'], format_local($ev['solar_noon']), round($ev['noon_elevation']));

    $shortest = [(int) round($year['daylight_min'][0] * 3600), $year['daylight_min'][1]];
    $longest = [(int) round($year['daylight_max'][0] * 3600), $year['daylight_max'][1]];
    $through = [format_range_bar($d['seconds'], $shortest[0], $longest[0])];
    // With polar day or night the extremes last weeks, so a single date would mislead
    if (!$year['polar']) {
        $through[] = format_extremes('daylight', $d['seconds'], $shortest, $longest, $S);
    }

    return implode("\n", $lines) . "\n\n" . $S['headers']['year'] . "\n" . implode("\n", $through) . "\n";
}

/**
 * Night facts with the moon, shared by the Night event and the Tonight schedule.
 *
 * @param array $c Day context: spans, night stats, year
 */
function night_details(array $c, array $S): string
{
    $span = $c['spans']['night'];
    $n = $c['night'];
    $L = $S['labels'];

    // Deep polar night: solar noon stands in for the start or end of the night
    $noonStart = $span[2] === 'solar_noon';
    $noonEnd = $span[3] === 'solar_noon';
    $lines = [];
    if ($noonStart && $noonEnd) {
        $lines[] = $L['night_all_day'];
    } elseif ($noonStart || $noonEnd) {
        $lines[] = $S['no_crossing'][$noonStart ? 'night_from_noon' : 'night_to_noon'];
    }
    // Round-the-clock nights differ only by seconds of solar-noon drift: no change to give
    if (!($noonStart && $noonEnd) && $n['comparison'] !== null) {
        $lines[] = $n['comparison'];
    }

    $text = ($lines === [] ? '' : implode("\n", $lines) . "\n\n") . format_moon_section($n['moon'], $S);

    $nights = array_filter($c['year']['nights'], fn($length) => $length !== null);
    if ($nights !== [] && !($noonStart && $noonEnd)) {
        $days = array_column($c['year']['days'], 'timestamp');
        $shortest = [min($nights), $days[array_search(min($nights), $nights, true)]];
        $longest = [max($nights), $days[array_search(max($nights), $nights, true)]];
        $text .= "\n" . $S['headers']['year'] . "\n" . format_range_bar($n['seconds'], $shortest[0], $longest[0]);
        // Polar or white-night years have no single shortest or longest night worth dating
        if (!$c['year']['polar'] && $c['year']['white_nights'] === []) {
            $text .= "\n" . format_extremes('night', $n['seconds'], $shortest, $longest, $S);
        }
        $text .= "\n";
    }

    return $text;
}

/**
 * The "About" section of the Daylight event.
 */
function daylight_about(array $ev, array $S): string
{
    $about = $S['about']['daylight'];
    $text = $about['text'];
    if (light_periods($ev) !== []) {
        $text .= ' ' . $S['about_dynamic']['light'];
    }

    return sprintf($S['headers']['about'], $S['names']['daylight']) . "\n" . $text;
}
/**
 * The "About" section of the Night event.
 */
function night_about(array $S): string
{
    return sprintf($S['headers']['about'], $S['names']['night']) . "\n" . $S['about']['night']['text'];
}

/**
 * Build the Today schedule: morning periods not subscribed to as events, so a
 * minimal calendar still carries them.
 *
 * @param array $c Day context: ev, spans, enabled, daylight stats
 */
function build_dawn_supplemental(array $c, array $S): string
{
    $twilights = ['morning_astronomical', 'morning_nautical', 'morning_civil'];

    return build_supplemental($c, $S, 'today', $twilights, 'daylight');
}

/**
 * Build the Tonight schedule (evening counterpart of build_dawn_supplemental()).
 *
 * @param array $c Day context: spans, enabled, night stats
 */
function build_dusk_supplemental(array $c, array $S): string
{
    $twilights = ['evening_civil', 'evening_nautical', 'evening_astronomical'];

    return build_supplemental($c, $S, 'tonight', $twilights, 'night');
}

/**
 * All of today's twilights, for a calendar subscribed to no twilight events, so
 * Daylight (or Night, when the sun does not rise) still carries every time.
 *
 * @param array $c Day context: spans, enabled
 */
function build_twilight_schedule(array $c, array $S): string
{
    if ($c['enabled']['civil'] || $c['enabled']['nautical'] || $c['enabled']['astro']) {
        return '';
    }
    $keys = [
        'morning_astronomical', 'morning_nautical', 'morning_civil',
        'evening_civil', 'evening_nautical', 'evening_astronomical',
    ];
    $info = '';
    foreach ($keys as $key) {
        if ($c['spans'][$key] !== null) {
            $info .= format_schedule_span($S['summaries'][$key], $c['spans'][$key], $S['supplemental'][$key]);
        }
    }

    return $info === '' ? '' : "\n\n" . $S['headers']['twilight'] . "\n" . rtrim($info, "\n");
}

/**
 * @param array<string> $twilights Twilight keys in chronological order
 * @param string $period 'daylight' or 'night', listed after the twilights
 */
function build_supplemental(array $c, array $S, string $header, array $twilights, string $period): string
{
    $bands = twilight_bands();
    $info = '';
    foreach ($twilights as $key) {
        if (!$c['enabled'][$bands[$key]['flag']] && $c['spans'][$key] !== null) {
            $info .= format_schedule_span($S['summaries'][$key], $c['spans'][$key], $S['supplemental'][$key]);
        }
    }

    if (!$c['enabled']['daylight'] && $c['spans'][$period] !== null) {
        // Not this event's own period, so its times are worth listing here
        $span = $c['spans'][$period];
        $label = $S['schedule_labels'][$period] . ': ' . format_local($span[0]) . '–' . format_local($span[1])
            . ' (' . format_duration($span[1] - $span[0]) . ')';
        $details = $period === 'daylight' ? daylight_details($c, $S) : night_details($c, $S);
        $info .= ($info === '' ? '' : "\n") . $label . "\n" . $details;
    }

    return $info === '' ? '' : "\n\n" . $S['headers'][$header] . "\n" . rtrim($info, "\n");
}
