<?php

namespace Tests\Unit;

use Tests\BaseTest;

/**
 * Comprehensive Percentile Calculation Test Suite
 * Tests day length percentile algorithm accuracy and edge cases.
 *
 * Coverage:
 * - Percentile algorithm correctness (formula validation)
 * - Solstice percentiles (0% winter, 100% summer)
 * - Equinox percentiles (~50%)
 * - Monotonic increase from winter to summer
 * - Percentile range validation (0-100%)
 * - Leap year percentile calculations
 * - Different latitudes (arctic, temperate, equator, southern hemisphere)
 * - Count accuracy verification
 * - Consistency/determinism tests
 */
class PercentileCalculationsTest extends BaseTest
{
    public function testWinterSolsticePercentileRome(): void
    {
        $lat = 41.9028;
        $lon = 12.4964;
        $utc_offset = 1;
        $year = 2026;

        $winter = $this->calculateSunTimes($year, 12, 21, $lat, $lon, $utc_offset);
        $percentile = calculate_daylight_percentile($winter['daylength_h'], $lat, $lon, $year, $utc_offset);

        $this->assertPercentile(0.0, $percentile, 'Rome winter solstice = 0th percentile', 1.0);
    }

    public function testWinterSolsticePercentileNewYork(): void
    {
        $ny_winter = $this->calculateSunTimes(2026, 12, 21, 40.7128, -74.006, -5);
        $ny_percentile = calculate_daylight_percentile($ny_winter['daylength_h'], 40.7128, -74.006, 2026, -5);

        $this->assertPercentile(0.0, $ny_percentile, 'NYC winter solstice = 0th percentile', 1.0);
    }

    public function testWinterSolsticePercentileTokyo(): void
    {
        $tokyo_winter = $this->calculateSunTimes(2026, 12, 21, 35.6762, 139.6503, 9);
        $tokyo_percentile = calculate_daylight_percentile($tokyo_winter['daylength_h'], 35.6762, 139.6503, 2026, 9);

        $this->assertPercentile(0.0, $tokyo_percentile, 'Tokyo winter solstice = 0th percentile', 1.0);
    }

    public function testSummerSolsticePercentile(): void
    {
        $lat = 41.9028;
        $lon = 12.4964;
        $utc_offset = 1;
        $year = 2026;

        $summer = $this->calculateSunTimes($year, 6, 21, $lat, $lon, $utc_offset);
        $percentile = calculate_daylight_percentile($summer['daylength_h'], $lat, $lon, $year, $utc_offset);

        // Should be very close to 100th percentile
        $this->assertPercentile(100.0, $percentile, 'Rome summer solstice ~100th percentile', 2.0);

        // Test that it's the highest percentile in the year
        $this->assertGreaterThan(95.0, $percentile, 'Summer solstice > 95th percentile');
    }

    public function testSpringEquinoxPercentile(): void
    {
        $lat = 41.9028;
        $lon = 12.4964;
        $utc_offset = 1;
        $year = 2026;

        $equinox = $this->calculateSunTimes($year, 3, 20, $lat, $lon, $utc_offset);
        $percentile = calculate_daylight_percentile($equinox['daylength_h'], $lat, $lon, $year, $utc_offset);

        // Spring equinox should be near 50th percentile
        $this->assertPercentile(50.0, $percentile, 'Spring equinox near 50th percentile', 10.0);
        $this->assertInRange($percentile, 40.0, 60.0, 'Spring equinox between 40-60%');
    }

    public function testAutumnEquinoxPercentile(): void
    {
        $lat = 41.9028;
        $lon = 12.4964;
        $utc_offset = 1;
        $year = 2026;

        $equinox = $this->calculateSunTimes($year, 9, 22, $lat, $lon, $utc_offset);
        $percentile = calculate_daylight_percentile($equinox['daylength_h'], $lat, $lon, $year, $utc_offset);

        // Autumn equinox should also be near 50th percentile
        $this->assertPercentile(50.0, $percentile, 'Autumn equinox near 50th percentile', 10.0);
        $this->assertInRange($percentile, 40.0, 60.0, 'Autumn equinox between 40-60%');
    }

    public function testJanuaryPercentiles(): void
    {
        $lat = 41.9028;
        $lon = 12.4964;
        $utc_offset = 1;
        $year = 2026;

        // Early January (just after winter solstice)
        $jan5 = $this->calculateSunTimes($year, 1, 5, $lat, $lon, $utc_offset);
        $jan5_pct = calculate_daylight_percentile($jan5['daylength_h'], $lat, $lon, $year, $utc_offset);
        $this->assertInRange($jan5_pct, 0.0, 10.0, 'Jan 5 percentile 0-10%');

        // Mid January
        $jan15 = $this->calculateSunTimes($year, 1, 15, $lat, $lon, $utc_offset);
        $jan15_pct = calculate_daylight_percentile($jan15['daylength_h'], $lat, $lon, $year, $utc_offset);
        $this->assertInRange($jan15_pct, 5.0, 20.0, 'Jan 15 percentile 5-20%');

        // Late January (verified value from original test)
        $jan29 = $this->calculateSunTimes($year, 1, 29, $lat, $lon, $utc_offset);
        $jan29_pct = calculate_daylight_percentile($jan29['daylength_h'], $lat, $lon, $year, $utc_offset);
        $this->assertPercentile(20.8, $jan29_pct, 'Jan 29 percentile ~21%', 2.0);
    }

    public function testJulyPercentiles(): void
    {
        $lat = 41.9028;
        $lon = 12.4964;
        $utc_offset = 1;
        $year = 2026;

        // Early July
        $jul5 = $this->calculateSunTimes($year, 7, 5, $lat, $lon, $utc_offset);
        $jul5_pct = calculate_daylight_percentile($jul5['daylength_h'], $lat, $lon, $year, $utc_offset);
        $this->assertInRange($jul5_pct, 90.0, 100.0, 'Jul 5 percentile 90-100%');

        // Mid July
        $jul15 = $this->calculateSunTimes($year, 7, 15, $lat, $lon, $utc_offset);
        $jul15_pct = calculate_daylight_percentile($jul15['daylength_h'], $lat, $lon, $year, $utc_offset);
        $this->assertPercentile(92.0, $jul15_pct, 'Jul 15 percentile ~92%', 7.0);

        // Late July (day length declining from peak)
        $jul30 = $this->calculateSunTimes($year, 7, 30, $lat, $lon, $utc_offset);
        $jul30_pct = calculate_daylight_percentile($jul30['daylength_h'], $lat, $lon, $year, $utc_offset);
        $this->assertInRange($jul30_pct, 75.0, 95.0, 'Jul 30 percentile 75-95%');
    }

    public function testMonotonicDayLengthIncreaseJanuaryToJune(): void
    {
        $lat = 41.9028;
        $lon = 12.4964;
        $utc_offset = 1;
        $year = 2026;

        $prev_length = 0;

        for ($month = 1; $month <= 6; $month++) {
            $calc = $this->calculateSunTimes($year, $month, 15, $lat, $lon, $utc_offset);

            $this->assertGreaterThanOrEqual(
                $prev_length,
                $calc['daylength_h'],
                "Month $month day length should be >= previous month"
            );

            $prev_length = $calc['daylength_h'];
        }
    }

    public function testMonotonicDayLengthDecreaseJulyToDecember(): void
    {
        $lat = 41.9028;
        $lon = 12.4964;
        $utc_offset = 1;
        $year = 2026;

        $prev_length = 100; // Start high

        for ($month = 7; $month <= 12; $month++) {
            $calc = $this->calculateSunTimes($year, $month, 15, $lat, $lon, $utc_offset);

            $this->assertLessThanOrEqual(
                $prev_length,
                $calc['daylength_h'],
                "Month $month day length should be <= previous month"
            );

            $prev_length = $calc['daylength_h'];
        }
    }

    public function testPercentileRangeValidation(): void
    {
        $lat = 41.9028;
        $lon = 12.4964;
        $utc_offset = 1;
        $year = 2026;

        $test_dates = [
            [1, 1], [1, 15], [2, 1], [2, 14], [3, 1], [3, 15], [3, 20],
            [4, 1], [4, 15], [5, 1], [5, 15], [6, 1], [6, 15], [6, 21],
            [7, 1], [7, 15], [8, 1], [8, 15], [9, 1], [9, 15], [9, 22],
            [10, 1], [10, 15], [11, 1], [11, 15], [12, 1], [12, 15], [12, 21],
        ];

        foreach ($test_dates as $date) {
            $calc = $this->calculateSunTimes($year, $date[0], $date[1], $lat, $lon, $utc_offset);
            $perc = calculate_daylight_percentile($calc['daylength_h'], $lat, $lon, $year, $utc_offset);

            $this->assertInRange(
                $perc,
                0.0,
                100.0,
                sprintf('Date %02d-%02d percentile should be 0-100%%', $date[0], $date[1])
            );
        }
    }

    public function testPercentileCalculationConsistency(): void
    {
        $lat = 41.9028;
        $lon = 12.4964;
        $utc_offset = 1;
        $year = 2026;

        // Calculate same day multiple times
        $calc = $this->calculateSunTimes($year, 3, 20, $lat, $lon, $utc_offset);

        $perc1 = calculate_daylight_percentile($calc['daylength_h'], $lat, $lon, $year, $utc_offset);
        $perc2 = calculate_daylight_percentile($calc['daylength_h'], $lat, $lon, $year, $utc_offset);
        $perc3 = calculate_daylight_percentile($calc['daylength_h'], $lat, $lon, $year, $utc_offset);

        $this->assertFloatEquals($perc1, $perc2, 'Percentile calculation is deterministic (run 1 = run 2)', 0.0);
        $this->assertFloatEquals($perc2, $perc3, 'Percentile calculation is deterministic (run 2 = run 3)', 0.0);
    }

    public function testPercentileCountAccuracy(): void
    {
        $lat = 41.9028;
        $lon = 12.4964;
        $utc_offset = 1;
        $year = 2026;

        // Test April 15
        $test_calc = $this->calculateSunTimes($year, 4, 15, $lat, $lon, $utc_offset);
        $test_perc = calculate_daylight_percentile($test_calc['daylength_h'], $lat, $lon, $year, $utc_offset);

        // Expected count = percentile * 365 / 100
        $expected_count = round($test_perc * 365 / 100);

        // Manually count days with less daylight
        $actual_count = 0;
        for ($day = 1; $day <= 365; $day++) {
            $date = new \DateTime("$year-01-01");
            $date->modify('+' . ($day - 1) . ' days');
            $day_calc = $this->calculateSunTimes(
                (int) $date->format('Y'),
                (int) $date->format('m'),
                (int) $date->format('d'),
                $lat,
                $lon,
                $utc_offset
            );

            if ($day_calc['daylength_h'] < $test_calc['daylength_h']) {
                $actual_count++;
            }
        }

        // Allow ±1 day tolerance
        $this->assertLessThanOrEqual(1, abs($expected_count - $actual_count), 'Percentile count should be accurate within ±1 day');
    }

    public function testLeapYearFebruary29Percentile(): void
    {
        $lat = 41.9028;
        $lon = 12.4964;
        $utc_offset = 1;

        // Test Feb 29 in leap year 2024
        $leap_calc = $this->calculateSunTimes(2024, 2, 29, $lat, $lon, $utc_offset);
        $leap_perc = calculate_daylight_percentile($leap_calc['daylength_h'], $lat, $lon, 2024, $utc_offset);

        $this->assertInRange($leap_perc, 0.0, 100.0, 'Feb 29 2024 has valid percentile');
        $this->assertInRange($leap_perc, 30.0, 45.0, 'Feb 29 percentile reasonable (~35-40%)');
    }

    public function testLeapYearWinterSolsticeStillZero(): void
    {
        $lat = 41.9028;
        $lon = 12.4964;
        $utc_offset = 1;

        // Test that leap year has 366 days in calculation
        // Winter solstice should still be 0th percentile
        $leap_winter = $this->calculateSunTimes(2024, 12, 21, $lat, $lon, $utc_offset);
        $leap_winter_perc = calculate_daylight_percentile($leap_winter['daylength_h'], $lat, $lon, 2024, $utc_offset);

        $this->assertPercentile(0.0, $leap_winter_perc, 'Leap year winter solstice = 0th percentile', 1.0);
    }

    public function testArcticPercentiles(): void
    {
        $lat = 70.0;
        $lon = 10.0;
        $utc_offset = 2;
        $year = 2026;

        // Winter solstice (polar night)
        $winter = $this->calculateSunTimes($year, 12, 21, $lat, $lon, $utc_offset);
        $winter_perc = calculate_daylight_percentile($winter['daylength_h'], $lat, $lon, $year, $utc_offset);
        $this->assertPercentile(0.0, $winter_perc, 'Arctic winter solstice = 0th percentile', 2.0);

        // Summer solstice (midnight sun) - At extreme latitudes, midnight sun affects percentile
        $summer = $this->calculateSunTimes($year, 6, 21, $lat, $lon, $utc_offset);
        $summer_perc = calculate_daylight_percentile($summer['daylength_h'], $lat, $lon, $year, $utc_offset);
        $this->assertPercentile(100.0, $summer_perc, 'Arctic summer solstice ~100th percentile', 25.0);

        // Equinox (should still be near 50%)
        $equinox = $this->calculateSunTimes($year, 3, 20, $lat, $lon, $utc_offset);
        $equinox_perc = calculate_daylight_percentile($equinox['daylength_h'], $lat, $lon, $year, $utc_offset);
        $this->assertInRange($equinox_perc, 40.0, 60.0, 'Arctic equinox near 50%');
    }

    public function testEquatorPercentiles(): void
    {
        $lat = 0.0;
        $lon = 0.0;
        $utc_offset = 0;
        $year = 2026;

        // At equator, day length varies very little
        $jan = $this->calculateSunTimes($year, 1, 15, $lat, $lon, $utc_offset);
        $jul = $this->calculateSunTimes($year, 7, 15, $lat, $lon, $utc_offset);

        $jan_perc = calculate_daylight_percentile($jan['daylength_h'], $lat, $lon, $year, $utc_offset);
        $jul_perc = calculate_daylight_percentile($jul['daylength_h'], $lat, $lon, $year, $utc_offset);

        // Percentiles should still vary across the year, even if day length doesn't much
        $this->assertInRange($jan_perc, 0.0, 100.0, 'January percentile at equator valid');
        $this->assertInRange($jul_perc, 0.0, 100.0, 'July percentile at equator valid');

        // Day lengths should be very similar
        $length_diff = abs($jan['daylength_h'] - $jul['daylength_h']);
        $this->assertLessThan(0.5, $length_diff, 'Equator day length varies < 30 min year-round');
    }

    public function testSouthernHemispherePercentiles(): void
    {
        $lat = -33.8688;
        $lon = 151.2093;
        $utc_offset = 10;
        $year = 2026;

        // June (winter) should be low percentile
        $june = $this->calculateSunTimes($year, 6, 21, $lat, $lon, $utc_offset);
        $june_perc = calculate_daylight_percentile($june['daylength_h'], $lat, $lon, $year, $utc_offset);
        $this->assertPercentile(0.0, $june_perc, 'Sydney winter solstice (June) = 0th percentile', 2.0);

        // December (summer) should be high percentile
        $december = $this->calculateSunTimes($year, 12, 21, $lat, $lon, $utc_offset);
        $december_perc = calculate_daylight_percentile($december['daylength_h'], $lat, $lon, $year, $utc_offset);
        $this->assertPercentile(100.0, $december_perc, 'Sydney summer solstice (Dec) ~100th percentile', 2.0);

        // Opposite of northern hemisphere
        $this->assertLessThan($december_perc, $june_perc, 'June percentile < December in southern hemisphere');
    }

    public function testPercentileSymmetryAroundEquinoxes(): void
    {
        $lat = 45.0;
        $lon = 0.0;
        $utc_offset = 0;
        $year = 2026;

        // Days equidistant from spring equinox should have similar percentiles
        $before_equinox = $this->calculateSunTimes($year, 3, 10, $lat, $lon, $utc_offset);
        $after_equinox = $this->calculateSunTimes($year, 3, 30, $lat, $lon, $utc_offset);

        $before_perc = calculate_daylight_percentile($before_equinox['daylength_h'], $lat, $lon, $year, $utc_offset);
        $after_perc = calculate_daylight_percentile($after_equinox['daylength_h'], $lat, $lon, $year, $utc_offset);

        // Percentiles should be on opposite sides of 50%
        $this->assertLessThan(50.0, $before_perc, '10 days before equinox < 50%');
        $this->assertGreaterThan(50.0, $after_perc, '10 days after equinox > 50%');

        // The differences from 50% should be roughly equal
        $before_diff = abs(50.0 - $before_perc);
        $after_diff = abs(50.0 - $after_perc);
        $this->assertInRange($before_diff - $after_diff, -10.0, 10.0, 'Symmetry around equinox within 10%');
    }

    public function testEdgeCaseJanuary1Percentile(): void
    {
        $lat = 41.9028;
        $lon = 12.4964;
        $utc_offset = 1;
        $year = 2026;

        // Test January 1 (very early in year, low percentile)
        $jan1 = $this->calculateSunTimes($year, 1, 1, $lat, $lon, $utc_offset);
        $jan1_perc = calculate_daylight_percentile($jan1['daylength_h'], $lat, $lon, $year, $utc_offset);
        $this->assertInRange($jan1_perc, 0.0, 6.0, 'Jan 1 percentile very low (0-6%)');
    }

    public function testEdgeCaseDecember31Percentile(): void
    {
        $lat = 41.9028;
        $lon = 12.4964;
        $utc_offset = 1;
        $year = 2026;

        // Test December 31 (end of year, should be low again)
        $dec31 = $this->calculateSunTimes($year, 12, 31, $lat, $lon, $utc_offset);
        $dec31_perc = calculate_daylight_percentile($dec31['daylength_h'], $lat, $lon, $year, $utc_offset);
        $this->assertInRange($dec31_perc, 0.0, 10.0, 'Dec 31 percentile low (0-10%)');
    }

    public function testEdgeCaseSolsticePlusMinus1Day(): void
    {
        $lat = 41.9028;
        $lon = 12.4964;
        $utc_offset = 1;
        $year = 2026;

        // Test exact solstice +/- 1 day (should be very close)
        $sol_minus1 = $this->calculateSunTimes($year, 6, 20, $lat, $lon, $utc_offset);
        $sol_exact = $this->calculateSunTimes($year, 6, 21, $lat, $lon, $utc_offset);
        $sol_plus1 = $this->calculateSunTimes($year, 6, 22, $lat, $lon, $utc_offset);

        $perc_minus1 = calculate_daylight_percentile($sol_minus1['daylength_h'], $lat, $lon, $year, $utc_offset);
        $perc_exact = calculate_daylight_percentile($sol_exact['daylength_h'], $lat, $lon, $year, $utc_offset);
        $perc_plus1 = calculate_daylight_percentile($sol_plus1['daylength_h'], $lat, $lon, $year, $utc_offset);

        // All three should be very high percentiles
        $this->assertGreaterThan(95.0, $perc_minus1, 'June 20 > 95th percentile');
        $this->assertGreaterThan(95.0, $perc_exact, 'June 21 > 95th percentile');
        $this->assertGreaterThan(95.0, $perc_plus1, 'June 22 > 95th percentile');
    }
}
