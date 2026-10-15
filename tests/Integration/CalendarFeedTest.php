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

        $this->assertStringContainsString('X-WR-CALNAME:🌅☀️🌙 Sun\, Twilight & Moon - Foo\, Bar\; Baz', $ics);
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
            $this->eventsOfType($ics, 'special')
        );

        $this->assertSame([
            '20260320 ⚖️ March Equinox',
            '20260621 ☀️ June Solstice',
            '20260923 ⚖️ September Equinox',
            '20261221 🌙 December Solstice',
            '20270320 ⚖️ March Equinox',
            '20270621 ☀️ June Solstice',
            '20270923 ⚖️ September Equinox',
            '20271222 🌙 December Solstice',
        ], $special);
    }

    public function testPolarNightHasMiddayTwilightAndNoDaylight(): void
    {
        $ics = $this->feed(['lat' => 69.65, 'lon' => 18.96, 'timezone' => 'Europe/Oslo', 'today' => '2026-12-21', 'window_days' => 0]);

        $this->assertSame([], $this->eventsOfType($ics, 'daylight'));

        // The sun peaks inside civil twilight, so dawn runs up to solar noon and dusk starts there
        [$dawn] = $this->eventsOfType($ics, 'civil-dawn');
        [$dusk] = $this->eventsOfType($ics, 'civil-dusk');
        $this->assertSame($dawn['DTEND'], $dusk['DTSTART']);
        $this->assertStringContainsString('Sunrise: —', $dawn['DESCRIPTION']);
    }

    public function testMidnightSunHasAllDayDaylightAndNoTwilight(): void
    {
        $ics = $this->feed(['lat' => 69.65, 'lon' => 18.96, 'timezone' => 'Europe/Oslo', 'today' => '2027-06-21', 'window_days' => 0]);

        [$daylight] = $this->eventsOfType($ics, 'daylight');
        $length = strtotime($daylight['DTEND']) - strtotime($daylight['DTSTART']);
        $this->assertSame(86400, $length);

        foreach (['civil-dawn', 'nautical-dawn', 'astro-dawn', 'civil-dusk', 'nautical-dusk', 'astro-dusk', 'night'] as $type) {
            $this->assertSame([], $this->eventsOfType($ics, $type), "No {$type} under the midnight sun");
        }
    }

    public function testWhiteNightTwilightRunsThroughSolarMidnight(): void
    {
        // Stockholm in June: the sun dips below the horizon but never below -12°
        $ics = $this->feed(['lat' => 59.33, 'lon' => 18.07, 'timezone' => 'Europe/Stockholm', 'today' => '2027-06-21', 'window_days' => 1]);

        [$dusk] = $this->eventsOfType($ics, 'nautical-dusk');
        [, $nextDawn] = $this->eventsOfType($ics, 'nautical-dawn');
        $this->assertEqualsWithDelta(strtotime($dusk['DTEND']), strtotime($nextDawn['DTSTART']), 120);
        $this->assertSame([], $this->eventsOfType($ics, 'astro-dusk'));
        $this->assertSame([], $this->eventsOfType($ics, 'night'));
    }

    public function testSouthernHemisphereWinterSolsticeIsInJune(): void
    {
        $ics = $this->feed(['lat' => -33.8688, 'lon' => 151.2093, 'timezone' => 'Australia/Sydney', 'window_days' => 0]);

        [$daylight] = $this->eventsOfType($ics, 'daylight');
        $this->assertMatchesRegularExpression('/Compared to Winter Solstice: \+[^\\\\]*\\\\n  \(Jun 21\)/', $daylight['DESCRIPTION']);
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

        $this->assertStringContainsString(
            'Duration: ' . format_duration_full($minutes * 60),
            $night['DESCRIPTION']
        );
    }

    public function testSupplementalScheduleListsOnlyDisabledTypes(): void
    {
        $ics = $this->feed([
            'window_days' => 0,
            'enabled' => ['civil' => true, 'nautical' => false, 'astro' => false, 'daylight' => false],
        ]);
        [$dawn] = $this->eventsOfType($ics, 'civil-dawn');

        $this->assertStringContainsString('⚓ Nautical Dawn: ', $dawn['DESCRIPTION']);
        $this->assertStringContainsString('🌌 Astronomical Dawn: ', $dawn['DESCRIPTION']);
        $this->assertStringContainsString('☀️ Daylight', $dawn['DESCRIPTION']);
        $this->assertStringNotContainsString('🌅 Civil Dawn: ', $dawn['DESCRIPTION']);
        $this->assertSame([], $this->eventsOfType($ics, 'daylight'));
    }

    public function testWeekSummaryOnSundaysOnly(): void
    {
        $summaries = $this->eventsOfType($this->feed(['window_days' => 13]), 'week-summary');

        $this->assertCount(2, $summaries);
        foreach ($summaries as $summary) {
            $this->assertSame('0', date('w', strtotime($summary['DTSTART'])));
        }
        $this->assertStringContainsString('Weekly Change: -', $summaries[0]['DESCRIPTION'], 'Days shorten in October');
    }

    public function testDefaultTimezoneIsRestored(): void
    {
        $this->feed(['window_days' => 0]);

        $this->assertSame('UTC', date_default_timezone_get());
    }
}
