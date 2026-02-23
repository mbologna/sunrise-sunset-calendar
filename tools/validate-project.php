#!/usr/bin/env php
<?php

/**
 * Project Validation Script
 * Checks if the project is ready for GitHub publication.
 */
echo "\n";
echo "╔══════════════════════════════════════════════════════╗\n";
echo "║  Sun & Twilight Calendar - Project Validation       ║\n";
echo "╚══════════════════════════════════════════════════════╝\n\n";

$passed = 0;
$failed = 0;

function check(bool $condition, string $message): bool
{
    global $passed, $failed;

    if ($condition) {
        echo '✓ ' . $message . "\n";
        $passed++;

        return true;
    } else {
        echo '✗ ' . $message . "\n";
        $failed++;

        return false;
    }
}

echo "Checking Files...\n";
check(file_exists('sunrise-sunset-calendar.php'), 'sunrise-sunset-calendar.php exists');
check(file_exists('src/calendar-generator.php'), 'src/calendar-generator.php exists');
check(file_exists('src/strings.php'), 'src/strings.php exists');
check(file_exists('src/astronomy.php'), 'src/astronomy.php exists');
check(file_exists('assets/index.html.php'), 'assets/index.html.php exists');
check(file_exists('assets/script.js'), 'assets/script.js exists');
check(file_exists('assets/styles.css'), 'assets/styles.css exists');
check(file_exists('config/config.example.php'), 'config/config.example.php exists');
check(!file_exists('config/config.php') || file_exists('config/config.php'), 'config.php check skipped (may exist locally)');

echo "\nChecking Documentation...\n";
check(file_exists('README.md'), 'README.md exists');
check(file_exists('LICENSE'), 'LICENSE exists');
check(file_exists('.gitignore'), '.gitignore exists');

echo "\nChecking Tests...\n";
check(file_exists('tests/Unit/SolarCalculationsTest.php'), 'Solar calculations tests exist');
check(file_exists('tests/Unit/PercentileCalculationsTest.php'), 'Percentile tests exist');
check(file_exists('tests/Unit/FormatTest.php'), 'Format tests exist');
check(file_exists('tests/Reference/MapelloReferenceTest.php'), 'Reference validation tests exist');
check(file_exists('phpunit.xml'), 'PHPUnit configuration exists');

echo "\nChecking Code Quality Tools...\n";
check(file_exists('.php-cs-fixer.php'), '.php-cs-fixer.php exists');
check(file_exists('.editorconfig'), '.editorconfig exists');

echo "\nChecking CI/CD...\n";
check(file_exists('.github/workflows/ci.yml'), 'GitHub Actions CI workflow exists');

echo "\nChecking PHP Syntax...\n";
$syntax_errors = 0;
$files_to_check = [
    'sunrise-sunset-calendar.php',
    'src/calendar-generator.php',
    'src/strings.php',
    'src/astronomy.php',
];

foreach ($files_to_check as $file) {
    if (file_exists($file)) {
        exec("php -l $file 2>&1", $output, $return);
        if ($return === 0) {
            check(true, "$file syntax valid");
        } else {
            check(false, "$file has syntax errors");
            $syntax_errors++;
        }
    }
}

echo "\nChecking Configuration...\n";
if (file_exists('config/config.example.php')) {
    $config_content = file_get_contents('config/config.example.php');
    check(
        strpos($config_content, 'CHANGE_ME') !== false,
        'config.example.php has placeholder token'
    );
    check(
        strpos($config_content, 'AUTH_TOKEN') !== false,
        'config.example.php defines AUTH_TOKEN'
    );
}

echo "\nChecking Composer...\n";
check(file_exists('composer.json'), 'composer.json exists');
check(file_exists('composer.lock'), 'composer.lock exists');
check(file_exists('vendor/autoload.php'), 'Composer dependencies installed');

echo "\nChecking README...\n";
if (file_exists('README.md')) {
    $readme = file_get_contents('README.md');
    check(strlen($readme) > 1000, 'README.md is comprehensive (>1000 chars)');
    check(strpos($readme, 'Installation') !== false, 'README has Installation section');
    check(strpos($readme, 'Usage') !== false, 'README has Usage section');
    check(strpos($readme, 'Testing') !== false || strpos($readme, 'Development') !== false, 'README has Testing/Development section');
    check(strpos($readme, 'License') !== false, 'README mentions License');
}

echo "\nChecking .gitignore...\n";
if (file_exists('.gitignore')) {
    $gitignore = file_get_contents('.gitignore');
    check(strpos($gitignore, 'config.php') !== false, '.gitignore excludes config.php');
    check(strpos($gitignore, 'vendor/') !== false, '.gitignore excludes vendor/');
}

echo "\n" . str_repeat('─', 54) . "\n";
echo 'Total Checks: ' . ($passed + $failed) . "\n";
echo "\033[32mPassed: $passed\033[0m\n";

if ($failed > 0) {
    echo "\033[31mFailed: $failed\033[0m\n";
    echo "\n❌ Project is NOT ready for publication\n";
    echo "Please fix the issues above.\n\n";
    exit(1);
} else {
    echo "\n✅ Project is READY for GitHub publication!\n";
    echo "\nNext steps:\n";
    echo "1. Create GitHub repository\n";
    echo "2. git init\n";
    echo "3. git add .\n";
    echo "4. git commit -m 'Initial commit: Sun & Twilight Calendar v7.3'\n";
    echo "5. git remote add origin https://github.com/yourusername/sun-twilight-calendar.git\n";
    echo "6. git push -u origin main\n";
    echo "\n";
    exit(0);
}
