<?php

/**
 * Helper functions for calendar generation.
 *
 * These functions provide utilities for location notes, astronomical events,
 * week summaries, and supplemental information formatting.
 */

declare(strict_types=1);

/**
 * Format description for iCalendar with proper escaping and line folding.
 * Per RFC 5545: newlines must be \n (literal), lines fold at 75 octets with space continuation.
 *
 * @param string $text The text to format
 * @return string RFC 5545 compliant DESCRIPTION property
 */
function format_ical_description(string $text): string
{
    // Step 1: Escape special characters per RFC 5545
    // IMPORTANT: Escape backslashes FIRST, then everything else
    $text = str_replace('\\', '\\\\', $text);

    // Replace actual newlines with literal \n (backslash + n)
    $text = str_replace(["\r\n", "\r", "\n"], '\n', $text);

    // Escape commas and semicolons
    $text = str_replace([',', ';'], ['\\,', '\\;'], $text);

    // Step 2: Build the complete DESCRIPTION line
    $line = 'DESCRIPTION:' . $text;

    // Step 3: Fold at 75 octets (bytes) with CRLF + space for continuation
    // IMPORTANT: Must not split multibyte UTF-8 characters
    $result = '';
    $current_line = '';
    $byte_count = 0;

    // Process character by character (UTF-8 aware)
    $char_count = mb_strlen($line, 'UTF-8');
    for ($i = 0; $i < $char_count; $i++) {
        $char = mb_substr($line, $i, 1, 'UTF-8');
        $char_bytes = strlen($char); // byte length of this character

        // Check if adding this character would exceed 75 bytes
        if ($byte_count + $char_bytes > 75) {
            // Add the current line with folding
            $result .= $current_line . "\r\n";
            // Start continuation line with space
            $current_line = ' ' . $char;
            $byte_count = 1 + $char_bytes; // space (1 byte) + current char bytes
        } else {
            $current_line .= $char;
            $byte_count += $char_bytes;
        }
    }

    // Add final line
    $result .= $current_line . "\r\n";

    return $result;
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
    $daylightLengths = get_cached_daylight_lengths($lat, $lon, $year, $utcOffset);

    $countBelow = 0;
    foreach ($daylightLengths as $length) {
        if ($length < $targetDaylightHours) {
            $countBelow++;
        }
    }

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
 * Get special astronomical events for a year.
 *
 * @param int $year Year to get events for
 * @return array<array{date: int, name: string, emoji: string, description: string}>
 */
function get_special_astronomical_events(int $year): array
{
    $strings = get_strings();

    return [
        [
            'date' => strtotime("$year-03-20"),
            'name' => $strings['astronomical_events']['march_equinox']['name'],
            'emoji' => $strings['astronomical_events']['march_equinox']['emoji'],
            'description' => $strings['astronomical_events']['march_equinox']['description'],
        ],
        [
            'date' => strtotime("$year-06-21"),
            'name' => $strings['astronomical_events']['june_solstice']['name'],
            'emoji' => $strings['astronomical_events']['june_solstice']['emoji'],
            'description' => $strings['astronomical_events']['june_solstice']['description'],
        ],
        [
            'date' => strtotime("$year-09-22"),
            'name' => $strings['astronomical_events']['september_equinox']['name'],
            'emoji' => $strings['astronomical_events']['september_equinox']['emoji'],
            'description' => $strings['astronomical_events']['september_equinox']['description'],
        ],
        [
            'date' => strtotime("$year-12-21"),
            'name' => $strings['astronomical_events']['december_solstice']['name'],
            'emoji' => $strings['astronomical_events']['december_solstice']['emoji'],
            'description' => $strings['astronomical_events']['december_solstice']['description'],
        ],
    ];
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
 * Get day of year information.
 *
 * @param int $timestamp Unix timestamp
 * @return array{day: int, total: int, formatted: string}
 */
function get_day_of_year(int $timestamp): array
{
    $year = (int) date('Y', $timestamp);
    $dayOfYear = (int) date('z', $timestamp) + 1; // date('z') is 0-indexed
    $isLeapYear = ($year % 4 == 0 && $year % 100 != 0) || $year % 400 == 0;
    $totalDays = $isLeapYear ? 366 : 365;

    return [
        'day' => $dayOfYear,
        'total' => $totalDays,
        'formatted' => "Day {$dayOfYear} of {$totalDays}",
    ];
}

/**
 * Create a visual separator line.
 *
 * @param int $width Width of the separator (default 30)
 * @return string Separator line
 */
function format_separator(int $width = 30): string
{
    return str_repeat('─', $width);
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
 * Get week data from the same week last year for comparison.
 *
 * @param int $weekStart Current week start timestamp
 * @param float $lat Latitude
 * @param float $lon Longitude
 * @param float $utcOffset UTC offset
 * @return array|null Last year's week data or null
 */
function get_last_year_week_data(int $weekStart, float $lat, float $lon, float $utcOffset): ?array
{
    // Get the same week last year (approximately)
    $lastYearWeekStart = strtotime('-1 year', $weekStart);

    // Adjust to the nearest Sunday
    $dayOfWeek = (int) date('w', $lastYearWeekStart);
    if ($dayOfWeek !== 0) {
        $lastYearWeekStart = strtotime('-' . $dayOfWeek . ' days', $lastYearWeekStart);
    }

    $weekEnd = strtotime('+6 days', $lastYearWeekStart);
    $totalLength = 0;
    $count = 0;
    $current = $lastYearWeekStart;

    while ($current <= $weekEnd) {
        $dateParts = getdate($current);
        $result = calculate_sun_times(
            $dateParts['year'],
            $dateParts['mon'],
            $dateParts['mday'],
            $lat,
            $lon,
            $utcOffset
        );
        $totalLength += $result['daylength_h'] * 3600;
        $count++;
        $current = strtotime('+1 day', $current);
    }

    if ($count === 0) {
        return null;
    }

    return [
        'avg_length' => $totalLength / $count,
        'week_start' => $lastYearWeekStart,
    ];
}

/**
 * Format day length comparison string.
 *
 * @param int $diffSeconds Difference in seconds
 * @param string $type Type of comparison ('day' or 'night') - reserved for future use
 * @return string Formatted comparison string
 */
function format_day_length_comparison(int $diffSeconds, string $type = 'day'): string
{
    $strings = get_strings();
    $absDiff = abs($diffSeconds);
    $minutes = (int) floor($absDiff / 60);
    $seconds = $absDiff % 60;

    if ($diffSeconds > 0) {
        return sprintf('+%dm %02ds', $minutes, $seconds);
    } elseif ($diffSeconds < 0) {
        return sprintf('-%dm %02ds', $minutes, $seconds);
    }

    return $strings['comparisons']['same_length'];
}

/**
 * Build dawn supplemental information.
 *
 * @param array $ctx Context array with keys:
 *                   - times: timestamps array (sunrise, sunset, solar_noon, etc.)
 *                   - format: time format string
 *                   - enabled: enabled event types array
 *                   - daylight: daylight statistics array
 * @param array $strings Strings configuration
 * @return string Formatted supplemental information
 */
function build_dawn_supplemental(array $ctx, array $strings): string
{
    $sunrise = $ctx['times']['sunrise'] ?? null;
    $sunset = $ctx['times']['sunset'] ?? null;
    $solar_noon = $ctx['times']['solar_noon'] ?? null;
    $civil_begin = $ctx['times']['civil_begin'] ?? null;
    $civil_end = $ctx['times']['civil_end'] ?? null;
    $nautical_begin = $ctx['times']['nautical_begin'] ?? null;
    $nautical_end = $ctx['times']['nautical_end'] ?? null;
    $astro_begin = $ctx['times']['astro_begin'] ?? null;
    $astro_end = $ctx['times']['astro_end'] ?? null;

    $time_format = $ctx['format'];
    $enabled = $ctx['enabled'];
    $daylight_seconds = $ctx['daylight']['daylight_seconds'];
    $daylight_pct = $ctx['daylight']['daylight_pct'];
    $daylight_percentile = $ctx['daylight']['daylight_percentile'];
    $day_length_comparison = $ctx['daylight']['day_length_comparison'];
    $winter_comparison = $ctx['daylight']['winter_comparison'];
    $summer_comparison = $ctx['daylight']['summer_comparison'];
    $solar_noon_time = $ctx['daylight']['solar_noon_time'];
    $winter_solstice_info = $ctx['daylight']['winter_solstice_info'];
    $summer_solstice_info = $ctx['daylight']['summer_solstice_info'];
    $diff_from_winter = $ctx['daylight']['diff_from_winter'];
    $diff_from_summer = $ctx['daylight']['diff_from_summer'];
    if (count(array_filter($enabled)) >= 4) {
        return '';
    }

    $info = "\n\n{$strings['headers']['daytime_schedule']}\n\n";

    if (!$enabled['astro'] && isset($astro_begin) && isset($nautical_begin)) {
        $info .= "{$strings['summaries']['astronomical_dawn']}: "
            . date($time_format, $astro_begin) . ' - '
            . date($time_format, $nautical_begin) . ' ('
            . sprintf('%dh %02dm', (int) floor(($nautical_begin - $astro_begin) / 3600), (int) floor((($nautical_begin - $astro_begin) % 3600) / 60)) . ")\n";
        $info .= "  {$strings['supplemental']['astronomical_dawn']}\n\n";
    }

    if (!$enabled['nautical'] && isset($nautical_begin) && isset($civil_begin)) {
        $info .= "{$strings['summaries']['nautical_dawn']}: "
            . date($time_format, $nautical_begin) . ' - '
            . date($time_format, $civil_begin) . ' ('
            . sprintf('%dh %02dm', (int) floor(($civil_begin - $nautical_begin) / 3600), (int) floor((($civil_begin - $nautical_begin) % 3600) / 60)) . ")\n";
        $info .= "  {$strings['supplemental']['nautical_dawn']}\n\n";
    }

    if (!$enabled['civil'] && isset($civil_begin) && isset($sunrise)) {
        $info .= "{$strings['summaries']['civil_dawn']}: "
            . date($time_format, $civil_begin) . ' - '
            . date($time_format, $sunrise) . ' ('
            . sprintf('%dh %02dm', (int) floor(($sunrise - $civil_begin) / 3600), (int) floor((($sunrise - $civil_begin) % 3600) / 60)) . ")\n";
        $info .= "  {$strings['supplemental']['civil_dawn']}\n\n";
    }

    if (!$enabled['daylight']) {
        $info .= "\n{$strings['headers']['daylight']}\n\n";
        // All data first
        $info .= "{$strings['labels']['time']}: "
            . date($time_format, $sunrise) . ' - '
            . date($time_format, $sunset) . ' ('
            . sprintf('%dh %02dm', (int) floor($daylight_seconds / 3600), (int) floor(($daylight_seconds % 3600) / 60)) . ", {$daylight_pct}%)\n";
        $info .= "{$strings['labels']['solar_noon']}: {$solar_noon_time}\n";
        $info .= "{$strings['labels']['percentile']}: "
            . sprintf($strings['percentile_explanation']['daylight'], $daylight_percentile) . "\n\n";

        if ($day_length_comparison) {
            $info .= "{$strings['labels']['vs_yesterday']}: {$day_length_comparison}\n";
        }

        $winter_sign = ($diff_from_winter >= 0) ? '+' : '-';
        $summer_sign = ($diff_from_summer >= 0) ? '+' : '-';
        $info .= "{$strings['labels']['vs_winter_solstice']} ({$winter_solstice_info}): "
            . "{$winter_sign}{$winter_comparison}\n";
        $info .= "{$strings['labels']['vs_summer_solstice']} ({$summer_solstice_info}): "
            . "{$summer_sign}{$summer_comparison}\n\n";

        // Description after data
        $info .= "{$strings['supplemental']['daylight']}\n";
    }

    return $info;
}

/**
 * Build dusk supplemental information.
 *
 * @param array $ctx Context array with keys:
 *                   - times: timestamps array (sunrise, sunset, twilight times, next_astro_begin)
 *                   - format: time format string
 *                   - enabled: enabled event types array
 *                   - night: night statistics array
 * @param array $strings Strings configuration
 * @return string Formatted supplemental information
 */
function build_dusk_supplemental(array $ctx, array $strings): string
{
    $sunrise = $ctx['times']['sunrise'] ?? null;
    $sunset = $ctx['times']['sunset'] ?? null;
    $civil_begin = $ctx['times']['civil_begin'] ?? null;
    $civil_end = $ctx['times']['civil_end'] ?? null;
    $nautical_begin = $ctx['times']['nautical_begin'] ?? null;
    $nautical_end = $ctx['times']['nautical_end'] ?? null;
    $astro_begin = $ctx['times']['astro_begin'] ?? null;
    $astro_end = $ctx['times']['astro_end'] ?? null;
    $next_astro_begin = $ctx['times']['next_astro_begin'] ?? null;

    $time_format = $ctx['format'];
    $enabled = $ctx['enabled'];
    $night_seconds = $ctx['night']['night_seconds'];
    $night_pct = $ctx['night']['night_pct'];
    $night_percentile = $ctx['night']['night_percentile'];
    $night_length_comparison = $ctx['night']['night_length_comparison'];
    $moon_info = $ctx['night']['moon_info'];
    if (count(array_filter($enabled)) >= 4) {
        return '';
    }

    $info = "\n\n{$strings['headers']['nighttime_schedule']}\n\n";

    if (!$enabled['civil'] && isset($sunset) && isset($civil_end)) {
        $info .= "{$strings['summaries']['civil_dusk']}: "
            . date($time_format, $sunset) . ' - '
            . date($time_format, $civil_end) . ' ('
            . sprintf('%dh %02dm', (int) floor(($civil_end - $sunset) / 3600), (int) floor((($civil_end - $sunset) % 3600) / 60)) . ")\n";
        $info .= "  {$strings['supplemental']['civil_dusk']}\n\n";
    }

    if (!$enabled['nautical'] && isset($civil_end) && isset($nautical_end)) {
        $info .= "{$strings['summaries']['nautical_dusk']}: "
            . date($time_format, $civil_end) . ' - '
            . date($time_format, $nautical_end) . ' ('
            . sprintf('%dh %02dm', (int) floor(($nautical_end - $civil_end) / 3600), (int) floor((($nautical_end - $civil_end) % 3600) / 60)) . ")\n";
        $info .= "  {$strings['supplemental']['nautical_dusk']}\n\n";
    }

    if (!$enabled['astro'] && isset($nautical_end) && isset($astro_end)) {
        $info .= "{$strings['summaries']['astronomical_dusk']}: "
            . date($time_format, $nautical_end) . ' - '
            . date($time_format, $astro_end) . ' ('
            . sprintf('%dh %02dm', (int) floor(($astro_end - $nautical_end) / 3600), (int) floor((($astro_end - $nautical_end) % 3600) / 60)) . ")\n";
        $info .= "  {$strings['supplemental']['astronomical_dusk']}\n\n";
    }

    if (!$enabled['daylight'] && isset($astro_end) && isset($next_astro_begin)) {
        $solar_midnight = $astro_end + (int) (($next_astro_begin - $astro_end) / 2);
        $info .= "\n{$strings['headers']['night']}\n\n";
        // All data first
        $info .= "{$strings['labels']['time']}: "
            . date($time_format, $astro_end) . ' - '
            . date($time_format, $next_astro_begin) . ' ('
            . sprintf('%dh %02dm', (int) floor($night_seconds / 3600), (int) floor(($night_seconds % 3600) / 60)) . ", {$night_pct}%)\n";
        $info .= "{$strings['labels']['solar_midnight']}: " . date($time_format, $solar_midnight) . "\n";
        $info .= "{$strings['labels']['percentile']}: "
            . sprintf($strings['percentile_explanation']['night'], $night_percentile) . "\n\n";

        if ($night_length_comparison) {
            $info .= "{$strings['labels']['vs_yesterday']}: {$night_length_comparison}\n\n";
        }

        // Description after data
        $info .= "{$strings['supplemental']['night']}\n\n";

        $moon_emoji_supp = get_moon_phase_emoji($moon_info['phase_name']);
        $illum_supp = number_format((float) $moon_info['illumination'], 1);
        $info .= "{$strings['headers']['moon_phase']}\n\n";
        $phase_lit = "{$moon_info['phase_name']} ({$illum_supp}% lit)";
        $info .= "{$strings['labels']['current']}: {$moon_emoji_supp} {$phase_lit}\n";
        if ($moon_info['prev_phase']) {
            $prev_emoji_supp = get_moon_phase_emoji($moon_info['prev_phase']['name']);
            $info .= "{$strings['labels']['previous']}: {$prev_emoji_supp} {$moon_info['prev_phase']['name']}\n";
            $info .= "  {$moon_info['prev_phase']['date']}\n";
        }
        if ($moon_info['next_phase']) {
            $next_emoji_supp = get_moon_phase_emoji($moon_info['next_phase']['name']);
            $info .= "{$strings['labels']['next']}: {$next_emoji_supp} {$moon_info['next_phase']['name']}\n";
            $info .= "  {$moon_info['next_phase']['date']}\n";
        }
    }

    return $info;
}
