<?php

// phpcs:disable PSR1.Files.SideEffects

/**
 * Core utility functions for Sun & Twilight Calendar.
 * Includes caching for performance optimization.
 */

declare(strict_types=1);

// Load Cache class
require_once __DIR__ . '/Cache.php';

use SunCalendar\Cache;

// ============================================================================
// STRINGS (Lazy Loading via Cache class)
// ============================================================================

/**
 * Initialize strings configuration (lazy loading).
 *
 * @return void
 */
function init_strings(): void
{
    // Strings are loaded lazily by Cache::getStrings()
    Cache::getInstance()->getStrings();
}

/**
 * Get strings configuration with lazy loading.
 *
 * @return array Strings configuration
 */
function get_strings(): array
{
    return Cache::getInstance()->getStrings();
}

// ============================================================================
// CACHING (Backward-compatible wrappers using Cache class)
// ============================================================================

// Maximum cache entries per cache type
define('MAX_CACHE_ENTRIES', 100);

/**
 * Clear all caches.
 *
 * Useful for testing and memory management in long-running processes.
 *
 * @param bool $includeStrings Also clear strings cache (default: false)
 * @return void
 */
function clear_caches(bool $includeStrings = false): void
{
    Cache::getInstance()->clearAll($includeStrings);
}

/**
 * Get cached daylight lengths for a location/year.
 *
 * @param float $lat Latitude
 * @param float $lon Longitude
 * @param int $year Year
 * @param float $utcOffset UTC offset in hours
 * @return array<float> Sorted array of daylight lengths in hours
 */
function get_cached_daylight_lengths(float $lat, float $lon, int $year, float $utcOffset): array
{
    return Cache::getInstance()->getDaylightLengths($lat, $lon, $year, $utcOffset);
}

/**
 * Get cached week summary data.
 *
 * @param int $weekStart Week start timestamp
 * @param float $lat Latitude
 * @param float $lon Longitude
 * @param float $utcOffset UTC offset
 * @param array $strings Strings configuration (kept for backward compatibility, now ignored)
 * @return array|null Week summary data
 */
function get_cached_week_summary(int $weekStart, float $lat, float $lon, float $utcOffset, array $strings): ?array
{
    return Cache::getInstance()->getWeekSummary($weekStart, $lat, $lon, $utcOffset);
}

// ============================================================================
// SANITIZATION
// ============================================================================

/**
 * Sanitize a float value within bounds.
 *
 * @param mixed $value Value to sanitize
 * @param float $default Default value if invalid
 * @param float $min Minimum allowed value
 * @param float $max Maximum allowed value
 * @return float Sanitized float value
 */
function sanitize_float($value, float $default, float $min = -90.0, float $max = 90.0): float
{
    $filtered = filter_var($value, FILTER_VALIDATE_FLOAT);
    return ($filtered === false || $filtered < $min || $filtered > $max) ? $default : $filtered;
}

/**
 * Sanitize latitude value.
 * Valid range: -90 to 90 degrees.
 *
 * @param mixed $value Value to sanitize
 * @param float $default Default value if invalid
 * @return float Sanitized latitude
 */
function sanitize_latitude($value, float $default = 41.9028): float
{
    return sanitize_float($value, $default, -90.0, 90.0);
}

/**
 * Sanitize longitude value.
 * Valid range: -180 to 180 degrees.
 *
 * @param mixed $value Value to sanitize
 * @param float $default Default value if invalid
 * @return float Sanitized longitude
 */
function sanitize_longitude($value, float $default = 12.4964): float
{
    return sanitize_float($value, $default, -180.0, 180.0);
}

/**
 * Sanitize an integer value within bounds.
 *
 * @param mixed $value Value to sanitize
 * @param int $default Default value if invalid
 * @param int $min Minimum allowed value
 * @param int $max Maximum allowed value
 * @return int Sanitized integer value
 */
function sanitize_int($value, int $default, int $min = -1440, int $max = 1440): int
{
    $filtered = filter_var($value, FILTER_VALIDATE_INT);
    return ($filtered === false || $filtered < $min || $filtered > $max) ? $default : $filtered;
}

/**
 * Sanitize timezone identifier.
 *
 * @param string $value Timezone identifier to validate
 * @return string Valid timezone identifier
 */
function sanitize_timezone(string $value): string
{
    $zones = timezone_identifiers_list();
    return in_array($value, $zones, true) ? $value : 'Europe/Rome';
}

/**
 * Sanitize text input.
 *
 * @param string $value Text to sanitize
 * @param int $maxLength Maximum length
 * @return string Sanitized text
 */
function sanitize_text(string $value, int $maxLength = 500): string
{
    $clean = strip_tags($value);
    $clean = str_replace(["\r\n", "\r", "\n"], ' ', $clean);
    return substr($clean, 0, $maxLength);
}

/**
 * Verify authentication token using constant-time comparison.
 *
 * @param string $providedToken Token to verify
 * @return bool True if token is valid
 */
function verify_token(string $providedToken): bool
{
    return hash_equals(AUTH_TOKEN, $providedToken);
}

// ============================================================================
// FORMATTING
// ============================================================================

/**
 * Format duration in hours and minutes.
 *
 * @param int $seconds Duration in seconds
 * @return string Formatted duration
 */
function format_duration(int $seconds): string
{
    $hours = (int) floor($seconds / 3600);
    $minutes = (int) floor(($seconds % 3600) / 60);
    return sprintf('%dh %02dm', $hours, $minutes);
}

/**
 * Format duration in short form.
 *
 * @param int $seconds Duration in seconds
 * @return string Formatted duration
 */
function format_duration_short(int $seconds): string
{
    $hours = (int) floor($seconds / 3600);
    $minutes = (int) floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;

    if ($hours > 0) {
        return sprintf('%dh %dm', $hours, $minutes);
    } elseif ($minutes > 0) {
        return sprintf('%dm %ds', $minutes, $secs);
    }
    return sprintf('%ds', $secs);
}

// ============================================================================
// SOLAR CALCULATIONS (basic formulas)
// ============================================================================

/**
 * Calculate Julian Day number.
 *
 * @param int $y Year
 * @param int $m Month
 * @param int $d Day
 * @param float $hourUTC Hour in UTC
 * @return float Julian Day number
 */
function julianDay(int $y, int $m, int $d, float $hourUTC = 0.0): float
{
    if ($m <= 2) {
        $y -= 1;
        $m += 12;
    }
    $A = floor($y / 100);
    $B = 2 - $A + floor($A / 4);
    return floor(365.25 * ($y + 4716)) + floor(30.6001 * ($m + 1)) + $d + $B - 1524.5 + $hourUTC / 24.0;
}

/**
 * Calculate Julian Century from Julian Day.
 *
 * @param float $JD Julian Day
 * @return float Julian Century
 */
function julianCentury(float $JD): float
{
    return ($JD - 2451545.0) / 36525.0;
}

/**
 * Calculate sun mean longitude.
 *
 * @param float $T Julian Century
 * @return float Sun mean longitude in degrees
 */
function sunMeanLongitude(float $T): float
{
    return fmod(280.46646 + $T * (36000.76983 + 0.0003032 * $T), 360.0);
}

/**
 * Calculate sun mean anomaly.
 *
 * @param float $T Julian Century
 * @return float Sun mean anomaly in degrees
 */
function sunMeanAnomaly(float $T): float
{
    return 357.52911 + $T * (35999.05029 - 0.0001537 * $T);
}

/**
 * Calculate sun equation of center.
 *
 * @param float $T Julian Century
 * @param float $M Sun mean anomaly
 * @return float Sun equation of center in degrees
 */
function sunEquationOfCenter(float $T, float $M): float
{
    $Mr = deg2rad($M);
    return sin($Mr) * (1.914602 - $T * (0.004817 + 0.000014 * $T))
         + sin(2 * $Mr) * (0.019993 - 0.000101 * $T)
         + sin(3 * $Mr) * 0.000289;
}

/**
 * Calculate sun true longitude.
 *
 * @param float $L0 Sun mean longitude
 * @param float $C Sun equation of center
 * @return float Sun true longitude in degrees
 */
function sunTrueLongitude(float $L0, float $C): float
{
    return $L0 + $C;
}

/**
 * Calculate sun apparent longitude.
 *
 * @param float $T Julian Century
 * @param float $trueLon Sun true longitude
 * @return float Sun apparent longitude in degrees
 */
function sunApparentLongitude(float $T, float $trueLon): float
{
    return $trueLon - 0.00569 - 0.00478 * sin(deg2rad(125.04 - 1934.136 * $T));
}

/**
 * Calculate mean obliquity of the ecliptic.
 *
 * @param float $T Julian Century
 * @return float Mean obliquity in degrees
 */
function meanObliquity(float $T): float
{
    return 23 + (26 + ((21.448 - $T * (46.815 + $T * (0.00059 - $T * 0.001813))) / 60)) / 60;
}

/**
 * Calculate corrected obliquity.
 *
 * @param float $T Julian Century
 * @param float $eps0 Mean obliquity
 * @return float Corrected obliquity in degrees
 */
function correctedObliquity(float $T, float $eps0): float
{
    return $eps0 + 0.00256 * cos(deg2rad(125.04 - 1934.136 * $T));
}

/**
 * Calculate solar declination.
 *
 * @param float $lambda Sun apparent longitude
 * @param float $eps Corrected obliquity
 * @return float Solar declination in degrees
 */
function solarDeclination(float $lambda, float $eps): float
{
    return rad2deg(asin(sin(deg2rad($eps)) * sin(deg2rad($lambda))));
}

/**
 * Calculate equation of time.
 *
 * @param float $T Julian Century
 * @param float $L0 Sun mean longitude
 * @param float $e Earth orbit eccentricity
 * @param float $M Sun mean anomaly
 * @param float $eps Corrected obliquity
 * @return float Equation of time in minutes
 */
function equationOfTime(float $T, float $L0, float $e, float $M, float $eps): float
{
    $y = pow(tan(deg2rad($eps) / 2), 2);
    $L0r = deg2rad($L0);
    $Mr = deg2rad($M);
    return 4 * rad2deg(
        $y * sin(2 * $L0r)
        - 2 * $e * sin($Mr)
        + 4 * $e * $y * sin($Mr) * cos(2 * $L0r)
        - 0.5 * $y * $y * sin(4 * $L0r)
        - 1.25 * $e * $e * sin(2 * $Mr)
    );
}

/**
 * Calculate sunrise hour angle.
 *
 * @param float $lat Latitude
 * @param float $decl Solar declination
 * @param float $alt Altitude angle
 * @return float Hour angle in degrees
 */
function sunriseHourAngle(float $lat, float $decl, float $alt): float
{
    $latr = deg2rad($lat);
    $declr = deg2rad($decl);
    $altr = deg2rad($alt);
    $cosHa = (sin($altr) - sin($latr) * sin($declr)) / (cos($latr) * cos($declr));

    if ($cosHa > 1) {
        return 0.0;
    }
    if ($cosHa < -1) {
        return 180.0;
    }
    return rad2deg(acos($cosHa));
}

/**
 * Convert fractional day to Unix timestamp.
 *
 * @param int $dateY Year
 * @param int $dateM Month
 * @param int $dateD Day
 * @param float $frac Fractional day (0-1)
 * @return int Unix timestamp
 */
function fraction_to_timestamp(int $dateY, int $dateM, int $dateD, float $frac): int
{
    $midnight = mktime(0, 0, 0, $dateM, $dateD, $dateY);
    return $midnight + (int) round($frac * 86400);
}

// ============================================================================
// LOCATION HELPERS
// ============================================================================

/**
 * Get solstice and equinox dates for a year.
 *
 * @param int $year Year
 * @return array<string, int> Timestamps for solstices and equinoxes
 */
function get_solstice_dates(int $year): array
{
    $result = calculate_equinox_solstice($year);

    return [
        'march_equinox' => $result['march_equinox'],
        'june_solstice' => $result['june_solstice'],
        'sept_equinox' => $result['september_equinox'],
        'dec_solstice' => $result['december_solstice'],
    ];
}
