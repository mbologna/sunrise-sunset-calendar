<?php

// phpcs:disable PSR1.Files.SideEffects

/**
 * Sunrise/Sunset iCal Calendar Generator: HTTP entry point.
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

// ============================================================================
// CONFIGURATION
// Environment variables take precedence (Cloud Run / container deployments).
// config/config.php is loaded afterwards as an override for local/self-hosted use.
// ============================================================================

$envSettings = [
    'AUTH_TOKEN'           => fn($v) => $v,
    'CALENDAR_WINDOW_DAYS' => fn($v) => (int) $v,
    'CALENDAR_PAST_DAYS'   => fn($v) => (int) $v,
    'UPDATE_INTERVAL'      => fn($v) => (int) $v,
];
foreach ($envSettings as $name => $cast) {
    $env = getenv($name);
    if ($env !== false && !defined($name)) {
        define($name, $cast($env));
    }
}

$configFile = __DIR__ . '/config/config.php';
if (file_exists($configFile)) {
    require_once $configFile;
}

if (!defined('AUTH_TOKEN') || AUTH_TOKEN === '' || AUTH_TOKEN === 'CHANGE_ME_TO_A_RANDOM_STRING') {
    http_response_code(500);
    exit('Error: Please set AUTH_TOKEN via environment variable or config/config.php');
}

defined('CALENDAR_WINDOW_DAYS') || define('CALENDAR_WINDOW_DAYS', 400);
defined('CALENDAR_PAST_DAYS') || define('CALENDAR_PAST_DAYS', 30);
defined('UPDATE_INTERVAL') || define('UPDATE_INTERVAL', 86400);

/**
 * Read a string request parameter; anything else (missing, array) yields the default.
 *
 * @param array $source $_GET or $_POST
 */
function param(array $source, string $key, string $default = ''): string
{
    return isset($source[$key]) && is_string($source[$key]) ? $source[$key] : $default;
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

// Health check endpoint
if (isset($_GET['health'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ok',
        'php' => PHP_VERSION,
        'timestamp' => time(),
    ]);
    exit;
}

// Calendar feed
if (isset($_GET['feed'])) {
    if (!verify_token(param($_GET, 'token'))) {
        http_response_code(403);
        exit('Invalid authentication token');
    }

    $flag = fn(string $key) => param($_GET, $key) === '1';
    $calendar = generate_calendar([
        'lat' => sanitize_float(param($_GET, 'lat'), DEFAULT_LAT, -90, 90),
        'lon' => sanitize_float(param($_GET, 'lon'), DEFAULT_LON, -180, 180),
        'timezone' => sanitize_timezone(param($_GET, 'zone', DEFAULT_TIMEZONE)),
        'location' => sanitize_text(param($_GET, 'location')),
        'description' => sanitize_text(param($_GET, 'desc')),
        'rise_offset' => sanitize_int(param($_GET, 'rise_off'), 0) * 60,
        'set_offset' => sanitize_int(param($_GET, 'set_off'), 0) * 60,
        'enabled' => [
            'civil' => $flag('civil'),
            'nautical' => $flag('nautical'),
            'astro' => $flag('astro'),
            'daylight' => $flag('daynight'),
        ],
        'past_days' => CALENDAR_PAST_DAYS,
        'window_days' => CALENDAR_WINDOW_DAYS,
        'refresh_seconds' => UPDATE_INTERVAL,
    ]);

    header('Content-Type: text/calendar; charset=utf-8');
    header('Cache-Control: max-age=' . UPDATE_INTERVAL);
    echo $calendar;
    exit;
}

// URL generation form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_url'])) {
    if (!verify_token(param($_POST, 'password'))) {
        $error = 'Invalid password';
    } else {
        $params = [
            'feed' => '1',
            'token' => AUTH_TOKEN,
            'lat' => param($_POST, 'lat', (string) DEFAULT_LAT),
            'lon' => param($_POST, 'lon', (string) DEFAULT_LON),
            'zone' => param($_POST, 'zone', DEFAULT_TIMEZONE),
            'location' => param($_POST, 'location'),
            'rise_off' => param($_POST, 'rise_off', '0'),
            'set_off' => param($_POST, 'set_off', '0'),
            'desc' => param($_POST, 'description'),
        ];
        foreach (['civil', 'nautical', 'astro', 'daynight'] as $type) {
            if (isset($_POST[$type])) {
                $params[$type] = '1';
            }
        }
        $protocol = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
            ? 'https'
            : 'http';
        $subscription_url = $protocol . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME']
            . '?' . http_build_query($params);
        $webcal_url = preg_replace('#^https?://#', 'webcal://', $subscription_url);
    }
}

// Web interface
date_default_timezone_set(DEFAULT_TIMEZONE);
require __DIR__ . '/src/index.html.php';
