<?php

/**
 * Configuration File for Sun & Twilight Calendar
 *
 * IMPORTANT: Copy this file to config.php and update the values below.
 * Never commit config.php to version control!
 *
 * Generate a secure AUTH_TOKEN:
 *   openssl rand -hex 32
 */

// Authentication token - CHANGE THIS to a secure random string
define('AUTH_TOKEN', 'CHANGE_ME_TO_A_RANDOM_STRING');

// Number of days to generate calendar events (default: 365)
// Increase for longer planning horizon, decrease for faster generation
define('CALENDAR_WINDOW_DAYS', 365);

// Update interval in seconds (default: 86400 = 24 hours)
// How often calendar apps should check for updates
define('UPDATE_INTERVAL', 86400);

/**
 * SECURITY NOTES:
 *
 * 1. Keep your AUTH_TOKEN secret - it's the password for generating calendars
 * 2. Use a strong random token (at least 32 characters)
 * 3. Add config.php to .gitignore to prevent accidental commits
 * 4. Use HTTPS for your web server (required by most calendar apps)
 * 5. Consider restricting access by IP address in your web server config
 *
 * EXAMPLE .gitignore:
 *   config.php
 *   *.log
 */
