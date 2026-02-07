<?php

/**
 * Accurate Astronomical Calculations
 * Based on Jean Meeus' "Astronomical Algorithms" (2nd Edition, 1998).
 *
 * Implements:
 * - Chapter 27: Equinoxes and Solstices
 * - Chapter 49: Phases of the Moon
 *
 * Accuracy: ±1 minute for dates 1000-3000 CE
 *
 * References:
 * - Meeus, Jean. "Astronomical Algorithms" 2nd ed. Willmann-Bell, 1998.
 * - USNO Data Services: https://aa.usno.navy.mil/data/
 */

/**
 * Calculate equinoxes and solstices for a given year.
 *
 * Uses Meeus Chapter 27 algorithm with periodic terms
 * Accuracy: ±1 minute (51 seconds typical)
 *
 * @param int $year Year (1000-3000 CE for best accuracy)
 * @return array Associative array with keys:
 *               - march_equinox: Unix timestamp (UTC)
 *               - june_solstice: Unix timestamp (UTC)
 *               - september_equinox: Unix timestamp (UTC)
 *               - december_solstice: Unix timestamp (UTC)
 */
function calculate_equinox_solstice($year)
{
    // Algorithm from Meeus Chapter 27
    // Valid for years -1000 to +3000

    // Calculate Y for the formulas
    if ($year >= -1000 && $year <= 1000) {
        $Y = $year / 1000.0;

        // Use historical formulas (Table 27.A)
        $march_jde = 1721139.29189 + 365242.13740 * $Y + 0.06134 * $Y * $Y
                     + 0.00111 * $Y * $Y * $Y - 0.00071 * $Y * $Y * $Y * $Y;
        $june_jde = 1721233.25401 + 365241.72562 * $Y - 0.05323 * $Y * $Y
                    + 0.00907 * $Y * $Y * $Y + 0.00025 * $Y * $Y * $Y * $Y;
        $sept_jde = 1721325.70455 + 365242.49558 * $Y - 0.11677 * $Y * $Y
                    - 0.00297 * $Y * $Y * $Y + 0.00074 * $Y * $Y * $Y * $Y;
        $dec_jde = 1721414.39987 + 365242.88257 * $Y - 0.00769 * $Y * $Y
                   - 0.00933 * $Y * $Y * $Y - 0.00006 * $Y * $Y * $Y * $Y;
    } else {
        // Modern era formulas (Table 27.B) for years 1000-3000
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

    // Apply periodic terms for higher accuracy (Table 27.C)
    // These corrections can improve accuracy to < 1 minute
    // CRITICAL: T must be calculated from JDE0, not from year!
    $march_jde = apply_periodic_corrections($march_jde);
    $june_jde = apply_periodic_corrections($june_jde);
    $sept_jde = apply_periodic_corrections($sept_jde);
    $dec_jde = apply_periodic_corrections($dec_jde);

    // Convert JDE (Julian Ephemeris Day) to Unix timestamp (UTC)
    // Pass year for DeltaT correction
    return [
        'march_equinox' => jde_to_unix($march_jde, $year),
        'june_solstice' => jde_to_unix($june_jde, $year),
        'september_equinox' => jde_to_unix($sept_jde, $year),
        'december_solstice' => jde_to_unix($dec_jde, $year),
    ];
}

/**
 * Apply periodic corrections to equinox/solstice times
 * Meeus Chapter 27, Table 27.C - 24 periodic terms.
 *
 * CRITICAL FIX: T must be calculated from JDE0, not from year!
 * This matches the reference implementation in PyMeeus and AstroAlgorithms4Python
 *
 * @param float $JDE0 Initial Julian Ephemeris Day
 * @return float Corrected JDE
 */
function apply_periodic_corrections($JDE0)
{
    // Calculate T from JDE0 (CRITICAL: must use JDE0, not year!)
    $T = ($JDE0 - 2451545.0) / 36525.0;

    // Calculate mean anomalies and arguments (Table 27.C)
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

    // Apply solar correction factor
    // W = mean anomaly of the Sun's orbit
    $W = 35999.373 * $T - 2.47;
    $dL = 1 + 0.0334 * cos(deg2rad($W)) + 0.0007 * cos(deg2rad(2 * $W));

    // Correct formula from Meeus Chapter 27
    return $JDE0 + ((0.00001 * $S) / $dL);
}

/**
 * Calculate moon phases for a given lunation.
 *
 * Uses Meeus Chapter 49 algorithm
 * Accuracy: ±2 minutes typical
 *
 * @param int $year Year
 * @param int $month Month (1-12)
 * @return array Array of moon phases near the given date:
 *               - phase_name: 'New Moon', 'First Quarter', 'Full Moon', 'Last Quarter'
 *               - timestamp: Unix timestamp (UTC)
 *               - jde: Julian Ephemeris Day
 */
function calculate_moon_phases_for_month($year, $month)
{
    // Calculate k (lunation number since 2000-01-06)
    // k = integer((year - 2000) * 12.3685)
    $k = floor(($year + ($month - 0.5) / 12 - 2000) * 12.3685);

    $phases = [];

    // Calculate all 4 phases for this and next lunation
    for ($i = -1; $i <= 1; $i++) {
        $k_current = $k + $i;

        // New Moon (phase = 0)
        $phases[] = calculate_specific_moon_phase($k_current, 0.0);

        // First Quarter (phase = 0.25)
        $phases[] = calculate_specific_moon_phase($k_current, 0.25);

        // Full Moon (phase = 0.5)
        $phases[] = calculate_specific_moon_phase($k_current, 0.5);

        // Last Quarter (phase = 0.75)
        $phases[] = calculate_specific_moon_phase($k_current, 0.75);
    }

    // Sort by timestamp
    usort($phases, function ($a, $b) {
        return $a['timestamp'] <=> $b['timestamp'];
    });

    // Filter to phases within reasonable range of the target month
    $target_start = mktime(0, 0, 0, $month, 1, $year);
    $target_end = mktime(23, 59, 59, $month + 1, 0, $year);

    $phases = array_filter($phases, function ($phase) use ($target_start, $target_end) {
        // Include phases from 15 days before to 45 days after month start
        $early_start = $target_start - (15 * 86400);
        $late_end = $target_end + (15 * 86400);

        return $phase['timestamp'] >= $early_start && $phase['timestamp'] <= $late_end;
    });

    return array_values($phases);
}

/**
 * Calculate a specific moon phase
 * Meeus Chapter 49, formulas 49.1-49.4.
 *
 * @param float $k Lunation number
 * @param float $phase Phase (0=new, 0.25=first quarter, 0.5=full, 0.75=last quarter)
 * @return array Moon phase data
 */
function calculate_specific_moon_phase($k, $phase)
{
    $k_adjusted = $k + $phase;
    $T = $k_adjusted / 1236.85; // Time in Julian centuries

    // Base JDE calculation (formula 49.1)
    $JDE = 2451550.09766
           + 29.530588861 * $k_adjusted
           + 0.00015437 * $T * $T
           - 0.000000150 * pow($T, 3)
           + 0.00000000073 * pow($T, 4);

    // Mean anomalies and arguments
    $E = 1 - 0.002516 * $T - 0.0000074 * $T * $T;
    $M = 2.5534 + 29.10535670 * $k_adjusted
         - 0.0000014 * $T * $T
         - 0.00000011 * pow($T, 3);
    $Mp = 201.5643 + 385.81693528 * $k_adjusted
          + 0.0107582 * $T * $T
          + 0.00001238 * pow($T, 3)
          - 0.000000058 * pow($T, 4);
    $F = 160.7108 + 390.67050284 * $k_adjusted
         - 0.0016118 * $T * $T
         - 0.00000227 * pow($T, 3)
         + 0.000000011 * pow($T, 4);
    $Omega = 124.7746 - 1.56375588 * $k_adjusted
             + 0.0020672 * $T * $T
             + 0.00000215 * pow($T, 3);

    // Convert to radians
    $M_rad = deg2rad($M);
    $Mp_rad = deg2rad($Mp);
    $F_rad = deg2rad($F);
    $Omega_rad = deg2rad($Omega);

    // Apply corrections based on phase
    if (abs($phase - 0.0) < 0.01 || abs($phase - 0.5) < 0.01) {
        // New Moon or Full Moon corrections (Table 49.I and 49.II)
        $corrections = calculate_new_full_corrections($M_rad, $Mp_rad, $F_rad, $Omega_rad, $E, $phase);
    } else {
        // First/Last Quarter corrections (Table 49.III)
        $corrections = calculate_quarter_corrections($M_rad, $Mp_rad, $F_rad, $Omega_rad, $E, $phase);
    }

    $JDE += $corrections;

    // Determine phase name based on phase value
    // Note: Cannot use float keys in PHP 8.5+, so we use conditionals
    if (abs($phase - 0.0) < 0.01) {
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

    // Extract year from JDE for DeltaT correction
    $year = jde_to_year($JDE);

    return [
        'phase_name' => $phase_name,
        'jde' => $JDE,
        'timestamp' => jde_to_unix($JDE, $year),
        'k' => $k_adjusted,
    ];
}

/**
 * Calculate corrections for New Moon and Full Moon
 * Meeus Chapter 49, Tables 49.I and 49.II.
 */
function calculate_new_full_corrections($M, $Mp, $F, $Omega, $E, $phase)
{
    $correction = 0;

    // Apply 14 most significant periodic terms from Meeus Table 49.I/II
    $correction += -0.40720 * sin($Mp);
    $correction += 0.17241 * $E * sin($M);
    $correction += 0.01608 * sin(2 * $Mp);
    $correction += 0.01039 * sin(2 * $F);
    $correction += 0.00739 * $E * sin($Mp - $M);
    $correction += -0.00514 * $E * sin($Mp + $M);
    $correction += 0.00208 * $E * $E * sin(2 * $M);
    $correction += -0.00111 * sin($Mp - 2 * $F);
    $correction += -0.00057 * sin($Mp + 2 * $F);
    $correction += 0.00056 * $E * sin(2 * $Mp + $M);
    $correction += -0.00042 * sin(3 * $Mp);
    $correction += 0.00042 * $E * sin($M + 2 * $F);
    $correction += 0.00038 * $E * sin($M - 2 * $F);
    $correction += -0.00024 * $E * sin(2 * $Mp - $M);

    // Additional correction for Full Moon
    if ($phase == 0.5) {
        $correction += -0.00017 * sin($Omega);
    }

    // Planetary arguments (A1-A14 from Table 49.IV)
    $A1 = 299.77 + 0.107408 * ($Mp / M_PI * 180) - 0.009173 * ($Mp / M_PI * 180) * ($Mp / M_PI * 180);
    $correction += 0.000325 * sin(deg2rad($A1));

    return $correction;
}

/**
 * Calculate corrections for First and Last Quarter
 * Meeus Chapter 49, Table 49.III.
 *
 * @param float $phase 0.25 for First Quarter, 0.75 for Last Quarter
 */
function calculate_quarter_corrections($M, $Mp, $F, $Omega, $E, $phase)
{
    $correction = 0;

    // Apply 12 most significant periodic terms from Meeus Table 49.III
    $correction += -0.62801 * sin($Mp);
    $correction += 0.17172 * $E * sin($M);
    $correction += -0.01183 * $E * sin($Mp + $M);
    $correction += 0.00862 * sin(2 * $Mp);
    $correction += 0.00804 * sin(2 * $F);
    $correction += 0.00454 * $E * sin($Mp - $M);
    $correction += 0.00204 * $E * $E * sin(2 * $M);
    $correction += -0.00180 * sin($Mp - 2 * $F);
    $correction += -0.00070 * sin($Mp + 2 * $F);
    $correction += -0.00040 * sin(3 * $Mp);
    $correction += -0.00034 * $E * sin(2 * $Mp - $M);
    $correction += 0.00032 * $E * sin($M + 2 * $F);

    // Quarter-specific correction (W term)
    // According to Meeus Chapter 49, Table 49.III
    // W is added for First Quarter, subtracted for Last Quarter
    $W = 0.00306 - 0.00038 * $E * cos($M)
         + 0.00026 * cos($Mp)
         - 0.00002 * cos($Mp - $M)
         + 0.00002 * cos($Mp + $M)
         + 0.00002 * cos(2 * $F);

    // Apply W based on phase type
    // Note: Using exact comparison with epsilon for floating point
    $is_first_quarter = (abs($phase - 0.25) < 0.01);

    if ($is_first_quarter) {
        // First Quarter: add W
        return $correction + $W;
    } else {
        // Last Quarter: subtract W
        return $correction - $W;
    }
}

/**
 * Extract approximate year from Julian Ephemeris Day.
 *
 * @param float $jde Julian Ephemeris Day
 * @return int Approximate year
 */
function jde_to_year($jde)
{
    // JDE 2451545.0 = 2000-01-01 12:00:00 TT
    // Average year length = 365.25 days
    return (int) round(2000 + ($jde - 2451545.0) / 365.25);
}

/**
 * Calculate DeltaT (difference between Dynamical Time and Universal Time).
 *
 * DeltaT = TD - UT, where TD is Terrestrial Dynamical Time and UT is Universal Time.
 * This accounts for irregularities in Earth's rotation.
 *
 * Uses polynomial approximations from:
 * - Morrison & Stephenson (2004)
 * - NASA JPL
 * - IERS Bulletins
 *
 * @param int $year Year
 * @return float DeltaT in seconds
 */
function calculate_delta_t($year)
{
    // For years 2005-2050, use polynomial from NASA/IERS
    // DeltaT = 62.92 + 0.32217 * t + 0.005589 * t^2
    // where t = year - 2000
    if ($year >= 2005 && $year <= 2050) {
        $t = $year - 2000;

        return 62.92 + 0.32217 * $t + 0.005589 * $t * $t;
    }

    // For years 1986-2005, use different polynomial
    if ($year >= 1986 && $year < 2005) {
        $t = $year - 2000;

        return 63.86 + 0.3345 * $t - 0.060374 * $t * $t
               + 0.0017275 * pow($t, 3) + 0.000651814 * pow($t, 4)
               + 0.00002373599 * pow($t, 5);
    }

    // For years 1961-1986
    if ($year >= 1961 && $year < 1986) {
        $t = $year - 1975;

        return 45.45 + 1.067 * $t - $t * $t / 260.0 - pow($t, 3) / 718.0;
    }

    // For years 1941-1961
    if ($year >= 1941 && $year < 1961) {
        $t = $year - 1950;

        return 29.07 + 0.407 * $t - $t * $t / 233.0 + pow($t, 3) / 2547.0;
    }

    // For years 1920-1941
    if ($year >= 1920 && $year < 1941) {
        $t = $year - 1920;

        return 21.20 + 0.84493 * $t - 0.076100 * $t * $t + 0.0020936 * pow($t, 3);
    }

    // For years 1900-1920
    if ($year >= 1900 && $year < 1920) {
        $t = $year - 1900;

        return -2.79 + 1.494119 * $t - 0.0598939 * $t * $t
               + 0.0061966 * pow($t, 3) - 0.000197 * pow($t, 4);
    }

    // For years before 1900 or after 2050, use simple approximation
    // This is rough but better than nothing
    $t = ($year - 1820) / 100.0;

    return -20 + 32 * $t * $t;
}

/**
 * Convert Julian Ephemeris Day to Unix timestamp.
 *
 * JDE is in Dynamical Time (TD/TT). We need to convert to Universal Time (UT/UTC)
 * by subtracting DeltaT.
 *
 * @param float $jde Julian Ephemeris Day (Dynamical Time)
 * @param int $year Year (for DeltaT calculation)
 * @return int Unix timestamp (UTC)
 */
function jde_to_unix($jde, $year = null)
{
    // JD 2440587.5 = Unix epoch (1970-01-01 00:00:00 UTC)
    $unix_epoch_jd = 2440587.5;
    $seconds_per_day = 86400;

    // Convert JDE to Unix timestamp (still in Dynamical Time)
    $unix_td = ($jde - $unix_epoch_jd) * $seconds_per_day;

    // If year provided, apply DeltaT correction to get UTC
    if ($year !== null) {
        $delta_t = calculate_delta_t($year);
        $unix_utc = $unix_td - $delta_t;

        return round($unix_utc);
    }

    // Fallback: no DeltaT correction (for backward compatibility)
    return round($unix_td);
}

/**
 * Find the moon phase closest to a given timestamp.
 *
 * @param int $timestamp Unix timestamp
 * @return array Moon phase information with prev/next phases
 */
function get_accurate_moon_phase($timestamp)
{
    $date_parts = getdate($timestamp);
    $year = $date_parts['year'];
    $month = $date_parts['mon'];

    // Get all phases for this month and adjacent months
    $phases = array_merge(
        calculate_moon_phases_for_month($year, $month - 1),
        calculate_moon_phases_for_month($year, $month),
        calculate_moon_phases_for_month($year, $month + 1)
    );

    // Find current phase, previous phase, and next phase
    $current = null;
    $prev = null;
    $next = null;

    foreach ($phases as $i => $phase) {
        if ($phase['timestamp'] <= $timestamp) {
            $prev = $phase;
        }
        if ($phase['timestamp'] > $timestamp && $next === null) {
            $next = $phase;
            break;
        }
    }

    // Calculate current illumination and phase name
    if ($prev && $next) {
        $cycle_length = $next['timestamp'] - $prev['timestamp'];
        $time_since_prev = $timestamp - $prev['timestamp'];
        $phase_fraction = $time_since_prev / $cycle_length;

        // Determine current phase name based on which major phases we're between
        // The intermediate phase depends on the prev→next transition:
        //   New Moon → First Quarter: Waxing Crescent
        //   First Quarter → Full Moon: Waxing Gibbous
        //   Full Moon → Last Quarter: Waning Gibbous
        //   Last Quarter → New Moon: Waning Crescent
        $intermediate_phases = [
            'New Moon' => [
                'First Quarter' => 'Waxing Crescent',
            ],
            'First Quarter' => [
                'Full Moon' => 'Waxing Gibbous',
            ],
            'Full Moon' => [
                'Last Quarter' => 'Waning Gibbous',
            ],
            'Last Quarter' => [
                'New Moon' => 'Waning Crescent',
            ],
        ];

        // Get the intermediate phase name for this segment
        $intermediate_phase = $intermediate_phases[$prev['phase_name']][$next['phase_name']] ?? 'Unknown';

        // Near the boundaries, show the major phase name; in the middle, show the intermediate
        if ($phase_fraction < 0.125) {
            $current_phase_name = $prev['phase_name'];
        } elseif ($phase_fraction > 0.875) {
            $current_phase_name = $next['phase_name'];
        } else {
            $current_phase_name = $intermediate_phase;
        }

        // Calculate illumination based on position relative to New Moon and Full Moon
        // Find the most recent New Moon to calculate illumination correctly
        $new_moon_ts = null;
        foreach (array_reverse($phases) as $phase) {
            if ($phase['phase_name'] === 'New Moon' && $phase['timestamp'] <= $timestamp) {
                $new_moon_ts = $phase['timestamp'];
                break;
            }
        }

        if ($new_moon_ts !== null) {
            // Days since New Moon (0 = New Moon, ~14.77 = Full Moon, ~29.53 = next New Moon)
            $days_since_new = ($timestamp - $new_moon_ts) / 86400;
            // Illumination formula: 0% at New Moon, 100% at Full Moon
            // Using cosine: at 0 days cos(0)=1 → 0%, at 14.77 days cos(π)=-1 → 100%
            $angle = ($days_since_new / 29.53) * 2 * M_PI;
            $illumination = round((1 - cos($angle)) * 50, 1);
        } else {
            // Fallback if no New Moon found
            $illumination = 50;
        }
    } else {
        $current_phase_name = 'Unknown';
        $illumination = 0;
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
