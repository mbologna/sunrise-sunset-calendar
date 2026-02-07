<?php

/**
 * Calendar Generation Logic
 * Version 8.0 - Full NREL SPA precision.
 */

// Load strings configuration
$STRINGS = require __DIR__ . '/strings.php';

/**
 * Format description for iCalendar with proper escaping and line folding
 * Per RFC 5545: newlines must be \n (literal), lines fold at 75 octets with space continuation.
 */
function format_ical_description($text)
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

if (!verify_token($_GET['token'])) {
    http_response_code(403);
    die('Invalid authentication token');
}

$lat = sanitize_float($_GET['lat'] ?? '', 41.9028, -90, 90);
$lon = sanitize_float($_GET['lon'] ?? '', 12.4964, -180, 180);
$timezone = sanitize_timezone($_GET['zone'] ?? 'Europe/Rome');
$location_name = sanitize_text($_GET['location'] ?? '');
$rise_offset = sanitize_int($_GET['rise_off'] ?? 0, 0) * 60;
$set_offset = sanitize_int($_GET['set_off'] ?? 0, 0) * 60;
$description = sanitize_text($_GET['desc'] ?? '');

$include_civil = isset($_GET['civil']) && $_GET['civil'] === '1';
$include_nautical = isset($_GET['nautical']) && $_GET['nautical'] === '1';
$include_astro = isset($_GET['astro']) && $_GET['astro'] === '1';
$include_daynight = isset($_GET['daynight']) && $_GET['daynight'] === '1';

putenv("TZ={$timezone}");
date_default_timezone_set($timezone);

// Get UTC offset for calculations
$tz = new DateTimeZone($timezone);
$dt_ref = new DateTime('now', $tz);
$utc_offset_hours = $dt_ref->getOffset() / 3600;

header('Content-Type: text/calendar; charset=utf-8');
header('Cache-Control: max-age=' . UPDATE_INTERVAL);

$calendar_name = $location_name ?: "$lat, $lon";

echo "BEGIN:VCALENDAR\r\n";
echo "VERSION:2.0\r\n";
echo "PRODID:{$STRINGS['calendar_prodid']}\r\n";
echo "CALSCALE:GREGORIAN\r\n";
echo "METHOD:PUBLISH\r\n";
echo 'X-WR-CALNAME:' . sprintf($STRINGS['calendar_name_format'], $calendar_name) . "\r\n";
echo "X-WR-TIMEZONE:{$timezone}\r\n";
echo 'X-PUBLISHED-TTL:PT' . (UPDATE_INTERVAL / 3600) . "H\r\n";
echo 'REFRESH-INTERVAL;VALUE=DURATION:PT' . (UPDATE_INTERVAL / 3600) . "H\r\n";

$start = strtotime('today');
$end = strtotime('+' . CALENDAR_WINDOW_DAYS . ' days');
$current_day = $start;

// Get special events and location notes
$year = date('Y', $start);
$special_events = get_special_astronomical_events($year);
$location_notes = get_location_notes($lat);
$notes_shown = false;

while ($current_day <= $end) {
    $date_parts = getdate($current_day);
    $date_str = date('Ymd', $current_day);
    $year = $date_parts['year'];

    // Calculate sun times using high-precision algorithm
    $sun_calc = calculate_sun_times(
        $date_parts['year'],
        $date_parts['mon'],
        $date_parts['mday'],
        $lat,
        $lon,
        $utc_offset_hours
    );

    // Convert to timestamps
    $sunrise = fraction_to_timestamp($date_parts['year'], $date_parts['mon'], $date_parts['mday'], $sun_calc['sunrise_frac']);
    $sunset = fraction_to_timestamp($date_parts['year'], $date_parts['mon'], $date_parts['mday'], $sun_calc['sunset_frac']);
    $solar_noon = fraction_to_timestamp($date_parts['year'], $date_parts['mon'], $date_parts['mday'], $sun_calc['solar_noon_frac']);
    $civil_begin = fraction_to_timestamp($date_parts['year'], $date_parts['mon'], $date_parts['mday'], $sun_calc['civil_begin_frac']);
    $civil_end = fraction_to_timestamp($date_parts['year'], $date_parts['mon'], $date_parts['mday'], $sun_calc['civil_end_frac']);
    $nautical_begin = fraction_to_timestamp($date_parts['year'], $date_parts['mon'], $date_parts['mday'], $sun_calc['nautical_begin_frac']);
    $nautical_end = fraction_to_timestamp($date_parts['year'], $date_parts['mon'], $date_parts['mday'], $sun_calc['nautical_end_frac']);
    $astro_begin = fraction_to_timestamp($date_parts['year'], $date_parts['mon'], $date_parts['mday'], $sun_calc['astro_begin_frac']);
    $astro_end = fraction_to_timestamp($date_parts['year'], $date_parts['mon'], $date_parts['mday'], $sun_calc['astro_end_frac']);

    $daylight_hours = $sun_calc['daylength_h'];
    $daylight_seconds = round($daylight_hours * 3600);

    // Get next and previous day calculations
    $next_date_parts = getdate(strtotime('+1 day', $current_day));
    $next_sun_calc = calculate_sun_times(
        $next_date_parts['year'],
        $next_date_parts['mon'],
        $next_date_parts['mday'],
        $lat,
        $lon,
        $utc_offset_hours
    );
    $next_astro_begin = fraction_to_timestamp($next_date_parts['year'], $next_date_parts['mon'], $next_date_parts['mday'], $next_sun_calc['astro_begin_frac']);

    $prev_date_parts = getdate(strtotime('-1 day', $current_day));
    $prev_sun_calc = calculate_sun_times(
        $prev_date_parts['year'],
        $prev_date_parts['mon'],
        $prev_date_parts['mday'],
        $lat,
        $lon,
        $utc_offset_hours
    );
    $prev_daylight_hours = $prev_sun_calc['daylength_h'];

    // Calculate statistics
    $night_seconds = 86400 - $daylight_seconds;
    $daylight_pct = round(($daylight_seconds / 86400) * 100, 1);
    $night_pct = round(($night_seconds / 86400) * 100, 1);

    // Calculate percentile using hours (not seconds!)
    $daylight_percentile = calculate_daylight_percentile($daylight_hours, $lat, $lon, $year, $utc_offset_hours);
    $night_percentile = 100 - $daylight_percentile;

    // Day/night length comparisons
    $prev_daylight_seconds = round($prev_daylight_hours * 3600);
    $prev_night_seconds = 86400 - $prev_daylight_seconds;
    $day_length_diff = $daylight_seconds - $prev_daylight_seconds;
    $night_length_diff = $night_seconds - $prev_night_seconds;
    $day_length_comparison = format_day_length_comparison($day_length_diff, 'day');
    $night_length_comparison = format_day_length_comparison($night_length_diff, 'night');

    // Solstice comparisons - use actual solstice dates for the year
    $solstice_dates = get_solstice_dates($year);

    $winter_solstice_date_parts = getdate($solstice_dates['dec_solstice']);
    $winter_solstice_calc = calculate_sun_times(
        $winter_solstice_date_parts['year'],
        $winter_solstice_date_parts['mon'],
        $winter_solstice_date_parts['mday'],
        $lat,
        $lon,
        $utc_offset_hours
    );

    $summer_solstice_date_parts = getdate($solstice_dates['june_solstice']);
    $summer_solstice_calc = calculate_sun_times(
        $summer_solstice_date_parts['year'],
        $summer_solstice_date_parts['mon'],
        $summer_solstice_date_parts['mday'],
        $lat,
        $lon,
        $utc_offset_hours
    );

    // Get solar noon times for solstices
    $winter_solar_noon = fraction_to_timestamp(
        $winter_solstice_date_parts['year'],
        $winter_solstice_date_parts['mon'],
        $winter_solstice_date_parts['mday'],
        $winter_solstice_calc['solar_noon_frac']
    );
    $summer_solar_noon = fraction_to_timestamp(
        $summer_solstice_date_parts['year'],
        $summer_solstice_date_parts['mon'],
        $summer_solstice_date_parts['mday'],
        $summer_solstice_calc['solar_noon_frac']
    );

    // Format solstice info with date and time
    $winter_solstice_info = date('M j, H:i', $winter_solar_noon);
    $summer_solstice_info = date('M j, H:i', $summer_solar_noon);

    $winter_daylight_seconds = round($winter_solstice_calc['daylength_h'] * 3600);
    $summer_daylight_seconds = round($summer_solstice_calc['daylength_h'] * 3600);

    $diff_from_winter = $daylight_seconds - $winter_daylight_seconds;
    $winter_comparison = format_duration_short(abs($diff_from_winter));

    $diff_from_summer = $daylight_seconds - $summer_daylight_seconds;
    $summer_comparison = format_duration_short(abs($diff_from_summer));

    $time_format = 'H:i'; // Always 24-hour format

    $enabled = [
        'civil' => $include_civil,
        'nautical' => $include_nautical,
        'astro' => $include_astro,
        'daylight' => $include_daynight,
    ];

    // Get moon phase
    $moon_info = get_moon_phase_info($current_day);

    $solar_noon_time = date($time_format, $solar_noon);

    // Check for week summary (Sundays)
    $day_of_week = date('w', $current_day);
    if ($day_of_week == 0 && $include_daynight) {
        $week_data = get_week_summary_data($current_day, $lat, $lon, $year, $utc_offset_hours);

        if ($week_data) {
            $week_end_date = date('M j', strtotime('+6 days', $current_day));
            $start_date = date('M j', $current_day);

            echo "BEGIN:VEVENT\r\n";
            echo "UID:week-summary-{$date_str}-{$lat}-{$lon}@sun-calendar\r\n";
            echo 'DTSTAMP:' . gmdate('Ymd\THis\Z') . "\r\n";
            echo 'DTSTART;VALUE=DATE:' . date('Ymd', $current_day) . "\r\n";
            echo 'DTEND;VALUE=DATE:' . date('Ymd', strtotime('+1 day', $current_day)) . "\r\n";
            echo 'SUMMARY:' . sprintf($STRINGS['week_summary']['title_format'], $start_date, $week_end_date) . "\r\n";

            $desc = "{$STRINGS['week_summary']['header']}\n\n";
            $desc .= "{$STRINGS['labels']['trend']}:    {$week_data['trend']}\n";
            $desc .= "{$STRINGS['labels']['average']}:  " . format_duration($week_data['avg_length']) . "\n";

            $week_sign = ($week_data['total_change'] >= 0) ? '+' : '';
            $desc .= "{$STRINGS['labels']['change']}:   {$week_sign}" . format_duration_short(abs($week_data['total_change'])) . "\n";
            $desc .= "          {$STRINGS['comparisons']['mon_to_sun']}\n\n";

            $desc .= "{$STRINGS['labels']['shortest']}: " . date('D, M j', $week_data['shortest_day']) . "\n";
            $desc .= '          ' . format_duration($week_data['min_length']) . "\n\n";
            $desc .= "{$STRINGS['labels']['longest']}:  " . date('D, M j', $week_data['longest_day']) . "\n";
            $desc .= '          ' . format_duration($week_data['max_length']) . "\n\n";
            $desc .= "{$STRINGS['labels']['moon']}:     {$week_data['moon_phase']}";

            // Add location notes (only first week)
            if (!$notes_shown && !empty($location_notes)) {
                $desc .= "\n\n{$STRINGS['headers']['location_notes']}\n\n";
                foreach ($location_notes as $note) {
                    $desc .= $note . "\n";
                }
                $notes_shown = true;
            }

            echo format_ical_description($desc);
            echo "TRANSP:TRANSPARENT\r\n";
            echo "END:VEVENT\r\n";
        }
    }

    // Special astronomical events
    foreach ($special_events as $special_event) {
        if (date('Y-m-d', $current_day) == date('Y-m-d', $special_event['date'])) {
            echo "BEGIN:VEVENT\r\n";
            echo 'UID:special-' . strtolower(str_replace(' ', '-', $special_event['name'])) . "-{$date_str}@sun-calendar\r\n";
            echo 'DTSTAMP:' . gmdate('Ymd\THis\Z') . "\r\n";
            echo 'DTSTART;VALUE=DATE:' . date('Ymd', $special_event['date']) . "\r\n";
            echo 'DTEND;VALUE=DATE:' . date('Ymd', strtotime('+1 day', $special_event['date'])) . "\r\n";
            echo "SUMMARY:{$special_event['emoji']} {$special_event['name']}\r\n";

            $desc = "{$STRINGS['headers']['astronomical_event']}\n";
            $desc .= "   {$STRINGS['labels']['event']}\n\n";
            $desc .= "{$STRINGS['labels']['event']}: {$special_event['name']}\n";
            $desc .= "{$STRINGS['labels']['date']}:  " . date('F j, Y', $special_event['date']) . "\n\n";
            $desc .= "{$special_event['description']}";

            echo format_ical_description($desc);
            echo "TRANSP:TRANSPARENT\r\n";
            echo "END:VEVENT\r\n";
        }
    }

    // CIVIL DAWN
    if ($include_civil && isset($civil_begin) && isset($sunrise)) {
        $start_time = $civil_begin + $rise_offset;
        $end_time = $sunrise + $rise_offset;
        $duration = format_duration($sunrise - $civil_begin);
        $start_time_str = date($time_format, $civil_begin);
        $end_time_str = date($time_format, $sunrise);

        $supplemental = build_dawn_supplemental($sunrise, $sunset, $solar_noon, $civil_begin, $civil_end, $nautical_begin, $nautical_end, $astro_begin, $astro_end, $time_format, $enabled, $daylight_seconds, $daylight_pct, $daylight_percentile, $day_length_comparison, $winter_comparison, $summer_comparison, $solar_noon_time, $winter_solstice_info, $summer_solstice_info, $diff_from_winter, $diff_from_summer, 'civil', $STRINGS);

        echo "BEGIN:VEVENT\r\n";
        echo "UID:civil-dawn-{$date_str}-{$lat}-{$lon}@sun-calendar\r\n";
        echo 'DTSTAMP:' . gmdate('Ymd\THis\Z') . "\r\n";
        echo 'DTSTART:' . gmdate('Ymd\THis\Z', $start_time) . "\r\n";
        echo 'DTEND:' . gmdate('Ymd\THis\Z', $end_time) . "\r\n";
        echo "SUMMARY:{$STRINGS['summaries']['civil_dawn']}\r\n";

        $desc = "{$STRINGS['headers']['civil_twilight']}\n\n";
        $desc .= "{$STRINGS['labels']['time']}: {$start_time_str} - {$end_time_str} ({$duration})\n\n";
        $desc .= "{$STRINGS['twilight_descriptions']['civil_dawn']}\n\n";
        $desc .= "{$STRINGS['practical_notes']['civil_dawn']}";
        $desc .= $supplemental;

        echo format_ical_description($desc);
        echo "TRANSP:TRANSPARENT\r\n";
        echo "END:VEVENT\r\n";
    }

    // NAUTICAL DAWN
    if ($include_nautical && isset($nautical_begin) && isset($civil_begin)) {
        $start_time = $nautical_begin + $rise_offset;
        $end_time = $civil_begin + $rise_offset;
        $duration = format_duration($civil_begin - $nautical_begin);
        $start_time_str = date($time_format, $nautical_begin);
        $end_time_str = date($time_format, $civil_begin);

        $supplemental = build_dawn_supplemental($sunrise, $sunset, $solar_noon, $civil_begin, $civil_end, $nautical_begin, $nautical_end, $astro_begin, $astro_end, $time_format, $enabled, $daylight_seconds, $daylight_pct, $daylight_percentile, $day_length_comparison, $winter_comparison, $summer_comparison, $solar_noon_time, $winter_solstice_info, $summer_solstice_info, $diff_from_winter, $diff_from_summer, 'nautical', $STRINGS);

        echo "BEGIN:VEVENT\r\n";
        echo "UID:nautical-dawn-{$date_str}-{$lat}-{$lon}@sun-calendar\r\n";
        echo 'DTSTAMP:' . gmdate('Ymd\THis\Z') . "\r\n";
        echo 'DTSTART:' . gmdate('Ymd\THis\Z', $start_time) . "\r\n";
        echo 'DTEND:' . gmdate('Ymd\THis\Z', $end_time) . "\r\n";
        echo "SUMMARY:{$STRINGS['summaries']['nautical_dawn']}\r\n";

        $desc = "{$STRINGS['headers']['nautical_twilight']}\n\n";
        $desc .= "{$STRINGS['labels']['time']}: {$start_time_str} - {$end_time_str} ({$duration})\n\n";
        $desc .= "{$STRINGS['twilight_descriptions']['nautical_dawn']}\n\n";
        $desc .= "{$STRINGS['practical_notes']['nautical_dawn']}";
        $desc .= $supplemental;

        echo format_ical_description($desc);
        echo "TRANSP:TRANSPARENT\r\n";
        echo "END:VEVENT\r\n";
    }

    // ASTRONOMICAL DAWN
    if ($include_astro && isset($astro_begin) && isset($nautical_begin)) {
        $start_time = $astro_begin + $rise_offset;
        $end_time = $nautical_begin + $rise_offset;
        $duration = format_duration($nautical_begin - $astro_begin);
        $start_time_str = date($time_format, $astro_begin);
        $end_time_str = date($time_format, $nautical_begin);

        $supplemental = build_dawn_supplemental($sunrise, $sunset, $solar_noon, $civil_begin, $civil_end, $nautical_begin, $nautical_end, $astro_begin, $astro_end, $time_format, $enabled, $daylight_seconds, $daylight_pct, $daylight_percentile, $day_length_comparison, $winter_comparison, $summer_comparison, $solar_noon_time, $winter_solstice_info, $summer_solstice_info, $diff_from_winter, $diff_from_summer, 'astro', $STRINGS);

        echo "BEGIN:VEVENT\r\n";
        echo "UID:astro-dawn-{$date_str}-{$lat}-{$lon}@sun-calendar\r\n";
        echo 'DTSTAMP:' . gmdate('Ymd\THis\Z') . "\r\n";
        echo 'DTSTART:' . gmdate('Ymd\THis\Z', $start_time) . "\r\n";
        echo 'DTEND:' . gmdate('Ymd\THis\Z', $end_time) . "\r\n";
        echo "SUMMARY:{$STRINGS['summaries']['astronomical_dawn']}\r\n";

        $desc = "{$STRINGS['headers']['astronomical_twilight']}\n\n";
        $desc .= "{$STRINGS['labels']['time']}: {$start_time_str} - {$end_time_str} ({$duration})\n\n";
        $desc .= "{$STRINGS['twilight_descriptions']['astronomical_dawn']}\n\n";
        $desc .= "{$STRINGS['practical_notes']['astronomical_dawn']}";
        $desc .= $supplemental;

        echo format_ical_description($desc);
        echo "TRANSP:TRANSPARENT\r\n";
        echo "END:VEVENT\r\n";
    }

    // DAYLIGHT
    if ($include_daynight && isset($sunrise) && isset($sunset)) {
        $start_time = $sunrise + $rise_offset;
        $end_time = $sunset + $set_offset;
        $daylight_duration = format_duration($daylight_seconds);
        $sunrise_time = date($time_format, $sunrise);
        $sunset_time = date($time_format, $sunset);

        echo "BEGIN:VEVENT\r\n";
        echo "UID:daylight-{$date_str}-{$lat}-{$lon}@sun-calendar\r\n";
        echo 'DTSTAMP:' . gmdate('Ymd\THis\Z') . "\r\n";
        echo 'DTSTART:' . gmdate('Ymd\THis\Z', $start_time) . "\r\n";
        echo 'DTEND:' . gmdate('Ymd\THis\Z', $end_time) . "\r\n";
        echo "SUMMARY:{$STRINGS['summaries']['daylight']}\r\n";

        $desc = "{$STRINGS['headers']['daylight']}\n\n";
        $desc .= "{$STRINGS['labels']['time']}: {$sunrise_time} - {$sunset_time} ({$daylight_duration}, {$daylight_pct}% of day)\n\n";
        $desc .= "{$STRINGS['twilight_descriptions']['daylight']}\n\n";
        $desc .= "{$STRINGS['labels']['solar_noon']}: {$solar_noon_time}\n";
        $desc .= "{$STRINGS['labels']['percentile']}: " . sprintf($STRINGS['percentile_explanation']['daylight'], $daylight_percentile, $daylight_percentile) . "\n\n";

        if ($day_length_comparison) {
            $desc .= "{$STRINGS['labels']['vs_yesterday']}: {$day_length_comparison}\n";
        }

        // Format solstice comparisons with +/- on single line
        $winter_sign = ($diff_from_winter >= 0) ? '+' : '-';
        $summer_sign = ($diff_from_summer >= 0) ? '+' : '-';
        $desc .= "{$STRINGS['labels']['vs_winter_solstice']} ({$winter_solstice_info}): {$winter_sign}{$winter_comparison}\n";
        $desc .= "{$STRINGS['labels']['vs_summer_solstice']} ({$summer_solstice_info}): {$summer_sign}{$summer_comparison}\n";

        if ($description) {
            $desc .= "\n" . $description;
        }

        echo format_ical_description($desc);
        echo "TRANSP:TRANSPARENT\r\n";
        echo "END:VEVENT\r\n";
    }

    // CIVIL DUSK
    if ($include_civil && isset($sunset) && isset($civil_end)) {
        $start_time = $sunset + $set_offset;
        $end_time = $civil_end + $set_offset;
        $duration = format_duration($civil_end - $sunset);
        $start_time_str = date($time_format, $sunset);
        $end_time_str = date($time_format, $civil_end);

        $supplemental = build_dusk_supplemental($sunrise, $sunset, $civil_begin, $civil_end, $nautical_begin, $nautical_end, $astro_begin, $astro_end, $next_astro_begin, $time_format, $enabled, $night_seconds, $night_pct, $night_percentile, $night_length_comparison, $moon_info, 'civil', $STRINGS);

        echo "BEGIN:VEVENT\r\n";
        echo "UID:civil-dusk-{$date_str}-{$lat}-{$lon}@sun-calendar\r\n";
        echo 'DTSTAMP:' . gmdate('Ymd\THis\Z') . "\r\n";
        echo 'DTSTART:' . gmdate('Ymd\THis\Z', $start_time) . "\r\n";
        echo 'DTEND:' . gmdate('Ymd\THis\Z', $end_time) . "\r\n";
        echo "SUMMARY:{$STRINGS['summaries']['civil_dusk']}\r\n";

        $desc = "{$STRINGS['headers']['civil_twilight']}\n\n";
        $desc .= "{$STRINGS['labels']['time']}: {$start_time_str} - {$end_time_str} ({$duration})\n\n";
        $desc .= "{$STRINGS['twilight_descriptions']['civil_dusk']}\n\n";
        $desc .= "{$STRINGS['practical_notes']['civil_dusk']}";
        $desc .= $supplemental;

        echo format_ical_description($desc);
        echo "TRANSP:TRANSPARENT\r\n";
        echo "END:VEVENT\r\n";
    }

    // NAUTICAL DUSK
    if ($include_nautical && isset($civil_end) && isset($nautical_end)) {
        $start_time = $civil_end + $set_offset;
        $end_time = $nautical_end + $set_offset;
        $duration = format_duration($nautical_end - $civil_end);
        $start_time_str = date($time_format, $civil_end);
        $end_time_str = date($time_format, $nautical_end);

        $supplemental = build_dusk_supplemental($sunrise, $sunset, $civil_begin, $civil_end, $nautical_begin, $nautical_end, $astro_begin, $astro_end, $next_astro_begin, $time_format, $enabled, $night_seconds, $night_pct, $night_percentile, $night_length_comparison, $moon_info, 'nautical', $STRINGS);

        echo "BEGIN:VEVENT\r\n";
        echo "UID:nautical-dusk-{$date_str}-{$lat}-{$lon}@sun-calendar\r\n";
        echo 'DTSTAMP:' . gmdate('Ymd\THis\Z') . "\r\n";
        echo 'DTSTART:' . gmdate('Ymd\THis\Z', $start_time) . "\r\n";
        echo 'DTEND:' . gmdate('Ymd\THis\Z', $end_time) . "\r\n";
        echo "SUMMARY:{$STRINGS['summaries']['nautical_dusk']}\r\n";

        $desc = "{$STRINGS['headers']['nautical_twilight']}\n\n";
        $desc .= "{$STRINGS['labels']['time']}: {$start_time_str} - {$end_time_str} ({$duration})\n\n";
        $desc .= "{$STRINGS['twilight_descriptions']['nautical_dusk']}\n\n";
        $desc .= "{$STRINGS['practical_notes']['nautical_dusk']}";
        $desc .= $supplemental;

        echo format_ical_description($desc);
        echo "TRANSP:TRANSPARENT\r\n";
        echo "END:VEVENT\r\n";
    }

    // ASTRONOMICAL DUSK
    if ($include_astro && isset($nautical_end) && isset($astro_end)) {
        $start_time = $nautical_end + $set_offset;
        $end_time = $astro_end + $set_offset;
        $duration = format_duration($astro_end - $nautical_end);
        $start_time_str = date($time_format, $nautical_end);
        $end_time_str = date($time_format, $astro_end);

        $supplemental = build_dusk_supplemental($sunrise, $sunset, $civil_begin, $civil_end, $nautical_begin, $nautical_end, $astro_begin, $astro_end, $next_astro_begin, $time_format, $enabled, $night_seconds, $night_pct, $night_percentile, $night_length_comparison, $moon_info, 'astro', $STRINGS);

        echo "BEGIN:VEVENT\r\n";
        echo "UID:astro-dusk-{$date_str}-{$lat}-{$lon}@sun-calendar\r\n";
        echo 'DTSTAMP:' . gmdate('Ymd\THis\Z') . "\r\n";
        echo 'DTSTART:' . gmdate('Ymd\THis\Z', $start_time) . "\r\n";
        echo 'DTEND:' . gmdate('Ymd\THis\Z', $end_time) . "\r\n";
        echo "SUMMARY:{$STRINGS['summaries']['astronomical_dusk']}\r\n";

        $desc = "{$STRINGS['headers']['astronomical_twilight']}\n\n";
        $desc .= "{$STRINGS['labels']['time']}: {$start_time_str} - {$end_time_str} ({$duration})\n\n";
        $desc .= "{$STRINGS['twilight_descriptions']['astronomical_dusk']}\n\n";
        $desc .= "{$STRINGS['practical_notes']['astronomical_dusk']}";
        $desc .= $supplemental;

        echo format_ical_description($desc);
        echo "TRANSP:TRANSPARENT\r\n";
        echo "END:VEVENT\r\n";
    }

    // NIGHT
    if ($include_daynight && isset($astro_end) && isset($next_astro_begin)) {
        $start_time = $astro_end + $set_offset;
        $end_time = $next_astro_begin + $rise_offset;
        $night_duration = format_duration($night_seconds);
        $night_start = date($time_format, $astro_end);
        $night_end = date($time_format, $next_astro_begin);

        $solar_midnight = $astro_end + (($next_astro_begin - $astro_end) / 2);
        $solar_midnight_time = date($time_format, $solar_midnight);

        echo "BEGIN:VEVENT\r\n";
        echo "UID:night-{$date_str}-{$lat}-{$lon}@sun-calendar\r\n";
        echo 'DTSTAMP:' . gmdate('Ymd\THis\Z') . "\r\n";
        echo 'DTSTART:' . gmdate('Ymd\THis\Z', $start_time) . "\r\n";
        echo 'DTEND:' . gmdate('Ymd\THis\Z', $end_time) . "\r\n";
        echo "SUMMARY:{$STRINGS['summaries']['night']}\r\n";

        $desc = "{$STRINGS['headers']['night']}\n\n";
        $desc .= "{$STRINGS['labels']['time']}: {$night_start} - {$night_end} ({$night_duration}, {$night_pct}% of day)\n\n";
        $desc .= "{$STRINGS['twilight_descriptions']['night']}\n\n";
        $desc .= "{$STRINGS['labels']['solar_midnight']}: {$solar_midnight_time}\n";
        $desc .= "{$STRINGS['labels']['percentile']}: " . sprintf($STRINGS['percentile_explanation']['night'], $night_percentile, $night_percentile) . "\n\n";

        if ($night_length_comparison) {
            $desc .= "{$STRINGS['labels']['vs_yesterday']}: {$night_length_comparison}\n\n";
        }

        $desc .= "{$STRINGS['headers']['moon_phase']}\n\n";
        $desc .= "{$STRINGS['labels']['current']}:  {$moon_info['phase_name']}\n";
        $desc .= "          " . sprintf($STRINGS['comparisons']['lit'], $moon_info['illumination']) . "\n\n";
        $desc .= "{$STRINGS['labels']['previous']}: {$moon_info['prev_phase']['name']}\n";
        $desc .= "          {$moon_info['prev_phase']['date']}\n\n";
        $desc .= "{$STRINGS['labels']['next']}:     {$moon_info['next_phase']['name']}\n";
        $desc .= "          {$moon_info['next_phase']['date']}";
        if ($description) {
            $desc .= "\n\n" . $description;
        }

        echo format_ical_description($desc);
        echo "TRANSP:TRANSPARENT\r\n";
        echo "END:VEVENT\r\n";
    }

    $current_day = strtotime('+1 day', $current_day);
}

echo "END:VCALENDAR\r\n";
