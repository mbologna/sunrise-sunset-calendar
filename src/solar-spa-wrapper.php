<?php

/**
 * High-Precision Solar Calculation Wrapper
 * Uses abbadon1334/sun-position-spa-php (NREL SPA algorithm)
 * Accuracy: ±0.0003° (approximately ±30 seconds for time calculations)
 * Version 8.0.
 */

/**
 * Calculate sun times using high-precision NREL SPA algorithm.
 *
 * @param int $y Year
 * @param int $m Month (1-12)
 * @param int $d Day of month
 * @param float $lat Latitude in degrees (-90 to 90)
 * @param float $lon Longitude in degrees (-180 to 180)
 * @param float $utc_offset UTC offset in hours
 * @param float $sun_alt Solar altitude angle in degrees (default: -0.833 for sunrise/sunset)
 * @return array Solar calculation results
 */
function calculate_sun_times_spa($y, $m, $d, $lat, $lon, $utc_offset, $sun_alt = -0.833)
{
    // Check if SPA library is available
    if (!class_exists('Abbadon1334\SunPositionSPA\SunPositionSPA')) {
        // Fallback to legacy implementation if library not installed
        if (function_exists('calculate_sun_times_legacy')) {
            return calculate_sun_times_legacy($y, $m, $d, $lat, $lon, $utc_offset, $sun_alt);
        }
        throw new RuntimeException(
            'NREL SPA library not found. Please run: composer install'
        );
    }

    try {
        // Initialize SPA calculator
        $spa = new \Abbadon1334\SunPositionSPA\SunPositionSPA();

        // Set observer location (0 = sea level)
        $spa->setObserverPosition($lat, $lon, 0);

        // Set observer date
        $spa->setObserverDate($y, $m, $d);

        // Calculate at solar noon for position parameters
        $spa->setObserverTime(12, 0, 0);

        // Set timezone offset
        $spa->setObserverTimezone($utc_offset);

        // Calculate solar position parameters
        $result = $spa->calculate();

        // Get precise solar declination and equation of time from SPA
        $decl = $result->getDeclination();
        $eqTime = $result->getEquationOfTime();

        // Calculate sunrise/sunset/transit using SPA's built-in method
        $times = $spa->calculateSunRiseTransitSet();

        // Calculate solar noon as fractional day (0.0 = midnight, 1.0 = next midnight)
        // Convert from decimal hours (0-24) to fraction (0-1)
        $solarNoon = $times['transit'] / 24.0;

        // Calculate hour angles for different sun altitudes
        $HA_sunrise = sunriseHourAngle($lat, $decl, $sun_alt);
        $HA_civil = sunriseHourAngle($lat, $decl, -6.0);
        $HA_nautical = sunriseHourAngle($lat, $decl, -12.0);
        $HA_astro = sunriseHourAngle($lat, $decl, -18.0);

        // Calculate event times as fractional days
        // Formula: time = solar_noon ± (hour_angle * 4 minutes/degree / 1440 minutes/day)
        $sunrise = $solarNoon - ($HA_sunrise * 4) / 1440;
        $sunset = $solarNoon + ($HA_sunrise * 4) / 1440;

        $civil_begin = $solarNoon - ($HA_civil * 4) / 1440;
        $civil_end = $solarNoon + ($HA_civil * 4) / 1440;

        $nautical_begin = $solarNoon - ($HA_nautical * 4) / 1440;
        $nautical_end = $solarNoon + ($HA_nautical * 4) / 1440;

        $astro_begin = $solarNoon - ($HA_astro * 4) / 1440;
        $astro_end = $solarNoon + ($HA_astro * 4) / 1440;

        // Calculate day length in hours (2 * hour_angle / 15 degrees per hour)
        $dayLength = (2 * $HA_sunrise) / 15.0;

        // Return in same format as legacy implementation for backward compatibility
        return [
            'declination_deg' => $decl,
            'equation_of_time_min' => $eqTime,
            'sunrise_frac' => $sunrise,
            'sunset_frac' => $sunset,
            'solar_noon_frac' => $solarNoon,
            'daylength_h' => $dayLength,
            'civil_begin_frac' => $civil_begin,
            'civil_end_frac' => $civil_end,
            'nautical_begin_frac' => $nautical_begin,
            'nautical_end_frac' => $nautical_end,
            'astro_begin_frac' => $astro_begin,
            'astro_end_frac' => $astro_end,
        ];

    } catch (Exception $e) {
        // Log error and fall back to legacy if available
        if (function_exists('calculate_sun_times_legacy')) {
            error_log('SPA calculation error: ' . $e->getMessage() . '. Using legacy fallback.');

            return calculate_sun_times_legacy($y, $m, $d, $lat, $lon, $utc_offset, $sun_alt);
        }
        throw new RuntimeException('Solar calculation failed: ' . $e->getMessage());
    }
}
