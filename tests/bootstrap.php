<?php

/**
 * PHPUnit Bootstrap
 * Sets up test environment and autoloading.
 */

// PHPUNIT_RUNNING is defined in phpunit.xml

// Define AUTH_TOKEN for tests (needed by sunrise-sunset-calendar.php)
if (!defined('AUTH_TOKEN')) {
    define('AUTH_TOKEN', 'test-token-for-phpunit');
}

// Define optional constants
if (!defined('CALENDAR_WINDOW_DAYS')) {
    define('CALENDAR_WINDOW_DAYS', 365);
}
if (!defined('UPDATE_INTERVAL')) {
    define('UPDATE_INTERVAL', 86400);
}

// Require Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Load config (create a minimal test config if it doesn't exist)
$config_file = __DIR__ . '/../config/config.php';
if (!file_exists($config_file)) {
    // Create minimal config for testing
    $config_dir = dirname($config_file);
    if (!is_dir($config_dir)) {
        mkdir($config_dir, 0755, true);
    }
    file_put_contents($config_file, "<?php\ndefine('AUTH_TOKEN', 'test-token-for-phpunit');\n");
}

// Load functions from sunrise-sunset-calendar.php without executing the web interface
// We'll do this by using output buffering and discarding the HTML output
ob_start();
require_once __DIR__ . '/../sunrise-sunset-calendar.php';
ob_end_clean();

// Set timezone for consistent tests
date_default_timezone_set('UTC');

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');
