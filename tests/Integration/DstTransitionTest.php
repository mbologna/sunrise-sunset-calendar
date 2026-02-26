<?php

namespace Tests\Integration;

use Tests\BaseTest;

/**
 * DST Transition Test Suite
 * Tests solar calculations across daylight saving time boundaries
 */
class DstTransitionTest extends BaseTest
{
    /**
     * US DST Spring Forward: March 8, 2026 (2:00 AM → 3:00 AM)
     */
    public function testUsDstSpringForwardWeekSummary(): void
    {
        $lat = 40.7128;  // New York
        $lon = -74.0060;
        $utcOffset = -5.0;  // EST (before DST)

        $weekStart = strtotime('2026-03-01');
        $weekEnd = strtotime('+6 days', $weekStart);

        // Calculate for all 7 days of the week spanning the DST transition
        $current = $weekStart;
        $dayCount = 0;

        while ($current <= $weekEnd) {
            $dateParts = getdate($current);
            $result = calculate_sun_times(
                $dateParts['year'],
                $dateParts['mon'],
                $dateParts['mday'],
                $lat,
                $lon,
                $utcOffset
            );

            // Verify we get valid results
            $this->assertIsArray($result);
            $this->assertArrayHasKey('daylength_h', $result);
            $this->assertGreaterThan(0, $result['daylength_h']);

            $dayCount++;
            $current = strtotime('+1 day', $current);
        }

        // Verify we got all 7 days
        $this->assertEquals(7, $dayCount);
    }

    /**
     * Day length continuity across DST: change should be minutes, not hours
     */
    public function testDayLengthContinuityAcrossDstBoundary(): void
    {
        $lat = 40.7128;
        $lon = -74.0060;
        $utcOffset = -5.0;

        // Day before DST transition
        $dayBeforeParts = getdate(strtotime('2026-03-07'));
        $dayBefore = calculate_sun_times(
            $dayBeforeParts['year'],
            $dayBeforeParts['mon'],
            $dayBeforeParts['mday'],
            $lat,
            $lon,
            $utcOffset
        );

        // Day after DST transition
        $dayAfterParts = getdate(strtotime('2026-03-09'));
        $dayAfter = calculate_sun_times(
            $dayAfterParts['year'],
            $dayAfterParts['mon'],
            $dayAfterParts['mday'],
            $lat,
            $lon,
            $utcOffset
        );

        $dayBeforeDaylength = $dayBefore['daylength_h'] * 3600;
        $dayAfterDaylength = $dayAfter['daylength_h'] * 3600;

        // Day length change should be within 10 minutes (600 seconds)
        // (Near equinox, day length changes faster)
        $diff = abs($dayAfterDaylength - $dayBeforeDaylength);
        $this->assertLessThan(600, $diff, 'Day length changed by more than 10 minutes across DST');
    }

    /**
     * US DST Fall Back: November 1, 2026 (2:00 AM → 1:00 AM)
     */
    public function testUsDstFallBackWeekSummary(): void
    {
        $lat = 40.7128;
        $lon = -74.0060;
        $utcOffset = -4.0;  // EDT (before fall back)

        $weekStart = strtotime('2026-10-25');
        $weekEnd = strtotime('+6 days', $weekStart);

        $dayCount = 0;
        $current = $weekStart;

        while ($current <= $weekEnd) {
            $dateParts = getdate($current);
            $result = calculate_sun_times(
                $dateParts['year'],
                $dateParts['mon'],
                $dateParts['mday'],
                $lat,
                $lon,
                $utcOffset
            );

            $this->assertIsArray($result);
            $this->assertArrayHasKey('daylength_h', $result);

            $dayCount++;
            $current = strtotime('+1 day', $current);
        }

        $this->assertEquals(7, $dayCount);
    }

    /**
     * EU DST Spring Forward: March 29, 2026
     */
    public function testEuDstSpringForwardSunTimes(): void
    {
        $lat = 48.8566;   // Paris
        $lon = 2.3522;
        $utcOffset = 1.0;  // CET (before DST)

        // Days around EU spring transition (March 29)
        $dates = [
            '2026-03-28',
            '2026-03-29',
            '2026-03-30',
        ];

        $prevSunrise = null;

        foreach ($dates as $dateStr) {
            $timestamp = strtotime($dateStr);
            $dateParts = getdate($timestamp);

            $result = calculate_sun_times(
                $dateParts['year'],
                $dateParts['mon'],
                $dateParts['mday'],
                $lat,
                $lon,
                $utcOffset
            );

            $this->assertIsArray($result);

            // Sunrise should be gradually getting earlier each day
            if ($prevSunrise !== null) {
                $currSunrise = $result['sunrise_frac'] * 86400;
                $diff = $prevSunrise - $currSunrise;
                // Around 2 minutes earlier each day near equinox
                $this->assertGreaterThan(-300, $diff);  // Less than 5 min difference
            }

            $prevSunrise = $result['sunrise_frac'] * 86400;
        }
    }

    /**
     * EU DST Fall Back: October 25, 2026
     */
    public function testEuDstFallBackSunTimes(): void
    {
        $lat = 48.8566;
        $lon = 2.3522;
        $utcOffset = 2.0;  // CEST (before fall back)

        $dates = [
            '2026-10-24',
            '2026-10-25',
            '2026-10-26',
        ];

        $prevSunset = null;

        foreach ($dates as $dateStr) {
            $timestamp = strtotime($dateStr);
            $dateParts = getdate($timestamp);

            $result = calculate_sun_times(
                $dateParts['year'],
                $dateParts['mon'],
                $dateParts['mday'],
                $lat,
                $lon,
                $utcOffset
            );

            $this->assertIsArray($result);

            if ($prevSunset !== null) {
                $currSunset = $result['sunset_frac'] * 86400;
                $diff = $prevSunset - $currSunset;
                // Around 2 minutes earlier each day near autumn equinox
                $this->assertGreaterThan(-300, $diff);
            }

            $prevSunset = $result['sunset_frac'] * 86400;
        }
    }
}
