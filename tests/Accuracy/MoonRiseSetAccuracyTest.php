<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Moonrise and moonset against the US Naval Observatory (aa.usno.navy.mil).
 *
 * The low-precision lunar position is good to about 0.3°, so times are
 * required within 3 minutes.
 */
class MoonRiseSetAccuracyTest extends TestCase
{
    /**
     * @param array<array{0: string, 1: string}> $expected [event, local HH:MM] from USNO
     */
    #[DataProvider('usnoProvider')]
    public function testRiseSetMatchesUsno(float $lat, float $lon, string $zone, string $date, array $expected): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set($zone);
        try {
            $from = strtotime("{$date} 00:00");
            $events = moon_rise_set($from, $from + 86400, $lat, $lon)['events'];

            $this->assertSame(array_column($expected, 0), array_column($events, 0), "Events on {$date}");
            foreach ($expected as $i => [, $time]) {
                $this->assertEqualsWithDelta(strtotime("{$date} {$time}"), $events[$i][1], 180, "{$expected[$i][0]} on {$date}");
            }
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public static function usnoProvider(): array
    {
        return [
            'Rome waxing crescent' => [41.9028, 12.4964, 'Etc/GMT-1', '2026-11-15', [['rise', '12:12'], ['set', '21:45']]],
            'Rome waxing gibbous' => [41.9028, 12.4964, 'Etc/GMT-1', '2026-11-20', [['set', '02:04'], ['rise', '14:06']]],
            'Rome full moon' => [41.9028, 12.4964, 'Etc/GMT-1', '2026-11-24', [['set', '07:05'], ['rise', '16:13']]],
            'Rome waning gibbous' => [41.9028, 12.4964, 'Etc/GMT-1', '2026-11-28', [['set', '11:14'], ['rise', '20:53']]],
            'Sydney' => [-33.8688, 151.2093, 'Etc/GMT-10', '2027-06-10', [['rise', '11:19'], ['set', '22:56']]],
            'Tromsø, moon up all day' => [69.65, 18.96, 'Etc/GMT-1', '2027-01-20', []],
        ];
    }
}
