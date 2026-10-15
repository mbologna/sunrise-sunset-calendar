<?php

/**
 * PHPUnit Bootstrap
 * Loads the application through Composer's autoloader.
 */

declare(strict_types=1);

define('AUTH_TOKEN', 'test-token-for-phpunit');

require_once __DIR__ . '/../vendor/autoload.php';

// Set timezone for consistent tests
date_default_timezone_set('UTC');
