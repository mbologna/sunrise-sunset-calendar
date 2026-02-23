<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/meeus-astronomy.php';
require_once __DIR__ . '/../Fixtures/AstronomicalReferenceData.php';

/**
 * Moon Phase Accuracy Tests.
 *
 * Tests the Meeus Chapter 49 algorithm implementation against authoritative
 * reference data from timeanddate.com and USNO.
 *
 * Requirement: ±3 minutes accuracy (180 seconds tolerance)
 * Note: Moon phase calculations are inherently less precise than solar due to
 * lunar orbital perturbations. Meeus algorithm achieves ±2-3 min typical accuracy.
 */
class MoonPhaseAccuracyTest extends TestCase
{
    /**
     * Test accuracy for all 2026 moon phases.
     *
     * @dataProvider moonPhase2026Provider
     */
    public function testMoonPhase2026Accuracy($expected_phase, $expected_datestring)
    {
        // Parse expected date
        $expected_timestamp = AstronomicalReferenceData::dateToTimestamp($expected_datestring);
        $date_parts = getdate($expected_timestamp);

        // Calculate phases for this month
        $phases = calculate_moon_phases_for_month($date_parts['year'], $date_parts['mon']);

        // Find the phase closest to expected
        $closest_phase = null;
        $min_difference = PHP_INT_MAX;

        foreach ($phases as $phase) {
            $phase_name_normalized = AstronomicalReferenceData::normalizePhaseName($phase['phase_name']);
            $expected_normalized = AstronomicalReferenceData::normalizePhaseName($expected_phase);

            if ($phase_name_normalized === $expected_normalized) {
                $difference = abs($phase['timestamp'] - $expected_timestamp);
                if ($difference < $min_difference) {
                    $min_difference = $difference;
                    $closest_phase = $phase;
                }
            }
        }

        $this->assertNotNull(
            $closest_phase,
            sprintf(
                'Could not find calculated %s near %s',
                $expected_phase,
                $expected_datestring
            )
        );

        // Assert accuracy within ±180 seconds (3 minutes)
        $this->assertLessThanOrEqual(
            180,
            $min_difference,
            sprintf(
                "%s: Expected %s UTC, got %s UTC (difference: %d seconds)\n" .
                "Reference: timeanddate.com\n" .
                'Algorithm: Meeus Chapter 49',
                $expected_phase,
                date('Y-m-d H:i:s', $expected_timestamp),
                date('Y-m-d H:i:s', $closest_phase['timestamp']),
                $min_difference
            )
        );
    }

    /**
     * Provide all 2026 moon phase test cases.
     */
    public function moonPhase2026Provider()
    {
        $phases = AstronomicalReferenceData::getMoonPhases2026();
        $test_cases = [];

        foreach ($phases as $phase_data) {
            $test_cases[] = [$phase_data['phase'], $phase_data['date']];
        }

        return $test_cases;
    }

    /**
     * Test February 2026 phases specifically (user's test case)
     * User reported incorrect phases, let's verify our implementation is correct.
     */
    public function testFebruary2026Phases()
    {
        // Old code showed:
        // - Feb 1: Full Moon 10:12 (WRONG!)
        // - Feb 8: Last Quarter 19:22 (WRONG!)
        //
        // Actual (per timeanddate.com):
        // - Feb 1: Full Moon 22:09
        // - Feb 9: Last Quarter 12:43
        // - Feb 17: New Moon 12:01
        // - Feb 24: First Quarter 12:27

        $phases = calculate_moon_phases_for_month(2026, 2);

        // Find each major phase
        $full_moon = null;
        $last_quarter = null;
        $new_moon = null;
        $first_quarter = null;

        foreach ($phases as $phase) {
            $ts = $phase['timestamp'];
            $day = (int) date('j', $ts);

            // Filter to February phases only
            if (date('n', $ts) != 2) {
                continue;
            }

            if ($phase['phase_name'] === 'Full Moon' && $day === 1) {
                $full_moon = $phase;
            } elseif ($phase['phase_name'] === 'Last Quarter' && $day === 9) {
                $last_quarter = $phase;
            } elseif ($phase['phase_name'] === 'New Moon' && $day === 17) {
                $new_moon = $phase;
            } elseif ($phase['phase_name'] === 'First Quarter' && $day === 24) {
                $first_quarter = $phase;
            }
        }

        // Test Full Moon: Feb 1, 22:09 UTC
        $this->assertNotNull($full_moon, 'Should find Full Moon on Feb 1');
        $expected_full = strtotime('2026-02-01 22:09:00 UTC');
        $this->assertEqualsWithDelta(
            $expected_full,
            $full_moon['timestamp'],
            180,
            sprintf(
                "Full Moon Feb 1 should be 22:09 UTC, not 10:12!\n" .
                'Calculated: %s UTC',
                date('Y-m-d H:i', $full_moon['timestamp'])
            )
        );

        // Test Last Quarter: Feb 9, 12:43 UTC
        $this->assertNotNull($last_quarter, 'Should find Last Quarter on Feb 9');
        $expected_last = strtotime('2026-02-09 12:43:00 UTC');
        $this->assertEqualsWithDelta(
            $expected_last,
            $last_quarter['timestamp'],
            180,
            sprintf(
                "Last Quarter Feb 9 should be 12:43 UTC\n" .
                'Calculated: %s UTC',
                date('Y-m-d H:i', $last_quarter['timestamp'])
            )
        );

        // Test New Moon: Feb 17, 12:01 UTC
        $this->assertNotNull($new_moon, 'Should find New Moon on Feb 17');
        $expected_new = strtotime('2026-02-17 12:01:00 UTC');
        $this->assertEqualsWithDelta(
            $expected_new,
            $new_moon['timestamp'],
            180,
            sprintf(
                "New Moon Feb 17 should be 12:01 UTC\n" .
                'Calculated: %s UTC',
                date('Y-m-d H:i', $new_moon['timestamp'])
            )
        );

        // Test First Quarter: Feb 24, 12:27 UTC
        $this->assertNotNull($first_quarter, 'Should find First Quarter on Feb 24');
        $expected_first = strtotime('2026-02-24 12:27:00 UTC');
        $this->assertEqualsWithDelta(
            $expected_first,
            $first_quarter['timestamp'],
            180,
            sprintf(
                "First Quarter Feb 24 should be 12:27 UTC\n" .
                'Calculated: %s UTC',
                date('Y-m-d H:i', $first_quarter['timestamp'])
            )
        );
    }

    /**
     * Test get_accurate_moon_phase() function for a specific date.
     */
    public function testGetAccurateMoonPhaseForFebruary1_2026()
    {
        // February 1, 2026 at 22:09 UTC is Full Moon
        // Test a timestamp just before the full moon
        $test_timestamp = strtotime('2026-02-01 20:00:00 UTC');

        $moon_info = get_accurate_moon_phase($test_timestamp);

        // Should detect it's approaching Full Moon
        $this->assertNotNull($moon_info);
        $this->assertNotNull($moon_info['next_phase']);

        // Next phase should be Full Moon on Feb 1
        $this->assertEquals('Full Moon', $moon_info['next_phase']['name']);

        $next_ts = $moon_info['next_phase']['timestamp'];
        $expected = strtotime('2026-02-01 22:09:00 UTC');

        $this->assertEqualsWithDelta(
            $expected,
            $next_ts,
            180,
            'Next phase should be Full Moon at 22:09 UTC'
        );
    }

    /**
     * Test that prev/next phase detection works correctly.
     */
    public function testPrevNextPhaseDetection()
    {
        // Test at a time between two phases
        // February 10, 2026 - between Last Quarter (Feb 9) and New Moon (Feb 17)
        $test_timestamp = strtotime('2026-02-10 12:00:00 UTC');

        $moon_info = get_accurate_moon_phase($test_timestamp);

        // Previous should be Last Quarter on Feb 9
        $this->assertNotNull($moon_info['prev_phase']);
        $this->assertEquals('Last Quarter', $moon_info['prev_phase']['name']);

        $prev_expected = strtotime('2026-02-09 12:43:00 UTC');
        $this->assertEqualsWithDelta(
            $prev_expected,
            $moon_info['prev_phase']['timestamp'],
            120
        );

        // Next should be New Moon on Feb 17
        $this->assertNotNull($moon_info['next_phase']);
        $this->assertEquals('New Moon', $moon_info['next_phase']['name']);

        $next_expected = strtotime('2026-02-17 12:01:00 UTC');
        $this->assertEqualsWithDelta(
            $next_expected,
            $moon_info['next_phase']['timestamp'],
            120
        );
    }

    /**
     * Test illumination percentage calculations.
     */
    public function testIlluminationPercentage()
    {
        // At New Moon, illumination should be ~0%
        $new_moon = strtotime('2026-02-17 12:01:00 UTC');
        $moon_info = get_accurate_moon_phase($new_moon);
        $this->assertLessThan(10, $moon_info['illumination'], 'New Moon should be <10% illuminated');

        // At Full Moon, illumination should be ~100%
        $full_moon = strtotime('2026-02-01 22:09:00 UTC');
        $moon_info = get_accurate_moon_phase($full_moon);
        $this->assertGreaterThan(90, $moon_info['illumination'], 'Full Moon should be >90% illuminated');

        // At First/Last Quarter, illumination should be ~50%
        $first_quarter = strtotime('2026-02-24 12:27:00 UTC');
        $moon_info = get_accurate_moon_phase($first_quarter);
        $this->assertEqualsWithDelta(50, $moon_info['illumination'], 15, 'Quarter should be ~50% illuminated');
    }

    /**
     * Test all 2026 phases meet accuracy requirement.
     */
    public function testAll2026PhasesWithinTolerance()
    {
        $reference_phases = AstronomicalReferenceData::getMoonPhases2026();
        $max_error = 0;
        $worst_case = '';
        $tested_count = 0;

        foreach ($reference_phases as $ref) {
            $expected_timestamp = AstronomicalReferenceData::dateToTimestamp($ref['date']);
            $date_parts = getdate($expected_timestamp);

            $calculated_phases = calculate_moon_phases_for_month($date_parts['year'], $date_parts['mon']);

            // Find matching phase
            $found = false;
            foreach ($calculated_phases as $calc) {
                $phase_match = AstronomicalReferenceData::normalizePhaseName($calc['phase_name'])
                    === AstronomicalReferenceData::normalizePhaseName($ref['phase']);

                if ($phase_match) {
                    $difference = abs($calc['timestamp'] - $expected_timestamp);

                    if ($difference < 7200) { // Within 2 hours (to find correct instance)
                        $tested_count++;
                        $found = true;

                        if ($difference > $max_error) {
                            $max_error = $difference;
                            $worst_case = sprintf(
                                '%s on %s: %d seconds off',
                                $ref['phase'],
                                $ref['date'],
                                $difference
                            );
                        }
                        break;
                    }
                }
            }

            $this->assertTrue($found, "Should find calculated phase for {$ref['phase']} on {$ref['date']}");
        }

        $this->assertGreaterThan(40, $tested_count, 'Should test all moon phases for 2026');

        $this->assertLessThanOrEqual(
            180,
            $max_error,
            sprintf(
                "Maximum error across all 2026 phases: %d seconds\n" .
                "Worst case: %s\n" .
                'Requirement: ±180 seconds (3 minutes)',
                $max_error,
                $worst_case
            )
        );
    }

    /**
     * Test month boundary handling.
     */
    public function testMonthBoundaryPhases()
    {
        // Test phases that occur near month boundaries
        // December 30, 2026: Last Quarter at 18:59 UTC (updated from timeanddate.com)

        $phases = calculate_moon_phases_for_month(2026, 12);

        $found_dec30 = false;
        foreach ($phases as $phase) {
            if ($phase['phase_name'] === 'Last Quarter') {
                $day = (int) date('j', $phase['timestamp']);
                $month = (int) date('n', $phase['timestamp']);

                if ($month === 12 && $day === 30) {
                    $found_dec30 = true;

                    $expected = strtotime('2026-12-30 18:59:00 UTC');
                    $this->assertEqualsWithDelta(
                        $expected,
                        $phase['timestamp'],
                        180,
                        'Should correctly handle phase near end of year'
                    );
                }
            }
        }

        $this->assertTrue($found_dec30, 'Should find Last Quarter on Dec 30, 2026');
    }

    /**
     * Test that phases are in chronological order.
     */
    public function testPhasesChronologicalOrder()
    {
        $phases = calculate_moon_phases_for_month(2026, 2);

        $prev_timestamp = 0;
        foreach ($phases as $phase) {
            $this->assertGreaterThan(
                $prev_timestamp,
                $phase['timestamp'],
                'Phases should be in chronological order'
            );
            $prev_timestamp = $phase['timestamp'];
        }
    }

    /**
     * Test synodic month length variation.
     */
    public function testSynodicMonthVariation()
    {
        // Calculate several consecutive New Moons
        $new_moons = [];

        for ($month = 1; $month <= 6; $month++) {
            $phases = calculate_moon_phases_for_month(2026, $month);

            foreach ($phases as $phase) {
                if ($phase['phase_name'] === 'New Moon' && date('n', $phase['timestamp']) == $month) {
                    $new_moons[] = $phase['timestamp'];
                    break;
                }
            }
        }

        // Calculate synodic month lengths
        $synodic_lengths = [];
        for ($i = 1; $i < count($new_moons); $i++) {
            $length_days = ($new_moons[$i] - $new_moons[$i - 1]) / 86400;
            $synodic_lengths[] = $length_days;
        }

        // Synodic month varies from 29.18 to 29.93 days
        // Check that our calculations show this variation (not fixed 29.53)
        $min_length = min($synodic_lengths);
        $max_length = max($synodic_lengths);

        $this->assertGreaterThan(
            29.0,
            $min_length,
            'Minimum synodic month should be > 29 days'
        );

        $this->assertLessThan(
            30.0,
            $max_length,
            'Maximum synodic month should be < 30 days'
        );

        // There should be variation (not all the same)
        $range = $max_length - $min_length;
        $this->assertGreaterThan(
            0.1,
            $range,
            'Synodic month length should vary (not fixed 29.53 days)'
        );
    }
}
