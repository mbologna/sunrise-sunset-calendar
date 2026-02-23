<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the full calendar generation flow.
 *
 * These tests exercise the actual code paths used in production to catch
 * type errors, missing functions, and runtime issues before deployment.
 */
class CalendarGenerationTest extends TestCase
{
    /**
     * Test that get_special_astronomical_events works with integer year.
     */
    public function testGetSpecialAstronomicalEventsWithIntYear(): void
    {
        $year = 2026;
        $events = get_special_astronomical_events($year);

        $this->assertIsArray($events);
        $this->assertCount(4, $events);

        foreach ($events as $event) {
            $this->assertArrayHasKey('date', $event);
            $this->assertArrayHasKey('name', $event);
            $this->assertArrayHasKey('emoji', $event);
            $this->assertArrayHasKey('description', $event);
        }
    }

    /**
     * Test that year extracted from date() is properly cast to int.
     * This simulates the calendar-generator.php flow.
     */
    public function testYearFromDateFunctionMustBeCastToInt(): void
    {
        $start = strtotime('today');
        $year = (int) date('Y', $start);

        $this->assertIsInt($year);

        // This should not throw a TypeError
        $events = get_special_astronomical_events($year);
        $this->assertIsArray($events);
    }

    /**
     * Test full calendar generation data flow.
     * Simulates the main loop in calendar-generator.php.
     */
    public function testCalendarGenerationDataFlow(): void
    {
        $lat = 45.68;
        $lon = 9.55;
        $utc_offset = 1.0;

        $start = strtotime('today');
        $year = (int) date('Y', $start);

        // Test special events
        $special_events = get_special_astronomical_events($year);
        $this->assertCount(4, $special_events);

        // Test location notes
        $location_notes = get_location_notes($lat);
        $this->assertIsArray($location_notes);

        // Test sun calculations
        $date_parts = getdate($start);
        $sun_calc = calculate_sun_times(
            $date_parts['year'],
            $date_parts['mon'],
            $date_parts['mday'],
            $lat,
            $lon,
            $utc_offset
        );

        $this->assertArrayHasKey('sunrise_frac', $sun_calc);
        $this->assertArrayHasKey('sunset_frac', $sun_calc);
        $this->assertArrayHasKey('daylength_h', $sun_calc);

        // Test percentile calculation
        $daylight_hours = $sun_calc['daylength_h'];
        $percentile = calculate_daylight_percentile($daylight_hours, $lat, $lon, $year, $utc_offset);
        $this->assertGreaterThanOrEqual(0, $percentile);
        $this->assertLessThanOrEqual(100, $percentile);

        // Test moon phase
        $moon_info = get_moon_phase_info($start);
        $this->assertArrayHasKey('phase_name', $moon_info);
        $this->assertArrayHasKey('illumination', $moon_info);

        // Test day of year
        $day_info = get_day_of_year($start);
        $this->assertArrayHasKey('day', $day_info);
        $this->assertArrayHasKey('total', $day_info);
        $this->assertArrayHasKey('formatted', $day_info);

        // Test formatting functions
        $separator = format_separator();
        $this->assertIsString($separator);
        $this->assertNotEmpty($separator);

        $progress_bar = format_percentile_bar($percentile);
        $this->assertIsString($progress_bar);
        $this->assertStringContainsString('%', $progress_bar);

        $duration = format_duration_full(36000);
        $this->assertStringContainsString('hour', $duration);

        $moon_emoji = get_moon_phase_emoji($moon_info['phase_name']);
        $this->assertIsString($moon_emoji);
        $this->assertNotEmpty($moon_emoji);
    }

    /**
     * Test week summary data flow.
     */
    public function testWeekSummaryDataFlow(): void
    {
        $lat = 45.68;
        $lon = 9.55;
        $utc_offset = 1.0;

        // Find next Sunday
        $current = strtotime('today');
        while (date('w', $current) != 0) {
            $current = strtotime('+1 day', $current);
        }

        $strings = get_strings();
        $week_data = get_cached_week_summary($current, $lat, $lon, $utc_offset, $strings);

        $this->assertIsArray($week_data);
        $this->assertArrayHasKey('avg_length', $week_data);
        $this->assertArrayHasKey('trend', $week_data);
        $this->assertArrayHasKey('moon_phase', $week_data);

        // Test last year comparison
        $last_year_data = get_last_year_week_data($current, $lat, $lon, $utc_offset);
        $this->assertIsArray($last_year_data);
        $this->assertArrayHasKey('avg_length', $last_year_data);
        $this->assertArrayHasKey('week_start', $last_year_data);
    }

    /**
     * Test that all helper functions work with strict types.
     */
    public function testHelperFunctionsWithStrictTypes(): void
    {
        // Test format_day_length_comparison with int
        $result = format_day_length_comparison(150, 'day');
        $this->assertIsString($result);

        $result = format_day_length_comparison(-105, 'night');
        $this->assertIsString($result);

        $result = format_day_length_comparison(0, 'day');
        $this->assertIsString($result);
    }

    /**
     * Test solstice dates calculation.
     */
    public function testSolsticeDatesCalculation(): void
    {
        $year = 2026;
        $solstice_dates = get_solstice_dates($year);

        $this->assertArrayHasKey('march_equinox', $solstice_dates);
        $this->assertArrayHasKey('june_solstice', $solstice_dates);
        $this->assertArrayHasKey('sept_equinox', $solstice_dates);
        $this->assertArrayHasKey('dec_solstice', $solstice_dates);

        // All should be valid timestamps
        foreach ($solstice_dates as $name => $timestamp) {
            $this->assertIsInt($timestamp, "$name should be an integer timestamp");
            $this->assertGreaterThan(0, $timestamp, "$name should be a positive timestamp");
        }
    }

    /**
     * Test the dawn/dusk supplemental builders.
     */
    public function testSupplementalBuilders(): void
    {
        $strings = get_strings();
        $enabled = [
            'civil' => false,
            'nautical' => false,
            'astro' => false,
            'daylight' => true,
        ];

        $result = build_dawn_supplemental(
            1707400000, // sunrise
            1707440000, // sunset
            1707420000, // solar_noon
            1707398000, // civil_begin
            1707442000, // civil_end
            1707396000, // nautical_begin
            1707444000, // nautical_end
            1707394000, // astro_begin
            1707446000, // astro_end
            'H:i',
            $enabled,
            40000, // daylight_seconds
            46.3, // daylight_pct
            65.0, // daylight_percentile
            '+2m 30s', // day_length_comparison
            '1h 30m', // winter_comparison
            '-3h 15m', // summer_comparison
            '12:30', // solar_noon_time
            'Dec 21, 12:00', // winter_solstice_info
            'Jun 21, 12:00', // summer_solstice_info
            5400, // diff_from_winter
            -11700, // diff_from_summer
            'daylight', // current_event
            $strings
        );

        $this->assertIsString($result);
    }
}
