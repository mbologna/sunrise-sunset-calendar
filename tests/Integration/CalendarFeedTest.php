<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end tests of generate_calendar() output.
 */
class CalendarFeedTest extends TestCase
{
    private const ALL_TYPES = ['civil' => true, 'nautical' => true, 'astro' => true, 'daylight' => true];

    /**
     * @param array $overrides Options replacing the defaults
     */
    private function feed(array $overrides = []): string
    {
        return generate_calendar($overrides + [
            'lat' => 41.9028,
            'lon' => 12.4964,
            'timezone' => 'Europe/Rome',
            'location' => 'Rome',
            'description' => '',
            'rise_offset' => 0,
            'set_offset' => 0,
            'enabled' => self::ALL_TYPES,
            'past_days' => 0,
            'window_days' => 365,
            'refresh_seconds' => 86400,
            'today' => '2026-10-15',
        ]);
    }

    /**
     * Parse VEVENTs into property maps (unfolded, keyed by name without parameters).
     *
     * @return array<array<string, string>>
     */
    private function events(string $ics): array
    {
        $unfolded = str_replace("\r\n ", '', $ics);
        preg_match_all('/BEGIN:VEVENT\r\n(.*?)END:VEVENT/s', $unfolded, $matches);

        return array_map(function (string $block): array {
            $props = [];
            foreach (explode("\r\n", trim($block)) as $line) {
                [$name, $value] = explode(':', $line, 2);
                $props[explode(';', $name)[0]] = $value;
            }
            return $props;
        }, $matches[1]);
    }

    /**
     * @return array<array<string, string>> Events whose UID starts with $prefix
     */
    private function eventsOfType(string $ics, string $prefix): array
    {
        return array_values(array_filter($this->events($ics), fn($e) => str_starts_with($e['UID'], $prefix . '-')));
    }

    public function testFeedIsWellFormed(): void
    {
        $ics = $this->feed(['location' => 'Città di Roma, Lazio; Italia']);

        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        $this->assertSame(0, preg_match("/(?<!\r)\n/", $ics), 'Every line ends with CRLF');

        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), "Line exceeds 75 octets: {$line}");
            $this->assertTrue(mb_check_encoding($line, 'UTF-8'), 'Folding must not split UTF-8 characters');
        }

        $events = $this->events($ics);
        $this->assertSame(substr_count($ics, 'BEGIN:VEVENT'), count($events));
        $uids = array_column($events, 'UID');
        $this->assertSame(count($uids), count(array_unique($uids)), 'UIDs are unique');
    }

    public function testCalendarNameIsEscaped(): void
    {
        $ics = str_replace("\r\n ", '', $this->feed(['location' => 'Foo, Bar; Baz']));

        $this->assertStringContainsString('X-WR-CALNAME:🌅 Foo\, Bar\; Baz · Sun & Twilight', $ics);
    }

    public function testEveryEventEndsAfterItStarts(): void
    {
        foreach ([[41.9028, 12.4964, 'Europe/Rome'], [69.65, 18.96, 'Europe/Oslo'], [-77.85, 166.67, 'Antarctica/McMurdo']] as [$lat, $lon, $zone]) {
            $ics = $this->feed(['lat' => $lat, 'lon' => $lon, 'timezone' => $zone]);
            foreach ($this->events($ics) as $event) {
                $this->assertGreaterThan($event['DTSTART'], $event['DTEND'], "{$zone}: {$event['UID']}");
            }
        }
    }

    /**
     * Sunrise must track the zone's real UTC offset on each date, not the offset
     * on the day the feed was generated. Cross-check against PHP's own solar model.
     */
    #[DataProvider('dstLocations')]
    public function testDaylightStartsAtSunriseAcrossDstChanges(float $lat, float $lon, string $zone): void
    {
        $ics = $this->feed(['lat' => $lat, 'lon' => $lon, 'timezone' => $zone]);
        $tz = new DateTimeZone($zone);

        $daylight = $this->eventsOfType($ics, 'daylight');
        $this->assertCount(366, $daylight);

        foreach ($daylight as $event) {
            $date = DateTimeImmutable::createFromFormat('Ymd H:i', substr($event['UID'], 9, 8) . ' 12:00', $tz);
            $expected = date_sun_info($date->getTimestamp(), $lat, $lon)['sunrise'];
            $actual = (new DateTimeImmutable($event['DTSTART']))->getTimestamp();

            $this->assertEqualsWithDelta($expected, $actual, 120, "Sunrise on {$date->format('Y-m-d')} in {$zone}");
        }
    }

    public static function dstLocations(): array
    {
        return [
            'Rome' => [41.9028, 12.4964, 'Europe/Rome'],
            'New York' => [40.7128, -74.0060, 'America/New_York'],
            'Sydney' => [-33.8688, 151.2093, 'Australia/Sydney'],
        ];
    }

    public function testEquinoxesAndSolsticesForEveryYearInWindow(): void
    {
        $ics = $this->feed(['past_days' => 280, 'window_days' => 450]);

        $special = array_map(
            fn($e) => $e['DTSTART'] . ' ' . $e['SUMMARY'],
            array_filter($this->events($ics), fn($e) => preg_match('/^(spring|autumn)-equinox-|^(summer|winter)-solstice-/', $e['UID']))
        );

        $this->assertSame([
            '20260320 🌱 Spring Equinox',
            '20260621 ☀️ Summer Solstice',
            '20260923 🍂 Autumn Equinox',
            '20261221 ❄️ Winter Solstice',
            '20270320 🌱 Spring Equinox',
            '20270621 ☀️ Summer Solstice',
            '20270923 🍂 Autumn Equinox',
            '20271222 ❄️ Winter Solstice',
        ], array_values($special));
    }

    public function testPolarNightHasMiddayTwilightAndNoDaylight(): void
    {
        $ics = $this->feed(['lat' => 69.65, 'lon' => 18.96, 'timezone' => 'Europe/Oslo', 'today' => '2026-12-21', 'window_days' => 0]);

        $this->assertSame([], $this->eventsOfType($ics, 'daylight'));

        // The sun peaks inside civil twilight, so morning twilight runs up to solar noon and evening starts there
        [$dawn] = $this->eventsOfType($ics, 'morning-civil-twilight');
        [$dusk] = $this->eventsOfType($ics, 'evening-civil-twilight');
        $this->assertSame($dawn['DTEND'], $dusk['DTSTART']);
        $this->assertStringContainsString(
            'Polar night: today the sun climbs from 6° below the horizon to 3° below at solar noon\\, its highest today.',
            $dawn['DESCRIPTION']
        );
        $this->assertStringNotContainsString('to sunrise', $dawn['DESCRIPTION']);
        $this->assertStringContainsString('→ [Civil] → Solar noon', $dawn['DESCRIPTION']);
        $this->assertStringContainsString(
            'Polar night: today the sun sinks from 3° below the horizon at solar noon\\, its highest today\\, to 6° below.',
            $dusk['DESCRIPTION']
        );
        $this->assertStringContainsString('Solar noon → [Civil] →', $dusk['DESCRIPTION']);

        [$solstice] = $this->eventsOfType($ics, 'winter-solstice');
        $this->assertStringContainsString('The sun stays 3° below the horizon even at noon.', $solstice['DESCRIPTION']);
    }

    public function testMidnightSunHasAllDayDaylightAndNoTwilight(): void
    {
        $ics = $this->feed(['lat' => 69.65, 'lon' => 18.96, 'timezone' => 'Europe/Oslo', 'today' => '2027-06-21', 'window_days' => 0]);

        [$daylight] = $this->eventsOfType($ics, 'daylight');
        $length = strtotime($daylight['DTEND']) - strtotime($daylight['DTSTART']);
        $this->assertSame(86400, $length);
        $this->assertStringStartsWith('Midnight sun: the sun does not set today.', $daylight['DESCRIPTION']);
        $this->assertStringNotContainsString('of the year', $daylight['DESCRIPTION'], 'No rank among tied 24-hour days');

        $types = [
            'morning-civil-twilight', 'morning-nautical-twilight', 'morning-astronomical-twilight',
            'evening-civil-twilight', 'evening-nautical-twilight', 'evening-astronomical-twilight', 'night',
        ];
        foreach ($types as $type) {
            $this->assertSame([], $this->eventsOfType($ics, $type), "No {$type} under the midnight sun");
        }
    }

    public function testWhiteNightTwilightRunsThroughSolarMidnight(): void
    {
        // Stockholm in June: the sun dips below the horizon but never below -12°
        $ics = $this->feed(['lat' => 59.33, 'lon' => 18.07, 'timezone' => 'Europe/Stockholm', 'today' => '2027-06-21', 'window_days' => 1]);

        [$dusk] = $this->eventsOfType($ics, 'evening-nautical-twilight');
        [, $nextDawn] = $this->eventsOfType($ics, 'morning-nautical-twilight');
        $this->assertEqualsWithDelta(strtotime($dusk['DTEND']), strtotime($nextDawn['DTSTART']), 120);
        $this->assertSame([], $this->eventsOfType($ics, 'evening-astronomical-twilight'));
        $this->assertSame([], $this->eventsOfType($ics, 'night'));

        $this->assertStringContainsString('Sunset → Civil → [Nautical] → Solar midnight', $dusk['DESCRIPTION']);
        $this->assertStringContainsString(
            'Today the sun sinks from 6° below the horizon to 7° below at solar midnight\\, its lowest tonight.',
            $dusk['DESCRIPTION']
        );
        $this->assertStringNotContainsString('from 6° to 12° below', $dusk['DESCRIPTION']);
        $this->assertStringContainsString('Today the sun climbs from 7° below the horizon at solar midnight', $nextDawn['DESCRIPTION']);
        $this->assertStringContainsString('the sun never gets 18° below the horizon', str_replace("\r\n ", '', $ics));
    }

    public function testSouthernHemisphereShortestDayIsInJune(): void
    {
        $ics = $this->feed(['lat' => -33.8688, 'lon' => 151.2093, 'timezone' => 'Australia/Sydney', 'window_days' => 0]);

        [$daylight] = $this->eventsOfType($ics, 'daylight');
        $this->assertMatchesRegularExpression('/more than the shortest day \(Jun 2\d\)\\\\, [^\\\\]+ less than the longest \(Dec 2\d\)/', $daylight['DESCRIPTION']);
    }

    public function testOffsetsShiftMorningAndEveningEvents(): void
    {
        $base = $this->eventsOfType($this->feed(['window_days' => 0]), 'daylight')[0];
        $shifted = $this->eventsOfType($this->feed(['window_days' => 0, 'rise_offset' => -600, 'set_offset' => 900]), 'daylight')[0];

        $this->assertSame(-600, strtotime($shifted['DTSTART']) - strtotime($base['DTSTART']));
        $this->assertSame(900, strtotime($shifted['DTEND']) - strtotime($base['DTEND']));
    }

    public function testNightDurationMatchesEventSpan(): void
    {
        [$night] = $this->eventsOfType($this->feed(['window_days' => 0]), 'night');
        $minutes = (int) round((strtotime($night['DTEND']) - strtotime($night['DTSTART'])) / 60);

        $this->assertStringContainsString(' Night · ' . format_duration($minutes * 60), $night['SUMMARY']);
    }

    public function testSupplementalScheduleListsOnlyDisabledTypes(): void
    {
        $ics = $this->feed([
            'window_days' => 0,
            'enabled' => ['civil' => true, 'nautical' => false, 'astro' => false, 'daylight' => false],
        ]);
        [$dawn] = $this->eventsOfType($ics, 'morning-civil-twilight');

        $this->assertStringContainsString('⚓ Nautical Twilight: ', $dawn['DESCRIPTION']);
        $this->assertStringContainsString('🌌 Astronomical Twilight: ', $dawn['DESCRIPTION']);
        $this->assertMatchesRegularExpression('/☀️ Daylight: \d\d:\d\d–\d\d:\d\d \(\d+h \d\dm\)\\\\n[^\\\\]+ than yesterday: sunrise/u', $dawn['DESCRIPTION']);
        $this->assertStringNotContainsString('🌅 Civil Twilight: ', $dawn['DESCRIPTION']);
        $this->assertSame([], $this->eventsOfType($ics, 'daylight'));
    }

    public function testDaylightOnlyCalendarStillListsEveryTwilight(): void
    {
        $ics = $this->feed([
            'window_days' => 0,
            'enabled' => ['civil' => false, 'nautical' => false, 'astro' => false, 'daylight' => true],
        ]);
        [$daylight] = $this->eventsOfType($ics, 'daylight');
        [$night] = $this->eventsOfType($ics, 'night');

        foreach (['🌌 Astronomical Twilight: ', '⚓ Nautical Twilight: ', '🌅 Civil Twilight: ', '🌇 Civil Twilight: '] as $line) {
            $this->assertStringContainsString($line, $daylight['DESCRIPTION']);
        }
        $this->assertSame(2, substr_count($daylight['DESCRIPTION'], '🌌 Astronomical Twilight: '), 'Morning and evening');
        $this->assertStringNotContainsString('Twilight today', $night['DESCRIPTION'], 'Listed once, on Daylight');

        // With civil twilight subscribed, its events carry the rest instead
        [$daylight] = $this->eventsOfType($this->feed(['window_days' => 0, 'enabled' => ['civil' => true, 'nautical' => false, 'astro' => false, 'daylight' => true]]), 'daylight');
        $this->assertStringNotContainsString('Twilight today', $daylight['DESCRIPTION']);
    }

    public function testPolarNightCarriesTwilightsOnTheNight(): void
    {
        $ics = $this->feed([
            'lat' => 69.65, 'lon' => 18.96, 'timezone' => 'Europe/Oslo', 'today' => '2026-12-21', 'window_days' => 0,
            'enabled' => ['civil' => false, 'nautical' => false, 'astro' => false, 'daylight' => true],
        ]);
        [$night] = $this->eventsOfType($ics, 'night');

        $this->assertStringContainsString('🌗 Twilight today', $night['DESCRIPTION']);
        $this->assertStringContainsString('🌅 Civil Twilight: ', $night['DESCRIPTION']);
    }

    public function testWeekSummaryOnSundaysOnly(): void
    {
        $summaries = $this->eventsOfType($this->feed(['window_days' => 13]), 'week');

        $this->assertCount(2, $summaries);
        foreach ($summaries as $summary) {
            // All-day from Sunday through Saturday: DTEND is the following Sunday
            $this->assertSame('0', date('w', strtotime($summary['DTSTART'])));
            $this->assertSame(date('Ymd', strtotime($summary['DTSTART'] . ' +7 days')), $summary['DTEND']);
        }
        $this->assertMatchesRegularExpression('/^📉 \d+m less daylight this week$/', $summaries[0]['SUMMARY'], 'Days shorten in October');
        $this->assertMatchesRegularExpression('/Sunrise \d\d:\d\d → \d\d:\d\d · Sunset \d\d:\d\d → \d\d:\d\d/', $summaries[0]['DESCRIPTION']);
    }

    public function testDescriptionsDontRepeatTheEventTimesOrNameInstants(): void
    {
        $timed = array_filter($this->events($this->feed(['window_days' => 6])), fn($e) => str_contains($e['DTSTART'], 'T'));

        $this->assertNotEmpty($timed);
        foreach ($timed as $event) {
            $this->assertStringNotContainsString('From ', $event['DESCRIPTION'], $event['UID']);
            $this->assertDoesNotMatchRegularExpression('/\b(civil|nautical|astronomical) (dawn|dusk)\b/i', $event['DESCRIPTION'], $event['UID']);
        }
    }

    public function testDaylightShowsChangeAndPlaceInTheYear(): void
    {
        [$daylight] = $this->eventsOfType($this->feed(['today' => '2026-11-15', 'window_days' => 0]), 'daylight');
        $text = $daylight['DESCRIPTION'];

        // What changed since yesterday, including on the clock, and where the sun peaks
        $this->assertMatchesRegularExpression('/^2m 0\ds shorter than yesterday: sunrise 1m \d\ds later\\\\, sunset \d+s earlier\\\\nSun highest at 11:54\\\\, 30° above the horizon/u', $text);
        // Where today sits between the year's shortest and longest day, as a bar and in words
        $this->assertStringContainsString('📅 Through the year\n9h 08m ▕█░░░░░░░░░▏ 15h 14m', $text);
        $this->assertStringContainsString('42m more than the shortest day (Dec 21)\, 5h 24m less than the longest (Jun 21)', $text);
        $this->assertStringNotContainsString('%', $text);
    }

    public function testTwilightLengthSitsInTheYearsRange(): void
    {
        [$dawn] = $this->eventsOfType($this->feed(['today' => '2026-11-15', 'window_days' => 0]), 'morning-civil-twilight');

        $this->assertStringStartsWith('Length: 30m · over the year: 28m ▕███░░░░░░░▏ 34m', $dawn['DESCRIPTION']);
    }

    public function testCalendarDescriptionHasLocationFacts(): void
    {
        $ics = str_replace("\r\n ", '', $this->feed(['window_days' => 0]));

        $this->assertMatchesRegularExpression(
            '/X-WR-CALDESC:Sunrise\\\\, sunset and twilight for Rome \(41\.9028\\\\, 12\.4964\)\\\\, times in Europe\/Rome\..*'
                . 'Daylight here ranges from 9h 0\dm \(Dec 2\d\) to 15h 1\dm \(Jun 2\d\) over the year\./',
            $ics
        );
    }

    public function testSouthernHemisphereSeasonNames(): void
    {
        // The March equinox falls on Mar 21 in Sydney's time zone
        $ics = $this->feed(['lat' => -33.8688, 'lon' => 151.2093, 'timezone' => 'Australia/Sydney', 'today' => '2027-03-21', 'window_days' => 0]);

        [$equinox] = $this->eventsOfType($ics, 'autumn-equinox');
        $this->assertSame('🍂 Autumn Equinox', $equinox['SUMMARY']);
        $this->assertStringStartsWith('The March equinox is at 07:24.', $equinox['DESCRIPTION']);
        $this->assertStringContainsString('Astronomical autumn begins.', $equinox['DESCRIPTION']);
    }

    public function testSeasonUidsDependOnLocation(): void
    {
        $uid = fn(float $lat, float $lon) => $this->eventsOfType(
            $this->feed(['lat' => $lat, 'lon' => $lon, 'today' => '2026-06-21', 'window_days' => 0]),
            'summer-solstice'
        )[0]['UID'];

        $this->assertNotSame($uid(41.9028, 12.4964), $uid(45.4642, 9.19));
    }

    public function testTropicsSkipSolsticeClaimsThatDoNotHold(): void
    {
        $quito = ['lat' => -0.1807, 'lon' => -78.4678, 'timezone' => 'America/Guayaquil', 'window_days' => 0];

        // Day length varies by seconds here, and the year's shortest day is not the solstice
        [$june] = $this->eventsOfType($this->feed($quito + ['today' => '2027-06-21']), 'winter-solstice');
        $this->assertStringNotContainsString('shortest day', $june['DESCRIPTION']);

        // Inside the tropics the noon sun is highest when it passes overhead, not at the solstice
        [$december] = $this->eventsOfType($this->feed($quito + ['today' => '2026-12-21']), 'summer-solstice');
        $this->assertStringContainsString('In the tropics the sun is highest on two other days', $december['DESCRIPTION']);
        $this->assertStringNotContainsString('at its highest of the year', $december['DESCRIPTION']);
    }

    public function testMidLatitudeSolsticeClaims(): void
    {
        [$solstice] = $this->eventsOfType($this->feed(['today' => '2026-12-21', 'window_days' => 0]), 'winter-solstice');

        $this->assertStringContainsString('The shortest day of the year: 9h 08m of daylight.', $solstice['DESCRIPTION']);
        $this->assertStringContainsString('The noon sun is at its lowest of the year: 25°', $solstice['DESCRIPTION']);
        // Facts about other days go on those days, not here
        $this->assertDoesNotMatchRegularExpression('/\b(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) \d/', $solstice['DESCRIPTION']);
    }

    public function testSunriseAndSunsetTurningPointsGetTheirOwnDays(): void
    {
        // Bergamo: sunsets turn later on Dec 10, sunrises turn earlier on Jan 2
        $ics = $this->feed(['lat' => 45.6894, 'lon' => 9.5694, 'today' => '2026-11-30', 'window_days' => 40, 'enabled' => [
            'civil' => true, 'nautical' => false, 'astro' => false, 'daylight' => false,
        ]]);

        [$sunset] = $this->eventsOfType($ics, 'sunset-earliest');
        $this->assertSame('20261210', $sunset['DTSTART'], 'All day, on its own date');
        $this->assertSame('🌇 Sunsets start getting later', $sunset['SUMMARY']);
        $this->assertMatchesRegularExpression('/^Sunset at 16:3\d is the earliest it gets for months/', $sunset['DESCRIPTION']);
        $this->assertStringContainsString('The days still get shorter for a while', $sunset['DESCRIPTION']);

        [$sunrise] = $this->eventsOfType($ics, 'sunrise-latest');
        $this->assertSame('20270102', $sunrise['DTSTART']);
        $this->assertStringContainsString('The days are already getting longer', $sunrise['DESCRIPTION']);

        [$winter] = $this->eventsOfType($ics, 'meteorological-winter');
        $this->assertSame('20261201', $winter['DTSTART']);
        $this->assertSame('🌡️ Meteorological winter begins', $winter['SUMMARY']);
    }

    public function testTwelveHoursOfDaylightNearTheEquinoxes(): void
    {
        $ics = $this->feed(['today' => '2027-03-01', 'window_days' => 220, 'enabled' => [
            'civil' => false, 'nautical' => false, 'astro' => false, 'daylight' => false,
        ]]);

        [$spring] = $this->eventsOfType($ics, 'twelve-hours-rising');
        [$autumn] = $this->eventsOfType($ics, 'twelve-hours-falling');
        $this->assertMatchesRegularExpression('/^202703(1\d)$/', $spring['DTSTART'], 'Before the Mar 20 equinox');
        $this->assertMatchesRegularExpression('/^202709(2[4-9])$/', $autumn['DTSTART'], 'After the Sep 23 equinox');
        $this->assertMatchesRegularExpression('/^Daylight is 1[12]h \d\dm today\\\\, the closest it gets to 12 hours/', $spring['DESCRIPTION']);
        $this->assertStringContainsString('before the spring equinox\, not on it', $spring['DESCRIPTION']);
    }

    public function testPolarTransitionsGetTheirOwnDays(): void
    {
        $ics = $this->feed(['lat' => 69.65, 'lon' => 18.96, 'timezone' => 'Europe/Oslo', 'today' => '2026-11-25', 'window_days' => 60]);

        [$begins] = $this->eventsOfType($ics, 'polar-night-begins');
        [$ends] = $this->eventsOfType($ics, 'polar-night-ends');
        $this->assertSame('20261128', $begins['DTSTART']);
        $this->assertSame('20270116', $ends['DTSTART'], 'The first day the sun rises: 11:15 on Jan 16, 2027');
        $this->assertMatchesRegularExpression('/^The first sunrise after about 4\d days of polar night\.$/', $ends['DESCRIPTION']);
    }

    public function testNoMeteorologicalSeasonsInTheTropics(): void
    {
        $ics = $this->feed(['lat' => -0.1807, 'lon' => -78.4678, 'timezone' => 'America/Guayaquil', 'today' => '2026-11-30', 'window_days' => 3]);

        $this->assertSame([], array_filter($this->events($ics), fn($e) => str_starts_with($e['UID'], 'meteorological-')));
    }

    public function testTwilightDataComesBeforeTheExplanation(): void
    {
        [$dawn] = $this->eventsOfType($this->feed([
            'window_days' => 0,
            'enabled' => ['civil' => true, 'nautical' => false, 'astro' => false, 'daylight' => true],
        ]), 'morning-civil-twilight');
        $text = $dawn['DESCRIPTION'];

        $this->assertMatchesRegularExpression('/^Length: \d+m · over the year: 2\dm ▕[█░]{10}▏ 3\dm\\\\n/u', $text);
        $this->assertLessThan(strpos($text, 'ℹ️ About'), strpos($text, '☀️ Today'));
    }

    public function testNightGivesTheMoonlessWindow(): void
    {
        $night = fn(string $today) => $this->eventsOfType($this->feed(['today' => $today, 'window_days' => 0]), 'night')[0]['DESCRIPTION'];

        // The moon sets at 21:45 (USNO) and the night ends at astronomical dawn
        $this->assertMatchesRegularExpression('/Moonless sky: 21:45–05:2\d \(7h \d\dm\)/u', $night('2026-11-15'));
        // The Dec 24 full moon at 02:28 falls in the night of Dec 23
        $this->assertStringContainsString('· Full Moon at 02:28', $night('2026-12-23'));
        $this->assertStringNotContainsString('Next:', $night('2026-11-15'));
        $this->assertStringContainsString('Moonless sky: none\, the moon is up all night', $night('2026-11-24'));
        $this->assertStringContainsString('Moonless sky: all night', $night('2026-11-10'));
        $this->assertStringNotContainsString('While up', $night('2026-11-10'), 'No brightness note when the moon never rises');
    }

    public function testMoonEmojiIsMirroredSouthOfTheEquator(): void
    {
        // Waxing crescent on Nov 15, 2026: lit on the right from Rome, on the left from Sydney
        $night = fn(array $place) => $this->eventsOfType($this->feed($place + ['today' => '2026-11-15', 'window_days' => 0]), 'night')[0];

        $rome = $night([]);
        $sydney = $night(['lat' => -33.8688, 'lon' => 151.2093, 'timezone' => 'Australia/Sydney']);
        $this->assertStringStartsWith('🌒 Night', $rome['SUMMARY']);
        $this->assertStringStartsWith('🌘 Night', $sydney['SUMMARY']);
        $this->assertStringContainsString('🌘 Moon\\nWaxing Crescent', $sydney['DESCRIPTION']);
    }

    public function testBlueAndGoldenHourAppearOnlyOnDaylight(): void
    {
        $ics = $this->feed(['today' => '2026-11-15', 'window_days' => 0]);

        foreach (['morning-civil-twilight', 'evening-civil-twilight'] as $type) {
            [$twilight] = $this->eventsOfType($ics, $type);
            $this->assertStringNotContainsStringIgnoringCase('golden hour', $twilight['DESCRIPTION'], $type);
            $this->assertStringNotContainsStringIgnoringCase('blue hour', $twilight['DESCRIPTION'], $type);
        }

        // Morning and evening, each in time order
        [$daylight] = $this->eventsOfType($ics, 'daylight');
        $this->assertMatchesRegularExpression('/📷 Morning: blue hour \d\d:\d\d–(\d\d:\d\d)\\\\, golden hour \1–\d\d:\d\d/u', $daylight['DESCRIPTION']);
        $this->assertMatchesRegularExpression('/📷 Evening: golden hour \d\d:\d\d–(\d\d:\d\d)\\\\, blue hour \1–\d\d:\d\d/u', $daylight['DESCRIPTION']);
        $this->assertStringContainsString("photographers' rules of thumb", $daylight['DESCRIPTION']);
    }

    public function testBlueAndGoldenHourComeWithTheDaylightBlockWithoutADaylightEvent(): void
    {
        // A twilight-only calendar carries daylight in the morning twilight's Today block, photo light included
        $ics = $this->feed([
            'today' => '2026-11-15',
            'window_days' => 0,
            'enabled' => ['civil' => true, 'nautical' => false, 'astro' => false, 'daylight' => false],
        ]);
        [$dawn] = $this->eventsOfType($ics, 'morning-civil-twilight');
        [$dusk] = $this->eventsOfType($ics, 'evening-civil-twilight');

        $this->assertMatchesRegularExpression('/☀️ Daylight: .+📷 Morning: blue hour .+?\\\\n📷 Evening: golden hour/u', $dawn['DESCRIPTION']);
        $this->assertStringNotContainsStringIgnoringCase('golden hour', $dusk['DESCRIPTION'], 'Listed once, with the daylight');
    }

    public function testOffsetsAreExplained(): void
    {
        [$daylight] = $this->eventsOfType($this->feed(['window_days' => 0, 'rise_offset' => -600, 'set_offset' => 900]), 'daylight');

        $this->assertStringContainsString('Calendar times shifted from the actual times: start -10m\, end +15m.', $daylight['DESCRIPTION']);
    }

    public function testDeepPolarNightLastsAroundTheClock(): void
    {
        // At 88°S the noon sun stays more than 18° below the horizon from May 21
        $ics = $this->feed(['lat' => -88.0, 'lon' => 0.0, 'timezone' => 'Antarctica/McMurdo', 'today' => '2027-05-19', 'window_days' => 4]);
        $nights = $this->eventsOfType($ics, 'night');

        $this->assertCount(5, $nights);
        // From May 20's night on, solar noon joins each night to the next
        for ($i = 2; $i < count($nights); $i++) {
            $this->assertSame($nights[$i - 1]['DTEND'], $nights[$i]['DTSTART'], 'Nights join up without gaps');
        }
        $this->assertStringContainsString('all day tomorrow\, so this night lasts until solar noon.', $nights[1]['DESCRIPTION']);
        $this->assertStringStartsWith('Polar night: the sun stays more than 18° below the horizon all day', $nights[2]['DESCRIPTION']);
        $this->assertStringNotContainsString('this year\'s nights', $nights[2]['DESCRIPTION']);
        $this->assertStringContainsString(' Night · 24h 00m', $nights[3]['SUMMARY']);
    }

    public function testNightLengthSitsBetweenTheYearsShortestAndLongest(): void
    {
        [$solstice] = $this->eventsOfType($this->feed(['today' => '2026-12-21', 'window_days' => 0]), 'night');
        [$november] = $this->eventsOfType($this->feed(['today' => '2026-11-15', 'window_days' => 0]), 'night');

        $this->assertMatchesRegularExpression('/The longest night of the year: \d+h \d\dm more than the shortest \(Jun 2\d\)/', $solstice['DESCRIPTION']);
        $this->assertMatchesRegularExpression('/more than the shortest night \(Jun 2\d\)\\\\, \d+m less than the longest \(Dec 2\d\)/', $november['DESCRIPTION']);
    }

    public function testYearExtremesFallOnTheSolstices(): void
    {
        // Rome, New York, Tokyo and Sydney, plus a leap year: shortest and longest day at the solstices
        $places = [
            [41.9028, 12.4964, 'Europe/Rome', 2026],
            [40.7128, -74.006, 'America/New_York', 2026],
            [35.6762, 139.6503, 'Asia/Tokyo', 2026],
            [-33.8688, 151.2093, 'Australia/Sydney', 2026],
            [41.9028, 12.4964, 'Europe/Rome', 2028],
        ];
        foreach ($places as [$lat, $lon, $zone, $y]) {
            $previous = date_default_timezone_get();
            date_default_timezone_set($zone);
            try {
                $year = year_summary($lat, $lon, $y, (new DateTimeImmutable("{$y}-01-15 12:00"))->getOffset() / 3600);
                $short = date('M j', $year['daylight_min'][1]);
                $long = date('M j', $year['daylight_max'][1]);
            } finally {
                date_default_timezone_set($previous);
            }
            [$winter, $summer] = $lat > 0 ? ['/^Dec 2[0-2]$/', '/^Jun 2[0-2]$/'] : ['/^Jun 2[0-2]$/', '/^Dec 2[0-2]$/'];
            $this->assertMatchesRegularExpression($winter, $short, "{$zone} {$y} shortest day");
            $this->assertMatchesRegularExpression($summer, $long, "{$zone} {$y} longest day");
        }
    }

    public function testDefaultTimezoneIsRestored(): void
    {
        $this->feed(['window_days' => 0]);

        $this->assertSame('UTC', date_default_timezone_get());
    }
}
