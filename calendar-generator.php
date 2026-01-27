<?php
/**
 * Calendar Generation Logic
 * Version 6.1 - Enhanced formatting, night length comparison, moon phase improvements
 */

if (!verify_token($_GET['token'])) {
    http_response_code(403);
    die('Invalid authentication token');
}

$lat = sanitize_float($_GET['lat'] ?? '', 41.9028, -90, 90);
$lon = sanitize_float($_GET['lon'] ?? '', 12.4964, -180, 180);
$elevation = sanitize_float($_GET['elev'] ?? '', 21, -500, 9000);
$timezone = sanitize_timezone($_GET['zone'] ?? 'Europe/Rome');
$location_name = sanitize_text($_GET['location'] ?? '');
$rise_offset = sanitize_int($_GET['rise_off'] ?? 0, 0) * 60;
$set_offset = sanitize_int($_GET['set_off'] ?? 0, 0) * 60;
$twelve_hour = isset($_GET['twelve']) && $_GET['twelve'] === '1';
$description = sanitize_text($_GET['desc'] ?? '');

$include_civil = isset($_GET['civil']) && $_GET['civil'] === '1';
$include_nautical = isset($_GET['nautical']) && $_GET['nautical'] === '1';
$include_astro = isset($_GET['astro']) && $_GET['astro'] === '1';
$include_daynight = isset($_GET['daynight']) && $_GET['daynight'] === '1';

putenv("TZ={$timezone}");
date_default_timezone_set($timezone);

header('Content-Type: text/calendar; charset=utf-8');
header('Cache-Control: max-age=' . UPDATE_INTERVAL);

$calendar_name = $location_name ?: "$lat, $lon";

echo "BEGIN:VCALENDAR\r\n";
echo "VERSION:2.0\r\n";
echo "PRODID:-//Sun & Twilight Calendar//EN\r\n";
echo "CALSCALE:GREGORIAN\r\n";
echo "METHOD:PUBLISH\r\n";
echo "X-WR-CALNAME:☀️🌅 Sun & Twilight - {$calendar_name}\r\n";
echo "X-WR-TIMEZONE:{$timezone}\r\n";
echo "X-PUBLISHED-TTL:PT" . (UPDATE_INTERVAL / 3600) . "H\r\n";
echo "REFRESH-INTERVAL;VALUE=DURATION:PT" . (UPDATE_INTERVAL / 3600) . "H\r\n";

$start = strtotime('today');
$end = strtotime('+' . CALENDAR_WINDOW_DAYS . ' days');
$current_day = $start;

while ($current_day <= $end) {
    $sun_info = date_sun_info($current_day, $lat, $lon);
    $date_str = date('Ymd', $current_day);
    $year = date('Y', $current_day);
    
    $next_day = strtotime('+1 day', $current_day);
    $next_sun_info = date_sun_info($next_day, $lat, $lon);
    
    $prev_day = strtotime('-1 day', $current_day);
    $prev_sun_info = date_sun_info($prev_day, $lat, $lon);
    
    // Calculate daylight and day/night length comparisons
    if (isset($sun_info['sunrise']) && isset($sun_info['sunset'])) {
        $daylight_seconds = $sun_info['sunset'] - $sun_info['sunrise'];
        $night_seconds = 86400 - $daylight_seconds;
        $daylight_pct = round(($daylight_seconds / 86400) * 100, 1);
        $night_pct = round(($night_seconds / 86400) * 100, 1);
        $daylight_percentile = calculate_daylight_percentile($daylight_seconds, $lat, $lon, $year);
        $night_percentile = 100 - $daylight_percentile;
        
        // Day and night length comparison with yesterday
        if (isset($prev_sun_info['sunrise']) && isset($prev_sun_info['sunset'])) {
            $prev_daylight_seconds = $prev_sun_info['sunset'] - $prev_sun_info['sunrise'];
            $prev_night_seconds = 86400 - $prev_daylight_seconds;
            $day_length_diff = $daylight_seconds - $prev_daylight_seconds;
            $night_length_diff = $night_seconds - $prev_night_seconds;
            $day_length_comparison = format_day_length_comparison($day_length_diff, 'day');
            $night_length_comparison = format_day_length_comparison($night_length_diff, 'night');
        } else {
            $day_length_comparison = '';
            $night_length_comparison = '';
        }
    }
    
    $time_format = $twelve_hour ? 'g:i A' : 'H:i';
    
    $enabled = [
        'civil' => $include_civil,
        'nautical' => $include_nautical,
        'astro' => $include_astro,
        'daylight' => $include_daynight
    ];
    
    // Get moon phase information for this day
    $moon_info = get_moon_phase_info($current_day);
    
    // CIVIL DAWN (First Light → Sunrise)
    if ($include_civil && isset($sun_info['civil_twilight_begin']) && isset($sun_info['sunrise'])) {
        $start_time = $sun_info['civil_twilight_begin'] + $rise_offset;
        $end_time = $sun_info['sunrise'] + $rise_offset;
        $duration = format_duration($sun_info['sunrise'] - $sun_info['civil_twilight_begin']);
        
        $supplemental = build_dawn_supplemental($sun_info, $time_format, $enabled, $daylight_seconds, $daylight_pct, $daylight_percentile, $day_length_comparison, $moon_info, 'civil');
        
        echo "BEGIN:VEVENT\r\n";
        echo "UID:civil-dawn-{$date_str}-{$lat}-{$lon}@sun-calendar\r\n";
        echo "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
        echo "DTSTART:" . gmdate('Ymd\THis\Z', $start_time) . "\r\n";
        echo "DTEND:" . gmdate('Ymd\THis\Z', $end_time) . "\r\n";
        echo "SUMMARY:🌅 First Light → Sunrise\r\n";
        echo "DESCRIPTION:";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\\n";
        echo "🌅 CIVIL TWILIGHT (DAWN)\\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\\n";
        echo "Duration: {$duration}\\n\\n";
        echo "▸ BEFORE:\\n";
        echo "  Still dark with stars visible and artificial light needed.\\n\\n";
        echo "▸ DURING:\\n";
        echo "  Sky brightens for outdoor activities without artificial light; ideal for blue hour photography.\\n\\n";
        echo "▸ AFTER:\\n";
        echo "  Sun's upper edge breaks the horizon; full daylight begins.";
        echo $supplemental;
        if ($description) echo "\\n\\n" . str_replace(["\r", "\n"], ["", "\\n"], $description);
        echo "\r\n";
        echo "TRANSP:TRANSPARENT\r\n";
        echo "END:VEVENT\r\n";
    }
    
    // NAUTICAL DAWN (Nautical Dawn → First Light)
    if ($include_nautical && isset($sun_info['nautical_twilight_begin']) && isset($sun_info['civil_twilight_begin'])) {
        $start_time = $sun_info['nautical_twilight_begin'] + $rise_offset;
        $end_time = $sun_info['civil_twilight_begin'] + $rise_offset;
        $duration = format_duration($sun_info['civil_twilight_begin'] - $sun_info['nautical_twilight_begin']);
        
        $supplemental = build_dawn_supplemental($sun_info, $time_format, $enabled, $daylight_seconds, $daylight_pct, $daylight_percentile, $day_length_comparison, $moon_info, 'nautical');
        
        echo "BEGIN:VEVENT\r\n";
        echo "UID:nautical-dawn-{$date_str}-{$lat}-{$lon}@sun-calendar\r\n";
        echo "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
        echo "DTSTART:" . gmdate('Ymd\THis\Z', $start_time) . "\r\n";
        echo "DTEND:" . gmdate('Ymd\THis\Z', $end_time) . "\r\n";
        echo "SUMMARY:⚓ Nautical Dawn → First Light\r\n";
        echo "DESCRIPTION:";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\\n";
        echo "⚓ NAUTICAL TWILIGHT (DAWN)\\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\\n";
        echo "Duration: {$duration}\\n\\n";
        echo "▸ BEFORE:\\n";
        echo "  Complete darkness with horizon invisible and stars clearly visible.\\n\\n";
        echo "▸ DURING:\\n";
        echo "  Sky brightens significantly but remains too dark for most outdoor activities without artificial light.\\n\\n";
        echo "▸ AFTER:\\n";
        echo "  Horizon becomes visible at sea; enough light for outdoor activities without flashlights.";
        echo $supplemental;
        if ($description) echo "\\n\\n" . str_replace(["\r", "\n"], ["", "\\n"], $description);
        echo "\r\n";
        echo "TRANSP:TRANSPARENT\r\n";
        echo "END:VEVENT\r\n";
    }
    
    // ASTRONOMICAL DAWN (Astronomical Dawn → Nautical Dawn)
    if ($include_astro && isset($sun_info['astronomical_twilight_begin']) && isset($sun_info['nautical_twilight_begin'])) {
        $start_time = $sun_info['astronomical_twilight_begin'] + $rise_offset;
        $end_time = $sun_info['nautical_twilight_begin'] + $rise_offset;
        $duration = format_duration($sun_info['nautical_twilight_begin'] - $sun_info['astronomical_twilight_begin']);
        
        $supplemental = build_dawn_supplemental($sun_info, $time_format, $enabled, $daylight_seconds, $daylight_pct, $daylight_percentile, $day_length_comparison, $moon_info, 'astro');
        
        echo "BEGIN:VEVENT\r\n";
        echo "UID:astro-dawn-{$date_str}-{$lat}-{$lon}@sun-calendar\r\n";
        echo "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
        echo "DTSTART:" . gmdate('Ymd\THis\Z', $start_time) . "\r\n";
        echo "DTEND:" . gmdate('Ymd\THis\Z', $end_time) . "\r\n";
        echo "SUMMARY:🌌 Astronomical Dawn → Nautical Dawn\r\n";
        echo "DESCRIPTION:";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\\n";
        echo "🌌 ASTRONOMICAL TWILIGHT (DAWN)\\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\\n";
        echo "Duration: {$duration}\\n\\n";
        echo "▸ BEFORE:\\n";
        echo "  Complete darkness with all stars fully visible; optimal conditions for astronomy.\\n\\n";
        echo "▸ DURING:\\n";
        echo "  Very faint light appears on the horizon; time to pack up telescopes.\\n\\n";
        echo "▸ AFTER:\\n";
        echo "  Stars begin to fade and sky lightens; too bright for deep-sky astronomy.";
        echo $supplemental;
        if ($description) echo "\\n\\n" . str_replace(["\r", "\n"], ["", "\\n"], $description);
        echo "\r\n";
        echo "TRANSP:TRANSPARENT\r\n";
        echo "END:VEVENT\r\n";
    }
    
    // DAYLIGHT
    if ($include_daynight && isset($sun_info['sunrise']) && isset($sun_info['sunset'])) {
        $start_time = $sun_info['sunrise'] + $rise_offset;
        $end_time = $sun_info['sunset'] + $set_offset;
        $solar_noon_time = date($time_format, $sun_info['transit']);
        $daylight_duration = format_duration($daylight_seconds);
        $sunrise_time = date($time_format, $sun_info['sunrise']);
        $sunset_time = date($time_format, $sun_info['sunset']);
        
        echo "BEGIN:VEVENT\r\n";
        echo "UID:daylight-{$date_str}-{$lat}-{$lon}@sun-calendar\r\n";
        echo "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
        echo "DTSTART:" . gmdate('Ymd\THis\Z', $start_time) . "\r\n";
        echo "DTEND:" . gmdate('Ymd\THis\Z', $end_time) . "\r\n";
        echo "SUMMARY:☀️ Daylight\r\n";
        echo "DESCRIPTION:";
        echo "Complete period from when the Sun rises above the horizon until it sets below the horizon.\\n\\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\\n";
        echo "☀️ DAYLIGHT STATISTICS\\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\\n";
        echo "Duration: {$daylight_duration} ({$daylight_pct}% of day)\\n";
        echo "Period: {$sunrise_time} - {$sunset_time}\\n";
        echo "Percentile: ⊕ {$daylight_percentile}th percentile\\n";
        echo "            ({$daylight_percentile}% of days have less daylight)\\n";
        if ($day_length_comparison) {
            echo "vs Yesterday: {$day_length_comparison}\\n";
        }
        echo "Solar Noon: {$solar_noon_time} (Sun at highest point)";
        if ($description) echo "\\n\\n" . str_replace(["\r", "\n"], ["", "\\n"], $description);
        echo "\r\n";
        echo "TRANSP:TRANSPARENT\r\n";
        echo "END:VEVENT\r\n";
    }
    
    // CIVIL DUSK (Sunset → Last Light)
    if ($include_civil && isset($sun_info['sunset']) && isset($sun_info['civil_twilight_end'])) {
        $start_time = $sun_info['sunset'] + $set_offset;
        $end_time = $sun_info['civil_twilight_end'] + $set_offset;
        $duration = format_duration($sun_info['civil_twilight_end'] - $sun_info['sunset']);
        
        $supplemental = build_dusk_supplemental($sun_info, $next_sun_info, $time_format, $enabled, $night_seconds, $night_pct, $night_percentile, $night_length_comparison, $moon_info, 'civil');
        
        echo "BEGIN:VEVENT\r\n";
        echo "UID:civil-dusk-{$date_str}-{$lat}-{$lon}@sun-calendar\r\n";
        echo "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
        echo "DTSTART:" . gmdate('Ymd\THis\Z', $start_time) . "\r\n";
        echo "DTEND:" . gmdate('Ymd\THis\Z', $end_time) . "\r\n";
        echo "SUMMARY:🌇 Sunset → Last Light\r\n";
        echo "DESCRIPTION:";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\\n";
        echo "🌇 CIVIL TWILIGHT (DUSK)\\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\\n";
        echo "Duration: {$duration}\\n\\n";
        echo "▸ BEFORE:\\n";
        echo "  Sun above horizon with full daylight conditions.\\n\\n";
        echo "▸ DURING:\\n";
        echo "  Sun dips below horizon while sky displays beautiful colors; golden hour for photography.\\n\\n";
        echo "▸ AFTER:\\n";
        echo "  Artificial light becomes necessary; bright stars become visible.";
        echo $supplemental;
        if ($description) echo "\\n\\n" . str_replace(["\r", "\n"], ["", "\\n"], $description);
        echo "\r\n";
        echo "TRANSP:TRANSPARENT\r\n";
        echo "END:VEVENT\r\n";
    }
    
    // NAUTICAL DUSK (Last Light → Nautical Dusk)
    if ($include_nautical && isset($sun_info['civil_twilight_end']) && isset($sun_info['nautical_twilight_end'])) {
        $start_time = $sun_info['civil_twilight_end'] + $set_offset;
        $end_time = $sun_info['nautical_twilight_end'] + $set_offset;
        $duration = format_duration($sun_info['nautical_twilight_end'] - $sun_info['civil_twilight_end']);
        
        $supplemental = build_dusk_supplemental($sun_info, $next_sun_info, $time_format, $enabled, $night_seconds, $night_pct, $night_percentile, $night_length_comparison, $moon_info, 'nautical');
        
        echo "BEGIN:VEVENT\r\n";
        echo "UID:nautical-dusk-{$date_str}-{$lat}-{$lon}@sun-calendar\r\n";
        echo "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
        echo "DTSTART:" . gmdate('Ymd\THis\Z', $start_time) . "\r\n";
        echo "DTEND:" . gmdate('Ymd\THis\Z', $end_time) . "\r\n";
        echo "SUMMARY:⚓ Last Light → Nautical Dusk\r\n";
        echo "DESCRIPTION:";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\\n";
        echo "⚓ NAUTICAL TWILIGHT (DUSK)\\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\\n";
        echo "Duration: {$duration}\\n\\n";
        echo "▸ BEFORE:\\n";
        echo "  Still enough natural light for outdoor activities without artificial lighting.\\n\\n";
        echo "▸ DURING:\\n";
        echo "  Sky darkens considerably; more stars become visible.\\n\\n";
        echo "▸ AFTER:\\n";
        echo "  Horizon fades from view at sea; artificial light necessary for most activities.";
        echo $supplemental;
        if ($description) echo "\\n\\n" . str_replace(["\r", "\n"], ["", "\\n"], $description);
        echo "\r\n";
        echo "TRANSP:TRANSPARENT\r\n";
        echo "END:VEVENT\r\n";
    }
    
    // ASTRONOMICAL DUSK (Nautical Dusk → Astronomical Dusk)
    if ($include_astro && isset($sun_info['nautical_twilight_end']) && isset($sun_info['astronomical_twilight_end'])) {
        $start_time = $sun_info['nautical_twilight_end'] + $set_offset;
        $end_time = $sun_info['astronomical_twilight_end'] + $set_offset;
        $duration = format_duration($sun_info['astronomical_twilight_end'] - $sun_info['nautical_twilight_end']);
        
        $supplemental = build_dusk_supplemental($sun_info, $next_sun_info, $time_format, $enabled, $night_seconds, $night_pct, $night_percentile, $night_length_comparison, $moon_info, 'astro');
        
        echo "BEGIN:VEVENT\r\n";
        echo "UID:astro-dusk-{$date_str}-{$lat}-{$lon}@sun-calendar\r\n";
        echo "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
        echo "DTSTART:" . gmdate('Ymd\THis\Z', $start_time) . "\r\n";
        echo "DTEND:" . gmdate('Ymd\THis\Z', $end_time) . "\r\n";
        echo "SUMMARY:🌌 Nautical Dusk → Astronomical Dusk\r\n";
        echo "DESCRIPTION:";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\\n";
        echo "🌌 ASTRONOMICAL TWILIGHT (DUSK)\\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\\n";
        echo "Duration: {$duration}\\n\\n";
        echo "▸ BEFORE:\\n";
        echo "  Very dark with most stars visible and faint sky glow from the Sun.\\n\\n";
        echo "▸ DURING:\\n";
        echo "  Last traces of sunlight disappear; Milky Way becomes visible in dark locations.\\n\\n";
        echo "▸ AFTER:\\n";
        echo "  All stars fully visible; sky reaches maximum darkness and optimal astronomical observation conditions.";
        echo $supplemental;
        if ($description) echo "\\n\\n" . str_replace(["\r", "\n"], ["", "\\n"], $description);
        echo "\r\n";
        echo "TRANSP:TRANSPARENT\r\n";
        echo "END:VEVENT\r\n";
    }
    
    // NIGHT
    if ($include_daynight && isset($sun_info['astronomical_twilight_end']) && isset($next_sun_info['astronomical_twilight_begin'])) {
        $start_time = $sun_info['astronomical_twilight_end'] + $set_offset;
        $end_time = $next_sun_info['astronomical_twilight_begin'] + $rise_offset;
        
        $solar_midnight = $sun_info['astronomical_twilight_end'] + (($next_sun_info['astronomical_twilight_begin'] - $sun_info['astronomical_twilight_end']) / 2);
        $solar_midnight_time = date($time_format, $solar_midnight);
        $night_duration = format_duration($night_seconds);
        $night_start_time = date($time_format, $sun_info['astronomical_twilight_end']);
        $night_end_time = date($time_format, $next_sun_info['astronomical_twilight_begin']);
        
        echo "BEGIN:VEVENT\r\n";
        echo "UID:night-{$date_str}-{$lat}-{$lon}@sun-calendar\r\n";
        echo "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
        echo "DTSTART:" . gmdate('Ymd\THis\Z', $start_time) . "\r\n";
        echo "DTEND:" . gmdate('Ymd\THis\Z', $end_time) . "\r\n";
        echo "SUMMARY:🌙 Night\r\n";
        echo "DESCRIPTION:";
        echo "Complete astronomical darkness. The darkest part of night occurs at solar midnight, when the Sun is at its lowest point below the horizon.\\n\\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\\n";
        echo "🌙 NIGHT STATISTICS\\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\\n";
        echo "Duration: {$night_duration} ({$night_pct}% of day)\\n";
        echo "Period: {$night_start_time} - {$night_end_time}\\n";
        echo "Percentile: ⊕ {$night_percentile}th percentile\\n";
        echo "            ({$night_percentile}% of nights are longer)\\n";
        if ($night_length_comparison) {
            echo "vs Yesterday: {$night_length_comparison}\\n";
        }
        echo "Solar Midnight: {$solar_midnight_time} (Darkest point)\\n\\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\\n";
        echo "🌙 MOON PHASE\\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\\n";
        echo "Current: {$moon_info['phase_name']} ({$moon_info['illumination']}% illuminated)\\n";
        echo "Previous: {$moon_info['prev_phase']['name']} - {$moon_info['prev_phase']['date']}\\n";
        echo "Next: {$moon_info['next_phase']['name']} - {$moon_info['next_phase']['date']}";
        if ($description) echo "\\n\\n" . str_replace(["\r", "\n"], ["", "\\n"], $description);
        echo "\r\n";
        echo "TRANSP:TRANSPARENT\r\n";
        echo "END:VEVENT\r\n";
    }

    $current_day = strtotime('+1 day', $current_day);
}

echo "END:VCALENDAR\r\n";
?>