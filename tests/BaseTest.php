<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Base Test Class
 * Provides shared assertion methods for solar calculation tests.
 */
abstract class BaseTest extends TestCase
{
    /**
     * Assert two floats are equal within tolerance.
     *
     * @param float $expected Expected value
     * @param float $actual Actual value
     * @param string $message Assertion message
     * @param float $tolerance Acceptable difference
     */
    protected function assertFloatEquals(
        float $expected,
        float $actual,
        string $message = '',
        float $tolerance = 0.01
    ): void {
        $diff = abs($expected - $actual);
        $this->assertLessThanOrEqual(
            $tolerance,
            $diff,
            sprintf(
                "%s\n    Expected: %.6f\n    Actual:   %.6f\n    Difference: %.6f (tolerance: %.6f)",
                $message,
                $expected,
                $actual,
                $diff,
                $tolerance
            )
        );
    }

    /**
     * Assert timestamp equals expected time within tolerance (seconds).
     *
     * @param mixed $expected Expected time (timestamp or HH:MM string)
     * @param int $actual Actual timestamp
     * @param string $message Assertion message
     * @param int $toleranceSeconds Tolerance in seconds
     */
    protected function assertTimeEquals(
        $expected,
        int $actual,
        string $message = '',
        int $toleranceSeconds = 120
    ): void {
        // Convert string "HH:MM" to timestamp if needed
        if (is_string($expected) && strpos($expected, ':') !== false) {
            // Use a reference date for time comparison
            $parts = explode(':', $expected);
            $refDate = getdate($actual);
            $expected = mktime(
                (int) $parts[0],
                (int) $parts[1],
                isset($parts[2]) ? (int) $parts[2] : 0,
                $refDate['mon'],
                $refDate['mday'],
                $refDate['year']
            );
        }

        $diff = abs($expected - $actual);
        $diffMinutes = $diff / 60;

        $this->assertLessThanOrEqual(
            $toleranceSeconds,
            $diff,
            sprintf(
                "%s\n    Expected: %s\n    Actual:   %s\n    Difference: %.2f minutes (%d seconds, tolerance: %d seconds)",
                $message,
                date('Y-m-d H:i:s', $expected),
                date('Y-m-d H:i:s', $actual),
                $diffMinutes,
                $diff,
                $toleranceSeconds
            )
        );
    }

    /**
     * Assert duration in seconds within tolerance.
     *
     * @param int $expectedSeconds Expected duration in seconds
     * @param int $actualSeconds Actual duration in seconds
     * @param string $message Assertion message
     * @param int $toleranceSeconds Tolerance in seconds
     */
    protected function assertDurationEquals(
        int $expectedSeconds,
        int $actualSeconds,
        string $message = '',
        int $toleranceSeconds = 60
    ): void {
        $diff = abs($expectedSeconds - $actualSeconds);
        $this->assertLessThanOrEqual(
            $toleranceSeconds,
            $diff,
            sprintf(
                "%s\n    Expected: %s (%d seconds)\n    Actual:   %s (%d seconds)\n    Difference: %d seconds (tolerance: %d seconds)",
                $message,
                gmdate('H:i:s', $expectedSeconds),
                $expectedSeconds,
                gmdate('H:i:s', $actualSeconds),
                $actualSeconds,
                $diff,
                $toleranceSeconds
            )
        );
    }

    /**
     * Assert value is within range [min, max].
     *
     * @param float $value Value to check
     * @param float $min Minimum value (inclusive)
     * @param float $max Maximum value (inclusive)
     * @param string $message Assertion message
     */
    protected function assertInRange(
        float $value,
        float $min,
        float $max,
        string $message = ''
    ): void {
        $this->assertGreaterThanOrEqual(
            $min,
            $value,
            sprintf("%s\n    Value %.6f below minimum %.6f", $message, $value, $min)
        );
        $this->assertLessThanOrEqual(
            $max,
            $value,
            sprintf("%s\n    Value %.6f above maximum %.6f", $message, $value, $max)
        );
    }

    /**
     * Assert percentile value (0-100) within tolerance.
     *
     * @param float $expected Expected percentile
     * @param float $actual Actual percentile
     * @param string $message Assertion message
     * @param float $tolerance Tolerance for percentile difference
     */
    protected function assertPercentile(
        float $expected,
        float $actual,
        string $message = '',
        float $tolerance = 2.0
    ): void {
        $diff = abs($expected - $actual);
        $this->assertLessThanOrEqual(
            $tolerance,
            $diff,
            sprintf(
                "%s\n    Expected: %.2f%%\n    Actual:   %.2f%%\n    Difference: %.2f%% (tolerance: %.2f%%)",
                $message,
                $expected,
                $actual,
                $diff,
                $tolerance
            )
        );
    }

    /**
     * Helper: Calculate sun times (reduces boilerplate).
     *
     * @param int $year Year
     * @param int $month Month (1-12)
     * @param int $day Day (1-31)
     * @param float $lat Latitude
     * @param float $lon Longitude
     * @param int $utcOffset UTC offset in hours
     * @return array Solar calculation results
     */
    protected function calculateSunTimes(
        int $year,
        int $month,
        int $day,
        float $lat,
        float $lon,
        int $utcOffset
    ): array {
        return calculate_sun_times($year, $month, $day, $lat, $lon, $utcOffset);
    }

    /**
     * Helper: Convert fraction to timestamp.
     *
     * @param int $year Year
     * @param int $month Month (1-12)
     * @param int $day Day (1-31)
     * @param float $fraction Fractional day (0.0-1.0)
     * @return int Unix timestamp
     */
    protected function fractionToTimestamp(
        int $year,
        int $month,
        int $day,
        float $fraction
    ): int {
        return fraction_to_timestamp($year, $month, $day, $fraction);
    }
}
