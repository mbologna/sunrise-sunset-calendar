<?php

/**
 * Core utility functions: strings, input sanitization, authentication, formatting.
 */

declare(strict_types=1);

use SunCalendar\Cache;

const DEFAULT_LAT = 41.9028;
const DEFAULT_LON = 12.4964;
const DEFAULT_TIMEZONE = 'Europe/Rome';

/**
 * Get strings configuration (loaded once per request).
 *
 * @return array Strings configuration
 */
function get_strings(): array
{
    return Cache::getInstance()->getStrings();
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
function sanitize_float($value, float $default, float $min, float $max): float
{
    $filtered = filter_var($value, FILTER_VALIDATE_FLOAT);
    return ($filtered === false || $filtered < $min || $filtered > $max) ? $default : $filtered;
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
    return in_array($value, timezone_identifiers_list(), true) ? $value : DEFAULT_TIMEZONE;
}

/**
 * Sanitize text input: strip tags, flatten newlines, cap length in characters.
 *
 * @param string $value Text to sanitize
 * @param int $maxLength Maximum length in characters
 * @return string Sanitized text
 */
function sanitize_text(string $value, int $maxLength = 500): string
{
    $clean = strip_tags($value);
    $clean = str_replace(["\r\n", "\r", "\n"], ' ', $clean);
    return mb_substr($clean, 0, $maxLength, 'UTF-8');
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
 * Format a duration compactly: "9h 05m", "32m", or with seconds "2m 05s".
 *
 * Seconds are shown only when asked for and under an hour; otherwise the value
 * is rounded to the minute.
 *
 * @param int $seconds Duration in seconds (sign is ignored)
 * @param bool $withSeconds Keep seconds for durations under an hour
 * @return string Formatted duration
 */
function format_duration(int $seconds, bool $withSeconds = false): string
{
    $seconds = abs($seconds);
    if ($withSeconds && $seconds < 3600) {
        return $seconds < 60
            ? sprintf('%ds', $seconds)
            : sprintf('%dm %02ds', intdiv($seconds, 60), $seconds % 60);
    }

    $minutes = (int) round($seconds / 60);
    return $minutes < 60
        ? sprintf('%dm', $minutes)
        : sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60);
}

/**
 * Format a signed duration rounded to the minute: "+15m", "-1h 05m".
 */
function format_signed_duration(int $seconds): string
{
    return ($seconds <= -30 ? '-' : '+') . format_duration($seconds);
}
