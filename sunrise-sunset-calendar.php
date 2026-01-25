<?php
/**
 * Sunrise/Sunset iCal Calendar Generator with Enhanced Twilight Support
 * Generates dynamic iCalendar feeds with detailed astronomical information
 *
 * @version 5.1 - Dawn/Dusk naming, 24h format, contextual descriptions
 */

// Load configuration from external file
$config_file = __DIR__ . '/config.php';
if (!file_exists($config_file)) {
    die('Error: config.php not found. Please create it from config.example.php');
}
require_once $config_file;

// Validate AUTH_TOKEN
if (!defined('AUTH_TOKEN') || AUTH_TOKEN === 'CHANGE_ME_TO_A_RANDOM_STRING') {
    die('Error: Please set AUTH_TOKEN in config.php to a secure random string');
}

// Configuration defaults
if (!defined('CALENDAR_WINDOW_DAYS')) define('CALENDAR_WINDOW_DAYS', 365);
if (!defined('UPDATE_INTERVAL')) define('UPDATE_INTERVAL', 86400);

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Validation functions
function sanitize_float($value, $default, $min = -90, $max = 90) {
    $filtered = filter_var($value, FILTER_VALIDATE_FLOAT);
    return ($filtered === false || $filtered < $min || $filtered > $max) ? $default : $filtered;
}

function sanitize_int($value, $default, $min = -1440, $max = 1440) {
    $filtered = filter_var($value, FILTER_VALIDATE_INT);
    return ($filtered === false || $filtered < $min || $filtered > $max) ? $default : $filtered;
}

function sanitize_timezone($value) {
    $zones = timezone_identifiers_list();
    return in_array($value, $zones, true) ? $value : 'Europe/Rome';
}

function sanitize_text($value, $max_length = 500) {
    $clean = strip_tags($value);
    $clean = str_replace(["\r\n", "\r", "\n"], " ", $clean);
    return substr($clean, 0, $max_length);
}

function verify_token($provided_token) {
    return hash_equals(AUTH_TOKEN, $provided_token);
}

function format_duration($seconds) {
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    return sprintf("%dh %02dm", $hours, $minutes);
}

function calculate_daylight_percentile($target_daylight, $lat, $lon, $year) {
    $daylight_lengths = [];

    for ($day = 1; $day <= 365; $day++) {
        $timestamp = strtotime("$year-01-01 +".($day-1)." days");
        $sun_info = date_sun_info($timestamp, $lat, $lon);

        if (isset($sun_info['sunrise']) && isset($sun_info['sunset'])) {
            $daylight_lengths[] = $sun_info['sunset'] - $sun_info['sunrise'];
        }
    }

    sort($daylight_lengths);

    $count_below = 0;
    foreach ($daylight_lengths as $length) {
        if ($length < $target_daylight) $count_below++;
    }

    return round(($count_below / count($daylight_lengths)) * 100, 1);
}

function build_supplemental_info($sun_info, $time_format, $enabled_types, $daylight_duration = null, $daylight_pct = null, $daylight_percentile = null, $night_duration = null, $night_pct = null, $night_percentile = null) {
    $info = "";
    $total_enabled = count(array_filter($enabled_types));

    // Only add supplemental info if user selected just ONE event type
    if ($total_enabled !== 1) {
        return $info;
    }

    $info .= "\\n\\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\\n";
    $info .= "📅 COMPLETE SUN SCHEDULE FOR TODAY\\n";
    $info .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\\n\\n";

    if (!$enabled_types['astro'] && isset($sun_info['astronomical_twilight_begin'])) {
        $info .= "🌌 Astronomical Dawn: " . date($time_format, $sun_info['astronomical_twilight_begin']) . "\\n";
        $info .= "   Stars begin to fade, first hint of sunlight\\n\\n";
    }
    if (!$enabled_types['nautical'] && isset($sun_info['nautical_twilight_begin'])) {
        $info .= "⚓ Nautical Dawn: " . date($time_format, $sun_info['nautical_twilight_begin']) . "\\n";
        $info .= "   Horizon becomes visible at sea\\n\\n";
    }
    if (!$enabled_types['civil'] && isset($sun_info['civil_twilight_begin'])) {
        $info .= "🌅 First Light (Civil Dawn): " . date($time_format, $sun_info['civil_twilight_begin']) . "\\n";
        $info .= "   Enough light for outdoor activities\\n\\n";
    }
    if (!$enabled_types['daylight'] && isset($sun_info['sunrise'])) {
        $info .= "☀️ Sunrise: " . date($time_format, $sun_info['sunrise']) . "\\n";
        $info .= "   Sun breaks the horizon\\n\\n";
    }
    if (!$enabled_types['daylight'] && isset($sun_info['transit'])) {
        $info .= "☀️ Solar Noon: " . date($time_format, $sun_info['transit']) . "\\n";
        $info .= "   Sun at highest point in sky\\n\\n";
    }
    if (!$enabled_types['daylight'] && isset($sun_info['sunset'])) {
        $info .= "☀️ Sunset: " . date($time_format, $sun_info['sunset']) . "\\n";
        $info .= "   Sun dips below horizon\\n\\n";
    }
    if (!$enabled_types['civil'] && isset($sun_info['civil_twilight_end'])) {
        $info .= "🌇 Last Light (Civil Dusk): " . date($time_format, $sun_info['civil_twilight_end']) . "\\n";
        $info .= "   Artificial light becomes necessary\\n\\n";
    }
    if (!$enabled_types['nautical'] && isset($sun_info['nautical_twilight_end'])) {
        $info .= "⚓ Nautical Dusk: " . date($time_format, $sun_info['nautical_twilight_end']) . "\\n";
        $info .= "   Horizon fades from view\\n\\n";
    }
    if (!$enabled_types['astro'] && isset($sun_info['astronomical_twilight_end'])) {
        $info .= "🌌 Astronomical Dusk: " . date($time_format, $sun_info['astronomical_twilight_end']) . "\\n";
        $info .= "   Complete darkness, stars fully visible\\n\\n";
    }

    // Add daylight/night statistics if not showing daylight events
    if (!$enabled_types['daylight'] && $daylight_duration) {
        $info .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\\n";
        $info .= "📊 TODAY'S STATISTICS\\n";
        $info .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\\n\\n";
        $info .= "☀️ Daylight: {$daylight_duration} ({$daylight_pct}% of day) [{$daylight_percentile} percentile]\\n";
        $info .= "   Time when Sun is above the horizon\\n\\n";
        $info .= "🌙 Night: {$night_duration} ({$night_pct}% of day) [{$night_percentile} percentile]\\n";
        $info .= "   Time when Sun is below the horizon\\n";
    }

    return $info;
}

// Handle calendar feed requests
if (isset($_GET['feed']) && isset($_GET['token'])) {

    if (!verify_token($_GET['token'])) {
        http_response_code(403);
        die('Invalid authentication token');
    }

    $lat = sanitize_float($_GET['lat'] ?? '', 41.9028, -90, 90);
    $lon = sanitize_float($_GET['lon'] ?? '', 12.4964, -180, 180);
    $elevation = sanitize_float($_GET['elev'] ?? '', 21, -500, 9000);
    $timezone = sanitize_timezone($_GET['zone'] ?? 'Europe/Rome');
    $rise_offset = sanitize_int($_GET['rise_off'] ?? 0, 0) * 60;
    $set_offset = sanitize_int($_GET['set_off'] ?? 0, 0) * 60;
    $twelve_hour = isset($_GET['twelve']) && $_GET['twelve'] === '1';
    $description = sanitize_text($_GET['desc'] ?? '');

    $include_civil = isset($_GET['civil']) && $_GET['civil'] === '1';
    $include_nautical = isset($_GET['nautical']) && $_GET['nautical'] === '1';
    $include_astro = isset($_GET['astro']) && $_GET['astro'] === '1';
    $include_daylight = isset($_GET['sun']) && $_GET['sun'] === '1';

    putenv("TZ={$timezone}");
    date_default_timezone_set($timezone);

    header('Content-Type: text/calendar; charset=utf-8');
    header('Cache-Control: max-age=' . UPDATE_INTERVAL);

    echo "BEGIN:VCALENDAR\r\n";
    echo "VERSION:2.0\r\n";
    echo "PRODID:-//Sun & Twilight Calendar//EN\r\n";
    echo "CALSCALE:GREGORIAN\r\n";
    echo "METHOD:PUBLISH\r\n";
    echo "X-WR-CALNAME:Sun & Twilight - {$lat}, {$lon}\r\n";
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

        if (isset($sun_info['sunrise']) && isset($sun_info['sunset'])) {
            $daylight_seconds = $sun_info['sunset'] - $sun_info['sunrise'];
            $night_seconds = 86400 - $daylight_seconds;
            $daylight_pct = round(($daylight_seconds / 86400) * 100, 1);
            $night_pct = round(($night_seconds / 86400) * 100, 1);
            $daylight_percentile = calculate_daylight_percentile($daylight_seconds, $lat, $lon, $year);
            $night_percentile = 100 - $daylight_percentile;
        }

        $time_format = $twelve_hour ? 'g:i A' : 'H:i';

        // Track which event types are enabled for supplemental info
        $enabled_types = [
            'astro' => $include_astro,
            'nautical' => $include_nautical,
            'civil' => $include_civil,
            'daylight' => $include_daylight
        ];

        $supplemental_info = build_supplemental_info($sun_info, $time_format, $enabled_types, format_duration($daylight_seconds), $daylight_pct, $daylight_percentile, format_duration($night_seconds), $night_pct, $night_percentile);

        // ASTRONOMICAL DAWN
        if ($include_astro && isset($sun_info['astronomical_twilight_begin']) && isset($sun_info['nautical_twilight_begin'])) {
            $start_time = $sun_info['astronomical_twilight_begin'] + $rise_offset;
            $end_time = $sun_info['nautical_twilight_begin'] + $rise_offset;

            echo "BEGIN:VEVENT\r\n";
            echo "UID:astro-dawn-{$date_str}-{$lat}-{$lon}@sun-calendar\r\n";
            echo "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
            echo "DTSTART:" . gmdate('Ymd\THis\Z', $start_time) . "\r\n";
            echo "DTEND:" . gmdate('Ymd\THis\Z', $end_time) . "\r\n";
            echo "SUMMARY:🌌 Astronomical Dawn\r\n";
            echo "DESCRIPTION:";
            echo "The very first hint of sunlight begins to appear on the horizon. Stars start to fade as the Sun rises from 18° to 12° below the horizon. The sky transitions from complete darkness to a faint glow. ";
            echo "Perfect time for astrophotographers to finish their work before the sky becomes too bright.";
            echo $supplemental_info;
            if ($description) echo "\\n\\n" . str_replace(["\r", "\n"], ["", "\\n"], $description);
            echo "\r\n";
            echo "TRANSP:TRANSPARENT\r\n";
            echo "END:VEVENT\r\n";
        }

        // NAUTICAL DAWN
        if ($include_nautical && isset($sun_info['nautical_twilight_begin']) && isset($sun_info['civil_twilight_begin'])) {
            $start_time = $sun_info['nautical_twilight_begin'] + $rise_offset;
            $end_time = $sun_info['civil_twilight_begin'] + $rise_offset;

            echo "BEGIN:VEVENT\r\n";
            echo "UID:nautical-dawn-{$date_str}-{$lat}-{$lon}@sun-calendar\r\n";
            echo "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
            echo "DTSTART:" . gmdate('Ymd\THis\Z', $start_time) . "\r\n";
            echo "DTEND:" . gmdate('Ymd\THis\Z', $end_time) . "\r\n";
            echo "SUMMARY:⚓ Nautical Dawn\r\n";
            echo "DESCRIPTION:";
            echo "The horizon becomes visible at sea. The Sun rises from 12° to 6° below the horizon. Sky brightens significantly but still too dark for most outdoor activities without artificial light. ";
            echo "Sailors can distinguish the horizon and take star sightings for navigation.";
            echo $supplemental_info;
            if ($description) echo "\\n\\n" . str_replace(["\r", "\n"], ["", "\\n"], $description);
            echo "\r\n";
            echo "TRANSP:TRANSPARENT\r\n";
            echo "END:VEVENT\r\n";
        }

        // CIVIL DAWN (First Light → Sunrise)
        if ($include_civil && isset($sun_info['civil_twilight_begin']) && isset($sun_info['sunrise'])) {
            $start_time = $sun_info['civil_twilight_begin'] + $rise_offset;
            $end_time = $sun_info['sunrise'] + $rise_offset;

            echo "BEGIN:VEVENT\r\n";
            echo "UID:civil-dawn-{$date_str}-{$lat}-{$lon}@sun-calendar\r\n";
            echo "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
            echo "DTSTART:" . gmdate('Ymd\THis\Z', $start_time) . "\r\n";
            echo "DTEND:" . gmdate('Ymd\THis\Z', $end_time) . "\r\n";
            echo "SUMMARY:🌅 First Light → Sunrise\r\n";
            echo "DESCRIPTION:";
            echo "There is enough natural light for most outdoor activities without artificial lighting. The Sun rises from 6° below the horizon to the horizon. ";
            echo "Colors appear in the sky transitioning from deep blue to orange and pink. Birds begin their morning songs. Perfect for the photography 'blue hour' with soft, diffused light. ";
            echo "By the end of this period, the Sun's upper edge breaks the horizon.";
            echo $supplemental_info;
            if ($description) echo "\\n\\n" . str_replace(["\r", "\n"], ["", "\\n"], $description);
            echo "\r\n";
            echo "TRANSP:TRANSPARENT\r\n";
            echo "END:VEVENT\r\n";
        }

        // DAYLIGHT (Sunrise to Sunset)
        if ($include_daylight && isset($sun_info['sunrise']) && isset($sun_info['sunset'])) {
            $start_time = $sun_info['sunrise'] + $rise_offset;
            $end_time = $sun_info['sunset'] + $set_offset;
            $solar_noon = isset($sun_info['transit']) ? date($time_format, $sun_info['transit']) : 'N/A';
            $daylight_duration = format_duration($daylight_seconds);

            echo "BEGIN:VEVENT\r\n";
            echo "UID:daylight-{$date_str}-{$lat}-{$lon}@sun-calendar\r\n";
            echo "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
            echo "DTSTART:" . gmdate('Ymd\THis\Z', $start_time) . "\r\n";
            echo "DTEND:" . gmdate('Ymd\THis\Z', $end_time) . "\r\n";
            echo "SUMMARY:☀️ Daylight\r\n";
            echo "DESCRIPTION:";
            echo "The Sun is above the horizon providing full daylight. ";
            echo "Solar Noon: {$solar_noon}\\n\\n";
            echo "DAYLIGHT: {$daylight_duration} ({$daylight_pct}% of day) [{$daylight_percentile} percentile]\\n\\n";
            echo "This is the complete period from when the Sun rises above the horizon until it sets below the horizon. ";
            echo "Solar noon marks when the Sun reaches its highest point in the sky.";
            echo $supplemental_info;
            if ($description) echo "\\n\\n" . str_replace(["\r", "\n"], ["", "\\n"], $description);
            echo "\r\n";
            echo "TRANSP:TRANSPARENT\r\n";
            echo "END:VEVENT\r\n";
        }

        // CIVIL DUSK (Sunset → Last Light)
        if ($include_civil && isset($sun_info['sunset']) && isset($sun_info['civil_twilight_end'])) {
            $start_time = $sun_info['sunset'] + $set_offset;
            $end_time = $sun_info['civil_twilight_end'] + $set_offset;

            echo "BEGIN:VEVENT\r\n";
            echo "UID:civil-dusk-{$date_str}-{$lat}-{$lon}@sun-calendar\r\n";
            echo "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
            echo "DTSTART:" . gmdate('Ymd\THis\Z', $start_time) . "\r\n";
            echo "DTEND:" . gmdate('Ymd\THis\Z', $end_time) . "\r\n";
            echo "SUMMARY:🌇 Sunset → Last Light\r\n";
            echo "DESCRIPTION:";
            echo "The Sun descends from the horizon to 6° below. Beautiful golden and orange hues fill the sky. ";
            echo "Still enough natural light for outdoor activities without artificial lighting. Perfect for the photography 'golden hour' with warm, flattering light. ";
            echo "By the end of this period, street lights typically turn on and artificial lighting becomes necessary.";
            echo $supplemental_info;
            if ($description) echo "\\n\\n" . str_replace(["\r", "\n"], ["", "\\n"], $description);
            echo "\r\n";
            echo "TRANSP:TRANSPARENT\r\n";
            echo "END:VEVENT\r\n";
        }

        // NAUTICAL DUSK
        if ($include_nautical && isset($sun_info['civil_twilight_end']) && isset($sun_info['nautical_twilight_end'])) {
            $start_time = $sun_info['civil_twilight_end'] + $set_offset;
            $end_time = $sun_info['nautical_twilight_end'] + $set_offset;

            echo "BEGIN:VEVENT\r\n";
            echo "UID:nautical-dusk-{$date_str}-{$lat}-{$lon}@sun-calendar\r\n";
            echo "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
            echo "DTSTART:" . gmdate('Ymd\THis\Z', $start_time) . "\r\n";
            echo "DTEND:" . gmdate('Ymd\THis\Z', $end_time) . "\r\n";
            echo "SUMMARY:⚓ Nautical Dusk\r\n";
            echo "DESCRIPTION:";
            echo "The Sun descends from 6° to 12° below the horizon. The sky darkens considerably and most stars become visible. ";
            echo "The horizon fades from view at sea. Artificial light is necessary for most outdoor activities. ";
            echo "Sailors can still take celestial observations using both stars and the horizon.";
            echo $supplemental_info;
            if ($description) echo "\\n\\n" . str_replace(["\r", "\n"], ["", "\\n"], $description);
            echo "\r\n";
            echo "TRANSP:TRANSPARENT\r\n";
            echo "END:VEVENT\r\n";
        }

        // ASTRONOMICAL DUSK
        if ($include_astro && isset($sun_info['nautical_twilight_end']) && isset($sun_info['astronomical_twilight_end'])) {
            $start_time = $sun_info['nautical_twilight_end'] + $set_offset;
            $end_time = $sun_info['astronomical_twilight_end'] + $set_offset;

            echo "BEGIN:VEVENT\r\n";
            echo "UID:astro-dusk-{$date_str}-{$lat}-{$lon}@sun-calendar\r\n";
            echo "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
            echo "DTSTART:" . gmdate('Ymd\THis\Z', $start_time) . "\r\n";
            echo "DTEND:" . gmdate('Ymd\THis\Z', $end_time) . "\r\n";
            echo "SUMMARY:🌌 Astronomical Dusk\r\n";
            echo "DESCRIPTION:";
            echo "The Sun descends from 12° to 18° below the horizon. The last traces of sunlight disappear from the sky. ";
            echo "All stars and celestial objects become fully visible. The Milky Way emerges (weather and light pollution permitting). ";
            echo "This is when the night sky reaches its maximum darkness and astronomical observations reach optimal conditions.";
            echo $supplemental_info;
            if ($description) echo "\\n\\n" . str_replace(["\r", "\n"], ["", "\\n"], $description);
            echo "\r\n";
            echo "TRANSP:TRANSPARENT\r\n";
            echo "END:VEVENT\r\n";
        }

        // NIGHT
        if ($include_astro && isset($sun_info['astronomical_twilight_end'])) {
            $start_time = $sun_info['astronomical_twilight_end'] + $set_offset;
            $next_day = strtotime('+1 day', $current_day);
            $next_sun_info = date_sun_info($next_day, $lat, $lon);

            if (isset($next_sun_info['astronomical_twilight_begin'])) {
                $end_time = $next_sun_info['astronomical_twilight_begin'] + $rise_offset;
                $solar_midnight = $sun_info['astronomical_twilight_end'] + (($next_sun_info['astronomical_twilight_begin'] - $sun_info['astronomical_twilight_end']) / 2);
                $solar_midnight_display = date($time_format, $solar_midnight);
                $night_duration = format_duration($night_seconds);

                echo "BEGIN:VEVENT\r\n";
                echo "UID:night-{$date_str}-{$lat}-{$lon}@sun-calendar\r\n";
                echo "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
                echo "DTSTART:" . gmdate('Ymd\THis\Z', $start_time) . "\r\n";
                echo "DTEND:" . gmdate('Ymd\THis\Z', $end_time) . "\r\n";
                echo "SUMMARY:🌙 Night\r\n";
                echo "DESCRIPTION:";
                echo "Solar Midnight: {$solar_midnight_display}\\n\\n";
                echo "NIGHT: {$night_duration} ({$night_pct}% of day) [{$night_percentile} percentile]\\n\\n";
                echo "Complete astronomical darkness. The Sun provides no illumination whatsoever (more than 18° below horizon). ";
                echo "Optimal conditions for stargazing, astrophotography, and astronomical observations. ";
                echo "The darkest part of night occurs at solar midnight, when the Sun is at its lowest point below the horizon.";
                echo $supplemental_info;
                if ($description) echo "\\n\\n" . str_replace(["\r", "\n"], ["", "\\n"], $description);
                echo "\r\n";
                echo "TRANSP:TRANSPARENT\r\n";
                echo "END:VEVENT\r\n";
            }
        }

        $current_day = strtotime('+1 day', $current_day);
    }

    echo "END:VCALENDAR\r\n";
    exit;
}

// Handle form submission
if (isset($_POST['generate_url']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    if (!verify_token($password)) {
        $error = 'Invalid password';
    } else {
        $params = [
            'feed' => '1',
            'token' => AUTH_TOKEN,
            'lat' => $_POST['lat'] ?? 41.9028,
            'lon' => $_POST['lon'] ?? 12.4964,
            'elev' => $_POST['elevation'] ?? 21,
            'zone' => $_POST['zone'] ?? 'Europe/Rome',
            'rise_off' => $_POST['rise_off'] ?? 0,
            'set_off' => $_POST['set_off'] ?? 0,
            'twelve' => isset($_POST['twelve']) ? '1' : '0',
            'desc' => $_POST['description'] ?? '',
        ];

        if (isset($_POST['civil'])) $params['civil'] = '1';
        if (isset($_POST['nautical'])) $params['nautical'] = '1';
        if (isset($_POST['astro'])) $params['astro'] = '1';
        if (isset($_POST['sun'])) $params['sun'] = '1';

        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $script = $_SERVER['SCRIPT_NAME'];
        $subscription_url = $protocol . '://' . $host . $script . '?' . http_build_query($params);
        $webcal_url = str_replace(['https://', 'http://'], 'webcal://', $subscription_url);
    }
}

putenv("TZ=Europe/Rome");
date_default_timezone_set('Europe/Rome');
$default_lat = 41.9028;
$default_lon = 12.4964;
$sun_info = date_sun_info(time(), $default_lat, $default_lon);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enhanced Sun & Twilight Calendar</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 900px;
            margin: 0 auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 { color: #2c3e50; margin-top: 0; font-size: 1.8em; }
        .info-box, .success-box, .error-box, .warning-box, .feature-box {
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .info-box { background: #e8f4f8; border-left: 4px solid #3498db; }
        .success-box { background: #d4edda; border-left: 4px solid #28a745; }
        .error-box { background: #f8d7da; border-left: 4px solid #dc3545; color: #721c24; }
        .warning-box { background: #fff3cd; border-left: 4px solid #ffc107; }
        .feature-box { background: #f0f9ff; border-left: 4px solid #0ea5e9; }
        .form-group { margin-bottom: 20px; }
        label {
            display: block;
            font-weight: 600;
            margin-bottom: 5px;
            color: #2c3e50;
        }
        .required { color: #e74c3c; }
        input[type="text"], input[type="number"], input[type="password"], select, textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
        }
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }
        .checkbox-group {
            display: flex;
            align-items: center;
            padding: 10px 0;
        }
        input[type="checkbox"] {
            width: 20px;
            height: 20px;
            margin-right: 10px;
            cursor: pointer;
        }
        .checkbox-group label {
            margin: 0;
            cursor: pointer;
            font-weight: normal;
        }
        button {
            background: #3498db;
            color: white;
            border: none;
            padding: 12px 24px;
            font-size: 16px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            transition: background 0.3s;
        }
        button:hover { background: #2980b9; }
        button.secondary { background: #95a5a6; }
        button.secondary:hover { background: #7f8c8d; }
        input[type="submit"] {
            background: #27ae60;
            color: white;
            border: none;
            padding: 15px 30px;
            font-size: 18px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            width: 100%;
        }
        input[type="submit"]:hover { background: #229954; }
        .help-text { font-size: 0.9em; color: #666; margin-top: 5px; }
        hr { border: none; border-top: 1px solid #eee; margin: 25px 0; }
        .url-display {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            border: 1px solid #dee2e6;
            word-break: break-all;
            font-family: monospace;
            font-size: 14px;
        }
        .copy-button { margin-top: 10px; background: #6c757d; }
        .copy-button:hover { background: #5a6268; }
        #location-status {
            padding: 10px;
            margin-top: 10px;
            border-radius: 4px;
            display: none;
        }
        #location-status.success {
            display: block;
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        #location-status.error {
            display: block;
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .time-format-toggle {
            background: #6c757d;
            padding: 8px 16px;
            border-radius: 4px;
            display: inline-block;
            margin-bottom: 10px;
            cursor: pointer;
        }
        .time-format-toggle:hover {
            background: #5a6268;
        }
        @media (max-width: 768px) {
            body { padding: 10px; }
            .container { padding: 20px; }
            h1 { font-size: 1.5em; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🌅 Enhanced Sun & Twilight Calendar</h1>

        <div class="info-box">
            <strong>Today's Information (Rome, Italy)</strong><br>
            <button class="time-format-toggle" onclick="toggleTimeFormat()">Switch to 12-hour format</button><br>
            Date: <?php echo date('F j, Y'); ?><br>
            <span id="time-display">
                Astronomical Dawn: <?php echo date('H:i', $sun_info['astronomical_twilight_begin']); ?><br>
                Nautical Dawn: <?php echo date('H:i', $sun_info['nautical_twilight_begin']); ?><br>
                First Light (Civil Dawn): <?php echo date('H:i', $sun_info['civil_twilight_begin']); ?><br>
                Sunrise: <?php echo date('H:i', $sun_info['sunrise']); ?><br>
                Solar Noon: <?php echo date('H:i', $sun_info['transit']); ?><br>
                Sunset: <?php echo date('H:i', $sun_info['sunset']); ?><br>
                Last Light (Civil Dusk): <?php echo date('H:i', $sun_info['civil_twilight_end']); ?><br>
                Nautical Dusk: <?php echo date('H:i', $sun_info['nautical_twilight_end']); ?><br>
                Astronomical Dusk: <?php echo date('H:i', $sun_info['astronomical_twilight_end']); ?>
            </span>
        </div>

        <div class="feature-box">
            <strong>✨ Enhanced Features</strong><br>
            • <strong>Detailed Statistics:</strong> Daylight/night duration with percentages and yearly percentiles<br>
            • <strong>Solar Events:</strong> Solar noon and solar midnight times<br>
            • <strong>Multiple Twilight Options:</strong> Dawn/Dusk naming for Civil, Nautical, and Astronomical twilight<br>
            • <strong>Contextual Descriptions:</strong> Each event describes what happens during that specific period<br>
            • <strong>Smart Info:</strong> Select just one event type to get all other times in the event notes
        </div>

        <?php if (isset($error)): ?>
        <div class="error-box">
            <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>

        <?php if (isset($subscription_url)): ?>
        <div class="success-box">
            <h3>✅ Subscription URL Generated!</h3>
            <p><strong>Your Calendar Subscription URL:</strong></p>
            <div class="url-display" id="subscription-url"><?php echo htmlspecialchars($subscription_url); ?></div>
            <button class="copy-button" onclick="copyToClipboard('subscription-url')">📋 Copy URL</button>

            <p style="margin-top: 20px;"><strong>Webcal URL (recommended):</strong></p>
            <div class="url-display" id="webcal-url"><?php echo htmlspecialchars($webcal_url); ?></div>
            <button class="copy-button" onclick="copyToClipboard('webcal-url')">📋 Copy Webcal URL</button>

            <hr>
            <h4>How to Add to Google Calendar:</h4>
            <ol>
                <li>Copy the webcal URL above</li>
                <li>Open <a href="https://calendar.google.com" target="_blank">Google Calendar</a></li>
                <li>Click the <strong>+</strong> next to "Other calendars"</li>
                <li>Select <strong>"From URL"</strong></li>
                <li>Paste your subscription URL</li>
                <li>Click <strong>"Add calendar"</strong></li>
            </ol>
            <p><strong>Tip:</strong> If you selected only one event type, all other sun times are included in the event descriptions!</p>
        </div>
        <?php endif; ?>

        <div class="warning-box">
            <strong>🔒 Authentication Required</strong><br>
            This page requires a password to generate calendar feeds.
        </div>

        <button onclick="getLocation()" class="secondary">📍 Use My Current Location</button>
        <div id="location-status"></div>

        <form method="post" onsubmit="return validateForm()">
            <hr>

            <div class="form-group">
                <label for="password">Password <span class="required">*</span></label>
                <input type="password" name="password" id="password" required>
            </div>

            <hr>

            <div class="form-group">
                <label for="lat">Latitude <span class="required">*</span></label>
                <input type="number" name="lat" id="lat" step="0.000001" min="-90" max="90"
                       value="<?php echo $default_lat; ?>" required>
                <div class="help-text">Decimal format (e.g., 41.9028 for Rome)</div>
            </div>

            <div class="form-group">
                <label for="lon">Longitude <span class="required">*</span></label>
                <input type="number" name="lon" id="lon" step="0.000001" min="-180" max="180"
                       value="<?php echo $default_lon; ?>" required>
                <div class="help-text">Decimal format (e.g., 12.4964 for Rome)</div>
            </div>

            <div class="form-group">
                <label for="elevation">Elevation (meters)</label>
                <input type="number" name="elevation" id="elevation" step="1" min="-500" max="9000" value="21">
                <div class="help-text">Your elevation above sea level (Rome: ~21m)</div>
            </div>

            <div class="form-group">
                <label for="zone">Timezone <span class="required">*</span></label>
                <select name="zone" id="zone" required>
                    <option value="Europe/Rome" selected>Europe/Rome (Central European Time)</option>
                    <?php
                    $zones = timezone_identifiers_list();
                    foreach ($zones as $zone) {
                        if ($zone !== 'Europe/Rome') {
                            echo "<option value=\"" . htmlspecialchars($zone) . "\">" .
                                 htmlspecialchars($zone) . "</option>\n";
                        }
                    }
                    ?>
                </select>
            </div>

            <hr>

            <div class="form-group">
                <strong>Event Types <span class="required">*</span></strong>
                <div class="help-text" style="margin-bottom: 10px;">💡 Select just ONE type to get all sun times in the event notes!</div>
                <div class="checkbox-group">
                    <input type="checkbox" name="sun" id="sun" checked>
                    <label for="sun">☀️ Day & Night - Daylight period + complete night period with all statistics</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" name="civil" id="civil" checked>
                    <label for="civil">🌅 Civil Dawn/Dusk - First light to sunrise, sunset to last light</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" name="nautical" id="nautical">
                    <label for="nautical">⚓ Nautical Dawn/Dusk - When horizon becomes visible/invisible</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" name="astro" id="astro">
                    <label for="astro">🌌 Astronomical Dawn/Dusk - Stars appear/disappear + complete night period</label>
                </div>
            </div>

            <hr>

            <div class="form-group">
                <label for="rise_off">Sunrise Offset (minutes)</label>
                <input type="number" name="rise_off" id="rise_off" value="0" min="-1440" max="1440">
                <div class="help-text">Shift morning events earlier (negative) or later (positive)</div>
            </div>

            <div class="form-group">
                <label for="set_off">Sunset Offset (minutes)</label>
                <input type="number" name="set_off" id="set_off" value="0" min="-1440" max="1440">
                <div class="help-text">Shift evening events earlier (negative) or later (positive)</div>
            </div>

            <hr>

            <div class="form-group">
                <div class="checkbox-group">
                    <input type="checkbox" name="twelve" id="twelve">
                    <label for="twelve">Use 12-hour time format (AM/PM) in calendar events</label>
                </div>
            </div>

            <div class="form-group">
                <label for="description">Custom Event Description (optional)</label>
                <textarea name="description" id="description" rows="3"
                          placeholder="Add your own note to all events"></textarea>
            </div>

            <input type="submit" name="generate_url" value="Generate Subscription URL">
        </form>

        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; font-size: 0.9em; color: #666;">
            <strong>Understanding Dawn & Dusk:</strong>
            <ul>
                <li><strong>Astronomical:</strong> Sun 12-18° below horizon. Stars appear/disappear.</li>
                <li><strong>Nautical:</strong> Sun 6-12° below horizon. Horizon becomes visible/invisible at sea.</li>
                <li><strong>Civil:</strong> Sun 0-6° below horizon. Enough light for outdoor activities.</li>
                <li><strong>Daylight:</strong> Sun above horizon. Full sunlight.</li>
            </ul>

            <strong>Statistics Explained:</strong>
            <ul>
                <li><strong>Duration:</strong> Total time in hours and minutes</li>
                <li><strong>Percentage:</strong> Portion of the 24-hour day</li>
                <li><strong>Percentile:</strong> Ranking among all days (0 = shortest, 100 = longest)</li>
            </ul>

            <strong>Pro Tip:</strong>
            <ul>
                <li><strong>Select only ONE event type</strong> to get a focused calendar with all other sun times and statistics automatically included in each event's description. This gives you a clean calendar view while keeping all the astronomical data at your fingertips!</li>
                <li>For example: Select only "Civil Dawn/Dusk" to see just the useful twilight periods, but get sunrise, sunset, solar noon, and all other twilight times in the event notes.</li>
            </ul>
        </div>
    </div>

    <script>
        const sunInfo = {
            astro_begin: <?php echo $sun_info['astronomical_twilight_begin']; ?>,
            nautical_begin: <?php echo $sun_info['nautical_twilight_begin']; ?>,
            civil_begin: <?php echo $sun_info['civil_twilight_begin']; ?>,
            sunrise: <?php echo $sun_info['sunrise']; ?>,
            transit: <?php echo $sun_info['transit']; ?>,
            sunset: <?php echo $sun_info['sunset']; ?>,
            civil_end: <?php echo $sun_info['civil_twilight_end']; ?>,
            nautical_end: <?php echo $sun_info['nautical_twilight_end']; ?>,
            astro_end: <?php echo $sun_info['astronomical_twilight_end']; ?>
        };

        let use12Hour = false;

        function formatTime(timestamp, twelveHour) {
            const date = new Date(timestamp * 1000);
            if (twelveHour) {
                return date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
            } else {
                return date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: false });
            }
        }

        function toggleTimeFormat() {
            use12Hour = !use12Hour;
            const button = document.querySelector('.time-format-toggle');
            button.textContent = use12Hour ? 'Switch to 24-hour format' : 'Switch to 12-hour format';

            const display = document.getElementById('time-display');
            display.innerHTML = `
                Astronomical Dawn: ${formatTime(sunInfo.astro_begin, use12Hour)}<br>
                Nautical Dawn: ${formatTime(sunInfo.nautical_begin, use12Hour)}<br>
                First Light (Civil Dawn): ${formatTime(sunInfo.civil_begin, use12Hour)}<br>
                Sunrise: ${formatTime(sunInfo.sunrise, use12Hour)}<br>
                Solar Noon: ${formatTime(sunInfo.transit, use12Hour)}<br>
                Sunset: ${formatTime(sunInfo.sunset, use12Hour)}<br>
                Last Light (Civil Dusk): ${formatTime(sunInfo.civil_end, use12Hour)}<br>
                Nautical Dusk: ${formatTime(sunInfo.nautical_end, use12Hour)}<br>
                Astronomical Dusk: ${formatTime(sunInfo.astro_end, use12Hour)}
            `;
        }

        function getLocation() {
            const status = document.getElementById('location-status');
            status.className = '';
            status.style.display = 'none';

            if (!navigator.geolocation) {
                status.className = 'error';
                status.textContent = 'Geolocation not supported by your browser.';
                return;
            }

            navigator.geolocation.getCurrentPosition(
                function(position) {
                    document.getElementById('lat').value = Math.round(position.coords.latitude * 1000000) / 1000000;
                    document.getElementById('lon').value = Math.round(position.coords.longitude * 1000000) / 1000000;
                    if (position.coords.altitude !== null) {
                        document.getElementById('elevation').value = Math.round(position.coords.altitude);
                    }
                    status.className = 'success';
                    status.textContent = '✓ Location retrieved successfully!';
                },
                function(error) {
                    status.className = 'error';
                    let message = 'Unable to retrieve location. ';
                    switch(error.code) {
                        case error.PERMISSION_DENIED:
                            message += 'Permission denied.';
                            break;
                        case error.POSITION_UNAVAILABLE:
                            message += 'Location unavailable.';
                            break;
                        case error.TIMEOUT:
                            message += 'Request timed out.';
                            break;
                        default:
                            message += 'Error: ' + error.message;
                    }
                    status.textContent = message;
                },
                { enableHighAccuracy: true }
            );
        }

        function validateForm() {
            const sun = document.getElementById('sun').checked;
            const civil = document.getElementById('civil').checked;
            const nautical = document.getElementById('nautical').checked;
            const astro = document.getElementById('astro').checked;

            if (!sun && !civil && !nautical && !astro) {
                alert('Please select at least one event type.');
                return false;
            }

            const password = document.getElementById('password').value;
            if (!password) {
                alert('Please enter the password.');
                return false;
            }

            return true;
        }

        function copyToClipboard(elementId) {
            const element = document.getElementById(elementId);
            const text = element.textContent;

            navigator.clipboard.writeText(text).then(function() {
                const button = event.target;
                const originalText = button.textContent;
                button.textContent = '✓ Copied!';
                button.style.background = '#28a745';

                setTimeout(function() {
                    button.textContent = originalText;
                    button.style.background = '';
                }, 2000);
            }, function(err) {
                alert('Failed to copy: ' + err);
            });
        }
    </script>
</body>
</html>
