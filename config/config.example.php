<?php
/**
 * Configuration File Example
 * 
 * Copy this file to config.php and set your own values.
 * 
 * SECURITY WARNING:
 * - Never commit config.php to version control
 * - Use a cryptographically secure random string for AUTH_TOKEN
 * - Keep your token secret
 */

// REQUIRED: Authentication token for calendar generation
// Generate with: openssl rand -hex 32
if (!defined('AUTH_TOKEN')) {
    define('AUTH_TOKEN', 'CHANGE_ME_TO_A_RANDOM_STRING');
}

// OPTIONAL: Number of days to generate in calendar feed (future)
// Default: 400 (~13 months, ensures a full year ahead is always visible)
if (!defined('CALENDAR_WINDOW_DAYS')) {
    define('CALENDAR_WINDOW_DAYS', 400);
}

// OPTIONAL: Number of past days to include in calendar feed
// Default: 30 (one month of history)
if (!defined('CALENDAR_PAST_DAYS')) {
    define('CALENDAR_PAST_DAYS', 30);
}

// OPTIONAL: Update interval in seconds
// How often calendar apps should check for updates
// Default: 86400 (24 hours)
if (!defined('UPDATE_INTERVAL')) {
    define('UPDATE_INTERVAL', 86400);
}

// OPTIONAL: Default location (used for web interface display)
// These are not used in calculations, just for display
// define('DEFAULT_LATITUDE', 41.9028);
// define('DEFAULT_LONGITUDE', 12.4964);
// define('DEFAULT_TIMEZONE', 'Europe/Rome');
