<?php

/**
 * Astronomical Calculations Module
 *
 * Combines high-precision NREL SPA solar calculations with Meeus algorithms
 * for equinoxes, solstices, and moon phases.
 *
 * References:
 * - NREL SPA: Reda & Andreas, 2008 (±0.0003° precision)
 * - Meeus: "Astronomical Algorithms" 2nd ed., 1998 (±1 minute for equinox/solstice)
 */

declare(strict_types=1);

use SunCalendar\Cache;

// ============================================================================
// ERROR HANDLING
// ============================================================================

/**
 * Execute a callback with deprecation warnings suppressed.
 *
 * The SPA library uses dynamic properties which trigger PHP 8.2+ deprecation
 * warnings. This wrapper temporarily suppresses them.
 *
 * @param callable $callback The function to execute
 * @return mixed The callback's return value
 */
function suppress_deprecations(callable $callback): mixed
{
    $previousLevel = error_reporting(error_reporting() & ~E_DEPRECATED);
    try {
        return $callback();
    } finally {
        error_reporting($previousLevel);
    }
}

// ============================================================================
// SOLAR POSITION (NREL SPA)
// ============================================================================

/**
 * Calculate sun times using high-precision NREL SPA algorithm.
 *
 * Uses the SolarData\SunPosition library (NREL SPA implementation)
 * for ±30 second accuracy in solar position calculations.
 */
function calculate_sun_times_spa(
    int $y,
    int $m,
    int $d,
    float $lat,
    float $lon,
    float $utc_offset
): array {
    // Check cache first
    $cacheKey = sprintf('%d-%d-%d:%.4f:%.4f:%.2f', $y, $m, $d, $lat, $lon, $utc_offset);
    $cached = Cache::getInstance()->getSolarCalc($cacheKey);
    if ($cached !== null) {
        return $cached;
    }

    if (!class_exists('SolarData\SunPosition')) {
        throw new RuntimeException('NREL SPA library not found. Please run: composer install');
    }

    // Wrap SPA library calls to suppress PHP 8.2+ deprecation warnings
    $result = suppress_deprecations(function () use ($y, $m, $d, $lat, $lon, $utc_offset): array {
        // Create observer and set parameters
        $observer = new \SolarData\Observer\Observer();
        $observer->setPosition($lat, $lon, 0);
        $observer->setDate($y, $m, $d);
        $observer->setTime(12, 0, 0);
        $observer->setTimezone($utc_offset);
        $observer->calculate();  // REQUIRED: Calculate JD and other time values

        // Calculate solar position
        $spa = new \SolarData\SunPosition();
        $spa->setObserver($observer);
        $spa->calculate();

        // Get equation of time
        $eqTime = $spa->getEquationOfTime();

        // Calculate sunrise/set times using SPA (sets properties on the object)
        $spa->calcSunRiseTransitSet();

        // Get sunrise/sunset/transit in LOCAL hours (0-24)
        // The library's _DayFracToHours with local=true properly handles timezone
        $sunriseHours = $spa->_DayFracToHours($spa->DayFractionSunrise, true);
        $sunsetHours = $spa->_DayFracToHours($spa->DayFractionSunset, true);
        $transitHours = $spa->_DayFracToHours($spa->DayFractionTransit, true);

        // Convert local hours back to day fractions (0-1)
        $sunriseFrac = $sunriseHours / 24.0;
        $sunsetFrac = $sunsetHours / 24.0;
        $solarNoon = $transitHours / 24.0;

        // Calculate daylength (sunset hours - sunrise hours)
        $daylengthH = $sunsetHours - $sunriseHours;

        // Get declination for polar region check and twilight calculations
        $decl = $spa->{'δ°'};

        // Handle polar regions (no sunrise/sunset)
        if ($spa->DayFractionSunrise === null || $spa->DayFractionSunset === null) {
            // Check if it's polar day or polar night using sun elevation at noon
            // Sun elevation at local noon = 90 - |latitude - declination|
            $sunElevationAtNoon = 90 - abs($lat - $decl);
            if ($sunElevationAtNoon > 0) {
                // Sun is above horizon at noon = polar day
                $daylengthH = 24.0;
                $sunriseFrac = 0.0;
                $sunsetFrac = 1.0;
            } else {
                // Sun is below horizon at noon = polar night
                $daylengthH = 0.0;
                $sunriseFrac = 0.5;  // Set to noon (no actual sunrise)
                $sunsetFrac = 0.5;   // Set to noon (no actual sunset)
            }
        }

        // Calculate hour angles for twilight types using the declination
        $HA_civil = sunriseHourAngle($lat, $decl, -6.0);
        $HA_nautical = sunriseHourAngle($lat, $decl, -12.0);
        $HA_astro = sunriseHourAngle($lat, $decl, -18.0);

        return [
            'declination_deg' => $decl,
            'equation_of_time_min' => $eqTime,
            'sunrise_frac' => $sunriseFrac,
            'sunset_frac' => $sunsetFrac,
            'solar_noon_frac' => $solarNoon,
            'daylength_h' => $daylengthH,
            'civil_begin_frac' => $solarNoon - ($HA_civil * 4) / 1440,
            'civil_end_frac' => $solarNoon + ($HA_civil * 4) / 1440,
            'nautical_begin_frac' => $solarNoon - ($HA_nautical * 4) / 1440,
            'nautical_end_frac' => $solarNoon + ($HA_nautical * 4) / 1440,
            'astro_begin_frac' => $solarNoon - ($HA_astro * 4) / 1440,
            'astro_end_frac' => $solarNoon + ($HA_astro * 4) / 1440,
        ];
    });

    // Cache the result
    Cache::getInstance()->setSolarCalc($cacheKey, $result);

    return $result;
}

// ============================================================================
// EQUINOXES AND SOLSTICES (Meeus Chapter 27)
// ============================================================================

/**
 * Calculate equinoxes and solstices for a given year.
 * Accuracy: ±1 minute (51 seconds typical)
 */
function calculate_equinox_solstice(int $year): array
{
    if ($year >= -1000 && $year <= 1000) {
        $Y = $year / 1000.0;
        $march_jde = 1721139.29189 + 365242.13740 * $Y + 0.06134 * $Y * $Y
                     + 0.00111 * $Y * $Y * $Y - 0.00071 * $Y * $Y * $Y * $Y;
        $june_jde = 1721233.25401 + 365241.72562 * $Y - 0.05323 * $Y * $Y
                    + 0.00907 * $Y * $Y * $Y + 0.00025 * $Y * $Y * $Y * $Y;
        $sept_jde = 1721325.70455 + 365242.49558 * $Y - 0.11677 * $Y * $Y
                    - 0.00297 * $Y * $Y * $Y + 0.00074 * $Y * $Y * $Y * $Y;
        $dec_jde = 1721414.39987 + 365242.88257 * $Y - 0.00769 * $Y * $Y
                   - 0.00933 * $Y * $Y * $Y - 0.00006 * $Y * $Y * $Y * $Y;
    } else {
        $Y = ($year - 2000) / 1000.0;
        $march_jde = 2451623.80984 + 365242.37404 * $Y + 0.05169 * $Y * $Y
                     - 0.00411 * $Y * $Y * $Y - 0.00057 * $Y * $Y * $Y * $Y;
        $june_jde = 2451716.56767 + 365241.62603 * $Y + 0.00325 * $Y * $Y
                    + 0.00888 * $Y * $Y * $Y - 0.00030 * $Y * $Y * $Y * $Y;
        $sept_jde = 2451810.21715 + 365242.01767 * $Y - 0.11575 * $Y * $Y
                    + 0.00337 * $Y * $Y * $Y + 0.00078 * $Y * $Y * $Y * $Y;
        $dec_jde = 2451900.05952 + 365242.74049 * $Y - 0.06223 * $Y * $Y
                   - 0.00823 * $Y * $Y * $Y + 0.00032 * $Y * $Y * $Y * $Y;
    }

    return [
        'march_equinox' => jde_to_unix(apply_periodic_corrections($march_jde), $year),
        'june_solstice' => jde_to_unix(apply_periodic_corrections($june_jde), $year),
        'september_equinox' => jde_to_unix(apply_periodic_corrections($sept_jde), $year),
        'december_solstice' => jde_to_unix(apply_periodic_corrections($dec_jde), $year),
    ];
}

/**
 * Apply periodic corrections to equinox/solstice times (Meeus Table 27.C).
 */
function apply_periodic_corrections(float $JDE0): float
{
    $T = ($JDE0 - 2451545.0) / 36525.0;

    $A = [
        485 * cos(deg2rad(324.96 + 1934.136 * $T)),
        203 * cos(deg2rad(337.23 + 32964.467 * $T)),
        199 * cos(deg2rad(342.08 + 20.186 * $T)),
        182 * cos(deg2rad(27.85 + 445267.112 * $T)),
        156 * cos(deg2rad(73.14 + 45036.886 * $T)),
        136 * cos(deg2rad(171.52 + 22518.443 * $T)),
        77 * cos(deg2rad(222.54 + 65928.934 * $T)),
        74 * cos(deg2rad(296.72 + 3034.906 * $T)),
        70 * cos(deg2rad(243.58 + 9037.513 * $T)),
        58 * cos(deg2rad(119.81 + 33718.147 * $T)),
        52 * cos(deg2rad(297.17 + 150.678 * $T)),
        50 * cos(deg2rad(21.02 + 2281.226 * $T)),
        45 * cos(deg2rad(247.54 + 29929.562 * $T)),
        44 * cos(deg2rad(325.15 + 31555.956 * $T)),
        29 * cos(deg2rad(60.93 + 4443.417 * $T)),
        18 * cos(deg2rad(155.12 + 67555.328 * $T)),
        17 * cos(deg2rad(288.79 + 4562.452 * $T)),
        16 * cos(deg2rad(198.04 + 62894.029 * $T)),
        14 * cos(deg2rad(199.76 + 31436.921 * $T)),
        12 * cos(deg2rad(95.39 + 14577.848 * $T)),
        12 * cos(deg2rad(287.11 + 31931.756 * $T)),
        12 * cos(deg2rad(320.81 + 34777.259 * $T)),
        9 * cos(deg2rad(227.73 + 1222.114 * $T)),
        8 * cos(deg2rad(15.45 + 16859.074 * $T)),
    ];

    $S = array_sum($A);
    $W = 35999.373 * $T - 2.47;
    $dL = 1 + 0.0334 * cos(deg2rad($W)) + 0.0007 * cos(deg2rad(2 * $W));

    return $JDE0 + ((0.00001 * $S) / $dL);
}

// ============================================================================
// MOON PHASES (Meeus Chapter 49)
// ============================================================================

/**
 * Calculate moon phases for a given month with caching.
 */
function calculate_moon_phases_for_month(int $year, int $month): array
{
    $cacheKey = "$year-$month";
    $cached = Cache::getInstance()->getMoonPhase($cacheKey);
    if ($cached !== null) {
        return $cached;
    }

    $k = (int) floor(($year + ($month - 0.5) / 12 - 2000) * 12.3685);
    $phases = [];

    for ($i = -1; $i <= 1; $i++) {
        $k_current = $k + $i;
        $phases[] = calculate_specific_moon_phase($k_current, 0.0);
        $phases[] = calculate_specific_moon_phase($k_current, 0.25);
        $phases[] = calculate_specific_moon_phase($k_current, 0.5);
        $phases[] = calculate_specific_moon_phase($k_current, 0.75);
    }

    usort($phases, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);

    $target_start = mktime(0, 0, 0, $month, 1, $year);
    $target_end = mktime(23, 59, 59, $month + 1, 0, $year);
    $early_start = $target_start - (15 * 86400);
    $late_end = $target_end + (15 * 86400);

    $phases = array_values(array_filter($phases, fn($phase) =>
        $phase['timestamp'] >= $early_start && $phase['timestamp'] <= $late_end));

    Cache::getInstance()->setMoonPhase($cacheKey, $phases);
    return $phases;
}

/**
 * Calculate a specific moon phase (Meeus formulas 49.1-49.4).
 */
function calculate_specific_moon_phase(int $k, float $phase): array
{
    $k_adjusted = $k + $phase;
    $T = $k_adjusted / 1236.85;

    $JDE = 2451550.09766
           + 29.530588861 * $k_adjusted
           + 0.00015437 * $T * $T
           - 0.000000150 * pow($T, 3)
           + 0.00000000073 * pow($T, 4);

    $E = 1 - 0.002516 * $T - 0.0000074 * $T * $T;
    $M = deg2rad(2.5534 + 29.10535670 * $k_adjusted
        - 0.0000014 * $T * $T - 0.00000011 * pow($T, 3));
    $Mp = deg2rad(201.5643 + 385.81693528 * $k_adjusted + 0.0107582 * $T * $T
        + 0.00001238 * pow($T, 3) - 0.000000058 * pow($T, 4));
    $F = deg2rad(160.7108 + 390.67050284 * $k_adjusted - 0.0016118 * $T * $T
        - 0.00000227 * pow($T, 3) + 0.000000011 * pow($T, 4));
    $Omega = deg2rad(124.7746 - 1.56375588 * $k_adjusted + 0.0020672 * $T * $T + 0.00000215 * pow($T, 3));

    if (abs($phase) < 0.01 || abs($phase - 0.5) < 0.01) {
        $corrections = calculate_new_full_corrections($M, $Mp, $F, $Omega, $E, $phase);
    } else {
        $corrections = calculate_quarter_corrections($M, $Mp, $F, $Omega, $E, $phase);
    }

    $JDE += $corrections;

    // Determine phase name based on phase value (0.0=New, 0.25=First Quarter, 0.5=Full, 0.75=Last Quarter)
    if (abs($phase) < 0.01) {
        $phase_name = 'New Moon';
    } elseif (abs($phase - 0.25) < 0.01) {
        $phase_name = 'First Quarter';
    } elseif (abs($phase - 0.5) < 0.01) {
        $phase_name = 'Full Moon';
    } elseif (abs($phase - 0.75) < 0.01) {
        $phase_name = 'Last Quarter';
    } else {
        $phase_name = 'Unknown';
    }

    return [
        'phase_name' => $phase_name,
        'jde' => $JDE,
        'timestamp' => jde_to_unix($JDE, jde_to_year($JDE)),
        'k' => $k_adjusted,
    ];
}

/**
 * Calculate corrections for New Moon and Full Moon (Meeus Tables 49.I/II).
 */
function calculate_new_full_corrections(float $M, float $Mp, float $F, float $Omega, float $E, float $phase): float
{
    $c = -0.40720 * sin($Mp)
       + 0.17241 * $E * sin($M)
       + 0.01608 * sin(2 * $Mp)
       + 0.01039 * sin(2 * $F)
       + 0.00739 * $E * sin($Mp - $M)
       - 0.00514 * $E * sin($Mp + $M)
       + 0.00208 * $E * $E * sin(2 * $M)
       - 0.00111 * sin($Mp - 2 * $F)
       - 0.00057 * sin($Mp + 2 * $F)
       + 0.00056 * $E * sin(2 * $Mp + $M)
       - 0.00042 * sin(3 * $Mp)
       + 0.00042 * $E * sin($M + 2 * $F)
       + 0.00038 * $E * sin($M - 2 * $F)
       - 0.00024 * $E * sin(2 * $Mp - $M);

    if (abs($phase - 0.5) < 0.01) {
        $c -= 0.00017 * sin($Omega);
    }

    $A1 = 299.77 + 0.107408 * rad2deg($Mp) - 0.009173 * pow(rad2deg($Mp), 2);
    $c += 0.000325 * sin(deg2rad($A1));

    return $c;
}

/**
 * Calculate corrections for First/Last Quarter (Meeus Table 49.III).
 */
function calculate_quarter_corrections(float $M, float $Mp, float $F, float $Omega, float $E, float $phase): float
{
    $c = -0.62801 * sin($Mp)
       + 0.17172 * $E * sin($M)
       - 0.01183 * $E * sin($Mp + $M)
       + 0.00862 * sin(2 * $Mp)
       + 0.00804 * sin(2 * $F)
       + 0.00454 * $E * sin($Mp - $M)
       + 0.00204 * $E * $E * sin(2 * $M)
       - 0.00180 * sin($Mp - 2 * $F)
       - 0.00070 * sin($Mp + 2 * $F)
       - 0.00040 * sin(3 * $Mp)
       - 0.00034 * $E * sin(2 * $Mp - $M)
       + 0.00032 * $E * sin($M + 2 * $F);

    $W = 0.00306 - 0.00038 * $E * cos($M)
       + 0.00026 * cos($Mp)
       - 0.00002 * cos($Mp - $M)
       + 0.00002 * cos($Mp + $M)
       + 0.00002 * cos(2 * $F);

    return $c + (abs($phase - 0.25) < 0.01 ? $W : -$W);
}

/**
 * Get accurate moon phase for a timestamp with caching.
 */
function get_accurate_moon_phase(int $timestamp): array
{
    $date_parts = getdate($timestamp);
    $year = $date_parts['year'];
    $month = $date_parts['mon'];

    $phases = array_merge(
        calculate_moon_phases_for_month($year, $month - 1),
        calculate_moon_phases_for_month($year, $month),
        calculate_moon_phases_for_month($year, $month + 1)
    );

    $prev = null;
    $next = null;

    foreach ($phases as $phase) {
        if ($phase['timestamp'] <= $timestamp) {
            $prev = $phase;
        }
        if ($phase['timestamp'] > $timestamp && $next === null) {
            $next = $phase;
            break;
        }
    }

    $intermediate_phases = [
        'New Moon' => ['First Quarter' => 'Waxing Crescent'],
        'First Quarter' => ['Full Moon' => 'Waxing Gibbous'],
        'Full Moon' => ['Last Quarter' => 'Waning Gibbous'],
        'Last Quarter' => ['New Moon' => 'Waning Crescent'],
    ];

    $intermediate = ($prev && $next)
        ? ($intermediate_phases[$prev['phase_name']][$next['phase_name']] ?? 'Unknown')
        : 'Unknown';

    // Check if a named phase (New Moon, First Quarter, Full Moon, Last Quarter)
    // occurs on the same calendar day as the requested timestamp.
    // Only show the named phase on its actual day; show the intermediate name otherwise.
    $dayStart = mktime(0, 0, 0, (int) date('n', $timestamp), (int) date('j', $timestamp), (int) date('Y', $timestamp));
    $dayEnd = $dayStart + 86399;
    $current_phase_name = $intermediate;
    foreach ($phases as $p) {
        if ($p['timestamp'] >= $dayStart && $p['timestamp'] <= $dayEnd) {
            $current_phase_name = $p['phase_name'];
            break;
        }
    }

    if ($prev && $next) {
        $new_moon_ts = null;
        foreach (array_reverse($phases) as $phase) {
            if ($phase['phase_name'] === 'New Moon' && $phase['timestamp'] <= $timestamp) {
                $new_moon_ts = $phase['timestamp'];
                break;
            }
        }

        $illumination = 50.0;
        if ($new_moon_ts !== null) {
            $days_since_new = ($timestamp - $new_moon_ts) / 86400;
            $angle = ($days_since_new / 29.53) * 2 * M_PI;
            $illumination = round((1 - cos($angle)) * 50, 1);
        }
    } else {
        $illumination = 0.0;
    }

    return [
        'phase_name' => $current_phase_name,
        'illumination' => $illumination,
        'prev_phase' => $prev ? [
            'name' => $prev['phase_name'],
            'date' => date('j M Y, H:i', $prev['timestamp']),
            'timestamp' => $prev['timestamp'],
        ] : null,
        'next_phase' => $next ? [
            'name' => $next['phase_name'],
            'date' => date('j M Y, H:i', $next['timestamp']),
            'timestamp' => $next['timestamp'],
        ] : null,
    ];
}

// ============================================================================
// TIME CONVERSIONS
// ============================================================================

/**
 * Extract approximate year from Julian Ephemeris Day.
 */
function jde_to_year(float $jde): int
{
    return (int) round(2000 + ($jde - 2451545.0) / 365.25);
}

/**
 * Calculate DeltaT (TD - UT) in seconds.
 */
function calculate_delta_t(int $year): float
{
    if ($year >= 2005 && $year <= 2050) {
        $t = $year - 2000;
        return 62.92 + 0.32217 * $t + 0.005589 * $t * $t;
    }
    if ($year >= 1986 && $year < 2005) {
        $t = $year - 2000;
        return 63.86 + 0.3345 * $t - 0.060374 * $t * $t
               + 0.0017275 * pow($t, 3) + 0.000651814 * pow($t, 4)
               + 0.00002373599 * pow($t, 5);
    }
    if ($year >= 1961 && $year < 1986) {
        $t = $year - 1975;
        return 45.45 + 1.067 * $t - $t * $t / 260.0 - pow($t, 3) / 718.0;
    }
    if ($year >= 1941 && $year < 1961) {
        $t = $year - 1950;
        return 29.07 + 0.407 * $t - $t * $t / 233.0 + pow($t, 3) / 2547.0;
    }
    if ($year >= 1920 && $year < 1941) {
        $t = $year - 1920;
        return 21.20 + 0.84493 * $t - 0.076100 * $t * $t + 0.0020936 * pow($t, 3);
    }
    if ($year >= 1900 && $year < 1920) {
        $t = $year - 1900;
        return -2.79 + 1.494119 * $t - 0.0598939 * $t * $t
               + 0.0061966 * pow($t, 3) - 0.000197 * pow($t, 4);
    }

    $t = ($year - 1820) / 100.0;
    return -20 + 32 * $t * $t;
}

/**
 * Convert Julian Ephemeris Day to Unix timestamp.
 */
function jde_to_unix(float $jde, ?int $year = null): int
{
    $unix_td = ($jde - 2440587.5) * 86400;

    if ($year !== null) {
        return (int) round($unix_td - calculate_delta_t($year));
    }

    return (int) round($unix_td);
}

// ============================================================================
// PUBLIC API WRAPPERS
// ============================================================================

/**
 * Calculate sun times - main public API wrapper.
 *
 * @param int $y Year
 * @param int $m Month (1-12)
 * @param int $d Day of month
 * @param float $lat Latitude in degrees (-90 to 90)
 * @param float $lon Longitude in degrees (-180 to 180)
 * @param float $utc_offset UTC offset in hours
 * @return array Solar times and parameters
 */
function calculate_sun_times(int $y, int $m, int $d, float $lat, float $lon, float $utc_offset): array
{
    return calculate_sun_times_spa($y, $m, $d, $lat, $lon, $utc_offset);
}

/**
 * Get moon phase info - wrapper for Meeus algorithm.
 */
function get_moon_phase_info(int $timestamp): array
{
    return get_accurate_moon_phase($timestamp);
}
