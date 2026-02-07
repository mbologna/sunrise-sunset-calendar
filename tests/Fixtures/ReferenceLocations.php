<?php

namespace Tests\Fixtures;

/**
 * Reference Location Data
 * Centralized test data for multiple geographic locations
 * Sources: timeanddate.com, NOAA Solar Calculator.
 */
class ReferenceLocations
{
    /**
     * Mapello, Bergamo, Italy - Mid-latitude Northern Hemisphere
     * Comprehensive twilight validation with real-world reference data.
     *
     * @return array Location data and reference times
     */
    public static function mapello(): array
    {
        return [
            'name' => 'Mapello, Bergamo, Italy',
            'lat' => 45.7,
            'lon' => 9.6,
            'timezone_offset' => 1, // CET (UTC+1 in February)
            'characteristics' => ['mid-latitude', 'northern-hemisphere', 'temperate'],

            'reference_dates' => [
                '2026-02-01' => [
                    // Night: 00:00-06:01, 19:09-00:00 (Total: 10:51)
                    'night_start' => '19:09',
                    'night_end' => '06:01',
                    'night_duration_seconds' => 39060, // 10h 51m

                    // Astronomical Twilight: 06:01-06:36, 18:35-19:09 (Total: 01:10)
                    'astronomical_dawn_start' => '06:01',
                    'astronomical_dawn_end' => '06:36',
                    'astronomical_dusk_start' => '18:35',
                    'astronomical_dusk_end' => '19:09',
                    'astronomical_twilight_duration_seconds' => 4200, // 1h 10m

                    // Nautical Twilight: 06:36-07:11, 17:59-18:35 (Total: 01:11)
                    'nautical_dawn_start' => '06:36',
                    'nautical_dawn_end' => '07:11',
                    'nautical_dusk_start' => '17:59',
                    'nautical_dusk_end' => '18:35',
                    'nautical_twilight_duration_seconds' => 4260, // 1h 11m

                    // Civil Twilight: 07:11-07:43, 17:27-17:59 (Total: 01:03)
                    'civil_dawn_start' => '07:11',
                    'civil_dawn_end' => '07:43',
                    'civil_dusk_start' => '17:27',
                    'civil_dusk_end' => '17:59',
                    'civil_twilight_duration_seconds' => 3780, // 1h 3m

                    // Daylight: 07:43-17:27 (Total: 09:44)
                    'sunrise' => '07:43',
                    'sunset' => '17:27',
                    'daylight_duration_seconds' => 35040, // 9h 44m

                    // Solar Events
                    'solar_noon' => '12:35',
                    'solar_midnight' => '00:35',

                    // Day length: 9h44m (+2m38s than yesterday)
                    'day_length_change_seconds' => 158, // +2m 38s
                ],
            ],
        ];
    }

    /**
     * Tromsø, Norway - Arctic Circle (midnight sun/polar night)
     * Tests extreme conditions: polar day and polar night scenarios.
     *
     * @return array Location data and reference times
     */
    public static function tromso(): array
    {
        return [
            'name' => 'Tromsø, Norway',
            'lat' => 69.6492,
            'lon' => 18.9553,
            'timezone_offset' => 1, // CET
            'characteristics' => ['arctic', 'midnight-sun', 'polar-night', 'extreme'],

            'reference_dates' => [
                // Summer solstice - Midnight sun (sun never sets)
                '2026-06-21' => [
                    'sunrise' => null, // No sunrise/sunset during midnight sun
                    'sunset' => null,
                    'daylight_duration_seconds' => 86400, // Full 24 hours
                    'has_midnight_sun' => true,
                ],

                // Winter solstice - Polar night (sun never rises)
                '2026-12-21' => [
                    'sunrise' => null, // No sunrise/sunset during polar night
                    'sunset' => null,
                    'daylight_duration_seconds' => 0, // No daylight
                    'has_polar_night' => true,
                ],

                // Spring equinox - Normal day
                '2026-03-20' => [
                    'sunrise' => '06:01',
                    'sunset' => '18:16',
                    'daylight_duration_seconds' => 43980, // ~12h 13m
                ],
            ],
        ];
    }

    /**
     * Quito, Ecuador - Equator (consistent daylight year-round)
     * Tests equatorial location with minimal seasonal variation.
     *
     * @return array Location data and reference times
     */
    public static function quito(): array
    {
        return [
            'name' => 'Quito, Ecuador',
            'lat' => -0.1807,
            'lon' => -78.4678,
            'timezone_offset' => -5, // ECT (UTC-5)
            'characteristics' => ['equator', 'consistent-daylight', 'minimal-variation'],

            'reference_dates' => [
                // Equinoxes - Nearly 12 hours everywhere, especially at equator
                '2026-03-20' => [
                    'sunrise' => '06:16',
                    'sunset' => '18:22',
                    'daylight_duration_seconds' => 43560, // ~12h 6m
                ],

                // June solstice - Slightly shorter day
                '2026-06-21' => [
                    'sunrise' => '06:20',
                    'sunset' => '18:23',
                    'daylight_duration_seconds' => 43380, // ~12h 3m
                ],

                // December solstice - Slightly longer day
                '2026-12-21' => [
                    'sunrise' => '06:11',
                    'sunset' => '18:20',
                    'daylight_duration_seconds' => 43740, // ~12h 9m
                ],
            ],
        ];
    }

    /**
     * Sydney, Australia - Southern Hemisphere (reversed seasons)
     * Tests southern hemisphere with opposite seasonal patterns.
     *
     * @return array Location data and reference times
     */
    public static function sydney(): array
    {
        return [
            'name' => 'Sydney, Australia',
            'lat' => -33.8688,
            'lon' => 151.2093,
            'timezone_offset' => 10, // AEST (UTC+10, without DST)
            'characteristics' => ['southern-hemisphere', 'reversed-seasons', 'mid-latitude'],

            'reference_dates' => [
                // June solstice - Winter in southern hemisphere (shortest day)
                '2026-06-21' => [
                    'sunrise' => '07:00',
                    'sunset' => '16:58',
                    'daylight_duration_seconds' => 35880, // ~9h 58m
                ],

                // December solstice - Summer in southern hemisphere (longest day)
                '2026-12-21' => [
                    'sunrise' => '05:43',
                    'sunset' => '20:07',
                    'daylight_duration_seconds' => 51840, // ~14h 24m
                ],

                // March equinox - Nearly 12 hours
                '2026-03-20' => [
                    'sunrise' => '06:46',
                    'sunset' => '19:00',
                    'daylight_duration_seconds' => 43980, // ~12h 13m
                ],
            ],
        ];
    }

    /**
     * Reykjavik, Iceland - Sub-Arctic (extreme day length variation)
     * Tests sub-arctic location with significant seasonal changes.
     *
     * @return array Location data and reference times
     */
    public static function reykjavik(): array
    {
        return [
            'name' => 'Reykjavik, Iceland',
            'lat' => 64.1466,
            'lon' => -21.9426,
            'timezone_offset' => 0, // GMT/UTC
            'characteristics' => ['sub-arctic', 'extreme-variation', 'northern-hemisphere'],

            'reference_dates' => [
                // June solstice - Nearly midnight sun (longest day)
                '2026-06-21' => [
                    'sunrise' => '02:54',
                    'sunset' => '00:03', // Next day!
                    'daylight_duration_seconds' => 75480, // ~21h (approx, complex with midnight)
                    'nearly_midnight_sun' => true,
                ],

                // December solstice - Shortest day
                '2026-12-21' => [
                    'sunrise' => '11:19',
                    'sunset' => '15:43',
                    'daylight_duration_seconds' => 14880, // ~4h 8m
                ],

                // Equinox - Normal ~12h day
                '2026-03-20' => [
                    'sunrise' => '06:52',
                    'sunset' => '19:11',
                    'daylight_duration_seconds' => 43980, // ~12h 13m
                ],
            ],
        ];
    }

    /**
     * Get all reference locations.
     *
     * @return array Array of all location data
     */
    public static function all(): array
    {
        return [
            'mapello' => self::mapello(),
            'tromso' => self::tromso(),
            'quito' => self::quito(),
            'sydney' => self::sydney(),
            'reykjavik' => self::reykjavik(),
        ];
    }

    /**
     * Get location by name.
     *
     * @param string $name Location name (mapello, tromso, quito, sydney, reykjavik)
     * @return array|null Location data or null if not found
     */
    public static function get(string $name): ?array
    {
        $all = self::all();

        return $all[$name] ?? null;
    }
}
