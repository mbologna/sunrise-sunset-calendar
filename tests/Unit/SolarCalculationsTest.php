<?php

namespace Tests\Unit;

use Tests\BaseTest;

/**
 * Comprehensive Solar Calculations Test Suite
 * Tests high-precision NREL SPA-inspired solar calculations.
 *
 * Coverage:
 * - Julian Day calculations
 * - Solar position calculations (declination, equation of time)
 * - Sunrise/sunset calculations for various latitudes
 * - Twilight calculations (civil, nautical, astronomical)
 * - Edge cases (polar regions, equator, solstices, equinoxes)
 * - Hour angle calculations
 * - Leap year handling
 */
class SolarCalculationsTest extends BaseTest
{
    public function testJulianDayForJ2000Epoch(): void
    {
        // Test J2000.0 epoch (noon on January 1, 2000)
        $jd = julianDay(2000, 1, 1, 12.0);
        $this->assertFloatEquals(2451545.0, $jd, 'JD for J2000.0 epoch (2000-01-01 12:00 UTC)', 0.01);
    }

    public function testJulianDayForKnownDates(): void
    {
        // Test 2026-01-29
        $jd = julianDay(2026, 1, 29, 0.0);
        $this->assertFloatEquals(2461069.5, $jd, 'JD for 2026-01-29 00:00 UTC', 0.5);

        // Test 1999-01-01
        $jd = julianDay(1999, 1, 1, 0.0);
        $this->assertFloatEquals(2451179.5, $jd, 'JD for 1999-01-01 00:00 UTC', 0.5);
    }

    public function testJulianDayForLeapDay(): void
    {
        // Test leap year Feb 29
        $jd = julianDay(2024, 2, 29, 0.0);
        $expected = 2460369.5;
        $this->assertFloatEquals($expected, $jd, 'JD for leap day 2024-02-29', 0.5);
    }

    public function testJulianDayForDecember(): void
    {
        // Test December dates (month adjustment)
        $jd = julianDay(2026, 12, 31, 0.0);
        $jdJan = julianDay(2026, 1, 29, 0.0);
        $this->assertGreaterThan($jdJan, $jd, 'JD for Dec 31 2026 should be greater than Jan 29 2026');
    }

    public function testJulianCenturyForJ2000(): void
    {
        $T = julianCentury(2451545.0); // J2000.0
        $this->assertFloatEquals(0.0, $T, 'Julian century for J2000.0 is 0', 0.0001);
    }

    public function testJulianCenturyOneCenturyLater(): void
    {
        $T = julianCentury(2451545.0 + 36525); // One century later
        $this->assertFloatEquals(1.0, $T, 'Julian century +1 for 36525 days later', 0.0001);
    }

    public function testJulianCenturyOneCenturyEarlier(): void
    {
        $T = julianCentury(2451545.0 - 36525); // One century earlier
        $this->assertFloatEquals(-1.0, $T, 'Julian century -1 for 36525 days earlier', 0.0001);
    }

    public function testSolarDeclinationWinter(): void
    {
        // Test for winter (late January) - sun south of equator
        $jd = julianDay(2026, 1, 29, 12.0);
        $T = julianCentury($jd);

        // Calculate required intermediate values
        $L0 = sunMeanLongitude($T);
        $M = sunMeanAnomaly($T);
        $C = sunEquationOfCenter($T, $M);
        $trueLon = sunTrueLongitude($L0, $C);
        $lambda = sunApparentLongitude($T, $trueLon);
        $eps0 = meanObliquity($T);
        $eps = correctedObliquity($T, $eps0);
        $decl = solarDeclination($lambda, $eps);

        // In late January, sun should be south of equator (negative declination)
        // Range: approximately -17° to -20°
        $this->assertInRange($decl, -25.0, -15.0, 'Solar declination in late January');
    }

    public function testSolarDeclinationSummer(): void
    {
        // Test for summer (late June) - sun north of equator
        $jd = julianDay(2026, 6, 21, 12.0);
        $T = julianCentury($jd);

        // Calculate required intermediate values
        $L0 = sunMeanLongitude($T);
        $M = sunMeanAnomaly($T);
        $C = sunEquationOfCenter($T, $M);
        $trueLon = sunTrueLongitude($L0, $C);
        $lambda = sunApparentLongitude($T, $trueLon);
        $eps0 = meanObliquity($T);
        $eps = correctedObliquity($T, $eps0);
        $decl = solarDeclination($lambda, $eps);

        // Summer solstice: sun at maximum northern declination (~23.4°)
        $this->assertInRange($decl, 23.0, 24.0, 'Solar declination at summer solstice');
    }

    public function testEquationOfTimeRange(): void
    {
        // Test equation of time for various dates
        // Should be within ±16 minutes throughout the year
        $jd = julianDay(2026, 1, 1, 12.0);
        $T = julianCentury($jd);

        // Calculate required intermediate values
        $L0 = sunMeanLongitude($T);
        $M = sunMeanAnomaly($T);
        $eps0 = meanObliquity($T);
        $eps = correctedObliquity($T, $eps0);
        $e = 0.016708634 - $T * (0.000042037 + 0.0000001267 * $T);
        $eot = equationOfTime($T, $L0, $e, $M, $eps);

        // Function returns value in minutes (factor of 4 converts degrees to minutes)
        $this->assertInRange($eot, -17.0, 17.0, 'Equation of time in early January (minutes)');
    }

    public function testSunriseHourAngleForSunrise(): void
    {
        // Test sunrise hour angle for typical location (45°N)
        $lat = 45.0;
        $decl = 0.0; // Equinox
        $angle = -0.833; // Standard sunrise/sunset

        $ha = sunriseHourAngle($lat, $decl, $angle);

        // At equinox, sunrise should be around 6 hours before noon (90°)
        // Hour angle in degrees should be close to 90°
        $this->assertInRange($ha, 85.0, 95.0, 'Hour angle at equinox for 45°N');
    }

    public function testSunriseHourAngleForTwilight(): void
    {
        // Civil twilight has larger hour angle (earlier start)
        $lat = 45.0;
        $decl = 0.0;

        $haSunrise = sunriseHourAngle($lat, $decl, -0.833);
        $haCivil = sunriseHourAngle($lat, $decl, -6.0);

        // Civil twilight starts before sunrise (larger hour angle)
        $this->assertGreaterThan($haSunrise, $haCivil, 'Civil twilight hour angle > sunrise hour angle');
    }

    public function testRomeSunriseSunset(): void
    {
        // Rome, Italy (41.9°N, 12.5°E) on 2026-01-29
        $result = $this->calculateSunTimes(2026, 1, 29, 41.9, 12.5, 1);

        // Sunrise should be around 7:00-7:30 local time
        $sunrise = $this->fractionToTimestamp(2026, 1, 29, $result['sunrise_frac']);
        $expectedSunrise = mktime(7, 15, 0, 1, 29, 2026);
        $this->assertTimeEquals($expectedSunrise, $sunrise, 'Rome sunrise on Jan 29, 2026', 1800);

        // Sunset should be around 17:15-17:45
        $sunset = $this->fractionToTimestamp(2026, 1, 29, $result['sunset_frac']);
        $expectedSunset = mktime(17, 30, 0, 1, 29, 2026);
        $this->assertTimeEquals($expectedSunset, $sunset, 'Rome sunset on Jan 29, 2026', 1800);
    }

    public function testNewYorkSunriseSunset(): void
    {
        // New York (40.7°N, -74.0°W) on 2026-06-21 (summer solstice)
        $result = $this->calculateSunTimes(2026, 6, 21, 40.7, -74.0, -4);

        // Sunrise should be around 5:20-5:30
        $sunrise = $this->fractionToTimestamp(2026, 6, 21, $result['sunrise_frac']);
        $expectedSunrise = mktime(5, 25, 0, 6, 21, 2026);
        $this->assertTimeEquals($expectedSunrise, $sunrise, 'NYC sunrise on summer solstice', 1800);

        // Sunset should be around 20:25-20:35
        $sunset = $this->fractionToTimestamp(2026, 6, 21, $result['sunset_frac']);
        $expectedSunset = mktime(20, 30, 0, 6, 21, 2026);
        $this->assertTimeEquals($expectedSunset, $sunset, 'NYC sunset on summer solstice', 1800);
    }

    public function testTokyoSunriseSunset(): void
    {
        // Tokyo (35.7°N, 139.7°E) on 2026-03-20 (equinox)
        $result = $this->calculateSunTimes(2026, 3, 20, 35.7, 139.7, 9);

        // At equinox, sunrise/sunset should be roughly 12 hours apart
        $daylength = $result['daylength_h'];
        $this->assertInRange($daylength, 11.8, 12.2, 'Tokyo day length at equinox');
    }

    public function testTwilightCalculationsRome(): void
    {
        // Test all twilight phases for Rome
        $result = $this->calculateSunTimes(2026, 1, 29, 41.9, 12.5, 1);

        // Astronomical twilight begins earliest
        $astroBegin = $result['astro_begin_frac'];
        $nauticalBegin = $result['nautical_begin_frac'];
        $civilBegin = $result['civil_begin_frac'];
        $sunrise = $result['sunrise_frac'];

        // Verify correct ordering: astro < nautical < civil < sunrise
        $this->assertLessThan($nauticalBegin, $astroBegin, 'Astronomical dawn before nautical');
        $this->assertLessThan($civilBegin, $nauticalBegin, 'Nautical dawn before civil');
        $this->assertLessThan($sunrise, $civilBegin, 'Civil dawn before sunrise');

        // Same for evening (in reverse)
        $sunset = $result['sunset_frac'];
        $civilEnd = $result['civil_end_frac'];
        $nauticalEnd = $result['nautical_end_frac'];
        $astroEnd = $result['astro_end_frac'];

        $this->assertLessThan($civilEnd, $sunset, 'Sunset before civil dusk');
        $this->assertLessThan($nauticalEnd, $civilEnd, 'Civil dusk before nautical');
        $this->assertLessThan($astroEnd, $nauticalEnd, 'Nautical dusk before astronomical');
    }

    public function testEquatorConsistentDayLength(): void
    {
        // At the equator, day length should be close to 12h year-round
        $lat = 0.0;
        $lon = 0.0;

        // Test summer solstice (atmospheric refraction causes slight variation)
        $resultSummer = $this->calculateSunTimes(2026, 6, 21, $lat, $lon, 0);
        $this->assertInRange($resultSummer['daylength_h'], 11.9, 12.2, 'Equator day length at summer solstice');

        // Test winter solstice
        $resultWinter = $this->calculateSunTimes(2026, 12, 21, $lat, $lon, 0);
        $this->assertInRange($resultWinter['daylength_h'], 11.9, 12.2, 'Equator day length at winter solstice');
    }

    public function testArcticSummerSolstice(): void
    {
        // Arctic Circle (70°N) on summer solstice - midnight sun
        $result = $this->calculateSunTimes(2026, 6, 21, 70.0, 0.0, 0);

        // Day length should be close to 24 hours
        $this->assertGreaterThan(23.0, $result['daylength_h'], 'Arctic midnight sun - very long day');
    }

    public function testArcticWinterSolstice(): void
    {
        // Arctic Circle (70°N) on winter solstice - polar night
        $result = $this->calculateSunTimes(2026, 12, 21, 70.0, 0.0, 0);

        // Day length should be very short or zero
        $this->assertLessThan(2.0, $result['daylength_h'], 'Arctic polar night - very short day');
    }

    public function testSolsticeComparisonNewYork(): void
    {
        // Compare summer vs winter solstice in New York
        $lat = 40.7;
        $lon = -74.0;
        $offset = -5;

        $summer = $this->calculateSunTimes(2026, 6, 21, $lat, $lon, $offset);
        $winter = $this->calculateSunTimes(2026, 12, 21, $lat, $lon, $offset);

        // Summer should have longer days than winter
        $this->assertGreaterThan($winter['daylength_h'], $summer['daylength_h'], 'Summer day longer than winter');

        // Difference should be significant (at least 4 hours)
        $diff = $summer['daylength_h'] - $winter['daylength_h'];
        $this->assertGreaterThan(4.0, $diff, 'Solstice day length difference > 4 hours');
    }

    public function testEquinoxDayLength(): void
    {
        // At equinox, day length should be close to 12h everywhere
        $equinoxDate = [2026, 3, 20];

        // Test various latitudes
        $locations = [
            ['lat' => 0, 'name' => 'Equator'],
            ['lat' => 30, 'name' => '30°N'],
            ['lat' => 45, 'name' => '45°N'],
            ['lat' => 60, 'name' => '60°N'],
        ];

        foreach ($locations as $loc) {
            $result = $this->calculateSunTimes(
                $equinoxDate[0],
                $equinoxDate[1],
                $equinoxDate[2],
                $loc['lat'],
                0.0,
                0
            );

            $this->assertInRange(
                $result['daylength_h'],
                11.5,
                12.5,
                "{$loc['name']} day length at equinox"
            );
        }
    }

    public function testLeapYearFebruary29(): void
    {
        // Test that Feb 29 in leap year works correctly
        $result = $this->calculateSunTimes(2024, 2, 29, 45.0, 0.0, 0);

        // Should have valid day length
        $this->assertGreaterThan(0, $result['daylength_h'], 'Leap day has positive day length');
        $this->assertLessThan(24, $result['daylength_h'], 'Leap day has day length < 24h');
    }

    public function testNonLeapYearFebruary(): void
    {
        // Test Feb 28 in non-leap year
        $result = $this->calculateSunTimes(2026, 2, 28, 45.0, 0.0, 0);

        // Should have valid day length
        $this->assertGreaterThan(0, $result['daylength_h'], 'Feb 28 has positive day length');
    }

    public function testFractionToTimestampNoon(): void
    {
        // Test fraction 0.5 = noon
        $ts = $this->fractionToTimestamp(2026, 1, 29, 0.5);
        $expected = mktime(12, 0, 0, 1, 29, 2026);

        $this->assertTimeEquals($expected, $ts, 'Fraction 0.5 equals noon', 60);
    }

    public function testFractionToTimestampMidnight(): void
    {
        // Test fraction 0.0 = midnight
        $ts = $this->fractionToTimestamp(2026, 1, 29, 0.0);
        $expected = mktime(0, 0, 0, 1, 29, 2026);

        $this->assertTimeEquals($expected, $ts, 'Fraction 0.0 equals midnight', 60);
    }

    public function testFractionToTimestampQuarterDay(): void
    {
        // Test fraction 0.25 = 6:00 AM
        $ts = $this->fractionToTimestamp(2026, 1, 29, 0.25);
        $expected = mktime(6, 0, 0, 1, 29, 2026);

        $this->assertTimeEquals($expected, $ts, 'Fraction 0.25 equals 6 AM', 60);
    }

    public function testSolarNoonCalculations(): void
    {
        // Solar noon should be close to 12:00 for longitude 0°
        $result = $this->calculateSunTimes(2026, 1, 29, 45.0, 0.0, 0);

        $solarNoon = $this->fractionToTimestamp(2026, 1, 29, $result['solar_noon_frac']);
        $expectedNoon = mktime(12, 0, 0, 1, 29, 2026);

        // Within 30 minutes of local noon
        $this->assertTimeEquals($expectedNoon, $solarNoon, 'Solar noon near longitude 0°', 1800);
    }

    public function testNegativeLongitudesWesternHemisphere(): void
    {
        // Test negative longitude (Western Hemisphere)
        $result = $this->calculateSunTimes(2026, 1, 29, 40.0, -100.0, -6);

        // Should have valid sunrise/sunset
        $this->assertGreaterThan(0, $result['sunrise_frac'], 'Western hemisphere has valid sunrise');
        $this->assertLessThan(1, $result['sunrise_frac'], 'Sunrise fraction < 1.0');
        $this->assertGreaterThan($result['sunrise_frac'], $result['sunset_frac'], 'Sunset after sunrise');
    }

    public function testSouthernHemisphereSydney(): void
    {
        // Sydney, Australia (33.9°S, 151.2°E)
        // In June (winter), days should be shorter
        $winter = $this->calculateSunTimes(2026, 6, 21, -33.9, 151.2, 10);

        // In December (summer), days should be longer
        $summer = $this->calculateSunTimes(2026, 12, 21, -33.9, 151.2, 10);

        // Southern hemisphere has reversed seasons
        $this->assertGreaterThan($winter['daylength_h'], $summer['daylength_h'], 'Southern summer longer than winter');
    }

    public function testEdgeCaseLatitudeNorthPole(): void
    {
        // Near North Pole (89°N)
        $result = $this->calculateSunTimes(2026, 6, 21, 89.0, 0.0, 0);

        // Should have very long day (close to 24h) in summer
        $this->assertGreaterThan(20.0, $result['daylength_h'], 'Near North Pole summer has very long day');
    }

    public function testEdgeCaseLatitudeSouthPole(): void
    {
        // Near South Pole (89°S)
        $result = $this->calculateSunTimes(2026, 6, 21, -89.0, 0.0, 0);

        // Should have very short day (close to 0h) in June
        $this->assertLessThan(4.0, $result['daylength_h'], 'Near South Pole winter has very short day');
    }

    public function testConsistentCalculationsAcrossDates(): void
    {
        // Test that calculations are consistent when run multiple times
        $result1 = $this->calculateSunTimes(2026, 1, 29, 45.0, 0.0, 0);
        $result2 = $this->calculateSunTimes(2026, 1, 29, 45.0, 0.0, 0);

        $this->assertFloatEquals(
            $result1['sunrise_frac'],
            $result2['sunrise_frac'],
            'Consistent sunrise calculation',
            0.0001
        );

        $this->assertFloatEquals(
            $result1['daylength_h'],
            $result2['daylength_h'],
            'Consistent day length calculation',
            0.0001
        );
    }
}
