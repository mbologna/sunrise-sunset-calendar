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
 * Calculate daylight percentile with caching.
 *
 * @param float $targetDaylightHours Target daylight hours to compare
 * @param float $lat Latitude
 * @param float $lon Longitude
 * @param int $year Year for calculation
 * @param float $utcOffset UTC offset in hours
 * @return float Percentile (0-100)
 */
function calculate_daylight_percentile(
    float $targetDaylightHours,
    float $lat,
    float $lon,
    int $year,
    float $utcOffset
): float {
    $daylightLengths = Cache::getInstance()->getDaylightLengths($lat, $lon, $year, $utcOffset);

    // When today is the longest day of the year, return exactly 100%.
    // Without this, the solstice would score 364/365 = 99.7% because no day
    // is strictly shorter than itself.
    if ($targetDaylightHours >= max($daylightLengths)) {
        return 100.0;
    }

    $countBelow = count(array_filter($daylightLengths, fn($length) => $length < $targetDaylightHours));

    return round(($countBelow / count($daylightLengths)) * 100, 1);
}


/**
 * Get emoji for moon phase.
 *
 * @param string $phaseName Name of the moon phase
 * @return string Moon phase emoji
 */
function get_moon_phase_emoji(string $phaseName): string
{
    $emojis = [
        'New Moon' => '🌑',
        'Waxing Crescent' => '🌒',
        'First Quarter' => '🌓',
        'Waxing Gibbous' => '🌔',
        'Full Moon' => '🌕',
        'Waning Gibbous' => '🌖',
        'Last Quarter' => '🌗',
        'Waning Crescent' => '🌘',
    ];

    return $emojis[$phaseName] ?? '🌙';
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
 * Generate a visual bar for a percentile.
 *
 * @param float $percentile Percentile value (0-100)
 * @param int $width Width of the bar in characters (default 10)
 * @return string Bar like ████████░░
 */
function format_percentile_bar(float $percentile, int $width = 10): string
{
    $filled = (int) round(($percentile / 100) * $width);

    return str_repeat('█', $filled) . str_repeat('░', $width - $filled);
}

/**
 * Describe where a day or night ranks among this year's: "Longer than 18% of this year's days".
 *
 * @param string $kind 'daylight' or 'night'
 */
function format_rank(float $percentile, string $kind, array $S): string
{
    if ($percentile >= 100) {
        return $S['rank']["{$kind}_longest"];
    }
    if ($percentile <= 0) {
        return $S['rank']["{$kind}_shortest"];
    }

    // Never round a non-extreme day up to 100% or down to 0%
    return sprintf($S['rank'][$kind], max(1, min(99, (int) round($percentile))));
}

/**
 * Compare two lengths in words: "2m 05s shorter than yesterday".
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
 * The line naming a period's boundaries: "From civil dawn 06:37 to sunrise 07:06".
 *
 * @param array{0: int, 1: int, 2: string, 3: string} $span From solar_spans()
 */
function format_span_line(array $span, array $S): string
{
    [$start, $end, $from, $to] = $span;

    return sprintf(
        $S['span_line'],
        $S['instants'][$from],
        format_local($start),
        $S['instants'][$to],
        format_local($end)
    );
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
 * Format the moon section: current phase, previous and next principal phase.
 *
 * @param array $moon Result of get_accurate_moon_phase()
 */
function format_moon_section(array $moon, array $S): string
{
    $section = sprintf($S['headers']['moon'], get_moon_phase_emoji($moon['phase_name'])) . "\n";
    $section .= sprintf($S['moon']['current'], $moon['phase_name'], (int) round($moon['illumination'])) . "\n";

    foreach (['previous' => 'prev_phase', 'next' => 'next_phase'] as $label => $key) {
        if ($moon[$key]) {
            $section .= sprintf(
                $S['moon'][$label],
                get_moon_phase_emoji($moon[$key]['name']),
                $moon[$key]['name'],
                format_local($moon[$key]['timestamp'], 'datetime')
            ) . "\n";
        }
    }

    return $section;
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
 *     extremes: ?array{earliest_sunrise: int, latest_sunrise: int, earliest_sunset: int, latest_sunset: int}
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

    $bands = [];
    foreach (twilight_profile_fields() as $band => [$deep, $shallow]) {
        $lengthsByDay = [];
        foreach ($days as $i => $d) {
            if ($d[$deep] !== null && $d[$shallow] !== null) {
                $lengthsByDay[$i] = (int) round(abs($d[$deep] - $d[$shallow]) * 86400);
            }
        }
        $allNight = array_map(fn($d) => $d[$deep] === null && $d[$shallow] !== null, $days);
        $bands[$band] = $lengthsByDay === [] ? null : [
            'min' => [min($lengthsByDay), $timestamps[array_search(min($lengthsByDay), $lengthsByDay, true)]],
            'max' => [max($lengthsByDay), $timestamps[array_search(max($lengthsByDay), $lengthsByDay, true)]],
            'all_night' => day_runs($allNight, $timestamps),
        ];
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
 * Explain why a twilight runs to or from solar noon or midnight, when it does.
 *
 * @param string $key Twilight key, e.g. 'evening_nautical'
 * @param array $span From solar_spans()
 * @return array<string> Zero, one or two sentences
 */
function no_crossing_notes(string $key, array $span, array $S): array
{
    $band = twilight_bands()[$key];
    $N = $S['no_crossing'];
    $notes = [];
    [$deepMissing, $highMissing] = missing_boundaries($key, $span);

    if ($band['morning'] && $deepMissing) {
        $notes[] = sprintf($N['deep_morning'], $band['deep']);
    }
    if ($highMissing) {
        $when = $band['morning'] ? 'morning' : 'evening';
        $notes[] = $band['high'] === 0
            ? $N["polar_{$when}"]
            : sprintf($N["high_{$when}"], $band['high']);
    }
    if (!$band['morning'] && $deepMissing) {
        $notes[] = sprintf($N['deep_evening'], $band['deep']);
    }

    return $notes;
}

/**
 * Blue and golden hour for a civil twilight or daylight, or null.
 *
 * @param string $key 'morning_civil', 'evening_civil' or 'daylight'
 * @param array $ev sun_events() for the day
 */
function light_line(string $key, array $ev, array $S): ?string
{
    $L = $S['light'];
    $parts = [];

    if ($key === 'morning_civil') {
        if ($ev['civil_begin'] !== null && $ev['minus4_begin'] !== null) {
            $parts[] = sprintf($L['blue_hour'], format_local($ev['civil_begin']), format_local($ev['minus4_begin']));
        }
        if ($ev['minus4_begin'] !== null) {
            $parts[] = sprintf($L['golden_from'], format_local($ev['minus4_begin']));
        }
    } elseif ($key === 'evening_civil') {
        if ($ev['minus4_end'] !== null) {
            $parts[] = sprintf($L['golden_until'], format_local($ev['minus4_end']));
            if ($ev['civil_end'] !== null) {
                $parts[] = sprintf($L['blue_hour'], format_local($ev['minus4_end']), format_local($ev['civil_end']));
            }
        }
    } elseif ($ev['plus6_begin'] !== null && $ev['plus6_end'] !== null) {
        $parts[] = sprintf($L['golden_daylight'], format_local($ev['plus6_begin']), format_local($ev['plus6_end']));
    } elseif ($ev['noon_elevation'] < 6) {
        $parts[] = $L['golden_all_day'];
    }

    return $parts ? implode(' · ', $parts) : null;
}

/**
 * Sequence line with the current twilight bracketed: "Night → [Astronomical] → …".
 */
function format_sequence(string $key, array $S): string
{
    $sequence = $S['sequence'][str_starts_with($key, 'morning') ? 'morning' : 'evening'];

    return implode(' → ', array_map(
        fn($k, $name) => $k === $key ? "[{$name}]" : $name,
        array_keys($sequence),
        $sequence
    ));
}

/**
 * How the sun moves through a twilight: the usual angles, or today's actual path
 * when it never reaches one of the boundaries.
 *
 * @param array $span From solar_spans()
 * @param array $ev sun_events() for the day
 */
function twilight_path(string $key, array $span, array $ev, array $S): string
{
    [$deepMissing, $highMissing] = missing_boundaries($key, $span);
    if (!$deepMissing && !$highMissing) {
        return $S['about'][$key]['angles'];
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
        return sprintf($D['path_morning'], $from, $to);
    }

    $from = match (true) {
        $highMissing => sprintf($D['from_noon'], $noon),
        $civil => $S['instants']['sunset'],
        default => sprintf($D['from_angle'], $band['high']),
    };
    $to = $deepMissing ? sprintf($D['to_midnight'], $midnight) : sprintf($D['to_angle'], $band['deep']);

    return sprintf($D['path_evening'], $from, $to);
}

/**
 * The "About" section of a twilight: what is happening, its length over the year, what it is good for.
 *
 * @param array $span From solar_spans()
 * @param array $ev sun_events() for the day
 * @param array $year year_summary() for the day's year
 */
function twilight_about(string $key, array $span, array $ev, array $year, array $S): string
{
    $about = $S['about'][$key];
    $D = $S['about_dynamic'];
    $date = fn(int $ts) => format_local($ts, 'date_short');
    $text = sprintf($S['headers']['about'], $S['names'][$key]) . "\n"
        . format_sequence($key, $S) . "\n\n" . twilight_path($key, $span, $ev, $S) . ' ' . $about['text'];

    $band = $year['bands'][$key];
    if ($band !== null) {
        $text .= "\n\n" . sprintf(
            $D[str_starts_with($key, 'morning') ? 'range_morning' : 'range_evening'],
            format_duration($band['min'][0]),
            $date($band['min'][1]),
            format_duration($band['max'][0]),
            $date($band['max'][1])
        );
        if ($band['all_night'] !== []) {
            $text .= ' ' . sprintf($D['all_night'], format_runs($band['all_night'], $S));
        }
    }

    return $text . "\n\n" . sprintf($S['labels']['good_for'], $about['good_for']);
}

/**
 * Daylight facts, shared by the Daylight event and the Today schedule.
 *
 * @param array $c Day context: ev, spans, daylight stats
 */
function daylight_details(array $c, array $S): string
{
    $ev = $c['ev'];
    $d = $c['daylight'];
    $L = $S['labels'];

    $lines = [$ev['polar'] === 'day' ? $L['midnight_sun'] : format_span_line($c['spans']['daylight'], $S)];
    $lines[] = $ev['polar'] === 'day'
        ? sprintf(
            $L['solar_noon_midnight_sun'],
            format_local($ev['solar_noon']),
            round($ev['noon_elevation']),
            round($ev['midnight_elevation'])
        )
        : sprintf($L['solar_noon'], format_local($ev['solar_noon']), round($ev['noon_elevation']));
    $lines[] = sprintf($L['length_share'], format_duration($d['seconds']), round($d['seconds'] / 864));
    $lines[] = format_percentile_bar($d['percentile']) . ' ' . format_rank($d['percentile'], 'daylight', $S);
    $light = light_line('daylight', $ev, $S);
    if ($light !== null) {
        $lines[] = $light;
    }

    return implode("\n", $lines) . "\n\n"
        . $S['headers']['comparisons'] . "\n" . implode("\n", $d['comparisons']) . "\n";
}

/**
 * Night facts with the moon, shared by the Night event and the Tonight schedule.
 *
 * @param array $c Day context: spans, night stats
 */
function night_details(array $c, array $S): string
{
    $span = $c['spans']['night'];
    $n = $c['night'];
    $L = $S['labels'];

    $lines = [
        format_span_line($span, $S),
        sprintf($L['solar_midnight'], format_local(intdiv($span[0] + $span[1], 2))),
        sprintf($L['length_share'], format_duration($n['seconds']), round($n['seconds'] / 864)),
        format_percentile_bar($n['percentile']) . ' ' . format_rank($n['percentile'], 'night', $S),
    ];
    if ($n['comparison'] !== null) {
        $lines[] = $n['comparison'];
    }

    return implode("\n", $lines) . "\n\n" . format_moon_section($n['moon'], $S);
}

/**
 * The "About" section of the Daylight event.
 */
function daylight_about(array $ev, array $S): string
{
    $about = $S['about']['daylight'];
    $text = $about['text'];
    if ($ev['noon_elevation'] > 0) {
        $text .= ' ' . sprintf($about['noon'], round($ev['noon_elevation']));
    }
    if (light_line('daylight', $ev, $S) !== null) {
        $text .= ' ' . $about['golden'];
    }

    return sprintf($S['headers']['about'], $S['names']['daylight']) . "\n" . $text;
}

/**
 * The "About" section of the Night event, with a sentence on tonight's moon.
 *
 * @param array $moon Result of get_accurate_moon_phase()
 */
function night_about(array $moon, array $S): string
{
    $lit = (int) round($moon['illumination']);
    $bucket = $lit < 25 ? 'moon_dark' : ($lit <= 75 ? 'moon_partial' : 'moon_bright');

    return sprintf($S['headers']['about'], $S['names']['night']) . "\n"
        . $S['about']['night']['text'] . ' ' . sprintf($S['about_dynamic'][$bucket], $lit);
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
        $details = $period === 'daylight' ? daylight_details($c, $S) : night_details($c, $S);
        $info .= ($info === '' ? '' : "\n") . $S['schedule_labels'][$period] . "\n" . $details;
    }

    return $info === '' ? '' : "\n\n" . $S['headers'][$header] . "\n" . rtrim($info, "\n");
}
