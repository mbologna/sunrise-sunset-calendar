<?php

namespace Tests\Reference;

use Tests\BaseTest;
use Tests\Fixtures\ReferenceLocations;

/**
 * Mapello Reference Data Validation Test
 * Validates solar calculations against real-world data for Mapello, Bergamo (Italy)
 * Reference date: February 1, 2026
 * Reference source: timeanddate.com, NOAA Solar Calculator.
 *
 * This test uses actual observed/calculated times from authoritative sources
 * to validate the accuracy of our solar calculation algorithms.
 */
class MapelloReferenceTest extends BaseTest
{
    private const LAT = 45.7;
    private const LON = 9.6;
    private const TIMEZONE_OFFSET = 1;  // CET (UTC+1 in February)
    private const TEST_YEAR = 2026;
    private const TEST_MONTH = 2;
    private const TEST_DAY = 1;

    private array $reference;

    protected function setUp(): void
    {
        parent::setUp();

        // Load reference data from fixtures
        $locationData = ReferenceLocations::mapello();
        $this->reference = $locationData['reference_dates']['2026-02-01'];
    }

    /**
     * Helper: Calculate sun times for test date.
     */
    private function calculateTestSunTimes(): array
    {
        return calculate_sun_times(
            self::TEST_YEAR,
            self::TEST_MONTH,
            self::TEST_DAY,
            self::LAT,
            self::LON,
            self::TIMEZONE_OFFSET
        );
    }

    /**
     * Helper: Convert fraction to timestamp for test date.
     */
    private function convertFraction(float $fraction): int
    {
        return fraction_to_timestamp(
            self::TEST_YEAR,
            self::TEST_MONTH,
            self::TEST_DAY,
            $fraction
        );
    }

    public function testAstronomicalTwilightTimes(): void
    {
        $result = $this->calculateTestSunTimes();

        $astro_begin = $this->convertFraction($result['astro_begin_frac']);
        $astro_end = $this->convertFraction($result['astro_end_frac']);
        $nautical_begin = $this->convertFraction($result['nautical_begin_frac']);
        $nautical_end = $this->convertFraction($result['nautical_end_frac']);

        $this->assertTimeEquals(
            $this->reference['astronomical_dawn_start'],
            $astro_begin,
            'Astro dawn start (night end)',
            60
        );
        $this->assertTimeEquals(
            $this->reference['astronomical_dawn_end'],
            $nautical_begin,
            'Astro dawn end (nautical begins)',
            60
        );
        $this->assertTimeEquals(
            $this->reference['astronomical_dusk_start'],
            $nautical_end,
            'Astro dusk start (nautical ends)',
            60
        );
        $this->assertTimeEquals(
            $this->reference['astronomical_dusk_end'],
            $astro_end,
            'Astro dusk end (night begins)',
            60
        );
    }

    public function testAstronomicalTwilightDuration(): void
    {
        $result = $this->calculateTestSunTimes();

        $astro_begin = $this->convertFraction($result['astro_begin_frac']);
        $astro_end = $this->convertFraction($result['astro_end_frac']);
        $nautical_begin = $this->convertFraction($result['nautical_begin_frac']);
        $nautical_end = $this->convertFraction($result['nautical_end_frac']);

        $total_duration = ($nautical_begin - $astro_begin) + ($astro_end - $nautical_end);

        $this->assertDurationEquals(
            $this->reference['astronomical_twilight_duration_seconds'],
            $total_duration,
            'Total astronomical twilight duration',
            60
        );
    }

    public function testNauticalTwilightTimes(): void
    {
        $result = $this->calculateTestSunTimes();

        $nautical_begin = $this->convertFraction($result['nautical_begin_frac']);
        $nautical_end = $this->convertFraction($result['nautical_end_frac']);
        $civil_begin = $this->convertFraction($result['civil_begin_frac']);
        $civil_end = $this->convertFraction($result['civil_end_frac']);

        $this->assertTimeEquals(
            $this->reference['nautical_dawn_start'],
            $nautical_begin,
            'Nautical dawn start',
            60
        );
        $this->assertTimeEquals(
            $this->reference['nautical_dawn_end'],
            $civil_begin,
            'Nautical dawn end (civil begins)',
            60
        );
        $this->assertTimeEquals(
            $this->reference['nautical_dusk_start'],
            $civil_end,
            'Nautical dusk start (civil ends)',
            60
        );
        $this->assertTimeEquals(
            $this->reference['nautical_dusk_end'],
            $nautical_end,
            'Nautical dusk end',
            60
        );
    }

    public function testNauticalTwilightDuration(): void
    {
        $result = $this->calculateTestSunTimes();

        $nautical_begin = $this->convertFraction($result['nautical_begin_frac']);
        $nautical_end = $this->convertFraction($result['nautical_end_frac']);
        $civil_begin = $this->convertFraction($result['civil_begin_frac']);
        $civil_end = $this->convertFraction($result['civil_end_frac']);

        $total_duration = ($civil_begin - $nautical_begin) + ($nautical_end - $civil_end);

        $this->assertDurationEquals(
            $this->reference['nautical_twilight_duration_seconds'],
            $total_duration,
            'Total nautical twilight duration',
            60
        );
    }

    public function testCivilTwilightTimes(): void
    {
        $result = $this->calculateTestSunTimes();

        $civil_begin = $this->convertFraction($result['civil_begin_frac']);
        $civil_end = $this->convertFraction($result['civil_end_frac']);
        $sunrise = $this->convertFraction($result['sunrise_frac']);
        $sunset = $this->convertFraction($result['sunset_frac']);

        $this->assertTimeEquals(
            $this->reference['civil_dawn_start'],
            $civil_begin,
            'Civil dawn start',
            60
        );
        $this->assertTimeEquals(
            $this->reference['civil_dawn_end'],
            $sunrise,
            'Civil dawn end (sunrise)',
            60
        );
        $this->assertTimeEquals(
            $this->reference['civil_dusk_start'],
            $sunset,
            'Civil dusk start (sunset)',
            60
        );
        $this->assertTimeEquals(
            $this->reference['civil_dusk_end'],
            $civil_end,
            'Civil dusk end',
            60
        );
    }

    public function testCivilTwilightDuration(): void
    {
        $result = $this->calculateTestSunTimes();

        $civil_begin = $this->convertFraction($result['civil_begin_frac']);
        $civil_end = $this->convertFraction($result['civil_end_frac']);
        $sunrise = $this->convertFraction($result['sunrise_frac']);
        $sunset = $this->convertFraction($result['sunset_frac']);

        $total_duration = ($sunrise - $civil_begin) + ($civil_end - $sunset);

        $this->assertDurationEquals(
            $this->reference['civil_twilight_duration_seconds'],
            $total_duration,
            'Total civil twilight duration',
            60
        );
    }

    public function testSunriseSunsetTimes(): void
    {
        $result = $this->calculateTestSunTimes();

        $sunrise = $this->convertFraction($result['sunrise_frac']);
        $sunset = $this->convertFraction($result['sunset_frac']);

        $this->assertTimeEquals(
            $this->reference['sunrise'],
            $sunrise,
            'Sunrise',
            60
        );
        $this->assertTimeEquals(
            $this->reference['sunset'],
            $sunset,
            'Sunset',
            60
        );
    }

    public function testDaylightDuration(): void
    {
        $result = $this->calculateTestSunTimes();

        $sunrise = $this->convertFraction($result['sunrise_frac']);
        $sunset = $this->convertFraction($result['sunset_frac']);

        $daylight_duration = $sunset - $sunrise;

        $this->assertDurationEquals(
            $this->reference['daylight_duration_seconds'],
            $daylight_duration,
            'Daylight duration',
            120
        );

        // Also test from daylength_h calculation
        $daylight_from_calc = round($result['daylength_h'] * 3600);
        $this->assertDurationEquals(
            $this->reference['daylight_duration_seconds'],
            $daylight_from_calc,
            'Day length calculation',
            120
        );
    }

    public function testSolarNoon(): void
    {
        $result = $this->calculateTestSunTimes();
        $solar_noon = $this->convertFraction($result['solar_noon_frac']);

        $this->assertTimeEquals(
            $this->reference['solar_noon'],
            $solar_noon,
            'Solar noon',
            30
        );
    }

    public function testSolarMidnight(): void
    {
        $result = $this->calculateTestSunTimes();

        // Solar midnight occurs when the sun is at its lowest point
        // For Feb 1, it's around 00:35 (between midnight and 1 AM)
        // We calculate it as solar_noon - 12 hours
        $solar_noon = $result['solar_noon_frac'];
        $solar_midnight_frac = $solar_noon - 0.5;
        if ($solar_midnight_frac < 0) {
            $solar_midnight_frac += 1.0;
        }

        $solar_midnight = $this->convertFraction($solar_midnight_frac);

        $this->assertTimeEquals(
            $this->reference['solar_midnight'],
            $solar_midnight,
            'Solar midnight',
            30
        );
    }

    public function testNightDuration(): void
    {
        $result = $this->calculateTestSunTimes();

        $astro_begin = $this->convertFraction($result['astro_begin_frac']);
        $astro_end = $this->convertFraction($result['astro_end_frac']);

        // Night is from end of astronomical dusk to beginning of astronomical dawn
        // Split across midnight: from astro_end to midnight (23:59:59) + midnight to astro_begin
        $midnight_end = mktime(23, 59, 59, self::TEST_MONTH, self::TEST_DAY, self::TEST_YEAR);
        $midnight_start = mktime(0, 0, 0, self::TEST_MONTH, self::TEST_DAY, self::TEST_YEAR);

        $night_duration = ($midnight_end - $astro_end) + ($astro_begin - $midnight_start) + 1; // +1 for inclusive

        $this->assertDurationEquals(
            $this->reference['night_duration_seconds'],
            $night_duration,
            'Total night duration',
            120
        );
    }

    public function testDayLengthChangeFromPreviousDay(): void
    {
        // Feb 1, 2026
        $result_today = calculate_sun_times(
            self::TEST_YEAR,
            self::TEST_MONTH,
            self::TEST_DAY,
            self::LAT,
            self::LON,
            self::TIMEZONE_OFFSET
        );

        // Jan 31, 2026 (yesterday)
        $result_yesterday = calculate_sun_times(
            self::TEST_YEAR,
            1,
            31,
            self::LAT,
            self::LON,
            self::TIMEZONE_OFFSET
        );

        $daylight_today = $result_today['daylength_h'] * 3600;
        $daylight_yesterday = $result_yesterday['daylength_h'] * 3600;

        $change = round($daylight_today - $daylight_yesterday);

        $this->assertDurationEquals(
            $this->reference['day_length_change_seconds'],
            $change,
            'Day length change from yesterday',
            10
        );
    }

    public function testTotalDayBreakdownSumsTo24Hours(): void
    {
        $result = $this->calculateTestSunTimes();

        $sunrise = $this->convertFraction($result['sunrise_frac']);
        $sunset = $this->convertFraction($result['sunset_frac']);
        $civil_begin = $this->convertFraction($result['civil_begin_frac']);
        $civil_end = $this->convertFraction($result['civil_end_frac']);
        $nautical_begin = $this->convertFraction($result['nautical_begin_frac']);
        $nautical_end = $this->convertFraction($result['nautical_end_frac']);
        $astro_begin = $this->convertFraction($result['astro_begin_frac']);
        $astro_end = $this->convertFraction($result['astro_end_frac']);

        // Verify the day sums to 24 hours
        $daylight = $sunset - $sunrise;
        $civil = ($sunrise - $civil_begin) + ($civil_end - $sunset);
        $nautical = ($civil_begin - $nautical_begin) + ($nautical_end - $civil_end);
        $astro = ($nautical_begin - $astro_begin) + ($astro_end - $nautical_end);

        $midnight_start = mktime(0, 0, 0, self::TEST_MONTH, self::TEST_DAY, self::TEST_YEAR);
        $midnight_end = mktime(23, 59, 59, self::TEST_MONTH, self::TEST_DAY, self::TEST_YEAR);
        $night = ($astro_begin - $midnight_start) + ($midnight_end - $astro_end) + 1;

        $total = $daylight + $civil + $nautical + $astro + $night;
        $day_seconds = 86400;

        $diff = abs($total - $day_seconds);

        $this->assertLessThanOrEqual(10, $diff, "Total day breakdown should sum to 24h (difference: {$diff}s)");
    }
}
