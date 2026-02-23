<?php

// phpcs:disable PSR1.Files.SideEffects

/**
 * Sunrise/Sunset iCal Calendar Generator
 * Version 10.0 - Fully modular architecture.
 */

declare(strict_types=1);

$configFile = __DIR__ . '/config/config.php';
if (!file_exists($configFile)) {
    die('Error: config/config.php not found');
}
require_once $configFile;

// Load Composer autoloader
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Load core modules
require_once __DIR__ . '/src/functions.php';
require_once __DIR__ . '/src/astronomy.php';
require_once __DIR__ . '/src/geocoding.php';
require_once __DIR__ . '/src/helpers.php';

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

// Health check endpoint
if (isset($_GET['health'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ok',
        'version' => '10.0',
        'php' => PHP_VERSION,
        'timestamp' => time(),
    ]);
    exit;
}

// Handle geocode API request
if (isset($_GET['geocode']) && isset($_GET['address'])) {
    handle_geocode_request(sanitize_text($_GET['address'], 200));
    exit;
}

// Handle reverse geocode API request
if (isset($_GET['reverse']) && isset($_GET['lat']) && isset($_GET['lon'])) {
    $lat = sanitize_float($_GET['lat'], 0.0, -90.0, 90.0);
    $lon = sanitize_float($_GET['lon'], 0.0, -180.0, 180.0);
    handle_reverse_geocode_request($lat, $lon);
    exit;
}

// Calendar feed generation
if (isset($_GET['feed']) && isset($_GET['token'])) {
    // Lazy-load strings only when generating calendar
    init_strings();
    require_once __DIR__ . '/src/calendar-generator.php';
    exit;
}

// URL generation form submission
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
            'zone' => $_POST['zone'] ?? 'Europe/Rome',
            'location' => $_POST['location'] ?? '',
            'rise_off' => $_POST['rise_off'] ?? 0,
            'set_off' => $_POST['set_off'] ?? 0,
            'desc' => $_POST['description'] ?? '',
        ];
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

// Display web interface
putenv('TZ=Europe/Rome');
date_default_timezone_set('Europe/Rome');
$default_lat = 41.9028;
$default_lon = 12.4964;

// Initialize strings for web interface display
init_strings();

require_once __DIR__ . '/assets/index.html.php';
