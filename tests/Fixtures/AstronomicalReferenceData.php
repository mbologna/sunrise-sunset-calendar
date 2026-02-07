<?php

/**
 * Astronomical Reference Data for Testing.
 *
 * Data sources:
 * - timeanddate.com (primary source)
 * - USNO Astronomical Applications Department
 * - EarthSky.org (verification)
 *
 * All times are in UTC unless otherwise specified.
 * Accuracy requirement: ±1 minute (60 seconds)
 */
class AstronomicalReferenceData
{
    /**
     * Equinoxes and Solstices (2024-2030).
     *
     * Sources:
     * - https://www.timeanddate.com/calendar/seasons.html?n=1440
     * - https://aa.usno.navy.mil/data/Earth_Seasons
     *
     * Format: [year => [event => 'YYYY-MM-DD HH:MM UTC']]
     */
    public static function getEquinoxesSolstices()
    {
        return [
            2024 => [
                'march_equinox' => '2024-03-20 03:06',
                'june_solstice' => '2024-06-20 20:51',
                'september_equinox' => '2024-09-22 12:44',
                'december_solstice' => '2024-12-21 09:21',
            ],
            2025 => [
                'march_equinox' => '2025-03-20 09:01',
                'june_solstice' => '2025-06-21 02:42',
                'september_equinox' => '2025-09-22 18:19',
                'december_solstice' => '2025-12-21 15:03',
            ],
            2026 => [
                'march_equinox' => '2026-03-20 14:46',
                'june_solstice' => '2026-06-21 08:24',
                'september_equinox' => '2026-09-23 00:05',
                'december_solstice' => '2026-12-21 20:50',
            ],
            2027 => [
                'march_equinox' => '2027-03-20 20:25',
                'june_solstice' => '2027-06-21 14:11',
                'september_equinox' => '2027-09-23 06:02',
                'december_solstice' => '2027-12-22 02:42',
            ],
            2028 => [
                'march_equinox' => '2028-03-20 02:17',
                'june_solstice' => '2028-06-20 20:02',
                'september_equinox' => '2028-09-22 11:45',
                'december_solstice' => '2028-12-21 08:20',
            ],
            2029 => [
                'march_equinox' => '2029-03-20 08:02',
                'june_solstice' => '2029-06-21 01:48',
                'september_equinox' => '2029-09-22 17:38',
                'december_solstice' => '2029-12-21 14:14',
            ],
            2030 => [
                'march_equinox' => '2030-03-20 13:52',
                'june_solstice' => '2030-06-21 07:31',
                'september_equinox' => '2030-09-22 23:27',
                'december_solstice' => '2030-12-21 20:09',
            ],
        ];
    }

    /**
     * Moon Phases for 2026.
     *
     * Sources:
     * - https://www.timeanddate.com/moon/phases/timezone/utc
     * - https://aa.usno.navy.mil/data/MoonPhases
     *
     * Format: [['phase' => 'Name', 'date' => 'YYYY-MM-DD HH:MM UTC']]
     */
    public static function getMoonPhases2026()
    {
        return [
            // January 2026
            ['phase' => 'Full Moon', 'date' => '2026-01-03 10:02'],
            ['phase' => 'Last Quarter', 'date' => '2026-01-10 15:48'],
            ['phase' => 'New Moon', 'date' => '2026-01-18 19:52'],
            ['phase' => 'First Quarter', 'date' => '2026-01-26 04:47'],

            // February 2026
            ['phase' => 'Full Moon', 'date' => '2026-02-01 22:09'],
            ['phase' => 'Last Quarter', 'date' => '2026-02-09 12:43'],
            ['phase' => 'New Moon', 'date' => '2026-02-17 12:01'],
            ['phase' => 'First Quarter', 'date' => '2026-02-24 12:27'],

            // March 2026 (CORRECTED DATA from timeanddate.com)
            ['phase' => 'Full Moon', 'date' => '2026-03-03 11:37'],
            ['phase' => 'Last Quarter', 'date' => '2026-03-11 09:38'],
            ['phase' => 'New Moon', 'date' => '2026-03-19 01:23'],
            ['phase' => 'First Quarter', 'date' => '2026-03-25 19:17'],

            // April 2026 (CORRECTED DATA)
            ['phase' => 'Full Moon', 'date' => '2026-04-02 02:11'],
            ['phase' => 'Last Quarter', 'date' => '2026-04-10 04:51'],
            ['phase' => 'New Moon', 'date' => '2026-04-17 11:51'],
            ['phase' => 'First Quarter', 'date' => '2026-04-24 02:31'],

            // May 2026 (CORRECTED DATA)
            ['phase' => 'Full Moon', 'date' => '2026-05-01 17:23'],
            ['phase' => 'Last Quarter', 'date' => '2026-05-09 21:10'],
            ['phase' => 'New Moon', 'date' => '2026-05-16 20:01'],
            ['phase' => 'First Quarter', 'date' => '2026-05-23 11:10'],
            ['phase' => 'Full Moon', 'date' => '2026-05-31 08:45'],

            // June 2026 (CORRECTED DATA)
            ['phase' => 'Last Quarter', 'date' => '2026-06-08 10:00'],
            ['phase' => 'New Moon', 'date' => '2026-06-15 02:54'],
            ['phase' => 'First Quarter', 'date' => '2026-06-21 21:55'],
            ['phase' => 'Full Moon', 'date' => '2026-06-29 23:56'],

            // July 2026 (CORRECTED DATA)
            ['phase' => 'Last Quarter', 'date' => '2026-07-07 19:29'],
            ['phase' => 'New Moon', 'date' => '2026-07-14 09:43'],
            ['phase' => 'First Quarter', 'date' => '2026-07-21 11:05'],
            ['phase' => 'Full Moon', 'date' => '2026-07-29 14:35'],

            // August 2026 (CORRECTED DATA)
            ['phase' => 'Last Quarter', 'date' => '2026-08-06 02:21'],
            ['phase' => 'New Moon', 'date' => '2026-08-12 17:36'],
            ['phase' => 'First Quarter', 'date' => '2026-08-20 02:46'],
            ['phase' => 'Full Moon', 'date' => '2026-08-28 04:18'],

            // September 2026 (CORRECTED DATA)
            ['phase' => 'Last Quarter', 'date' => '2026-09-04 07:51'],
            ['phase' => 'New Moon', 'date' => '2026-09-11 03:27'],
            ['phase' => 'First Quarter', 'date' => '2026-09-18 20:43'],
            ['phase' => 'Full Moon', 'date' => '2026-09-26 16:49'],

            // October 2026 (CORRECTED DATA)
            ['phase' => 'Last Quarter', 'date' => '2026-10-03 13:25'],
            ['phase' => 'New Moon', 'date' => '2026-10-10 15:50'],
            ['phase' => 'First Quarter', 'date' => '2026-10-18 16:12'],
            ['phase' => 'Full Moon', 'date' => '2026-10-26 04:11'],

            // November 2026 (CORRECTED DATA)
            ['phase' => 'Last Quarter', 'date' => '2026-11-01 20:28'],
            ['phase' => 'New Moon', 'date' => '2026-11-09 07:02'],
            ['phase' => 'First Quarter', 'date' => '2026-11-17 11:47'],
            ['phase' => 'Full Moon', 'date' => '2026-11-24 14:53'],

            // December 2026 (CORRECTED DATA)
            ['phase' => 'Last Quarter', 'date' => '2026-12-01 06:08'],
            ['phase' => 'New Moon', 'date' => '2026-12-09 00:51'],
            ['phase' => 'First Quarter', 'date' => '2026-12-17 05:42'],
            ['phase' => 'Full Moon', 'date' => '2026-12-24 01:28'],
            ['phase' => 'Last Quarter', 'date' => '2026-12-30 18:59'],
        ];
    }

    /**
     * Convert reference date string to Unix timestamp (UTC).
     *
     * @param string $dateString Format: 'YYYY-MM-DD HH:MM'
     * @return int Unix timestamp
     */
    public static function dateToTimestamp($dateString)
    {
        $dt = DateTime::createFromFormat('Y-m-d H:i', $dateString, new DateTimeZone('UTC'));
        if (!$dt) {
            throw new Exception("Invalid date format: {$dateString}");
        }

        return $dt->getTimestamp();
    }

    /**
     * Phase name mapping (normalize different naming conventions).
     */
    public static function normalizePhaseName($phase)
    {
        $mapping = [
            'Last Quarter' => 'Last Quarter',
            'Third Quarter' => 'Last Quarter',
            'First Quarter' => 'First Quarter',
            'Full Moon' => 'Full Moon',
            'New Moon' => 'New Moon',
        ];

        return $mapping[$phase] ?? $phase;
    }
}
