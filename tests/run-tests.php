#!/usr/bin/env php
<?php

/**
 * Test Runner for Sun & Twilight Calendar.
 *
 * Usage: php tests/run-tests.php
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Colors for terminal output
class Colors
{
    public const GREEN = "\033[32m";
    public const RED = "\033[31m";
    public const YELLOW = "\033[33m";
    public const BLUE = "\033[34m";
    public const RESET = "\033[0m";
}

// Simple test framework
class TestRunner
{
    private $tests = [];
    private $passed = 0;
    private $failed = 0;

    public function addTestFile($file)
    {
        if (file_exists($file)) {
            $this->tests[] = $file;
        } else {
            echo Colors::RED . "Test file not found: $file" . Colors::RESET . "\n";
        }
    }

    public function run()
    {
        echo Colors::BLUE . "\n╔════════════════════════════════════════╗\n";
        echo "║  Sun & Twilight Calendar Test Suite   ║\n";
        echo "╚════════════════════════════════════════╝\n" . Colors::RESET . "\n";

        foreach ($this->tests as $test) {
            echo Colors::YELLOW . 'Running: ' . basename($test) . Colors::RESET . "\n";

            ob_start();
            $result = include $test;
            $output = ob_get_clean();

            if ($result === false) {
                $this->failed++;
                echo Colors::RED . '✗ FAILED' . Colors::RESET . "\n";
                if ($output) {
                    echo $output;
                }
            } else {
                $this->passed++;
                echo Colors::GREEN . '✓ PASSED' . Colors::RESET . "\n";
            }
            echo "\n";
        }

        $this->printSummary();
    }

    private function printSummary()
    {
        $total = $this->passed + $this->failed;

        echo str_repeat('─', 40) . "\n";
        echo "Total Tests: $total\n";
        echo Colors::GREEN . "Passed: {$this->passed}" . Colors::RESET . "\n";

        if ($this->failed > 0) {
            echo Colors::RED . "Failed: {$this->failed}" . Colors::RESET . "\n";
        }

        echo str_repeat('─', 40) . "\n";

        if ($this->failed === 0) {
            echo Colors::GREEN . "\n✓ ALL TESTS PASSED!\n" . Colors::RESET;
            exit(0);
        } else {
            echo Colors::RED . "\n✗ SOME TESTS FAILED\n" . Colors::RESET;
            exit(1);
        }
    }
}

// Find test files
$testDir = __DIR__;
$testFiles = glob($testDir . '/*Test.php');

if (empty($testFiles)) {
    echo Colors::RED . "No test files found in $testDir\n" . Colors::RESET;
    exit(1);
}

// Run tests
$runner = new TestRunner();

foreach ($testFiles as $testFile) {
    $runner->addTestFile($testFile);
}

$runner->run();
