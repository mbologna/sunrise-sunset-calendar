<?php

/**
 * Sunrise/Sunset iCal Calendar Generator
 * Version 8.0 - Full NREL SPA via external library.
 */
$config_file = __DIR__ . '/config/config.php';
if (!file_exists($config_file)) {
    die('Error: config/config.php not found');
}
require_once $config_file;

// Load Composer autoloader if available
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Load solar calculation wrapper
require_once __DIR__ . '/src/solar-spa-wrapper.php';

// Load accurate astronomical calculations (Meeus algorithms)
require_once __DIR__ . '/src/meeus-astronomy.php';

// Load strings configuration and make it globally accessible
$GLOBALS['STRINGS'] = require __DIR__ . '/src/strings.php';

if (!defined('AUTH_TOKEN') || AUTH_TOKEN === 'CHANGE_ME_TO_A_RANDOM_STRING') {
    die('Error: Please set AUTH_TOKEN in config.php');
}

if (!defined('CALENDAR_WINDOW_DAYS')) {
    define('CALENDAR_WINDOW_DAYS', 365);
}
if (!defined('UPDATE_INTERVAL')) {
    define('UPDATE_INTERVAL', 86400);
}

// Security headers (skip in CLI mode for testing)
if (php_sapi_name() !== 'cli') {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
}

function sanitize_float($value, $default, $min = -90, $max = 90)
{
    $filtered = filter_var($value, FILTER_VALIDATE_FLOAT);

    return ($filtered === false || $filtered < $min || $filtered > $max) ? $default : $filtered;
}

function sanitize_int($value, $default, $min = -1440, $max = 1440)
{
    $filtered = filter_var($value, FILTER_VALIDATE_INT);

    return ($filtered === false || $filtered < $min || $filtered > $max) ? $default : $filtered;
}

function sanitize_timezone($value)
{
    $zones = timezone_identifiers_list();

    return in_array($value, $zones, true) ? $value : 'Europe/Rome';
}

function sanitize_text($value, $max_length = 500)
{
    $clean = strip_tags($value);
    $clean = str_replace(["\r\n", "\r", "\n"], ' ', $clean);

    return substr($clean, 0, $max_length);
}

function verify_token($provided_token)
{
    return hash_equals(AUTH_TOKEN, $provided_token);
}

function format_duration($seconds)
{
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);

    return sprintf('%dh %02dm', $hours, $minutes);
}

function format_duration_short($seconds)
{
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    if ($hours > 0) {
        return sprintf('%dh %dm', $hours, $minutes);
    } elseif ($minutes > 0) {
        return sprintf('%dm %ds', $minutes, $secs);
    } else {
        return sprintf('%ds', $secs);
    }
}

function format_day_length_comparison($diff_seconds, $type = 'day')
{
    global $STRINGS;
    $abs_diff = abs($diff_seconds);
    $minutes = floor($abs_diff / 60);
    $seconds = $abs_diff % 60;

    if ($diff_seconds > 0) {
        return sprintf('+%dm %02ds', $minutes, $seconds);
    } elseif ($diff_seconds < 0) {
        return sprintf('-%dm %02ds', $minutes, $seconds);
    } else {
        return $STRINGS['comparisons']['same_length'];
    }
}

function julianDay($y, $m, $d, $hourUTC = 0.0)
{
    if ($m <= 2) {
        $y -= 1;
        $m += 12;
    }
    $A = floor($y / 100);
    $B = 2 - $A + floor($A / 4);

    return floor(365.25 * ($y + 4716)) + floor(30.6001 * ($m + 1)) + $d + $B - 1524.5 + $hourUTC / 24.0;
}

function julianCentury($JD)
{
    return ($JD - 2451545.0) / 36525.0;
}

function sunMeanLongitude($T)
{
    return fmod(280.46646 + $T * (36000.76983 + 0.0003032 * $T), 360.0);
}

function sunMeanAnomaly($T)
{
    return 357.52911 + $T * (35999.05029 - 0.0001537 * $T);
}

function sunEquationOfCenter($T, $M)
{
    $Mr = deg2rad($M);

    return sin($Mr) * (1.914602 - $T * (0.004817 + 0.000014 * $T)) + sin(2 * $Mr) * (0.019993 - 0.000101 * $T) + sin(3 * $Mr) * 0.000289;
}

function sunTrueLongitude($L0, $C)
{
    return $L0 + $C;
}

function sunApparentLongitude($T, $trueLon)
{
    return $trueLon - 0.00569 - 0.00478 * sin(deg2rad(125.04 - 1934.136 * $T));
}

function meanObliquity($T)
{
    return 23 + (26 + ((21.448 - $T * (46.815 + $T * (0.00059 - $T * 0.001813))) / 60)) / 60;
}

function correctedObliquity($T, $eps0)
{
    return $eps0 + 0.00256 * cos(deg2rad(125.04 - 1934.136 * $T));
}

function solarDeclination($lambda, $eps)
{
    return rad2deg(asin(sin(deg2rad($eps)) * sin(deg2rad($lambda))));
}

function equationOfTime($T, $L0, $e, $M, $eps)
{
    $y = pow(tan(deg2rad($eps) / 2), 2);
    $L0r = deg2rad($L0);
    $Mr = deg2rad($M);

    return 4 * rad2deg($y * sin(2 * $L0r) - 2 * $e * sin($Mr) + 4 * $e * $y * sin($Mr) * cos(2 * $L0r) - 0.5 * $y * $y * sin(4 * $L0r) - 1.25 * $e * $e * sin(2 * $Mr));
}

function sunriseHourAngle($lat, $decl, $alt)
{
    $latr = deg2rad($lat);
    $declr = deg2rad($decl);
    $altr = deg2rad($alt);
    $cos_ha = (sin($altr) - sin($latr) * sin($declr)) / (cos($latr) * cos($declr));
    if ($cos_ha > 1) {
        return 0;
    }
    if ($cos_ha < -1) {
        return 180;
    }

    return rad2deg(acos($cos_ha));
}

/**
 * Calculate sun times - uses high-precision NREL SPA library
 * Backward-compatible wrapper that calls SPA implementation.
 *
 * @param int $y Year
 * @param int $m Month (1-12)
 * @param int $d Day of month
 * @param float $lat Latitude in degrees
 * @param float $lon Longitude in degrees
 * @param float $utc_offset UTC offset in hours
 * @param float $sun_alt Solar altitude angle (default: -0.833 for sunrise/sunset)
 * @return array Solar times and parameters
 */
function calculate_sun_times($y, $m, $d, $lat, $lon, $utc_offset, $sun_alt = -0.833)
{
    return calculate_sun_times_spa($y, $m, $d, $lat, $lon, $utc_offset, $sun_alt);
}

/**
 * Legacy solar calculation implementation (NREL-inspired, ±1-2 minutes)
 * Kept for rollback purposes and backward compatibility testing.
 *
 * @deprecated Use calculate_sun_times() which calls SPA wrapper for ±30 second precision
 */
function calculate_sun_times_legacy($y, $m, $d, $lat, $lon, $utc_offset, $sun_alt = -0.833)
{
    $JD = julianDay($y, $m, $d);
    $T = julianCentury($JD);
    $L0 = sunMeanLongitude($T);
    $M = sunMeanAnomaly($T);
    $C = sunEquationOfCenter($T, $M);
    $trueLon = sunTrueLongitude($L0, $C);
    $lambda = sunApparentLongitude($T, $trueLon);
    $eps0 = meanObliquity($T);
    $eps = correctedObliquity($T, $eps0);
    $decl = solarDeclination($lambda, $eps);
    $e = 0.016708634 - $T * (0.000042037 + 0.0000001267 * $T);
    $eqTime = equationOfTime($T, $L0, $e, $M, $eps);
    $HA = sunriseHourAngle($lat, $decl, $sun_alt);
    $dayLength = (2 * $HA) / 15.0;
    $solarNoon = (720 - 4 * $lon - $eqTime + $utc_offset * 60) / 1440;
    $sunrise = $solarNoon - ($HA * 4) / 1440;
    $sunset = $solarNoon + ($HA * 4) / 1440;
    $HA_civil = sunriseHourAngle($lat, $decl, -6.0);
    $civil_begin = $solarNoon - ($HA_civil * 4) / 1440;
    $civil_end = $solarNoon + ($HA_civil * 4) / 1440;
    $HA_nautical = sunriseHourAngle($lat, $decl, -12.0);
    $nautical_begin = $solarNoon - ($HA_nautical * 4) / 1440;
    $nautical_end = $solarNoon + ($HA_nautical * 4) / 1440;
    $HA_astro = sunriseHourAngle($lat, $decl, -18.0);
    $astro_begin = $solarNoon - ($HA_astro * 4) / 1440;
    $astro_end = $solarNoon + ($HA_astro * 4) / 1440;

    return ['declination_deg' => $decl, 'equation_of_time_min' => $eqTime, 'sunrise_frac' => $sunrise, 'sunset_frac' => $sunset, 'solar_noon_frac' => $solarNoon, 'daylength_h' => $dayLength, 'civil_begin_frac' => $civil_begin, 'civil_end_frac' => $civil_end, 'nautical_begin_frac' => $nautical_begin, 'nautical_end_frac' => $nautical_end, 'astro_begin_frac' => $astro_begin, 'astro_end_frac' => $astro_end];
}

function fraction_to_timestamp($date_y, $date_m, $date_d, $frac)
{
    $midnight = mktime(0, 0, 0, $date_m, $date_d, $date_y);

    return $midnight + round($frac * 86400);
}

function calculate_daylight_percentile($target_daylight_hours, $lat, $lon, $year, $utc_offset)
{
    $daylight_lengths = [];
    $days_in_year = (($year % 4 == 0 && $year % 100 != 0) || $year % 400 == 0) ? 366 : 365;
    for ($day = 1; $day <= $days_in_year; $day++) {
        $date = new DateTime("$year-01-01");
        $date->modify('+' . ($day - 1) . ' days');
        $result = calculate_sun_times((int) $date->format('Y'), (int) $date->format('m'), (int) $date->format('d'), $lat, $lon, $utc_offset);
        $daylight_lengths[] = $result['daylength_h'];
    }
    sort($daylight_lengths);
    $count_below = 0;
    foreach ($daylight_lengths as $length) {
        if ($length < $target_daylight_hours) {
            $count_below++;
        }
    }

    return round(($count_below / count($daylight_lengths)) * 100, 1);
}

function get_location_notes($lat)
{
    global $STRINGS;
    $notes = [];
    if (abs($lat) > 66.5) {
        $notes[] = $lat > 0 ? $STRINGS['location_notes']['arctic'] : $STRINGS['location_notes']['antarctic'];
    }
    if (abs($lat) > 60 && abs($lat) <= 66.5) {
        $notes[] = $STRINGS['location_notes']['high_latitude'];
    }
    if (abs($lat) < 23.5) {
        $notes[] = $STRINGS['location_notes']['tropical'];
    }
    if (abs($lat) < 5) {
        $notes[] = $STRINGS['location_notes']['equatorial'];
    }

    return $notes;
}

function get_solstice_dates($year)
{
    // Use accurate Meeus algorithm from src/meeus-astronomy.php
    // Accuracy: ±1 minute (vast improvement over old ±12 hour error!)
    // Algorithm: Meeus "Astronomical Algorithms" Chapter 27

    $result = calculate_equinox_solstice($year);

    // Return with consistent key naming
    return [
        'march_equinox' => $result['march_equinox'],
        'june_solstice' => $result['june_solstice'],
        'sept_equinox' => $result['september_equinox'],  // Note: different key name
        'dec_solstice' => $result['december_solstice'],  // Note: different key name
    ];
}

function get_special_astronomical_events($year)
{
    global $STRINGS;
    return [
        ['date' => strtotime("$year-03-20"), 'name' => $STRINGS['astronomical_events']['march_equinox']['name'], 'emoji' => $STRINGS['astronomical_events']['march_equinox']['emoji'], 'description' => $STRINGS['astronomical_events']['march_equinox']['description']],
        ['date' => strtotime("$year-06-21"), 'name' => $STRINGS['astronomical_events']['june_solstice']['name'], 'emoji' => $STRINGS['astronomical_events']['june_solstice']['emoji'], 'description' => $STRINGS['astronomical_events']['june_solstice']['description']],
        ['date' => strtotime("$year-09-22"), 'name' => $STRINGS['astronomical_events']['september_equinox']['name'], 'emoji' => $STRINGS['astronomical_events']['september_equinox']['emoji'], 'description' => $STRINGS['astronomical_events']['september_equinox']['description']],
        ['date' => strtotime("$year-12-21"), 'name' => $STRINGS['astronomical_events']['december_solstice']['name'], 'emoji' => $STRINGS['astronomical_events']['december_solstice']['emoji'], 'description' => $STRINGS['astronomical_events']['december_solstice']['description']],
    ];
}

function get_week_summary_data($week_start, $lat, $lon, $year, $utc_offset)
{
    global $STRINGS;
    $week_end = strtotime('+6 days', $week_start);
    $day_lengths = [];
    $current = $week_start;
    while ($current <= $week_end) {
        $date_parts = getdate($current);
        $result = calculate_sun_times($date_parts['year'], $date_parts['mon'], $date_parts['mday'], $lat, $lon, $utc_offset);
        $day_lengths[] = ['timestamp' => $current, 'length' => $result['daylength_h'] * 3600];
        $current = strtotime('+1 day', $current);
    }
    if (empty($day_lengths)) {
        return null;
    }
    $lengths = array_column($day_lengths, 'length');
    $avg_length = array_sum($lengths) / count($lengths);
    $min_length = min($lengths);
    $max_length = max($lengths);
    $total_change = end($lengths) - $lengths[0];
    if ($total_change > 300) {
        $trend = $STRINGS['trends']['increasing'];
        $trend_emoji = $STRINGS['trend_emojis']['increasing'];
    } elseif ($total_change < -300) {
        $trend = $STRINGS['trends']['decreasing'];
        $trend_emoji = $STRINGS['trend_emojis']['decreasing'];
    } else {
        $trend = $STRINGS['trends']['stable'];
        $trend_emoji = $STRINGS['trend_emojis']['stable'];
    }
    $shortest_idx = array_search($min_length, $lengths);
    $longest_idx = array_search($max_length, $lengths);
    $moon_info = get_moon_phase_info($week_start);

    return ['avg_length' => $avg_length, 'min_length' => $min_length, 'max_length' => $max_length, 'total_change' => $total_change, 'trend' => $trend, 'trend_emoji' => $trend_emoji, 'shortest_day' => $day_lengths[$shortest_idx]['timestamp'], 'longest_day' => $day_lengths[$longest_idx]['timestamp'], 'moon_phase' => $moon_info['phase_name']];
}

function get_moon_phase_info($timestamp)
{
    // Use accurate Meeus algorithm from src/meeus-astronomy.php
    // Accuracy: ±2 minutes (vast improvement over old implementation!)
    // Algorithm: Meeus "Astronomical Algorithms" Chapter 49

    return get_accurate_moon_phase($timestamp);
}

function build_dawn_supplemental($sunrise, $sunset, $solar_noon, $civil_begin, $civil_end, $nautical_begin, $nautical_end, $astro_begin, $astro_end, $time_format, $enabled, $daylight_seconds, $daylight_pct, $daylight_percentile, $day_length_comparison, $winter_comparison, $summer_comparison, $solar_noon_time, $winter_solstice_info, $summer_solstice_info, $diff_from_winter, $diff_from_summer, $current_event, $strings)
{
    if (count(array_filter($enabled)) >= 4) {
        return '';
    }
    $info = "\n\n{$strings['headers']['daytime_schedule']}\n\n";

    if (!$enabled['astro'] && isset($astro_begin) && isset($nautical_begin)) {
        $info .= '🌌 Astronomical Dawn: ' . date($time_format, $astro_begin) . ' - ' . date($time_format, $nautical_begin) . ' (' . format_duration($nautical_begin - $astro_begin) . ")\n";
        $info .= "  {$strings['supplemental']['astronomical_dawn']}\n\n";
    }

    if (!$enabled['nautical'] && isset($nautical_begin) && isset($civil_begin)) {
        $info .= '⚓ Nautical Dawn: ' . date($time_format, $nautical_begin) . ' - ' . date($time_format, $civil_begin) . ' (' . format_duration($civil_begin - $nautical_begin) . ")\n";
        $info .= "  {$strings['supplemental']['nautical_dawn']}\n\n";
    }

    if (!$enabled['civil'] && isset($civil_begin) && isset($sunrise)) {
        $info .= '🌅 Civil Dawn: ' . date($time_format, $civil_begin) . ' - ' . date($time_format, $sunrise) . ' (' . format_duration($sunrise - $civil_begin) . ")\n";
        $info .= "  {$strings['supplemental']['civil_dawn']}\n\n";
    }

    if (!$enabled['daylight']) {
        $info .= "\n{$strings['headers']['daylight']}\n\n";
        $info .= "{$strings['labels']['time']}: " . date($time_format, $sunrise) . ' - ' . date($time_format, $sunset) . ' (' . format_duration($daylight_seconds) . ", {$daylight_pct}%)\n";
        $info .= "{$strings['supplemental']['daylight']}\n\n";
        $info .= "{$strings['labels']['solar_noon']}: {$solar_noon_time}\n";
        $info .= "{$strings['labels']['percentile']}: " . sprintf($strings['percentile_explanation']['daylight'], $daylight_percentile, $daylight_percentile) . "\n\n";
        if ($day_length_comparison) {
            $info .= "{$strings['labels']['vs_yesterday']}: {$day_length_comparison}\n";
        }
        $winter_sign = ($diff_from_winter >= 0) ? '+' : '-';
        $summer_sign = ($diff_from_summer >= 0) ? '+' : '-';
        $info .= "{$strings['labels']['vs_winter_solstice']} ({$winter_solstice_info}): {$winter_sign}{$winter_comparison}\n";
        $info .= "{$strings['labels']['vs_summer_solstice']} ({$summer_solstice_info}): {$summer_sign}{$summer_comparison}\n";
    }

    return $info;
}

function build_dusk_supplemental($sunrise, $sunset, $civil_begin, $civil_end, $nautical_begin, $nautical_end, $astro_begin, $astro_end, $next_astro_begin, $time_format, $enabled, $night_seconds, $night_pct, $night_percentile, $night_length_comparison, $moon_info, $current_event, $strings)
{
    if (count(array_filter($enabled)) >= 4) {
        return '';
    }
    $info = "\n\n{$strings['headers']['nighttime_schedule']}\n\n";

    if (!$enabled['civil'] && isset($sunset) && isset($civil_end)) {
        $info .= '🌇 Civil Dusk: ' . date($time_format, $sunset) . ' - ' . date($time_format, $civil_end) . ' (' . format_duration($civil_end - $sunset) . ")\n";
        $info .= "  {$strings['supplemental']['civil_dusk']}\n\n";
    }

    if (!$enabled['nautical'] && isset($civil_end) && isset($nautical_end)) {
        $info .= '⚓ Nautical Dusk: ' . date($time_format, $civil_end) . ' - ' . date($time_format, $nautical_end) . ' (' . format_duration($nautical_end - $civil_end) . ")\n";
        $info .= "  {$strings['supplemental']['nautical_dusk']}\n\n";
    }

    if (!$enabled['astro'] && isset($nautical_end) && isset($astro_end)) {
        $info .= '🌌 Astronomical Dusk: ' . date($time_format, $nautical_end) . ' - ' . date($time_format, $astro_end) . ' (' . format_duration($astro_end - $nautical_end) . ")\n";
        $info .= "  {$strings['supplemental']['astronomical_dusk']}\n\n";
    }

    if (!$enabled['daylight'] && isset($astro_end) && isset($next_astro_begin)) {
        $solar_midnight = $astro_end + (($next_astro_begin - $astro_end) / 2);
        $info .= "\n{$strings['headers']['night']}\n\n";
        $info .= "{$strings['labels']['time']}: " . date($time_format, $astro_end) . ' - ' . date($time_format, $next_astro_begin) . ' (' . format_duration($night_seconds) . ", {$night_pct}%)\n";
        $info .= "{$strings['supplemental']['night']}\n\n";
        $info .= "{$strings['labels']['solar_midnight']}: " . date($time_format, $solar_midnight) . "\n";
        $info .= "{$strings['labels']['percentile']}: " . sprintf($strings['percentile_explanation']['night'], $night_percentile, $night_percentile) . "\n\n";
        if ($night_length_comparison) {
            $info .= "{$strings['labels']['vs_yesterday']}: {$night_length_comparison}\n\n";
        }
        $info .= "{$strings['headers']['moon_phase']}\n\n";
        $info .= "{$strings['labels']['current']}:  {$moon_info['phase_name']}\n";
        $info .= "          " . sprintf($strings['comparisons']['lit'], $moon_info['illumination']) . "\n\n";
        $info .= "{$strings['labels']['previous']}: {$moon_info['prev_phase']['name']}\n";
        $info .= "          {$moon_info['prev_phase']['date']}\n\n";
        $info .= "{$strings['labels']['next']}:     {$moon_info['next_phase']['name']}\n";
        $info .= "          {$moon_info['next_phase']['date']}\n";
    }

    return $info;
}

if (isset($_GET['geocode']) && isset($_GET['address'])) {
    header('Content-Type: application/json');
    $address = sanitize_text($_GET['address'], 200);
    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query(['q' => $address, 'format' => 'json', 'limit' => 1, 'addressdetails' => 1]);
    $opts = ['http' => ['header' => 'User-Agent: Sun-Twilight-Calendar/7.3']];
    $context = stream_context_create($opts);
    $response = @file_get_contents($url, false, $context);
    if ($response) {
        $data = json_decode($response, true);
        echo json_encode(!empty($data) ? ['success' => true, 'lat' => $data[0]['lat'], 'lon' => $data[0]['lon'], 'display_name' => $data[0]['display_name']] : ['success' => false, 'error' => 'Location not found']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Geocoding unavailable']);
    }
    exit;
}

if (isset($_GET['reverse']) && isset($_GET['lat']) && isset($_GET['lon'])) {
    header('Content-Type: application/json');
    $lat = sanitize_float($_GET['lat'], 0, -90, 90);
    $lon = sanitize_float($_GET['lon'], 0, -180, 180);
    $url = 'https://nominatim.openstreetmap.org/reverse?' . http_build_query(['lat' => $lat, 'lon' => $lon, 'format' => 'json', 'zoom' => 10]);
    $opts = ['http' => ['header' => 'User-Agent: Sun-Twilight-Calendar/7.3']];
    $context = stream_context_create($opts);
    $response = @file_get_contents($url, false, $context);
    if ($response) {
        $data = json_decode($response, true);
        if (!empty($data)) {
            $address = $data['address'];
            $name_parts = [];
            if (!empty($address['city'])) {
                $name_parts[] = $address['city'];
            } elseif (!empty($address['town'])) {
                $name_parts[] = $address['town'];
            } elseif (!empty($address['village'])) {
                $name_parts[] = $address['village'];
            } elseif (!empty($address['municipality'])) {
                $name_parts[] = $address['municipality'];
            }
            if (!empty($address['state'])) {
                $name_parts[] = $address['state'];
            } elseif (!empty($address['province'])) {
                $name_parts[] = $address['province'];
            }
            echo json_encode(['success' => true, 'name' => !empty($name_parts) ? implode(', ', $name_parts) : $data['display_name']]);
        } else {
            echo json_encode(['success' => false]);
        }
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

if (isset($_GET['feed']) && isset($_GET['token'])) {
    require_once __DIR__ . '/src/calendar-generator.php';
    exit;
}

if (isset($_POST['generate_url']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    if (!verify_token($password)) {
        $error = 'Invalid password';
    } else {
        $params = ['feed' => '1', 'token' => AUTH_TOKEN, 'lat' => $_POST['lat'] ?? 41.9028, 'lon' => $_POST['lon'] ?? 12.4964, 'zone' => $_POST['zone'] ?? 'Europe/Rome', 'location' => $_POST['location'] ?? '', 'rise_off' => $_POST['rise_off'] ?? 0, 'set_off' => $_POST['set_off'] ?? 0, 'desc' => $_POST['description'] ?? ''];
        if (isset($_POST['civil'])) {
            $params['civil'] = '1';
        }
        if (isset($_POST['nautical'])) {
            $params['nautical'] = '1';
        }
        if (isset($_POST['astro'])) {
            $params['astro'] = '1';
        }
        if (isset($_POST['daynight'])) {
            $params['daynight'] = '1';
        }
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $script = $_SERVER['SCRIPT_NAME'];
        $subscription_url = $protocol . '://' . $host . $script . '?' . http_build_query($params);
        $webcal_url = str_replace(['https://', 'http://'], 'webcal://', $subscription_url);
    }
}

putenv('TZ=Europe/Rome');
date_default_timezone_set('Europe/Rome');
$default_lat = 41.9028;
$default_lon = 12.4964;

require_once __DIR__ . '/assets/index.html.php';
