<?php
/**
 * Sunrise/Sunset iCal Calendar Generator with Enhanced Twilight Support
 * Generates dynamic iCalendar feeds with detailed astronomical information
 *
 * @version 7.0 - Week summaries, special events, UV index, location notes
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

function format_duration_short($seconds) {
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    if ($hours > 0) {
        return sprintf("%dh %dm", $hours, $minutes);
    } else if ($minutes > 0) {
        return sprintf("%dm %ds", $minutes, $secs);
    } else {
        return sprintf("%ds", $secs);
    }
}

function format_day_length_comparison($diff_seconds, $type = 'day') {
    $abs_diff = abs($diff_seconds);
    $minutes = floor($abs_diff / 60);
    $seconds = $abs_diff % 60;
    
    $period = ($type === 'day') ? 'daylight' : 'night';
    
    if ($diff_seconds > 0) {
        return sprintf("+%dm %02ds longer %s", $minutes, $seconds, $period);
    } elseif ($diff_seconds < 0) {
        return sprintf("-%dm %02ds shorter %s", $minutes, $seconds, $period);
    } else {
        return "same length as yesterday";
    }
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

function calculate_uv_index($lat, $timestamp) {
    // Simplified UV index calculation based on latitude and day of year
    // This is an approximation - real UV index requires ozone data, cloud cover, etc.
    
    $day_of_year = date('z', $timestamp);
    $lat_rad = deg2rad($lat);
    
    // Solar declination (angle of sun relative to equator)
    $declination = 23.45 * sin(deg2rad(360/365 * ($day_of_year + 284)));
    $declination_rad = deg2rad($declination);
    
    // Solar noon angle (how high the sun gets at noon)
    $solar_noon_angle = 90 - abs($lat - $declination);
    
    // Base UV calculation (0-11+ scale)
    // Higher sun angle = more UV
    if ($solar_noon_angle < 0) {
        $uv = 0; // Polar night
    } else {
        $uv = ($solar_noon_angle / 90) * 11;
        
        // Seasonal adjustment
        // UV is higher in summer, lower in winter
        $season_factor = 1 + 0.3 * sin(deg2rad(360/365 * ($day_of_year - 80)));
        $uv *= $season_factor;
        
        // Clamp between 0 and 11
        $uv = max(0, min(11, $uv));
    }
    
    return round($uv, 1);
}

function get_uv_category($uv_index) {
    if ($uv_index < 3) return "Low";
    if ($uv_index < 6) return "Moderate";
    if ($uv_index < 8) return "High";
    if ($uv_index < 11) return "Very High";
    return "Extreme";
}

function get_location_notes($lat) {
    $notes = [];
    
    // Polar regions
    if (abs($lat) > 66.5) {
        if ($lat > 0) {
            $notes[] = "⚠️ ARCTIC LOCATION: You experience midnight sun in summer and polar night in winter.";
        } else {
            $notes[] = "⚠️ ANTARCTIC LOCATION: You experience midnight sun in summer (Dec-Feb) and polar night in winter (Jun-Aug).";
        }
    }
    
    // Near polar circles
    if (abs($lat) > 60 && abs($lat) <= 66.5) {
        $notes[] = "ℹ️ HIGH LATITUDE: Extreme day length variations throughout the year. Summer has very long days, winter has very short days.";
    }
    
    // Tropical regions
    if (abs($lat) < 23.5) {
        $notes[] = "ℹ️ TROPICAL LOCATION: Day length varies minimally throughout the year (within 1-2 hours). Sun passes directly overhead twice per year.";
    }
    
    // Equatorial
    if (abs($lat) < 5) {
        $notes[] = "ℹ️ EQUATORIAL LOCATION: Nearly equal day and night year-round (~12 hours each). Minimal seasonal variation.";
    }
    
    return $notes;
}

function get_special_astronomical_events($year) {
    // Calculate key astronomical events for the year
    // These are approximations - exact times would require more complex calculations
    
    $events = [];
    
    // Equinoxes and Solstices (approximate dates)
    $events[] = [
        'date' => strtotime("$year-03-20"),
        'name' => 'March Equinox',
        'emoji' => '⚖️',
        'description' => 'Day and night are approximately equal length worldwide. Spring begins in Northern Hemisphere.'
    ];
    
    $events[] = [
        'date' => strtotime("$year-06-21"),
        'name' => 'June Solstice',
        'emoji' => '☀️',
        'description' => 'Longest day in Northern Hemisphere, shortest in Southern. Summer begins in Northern Hemisphere.'
    ];
    
    $events[] = [
        'date' => strtotime("$year-09-22"),
        'name' => 'September Equinox',
        'emoji' => '⚖️',
        'description' => 'Day and night are approximately equal length worldwide. Autumn begins in Northern Hemisphere.'
    ];
    
    $events[] = [
        'date' => strtotime("$year-12-21"),
        'name' => 'December Solstice',
        'emoji' => '🌙',
        'description' => 'Shortest day in Northern Hemisphere, longest in Southern. Winter begins in Northern Hemisphere.'
    ];
    
    return $events;
}

function get_week_summary_data($week_start, $lat, $lon, $year) {
    $week_end = strtotime('+6 days', $week_start);
    $day_lengths = [];
    $current = $week_start;
    
    while ($current <= $week_end) {
        $sun_info = date_sun_info($current, $lat, $lon);
        if (isset($sun_info['sunrise']) && isset($sun_info['sunset'])) {
            $day_lengths[] = [
                'timestamp' => $current,
                'length' => $sun_info['sunset'] - $sun_info['sunrise']
            ];
        }
        $current = strtotime('+1 day', $current);
    }
    
    if (empty($day_lengths)) {
        return null;
    }
    
    // Calculate statistics
    $lengths = array_column($day_lengths, 'length');
    $avg_length = array_sum($lengths) / count($lengths);
    $min_length = min($lengths);
    $max_length = max($lengths);
    $first_length = $lengths[0];
    $last_length = end($lengths);
    $total_change = $last_length - $first_length;
    
    // Determine trend
    if ($total_change > 300) { // > 5 minutes
        $trend = "Increasing";
        $trend_emoji = "📈";
    } elseif ($total_change < -300) {
        $trend = "Decreasing";
        $trend_emoji = "📉";
    } else {
        $trend = "Stable";
        $trend_emoji = "➡️";
    }
    
    // Find shortest and longest days
    $shortest_idx = array_search($min_length, $lengths);
    $longest_idx = array_search($max_length, $lengths);
    
    // Get moon phase at week start
    $moon_info = get_moon_phase_info($week_start);
    
    return [
        'avg_length' => $avg_length,
        'min_length' => $min_length,
        'max_length' => $max_length,
        'total_change' => $total_change,
        'trend' => $trend,
        'trend_emoji' => $trend_emoji,
        'shortest_day' => $day_lengths[$shortest_idx]['timestamp'],
        'longest_day' => $day_lengths[$longest_idx]['timestamp'],
        'moon_phase' => $moon_info['phase_name']
    ];
}

function get_moon_phase_info($timestamp) {
    // Calculate moon phase using astronomical formula
    $year = date('Y', $timestamp);
    $month = date('n', $timestamp);
    $day = date('j', $timestamp);
    
    // Convert to Julian Day
    if ($month <= 2) {
        $year--;
        $month += 12;
    }
    
    $a = floor($year / 100);
    $b = 2 - $a + floor($a / 4);
    $jd = floor(365.25 * ($year + 4716)) + floor(30.6001 * ($month + 1)) + $day + $b - 1524.5;
    
    // Days since known new moon (January 6, 2000)
    $days_since_new = $jd - 2451549.5;
    
    // New moon cycle is approximately 29.53 days
    $new_moons = $days_since_new / 29.53;
    $phase = ($new_moons - floor($new_moons));
    
    // Calculate illumination percentage
    $illumination = round((1 - cos($phase * 2 * M_PI)) * 50, 1);
    
    // Determine phase name
    if ($phase < 0.0625 || $phase >= 0.9375) {
        $phase_name = 'New Moon';
    } elseif ($phase < 0.1875) {
        $phase_name = 'Waxing Crescent';
    } elseif ($phase < 0.3125) {
        $phase_name = 'First Quarter';
    } elseif ($phase < 0.4375) {
        $phase_name = 'Waxing Gibbous';
    } elseif ($phase < 0.5625) {
        $phase_name = 'Full Moon';
    } elseif ($phase < 0.6875) {
        $phase_name = 'Waning Gibbous';
    } elseif ($phase < 0.8125) {
        $phase_name = 'Last Quarter';
    } else {
        $phase_name = 'Waning Crescent';
    }
    
    // Find previous and next major phases
    $phases = [
        ['name' => 'New Moon', 'phase' => 0],
        ['name' => 'First Quarter', 'phase' => 0.25],
        ['name' => 'Full Moon', 'phase' => 0.5],
        ['name' => 'Last Quarter', 'phase' => 0.75],
        ['name' => 'New Moon', 'phase' => 1.0]
    ];
    
    $prev_phase = null;
    $next_phase = null;
    
    foreach ($phases as $i => $p) {
        if ($phase < $p['phase']) {
            $next_phase = $p;
            $prev_phase = $phases[$i - 1];
            break;
        }
    }
    
    if (!$prev_phase) {
        $prev_phase = $phases[3]; // Last Quarter
        $next_phase = $phases[0]; // New Moon
    }
    
    // Calculate dates
    $days_to_prev = ($phase - $prev_phase['phase']) * 29.53;
    if ($days_to_prev < 0) $days_to_prev += 29.53;
    $prev_date = date('j M Y, H:i', $timestamp - ($days_to_prev * 86400));
    
    $days_to_next = ($next_phase['phase'] - $phase) * 29.53;
    if ($days_to_next < 0) $days_to_next += 29.53;
    $next_date = date('j M Y, H:i', $timestamp + ($days_to_next * 86400));
    
    return [
        'phase_name' => $phase_name,
        'illumination' => $illumination,
        'prev_phase' => [
            'name' => $prev_phase['name'],
            'date' => $prev_date
        ],
        'next_phase' => [
            'name' => $next_phase['name'],
            'date' => $next_date
        ]
    ];
}

function build_dawn_supplemental($sun_info, $time_format, $enabled, $daylight_seconds, $daylight_pct, $daylight_percentile, $day_length_comparison, $moon_info, $current_event) {
    $total_selected = count(array_filter($enabled));
    
    // Only add supplemental if not all 4 options are selected
    if ($total_selected >= 4) {
        return "";
    }
    
    $info = "\\n\\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\\n";
    $info .= "☀️ COMPLETE MORNING & DAYTIME SCHEDULE\\n";
    $info .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\\n\\n";
    
    // Astronomical Dawn
    if (!$enabled['astro'] && isset($sun_info['astronomical_twilight_begin']) && isset($sun_info['nautical_twilight_begin'])) {
        $start = date($time_format, $sun_info['astronomical_twilight_begin']);
        $end = date($time_format, $sun_info['nautical_twilight_begin']);
        $duration = format_duration($sun_info['nautical_twilight_begin'] - $sun_info['astronomical_twilight_begin']);
        
        $bold_start = ($current_event === 'astro') ? "**{$start}**" : $start;
        
        $info .= "🌌 ASTRONOMICAL DAWN: {$bold_start} - {$end} ({$duration})\\n";
        $info .= "   ▸ Before: Complete darkness; optimal astronomy conditions.\\n";
        $info .= "   ▸ During: Very faint light appears; time to pack up telescopes.\\n";
        $info .= "   ▸ After: Stars fade and sky lightens; too bright for deep-sky astronomy.\\n\\n";
    }
    
    // Nautical Dawn
    if (!$enabled['nautical'] && isset($sun_info['nautical_twilight_begin']) && isset($sun_info['civil_twilight_begin'])) {
        $start = date($time_format, $sun_info['nautical_twilight_begin']);
        $end = date($time_format, $sun_info['civil_twilight_begin']);
        $duration = format_duration($sun_info['civil_twilight_begin'] - $sun_info['nautical_twilight_begin']);
        
        $bold_start = ($current_event === 'nautical') ? "**{$start}**" : $start;
        
        $info .= "⚓ NAUTICAL DAWN: {$bold_start} - {$end} ({$duration})\\n";
        $info .= "   ▸ Before: Complete darkness with horizon invisible.\\n";
        $info .= "   ▸ During: Sky brightens but too dark for most activities without light.\\n";
        $info .= "   ▸ After: Horizon becomes visible at sea; enough light for outdoor activities.\\n\\n";
    }
    
    // Civil Dawn
    if (!$enabled['civil'] && isset($sun_info['civil_twilight_begin']) && isset($sun_info['sunrise'])) {
        $start = date($time_format, $sun_info['civil_twilight_begin']);
        $end = date($time_format, $sun_info['sunrise']);
        $duration = format_duration($sun_info['sunrise'] - $sun_info['civil_twilight_begin']);
        
        $bold_start = ($current_event === 'civil') ? "**{$start}**" : $start;
        
        $info .= "🌅 CIVIL DAWN (First Light): {$bold_start} - {$end} ({$duration})\\n";
        $info .= "   ▸ Before: Still dark with stars visible and artificial light needed.\\n";
        $info .= "   ▸ During: Enough light for outdoor activities; blue hour for photography.\\n";
        $info .= "   ▸ After: Sun's upper edge breaks horizon; full daylight begins.\\n\\n";
    }
    
    // Daylight info (if Day & Night not selected)
    if (!$enabled['daylight']) {
        $sunrise_time = date($time_format, $sun_info['sunrise']);
        $sunset_time = date($time_format, $sun_info['sunset']);
        $solar_noon_time = date($time_format, $sun_info['transit']);
        $daylight_duration = format_duration($daylight_seconds);
        
        $info .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\\n";
        $info .= "☀️ DAYLIGHT STATISTICS\\n";
        $info .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\\n";
        $info .= "Duration: {$daylight_duration} ({$daylight_pct}% of day)\\n";
        $info .= "Period: {$sunrise_time} - {$sunset_time}\\n";
        $info .= "Percentile: ⊕ {$daylight_percentile}th percentile\\n";
        $info .= "            ({$daylight_percentile}% of days have less daylight)\\n";
        if ($day_length_comparison) {
            $info .= "vs Yesterday: {$day_length_comparison}\\n";
        }
        $info .= "Solar Noon: {$solar_noon_time} (Sun at highest point)\\n\\n";
    }
    
    return $info;
}

function build_dusk_supplemental($sun_info, $next_sun_info, $time_format, $enabled, $night_seconds, $night_pct, $night_percentile, $night_length_comparison, $moon_info, $current_event) {
    $total_selected = count(array_filter($enabled));
    
    // Only add supplemental if not all 4 options are selected
    if ($total_selected >= 4) {
        return "";
    }
    
    $info = "\\n\\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\\n";
    $info .= "🌙 COMPLETE EVENING & NIGHTTIME SCHEDULE\\n";
    $info .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\\n\\n";
    
    // Civil Dusk
    if (!$enabled['civil'] && isset($sun_info['sunset']) && isset($sun_info['civil_twilight_end'])) {
        $start = date($time_format, $sun_info['sunset']);
        $end = date($time_format, $sun_info['civil_twilight_end']);
        $duration = format_duration($sun_info['civil_twilight_end'] - $sun_info['sunset']);
        
        $bold_end = ($current_event === 'civil') ? "**{$end}**" : $end;
        
        $info .= "🌇 CIVIL DUSK (Last Light): {$start} - {$bold_end} ({$duration})\\n";
        $info .= "   ▸ Before: Sun above horizon with full daylight.\\n";
        $info .= "   ▸ During: Sun dips below horizon; golden hour for photography.\\n";
        $info .= "   ▸ After: Artificial light becomes necessary; bright stars visible.\\n\\n";
    }
    
    // Nautical Dusk
    if (!$enabled['nautical'] && isset($sun_info['civil_twilight_end']) && isset($sun_info['nautical_twilight_end'])) {
        $start = date($time_format, $sun_info['civil_twilight_end']);
        $end = date($time_format, $sun_info['nautical_twilight_end']);
        $duration = format_duration($sun_info['nautical_twilight_end'] - $sun_info['civil_twilight_end']);
        
        $bold_end = ($current_event === 'nautical') ? "**{$end}**" : $end;
        
        $info .= "⚓ NAUTICAL DUSK: {$start} - {$bold_end} ({$duration})\\n";
        $info .= "   ▸ Before: Still enough natural light for outdoor activities.\\n";
        $info .= "   ▸ During: Sky darkens considerably; more stars become visible.\\n";
        $info .= "   ▸ After: Horizon fades from view; artificial light necessary for activities.\\n\\n";
    }
    
    // Astronomical Dusk
    if (!$enabled['astro'] && isset($sun_info['nautical_twilight_end']) && isset($sun_info['astronomical_twilight_end'])) {
        $start = date($time_format, $sun_info['nautical_twilight_end']);
        $end = date($time_format, $sun_info['astronomical_twilight_end']);
        $duration = format_duration($sun_info['astronomical_twilight_end'] - $sun_info['nautical_twilight_end']);
        
        $bold_end = ($current_event === 'astro') ? "**{$end}**" : $end;
        
        $info .= "🌌 ASTRONOMICAL DUSK: {$start} - {$bold_end} ({$duration})\\n";
        $info .= "   ▸ Before: Very dark with most stars visible and faint sky glow.\\n";
        $info .= "   ▸ During: Last traces of sunlight fade; Milky Way becomes visible.\\n";
        $info .= "   ▸ After: Complete darkness; optimal astronomy conditions.\\n\\n";
    }
    
    // Night info (if Day & Night not selected)
    if (!$enabled['daylight'] && isset($sun_info['astronomical_twilight_end']) && isset($next_sun_info['astronomical_twilight_begin'])) {
        $night_start = date($time_format, $sun_info['astronomical_twilight_end']);
        $night_end = date($time_format, $next_sun_info['astronomical_twilight_begin']);
        $night_duration = format_duration($night_seconds);
        
        $solar_midnight = $sun_info['astronomical_twilight_end'] + (($next_sun_info['astronomical_twilight_begin'] - $sun_info['astronomical_twilight_end']) / 2);
        $solar_midnight_time = date($time_format, $solar_midnight);
        
        $info .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\\n";
        $info .= "🌙 NIGHT STATISTICS\\n";
        $info .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\\n";
        $info .= "Duration: {$night_duration} ({$night_pct}% of day)\\n";
        $info .= "Period: {$night_start} - {$night_end}\\n";
        $info .= "Percentile: ⊕ {$night_percentile}th percentile\\n";
        $info .= "            ({$night_percentile}% of nights are longer)\\n";
        if ($night_length_comparison) {
            $info .= "vs Yesterday: {$night_length_comparison}\\n";
        }
        $info .= "Solar Midnight: {$solar_midnight_time} (Darkest point)\\n\\n";
        
        $info .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\\n";
        $info .= "🌙 MOON PHASE\\n";
        $info .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\\n";
        $info .= "Current: {$moon_info['phase_name']} ({$moon_info['illumination']}% illuminated)\\n";
        $info .= "Previous: {$moon_info['prev_phase']['name']} - {$moon_info['prev_phase']['date']}\\n";
        $info .= "Next: {$moon_info['next_phase']['name']} - {$moon_info['next_phase']['date']}\\n\\n";
    }
    
    return $info;
}

// Handle geocoding requests
if (isset($_GET['geocode']) && isset($_GET['address'])) {
    header('Content-Type: application/json');
    
    $address = sanitize_text($_GET['address'], 200);
    
    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q' => $address,
        'format' => 'json',
        'limit' => 1,
        'addressdetails' => 1
    ]);
    
    $opts = [
        'http' => [
            'header' => 'User-Agent: Sun-Twilight-Calendar/7.0'
        ]
    ];
    $context = stream_context_create($opts);
    $response = @file_get_contents($url, false, $context);
    
    if ($response) {
        $data = json_decode($response, true);
        if (!empty($data)) {
            echo json_encode([
                'success' => true,
                'lat' => $data[0]['lat'],
                'lon' => $data[0]['lon'],
                'display_name' => $data[0]['display_name']
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Location not found']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Geocoding service unavailable']);
    }
    exit;
}

// Handle reverse geocoding requests
if (isset($_GET['reverse']) && isset($_GET['lat']) && isset($_GET['lon'])) {
    header('Content-Type: application/json');
    
    $lat = sanitize_float($_GET['lat'], 0, -90, 90);
    $lon = sanitize_float($_GET['lon'], 0, -180, 180);
    
    $url = 'https://nominatim.openstreetmap.org/reverse?' . http_build_query([
        'lat' => $lat,
        'lon' => $lon,
        'format' => 'json',
        'zoom' => 10
    ]);
    
    $opts = [
        'http' => [
            'header' => 'User-Agent: Sun-Twilight-Calendar/7.0'
        ]
    ];
    $context = stream_context_create($opts);
    $response = @file_get_contents($url, false, $context);
    
    if ($response) {
        $data = json_decode($response, true);
        if (!empty($data)) {
            $address = $data['address'];
            $name_parts = [];
            
            if (!empty($address['city'])) $name_parts[] = $address['city'];
            elseif (!empty($address['town'])) $name_parts[] = $address['town'];
            elseif (!empty($address['village'])) $name_parts[] = $address['village'];
            elseif (!empty($address['municipality'])) $name_parts[] = $address['municipality'];
            
            if (!empty($address['state'])) $name_parts[] = $address['state'];
            elseif (!empty($address['province'])) $name_parts[] = $address['province'];
            
            $location_name = !empty($name_parts) ? implode(', ', $name_parts) : $data['display_name'];
            
            echo json_encode([
                'success' => true,
                'name' => $location_name
            ]);
        } else {
            echo json_encode(['success' => false]);
        }
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

// Handle calendar feed requests
if (isset($_GET['feed']) && isset($_GET['token'])) {
    require_once __DIR__ . '/calendar-generator.php';
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
            'location' => $_POST['location'] ?? '',
            'rise_off' => $_POST['rise_off'] ?? 0,
            'set_off' => $_POST['set_off'] ?? 0,
            'twelve' => isset($_POST['twelve']) ? '1' : '0',
            'desc' => $_POST['description'] ?? '',
        ];

        if (isset($_POST['civil'])) $params['civil'] = '1';
        if (isset($_POST['nautical'])) $params['nautical'] = '1';
        if (isset($_POST['astro'])) $params['astro'] = '1';
        if (isset($_POST['daynight'])) $params['daynight'] = '1';

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

require_once __DIR__ . '/index.html.php';
?>