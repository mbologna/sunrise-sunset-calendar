<?php

/**
 * Helper functions for calendar generation.
 *
 * These functions provide utilities for iCalendar formatting, location notes,
 * astronomical events, week summaries, and supplemental information.
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
 * Get location notes based on latitude.
 *
 * @param float $lat Latitude in degrees
 * @return array<string> Array of location notes
 */
function get_location_notes(float $lat): array
{
    $strings = get_strings();
    $notes = [];

    if (abs($lat) > 66.5) {
        $notes[] = $lat > 0
            ? $strings['location_notes']['arctic']
            : $strings['location_notes']['antarctic'];
    }
    if (abs($lat) > 60 && abs($lat) <= 66.5) {
        $notes[] = $strings['location_notes']['high_latitude'];
    }
    if (abs($lat) < 23.5) {
        $notes[] = $strings['location_notes']['tropical'];
    }
    if (abs($lat) < 5) {
        $notes[] = $strings['location_notes']['equatorial'];
    }

    return $notes;
}

/**
 * Get equinoxes and solstices for a year, at their computed instants.
 *
 * @param int $year Year to get events for
 * @return array<array{date: int, name: string, emoji: string, description: string}>
 */
function get_special_astronomical_events(int $year): array
{
    $strings = get_strings()['astronomical_events'];
    $events = [];

    foreach (calculate_equinox_solstice($year) as $key => $timestamp) {
        $events[] = ['date' => $timestamp] + $strings[$key];
    }

    return $events;
}

/**
 * Generate a visual progress bar for percentile display.
 *
 * @param float $percentile Percentile value (0-100)
 * @param int $width Width of the bar in characters (default 10)
 * @return string Visual progress bar like [████████░░] 82%
 */
function format_percentile_bar(float $percentile, int $width = 10): string
{
    $filled = (int) round(($percentile / 100) * $width);
    $empty = $width - $filled;
    $bar = str_repeat('█', $filled) . str_repeat('░', $empty);

    return sprintf('[%s] %d%%', $bar, (int) round($percentile));
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

/**
 * Format duration with full words (no abbreviations).
 *
 * @param int $seconds Duration in seconds
 * @return string Formatted duration like "9 hours 42 minutes"
 */
function format_duration_full(int $seconds): string
{
    // Round to nearest minute for cleaner display
    $totalMinutes = (int) round($seconds / 60);
    $hours = (int) floor($totalMinutes / 60);
    $minutes = $totalMinutes % 60;

    $parts = [];
    if ($hours > 0) {
        $parts[] = $hours . ' ' . ($hours === 1 ? 'hour' : 'hours');
    }
    if ($minutes > 0 || $hours === 0) {
        $parts[] = $minutes . ' ' . ($minutes === 1 ? 'minute' : 'minutes');
    }

    return implode(' ', $parts);
}

/**
 * Format a duration as "Xh YYm".
 *
 * @param int $seconds Duration in seconds
 * @return string Formatted duration
 */
function format_hours_minutes(int $seconds): string
{
    return sprintf('%dh %02dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
}

/**
 * Get week data from the same week last year for comparison.
 *
 * @param int $weekStart Current week start timestamp
 * @param float $lat Latitude
 * @param float $lon Longitude
 * @param float $utcOffset UTC offset
 * @return array{avg_length: float, week_start: int}
 */
function get_last_year_week_data(int $weekStart, float $lat, float $lon, float $utcOffset): array
{
    // Same week last year, moved back to the nearest Sunday
    $lastYearWeekStart = strtotime('-1 year', $weekStart);
    $dayOfWeek = (int) date('w', $lastYearWeekStart);
    if ($dayOfWeek !== 0) {
        $lastYearWeekStart = strtotime('-' . $dayOfWeek . ' days', $lastYearWeekStart);
    }

    $totalLength = 0.0;
    for ($i = 0; $i < 7; $i++) {
        $dateParts = getdate(strtotime("+{$i} days", $lastYearWeekStart));
        $result = calculate_sun_times(
            $dateParts['year'],
            $dateParts['mon'],
            $dateParts['mday'],
            $lat,
            $lon,
            $utcOffset
        );
        $totalLength += $result['daylength_h'] * 3600;
    }

    return [
        'avg_length' => $totalLength / 7,
        'week_start' => $lastYearWeekStart,
    ];
}

/**
 * Format day length comparison string.
 *
 * @param int $diffSeconds Difference in seconds
 * @return string Formatted comparison string
 */
function format_day_length_comparison(int $diffSeconds): string
{
    if ($diffSeconds === 0) {
        return get_strings()['comparisons']['same_length'];
    }

    $absDiff = abs($diffSeconds);

    return sprintf('%s%dm %02ds', $diffSeconds > 0 ? '+' : '-', intdiv($absDiff, 60), $absDiff % 60);
}

/**
 * Format one twilight span line for the supplemental schedule.
 *
 * @param string $label Span name
 * @param array{0: int, 1: int} $span Start and end timestamps
 * @param string $note One-line description
 * @return string Formatted lines
 */
function format_schedule_span(string $label, array $span, string $note): string
{
    [$start, $end] = $span;

    return "{$label}: " . date('H:i', $start) . ' - ' . date('H:i', $end)
        . ' (' . format_hours_minutes($end - $start) . ")\n  {$note}\n\n";
}

/**
 * Build dawn supplemental information: the morning spans the user did not
 * subscribe to as separate events, so a minimal calendar still carries them.
 *
 * @param array $ctx Context with keys:
 *                   - spans: solar spans from solar_spans()
 *                   - enabled: enabled event types (civil, nautical, astro, daylight)
 *                   - daylight: daylight statistics
 * @param array $strings Strings configuration
 * @return string Formatted supplemental information
 */
function build_dawn_supplemental(array $ctx, array $strings): string
{
    $spans = $ctx['spans'];
    $enabled = $ctx['enabled'];
    if (count(array_filter($enabled)) >= 4) {
        return '';
    }

    $info = "\n\n{$strings['headers']['daytime_schedule']}\n\n";

    $dawns = ['astro' => 'astronomical_dawn', 'nautical' => 'nautical_dawn', 'civil' => 'civil_dawn'];
    foreach ($dawns as $type => $key) {
        if (!$enabled[$type] && $spans[$key] !== null) {
            $info .= format_schedule_span($strings['summaries'][$key], $spans[$key], $strings['supplemental'][$key]);
        }
    }

    if (!$enabled['daylight'] && $spans['daylight'] !== null) {
        $stats = $ctx['daylight'];
        [$sunrise, $sunset] = $spans['daylight'];

        $info .= "\n{$strings['headers']['daylight']}\n\n";
        $info .= "{$strings['labels']['time']}: " . date('H:i', $sunrise) . ' - ' . date('H:i', $sunset)
            . ' (' . format_hours_minutes($stats['daylight_seconds']) . ", {$stats['daylight_pct']}%)\n";
        $info .= "{$strings['labels']['solar_noon']}: {$stats['solar_noon_time']}\n";
        $info .= "{$strings['labels']['percentile']}: "
            . sprintf($strings['percentile_explanation']['daylight'], $stats['daylight_percentile']) . "\n\n";
        $info .= "{$strings['labels']['vs_yesterday']}: {$stats['day_length_comparison']}\n";
        $info .= "{$strings['labels']['vs_winter_solstice']} ({$stats['winter_solstice_info']}): "
            . "{$stats['winter_comparison']}\n";
        $info .= "{$strings['labels']['vs_summer_solstice']} ({$stats['summer_solstice_info']}): "
            . "{$stats['summer_comparison']}\n\n";
        $info .= "{$strings['supplemental']['daylight']}\n";
    }

    return $info;
}

/**
 * Build dusk supplemental information (evening counterpart of build_dawn_supplemental()).
 *
 * @param array $ctx Context with keys:
 *                   - spans: solar spans from solar_spans()
 *                   - enabled: enabled event types (civil, nautical, astro, daylight)
 *                   - night: night statistics including moon_info
 * @param array $strings Strings configuration
 * @return string Formatted supplemental information
 */
function build_dusk_supplemental(array $ctx, array $strings): string
{
    $spans = $ctx['spans'];
    $enabled = $ctx['enabled'];
    if (count(array_filter($enabled)) >= 4) {
        return '';
    }

    $info = "\n\n{$strings['headers']['nighttime_schedule']}\n\n";

    $dusks = ['civil' => 'civil_dusk', 'nautical' => 'nautical_dusk', 'astro' => 'astronomical_dusk'];
    foreach ($dusks as $type => $key) {
        if (!$enabled[$type] && $spans[$key] !== null) {
            $info .= format_schedule_span($strings['summaries'][$key], $spans[$key], $strings['supplemental'][$key]);
        }
    }

    if (!$enabled['daylight'] && $spans['night'] !== null) {
        $stats = $ctx['night'];
        [$start, $end] = $spans['night'];

        $info .= "\n{$strings['headers']['night']}\n\n";
        $info .= "{$strings['labels']['time']}: " . date('H:i', $start) . ' - ' . date('H:i', $end)
            . ' (' . format_hours_minutes($stats['night_seconds']) . ", {$stats['night_pct']}%)\n";
        $info .= "{$strings['labels']['solar_midnight']}: " . date('H:i', intdiv($start + $end, 2)) . "\n";
        $info .= "{$strings['labels']['percentile']}: "
            . sprintf($strings['percentile_explanation']['night'], $stats['night_percentile']) . "\n\n";
        if ($stats['night_length_comparison'] !== null) {
            $info .= "{$strings['labels']['vs_yesterday']}: {$stats['night_length_comparison']}\n\n";
        }
        $info .= "{$strings['supplemental']['night']}\n\n";
        $info .= format_moon_section($stats['moon_info'], $strings);
    }

    return $info;
}

/**
 * Format the moon phase section (current, previous, next phase).
 *
 * @param array $moon Result of get_accurate_moon_phase()
 * @param array $strings Strings configuration
 * @return string Formatted section
 */
function format_moon_section(array $moon, array $strings): string
{
    $illumination = number_format((float) $moon['illumination'], 1);
    $section = "{$strings['headers']['moon_phase']}\n\n";
    $section .= "{$strings['labels']['current']}: " . get_moon_phase_emoji($moon['phase_name'])
        . " {$moon['phase_name']} ({$illumination}% lit)\n";

    foreach (['previous' => 'prev_phase', 'next' => 'next_phase'] as $label => $key) {
        if ($moon[$key]) {
            $section .= "{$strings['labels'][$label]}: " . get_moon_phase_emoji($moon[$key]['name'])
                . " {$moon[$key]['name']} ({$moon[$key]['date']})\n";
        }
    }

    return $section;
}
