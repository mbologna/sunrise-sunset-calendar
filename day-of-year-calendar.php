<?php

declare(strict_types=1);

/**
 * Day of Year iCalendar Generator
 *
 * Generates a 365-day iCalendar feed where each day has an all-day event
 * showing the day number: "Day X of Y".
 *
 * Standalone application — can be moved to any directory.
 * Requires: config/config.php with AUTH_TOKEN defined.
 *
 * URL parameters:
 *   token    – authentication token (required)
 *   zone     – timezone identifier (default: Europe/Rome)
 *   location – optional display name shown in the calendar title
 */

// ============================================================================
// CONFIGURATION
// ============================================================================

$configFile = __DIR__ . '/config/config.php';
if (!file_exists($configFile)) {
    die('Error: config/config.php not found. Copy config/config.example.php and set AUTH_TOKEN.');
}
require_once $configFile;

if (!defined('AUTH_TOKEN') || AUTH_TOKEN === 'CHANGE_ME_TO_A_RANDOM_STRING') {
    die('Error: Please set AUTH_TOKEN in config.php');
}

define('DOY_WINDOW_DAYS', 365);
define('DOY_UPDATE_INTERVAL', 86400); // 24 hours

// ============================================================================
// HELPERS (self-contained — no dependencies on the main application)
// ============================================================================

function doy_verify_token(string $provided): bool
{
    return hash_equals(AUTH_TOKEN, $provided);
}

function doy_sanitize_timezone(string $value): string
{
    return in_array($value, timezone_identifiers_list(), true) ? $value : 'Europe/Rome';
}

function doy_sanitize_text(string $value, int $maxLength = 200): string
{
    $clean = strip_tags($value);
    $clean = str_replace(["\r\n", "\r", "\n"], ' ', $clean);
    return substr($clean, 0, $maxLength);
}

/**
 * Escape and fold a DESCRIPTION value per RFC 5545.
 */
function doy_format_description(string $text): string
{
    $text = str_replace('\\', '\\\\', $text);
    $text = str_replace(["\r\n", "\r", "\n"], '\n', $text);
    $text = str_replace([',', ';'], ['\\,', '\\;'], $text);

    $line = 'DESCRIPTION:' . $text;
    $result = '';
    $current_line = '';
    $byte_count = 0;
    $char_count = mb_strlen($line, 'UTF-8');

    for ($i = 0; $i < $char_count; $i++) {
        $char = mb_substr($line, $i, 1, 'UTF-8');
        $char_bytes = strlen($char);
        if ($byte_count + $char_bytes > 75) {
            $result .= $current_line . "\r\n";
            $current_line = ' ' . $char;
            $byte_count = 1 + $char_bytes;
        } else {
            $current_line .= $char;
            $byte_count += $char_bytes;
        }
    }

    return $result . $current_line . "\r\n";
}

/**
 * Return true when $year is a leap year.
 */
function doy_is_leap_year(int $year): bool
{
    return ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;
}

// ============================================================================
// SECURITY HEADERS
// ============================================================================

if (php_sapi_name() !== 'cli') {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
}

// ============================================================================
// TOKEN CHECK
// ============================================================================

$token = $_GET['token'] ?? '';
if (!doy_verify_token($token)) {
    http_response_code(403);
    die('Invalid authentication token');
}

// ============================================================================
// PARAMETERS
// ============================================================================

$timezone = doy_sanitize_timezone($_GET['zone'] ?? 'Europe/Rome');
$location_name = doy_sanitize_text($_GET['location'] ?? '');

date_default_timezone_set($timezone);

$calendar_title = $location_name
    ? "📅 Day of Year – {$location_name}"
    : '📅 Day of Year';

// ============================================================================
// OUTPUT
// ============================================================================

header('Content-Type: text/calendar; charset=utf-8');
header('Cache-Control: max-age=' . DOY_UPDATE_INTERVAL);

$ttl_hours = (int) (DOY_UPDATE_INTERVAL / 3600);

echo "BEGIN:VCALENDAR\r\n";
echo "VERSION:2.0\r\n";
echo "PRODID:-//Day of Year Calendar//EN\r\n";
echo "CALSCALE:GREGORIAN\r\n";
echo "METHOD:PUBLISH\r\n";
echo "X-WR-CALNAME:{$calendar_title}\r\n";
echo "X-WR-TIMEZONE:{$timezone}\r\n";
echo "X-PUBLISHED-TTL:PT{$ttl_hours}H\r\n";
echo "REFRESH-INTERVAL;VALUE=DURATION:PT{$ttl_hours}H\r\n";

$start = strtotime('today');
$current = $start;
$end = strtotime('+' . DOY_WINDOW_DAYS . ' days', $start);

while ($current <= $end) {
    $year      = (int) date('Y', $current);
    $dayOfYear = (int) date('z', $current) + 1; // date('z') is 0-indexed
    $totalDays = doy_is_leap_year($year) ? 366 : 365;

    $summary = "Day {$dayOfYear} of {$totalDays}";
    $dateStr  = date('Ymd', $current);
    $nextDateStr = date('Ymd', strtotime('+1 day', $current));

    // UID: stable identifier for this calendar day
    $uid = "doy-{$dateStr}@day-of-year-calendar";

    echo "BEGIN:VEVENT\r\n";
    echo "UID:{$uid}\r\n";
    echo 'DTSTAMP:' . gmdate('Ymd\THis\Z') . "\r\n";
    echo "DTSTART;VALUE=DATE:{$dateStr}\r\n";
    echo "DTEND;VALUE=DATE:{$nextDateStr}\r\n";
    echo "SUMMARY:{$summary}\r\n";
    echo doy_format_description("{$summary} — {$year}");
    echo "TRANSP:TRANSPARENT\r\n";
    echo "END:VEVENT\r\n";

    $current = strtotime('+1 day', $current);
}

echo "END:VCALENDAR\r\n";
