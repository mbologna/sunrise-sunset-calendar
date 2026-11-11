<?php

namespace Tests\Unit;

use Tests\BaseTest;

/**
 * Format and Helper Functions Test Suite
 * Tests formatting, validation, and utility functions.
 */
class FormatTest extends BaseTest
{
    public function testDurationHoursPadMinutes(): void
    {
        $this->assertSame('9h 05m', format_duration(9 * 3600 + 5 * 60));
    }

    public function testDurationUnderAnHourRoundsToMinutes(): void
    {
        $this->assertSame('32m', format_duration(32 * 60 + 20));
    }

    public function testDurationWithSecondsUnderAnHour(): void
    {
        $this->assertSame('2m 05s', format_duration(125, true));
        $this->assertSame('45s', format_duration(45, true));
    }

    public function testDurationWithSecondsDropsThemFromAnHour(): void
    {
        $this->assertSame('5h 25m', format_duration(5 * 3600 + 25 * 60 + 20, true));
    }

    public function testDurationIgnoresSign(): void
    {
        $this->assertSame('1h 30m', format_duration(-5400));
    }

    public function testSignedDuration(): void
    {
        $this->assertSame('-10m', format_signed_duration(-600));
        $this->assertSame('+15m', format_signed_duration(900));
        $this->assertSame('+0m', format_signed_duration(-20), 'No negative zero');
    }

    public function testComparisonLonger(): void
    {
        $this->assertSame('2m 30s longer than yesterday', format_comparison(150, 'yesterday', get_strings()));
    }

    public function testComparisonShorter(): void
    {
        $this->assertSame('1m 45s shorter than last night', format_comparison(-105, 'last night', get_strings()));
    }

    public function testComparisonSame(): void
    {
        $this->assertSame('Same length as yesterday', format_comparison(0, 'yesterday', get_strings()));
    }

    public function testClockShift(): void
    {
        $S = get_strings();
        $this->assertSame('1m 13s later', format_clock_shift(73, $S));
        $this->assertSame('53s earlier', format_clock_shift(-53, $S));
        $this->assertSame('unchanged', format_clock_shift(0, $S));
    }

    public function testRangeBarIsLinearBetweenLabelledEnds(): void
    {
        $this->assertSame('9h 00m ▕█████░░░░░▏ 15h 00m', format_range_bar(12 * 3600, 9 * 3600, 15 * 3600));
        $this->assertSame('9h 00m ▕░░░░░░░░░░▏ 15h 00m', format_range_bar(9 * 3600, 9 * 3600, 15 * 3600));
        $this->assertSame('9h 00m ▕██████████▏ 15h 00m', format_range_bar(15 * 3600, 9 * 3600, 15 * 3600));
    }

    public function testExtremesNameTheEndToday(): void
    {
        $S = get_strings();
        $shortest = [9 * 3600, mktime(12, 0, 0, 12, 21, 2026)];
        $longest = [15 * 3600, mktime(12, 0, 0, 6, 21, 2026)];

        $this->assertSame(
            '1h 00m more than the shortest day (Dec 21), 5h 00m less than the longest (Jun 21)',
            format_extremes('daylight', 10 * 3600, $shortest, $longest, $S)
        );
        $this->assertSame(
            'The shortest day of the year: 6h 00m less than the longest (Jun 21)',
            format_extremes('daylight', 9 * 3600 + 1, $shortest, $longest, $S)
        );
        // A day seconds from the shortest is not the shortest, and says by how much
        $this->assertSame(
            '30s more than the shortest day (Dec 21), 6h 00m less than the longest (Jun 21)',
            format_extremes('daylight', 9 * 3600 + 30, $shortest, $longest, $S)
        );
    }

    public function testMoonlessWindowsFollowRisesAndSets(): void
    {
        $night = [1000, 9000];
        $this->assertSame([[1000, 9000]], moonless_windows(['up_at_start' => false, 'events' => []], ...$night));
        $this->assertSame([], moonless_windows(['up_at_start' => true, 'events' => []], ...$night));
        $this->assertSame([[3000, 9000]], moonless_windows(['up_at_start' => true, 'events' => [['set', 3000]]], ...$night));
        $this->assertSame(
            [[3000, 6000]],
            moonless_windows(['up_at_start' => true, 'events' => [['set', 3000], ['rise', 6000]]], ...$night)
        );
    }

    public function testDayRunsMergeAcrossNewYear(): void
    {
        $flags = [true, true, false, false, true, false, true];
        $runs = day_runs($flags, range(0, 6));

        $this->assertSame([[4, 4], [6, 1]], $runs);
    }

    public function testSanitizeFloatValidLatitude(): void
    {
        $this->assertEquals(45.5, sanitize_float('45.5', 0.0, -90, 90));
    }

    public function testSanitizeFloatInvalidReturnsDefault(): void
    {
        $this->assertEquals(0.0, sanitize_float('invalid', 0.0, -90, 90));
    }

    public function testSanitizeFloatOutOfRangeReturnsDefault(): void
    {
        $this->assertEquals(0.0, sanitize_float('100', 0.0, -90, 90));
    }

    public function testSanitizeFloatNegativeValid(): void
    {
        $this->assertEquals(-45.5, sanitize_float('-45.5', 0.0, -90, 90));
    }

    public function testSanitizeIntValidOffset(): void
    {
        $this->assertEquals(30, sanitize_int('30', 0, -1440, 1440));
    }

    public function testSanitizeIntInvalidReturnsDefault(): void
    {
        $this->assertEquals(0, sanitize_int('invalid', 0, -1440, 1440));
    }

    public function testSanitizeIntOutOfRangeReturnsDefault(): void
    {
        $this->assertEquals(0, sanitize_int('2000', 0, -1440, 1440));
    }

    public function testSanitizeIntNegativeValid(): void
    {
        $this->assertEquals(-60, sanitize_int('-60', 0, -1440, 1440));
    }

    public function testSanitizeTimezoneValid(): void
    {
        $this->assertEquals('Europe/Rome', sanitize_timezone('Europe/Rome'));
    }

    public function testSanitizeTimezoneInvalidReturnsDefault(): void
    {
        $this->assertEquals('Europe/Rome', sanitize_timezone('Invalid/Timezone'));
    }

    public function testSanitizeTimezoneUSTimezone(): void
    {
        $this->assertEquals('America/New_York', sanitize_timezone('America/New_York'));
    }

    public function testSanitizeTextClean(): void
    {
        $this->assertEquals('Hello World', sanitize_text('Hello World'));
    }

    public function testSanitizeTextStripsHTMLTags(): void
    {
        $this->assertEquals('Hello World', sanitize_text('<script>Hello World</script>'));
    }

    public function testSanitizeTextRemovesNewlines(): void
    {
        $this->assertEquals('Hello World', sanitize_text("Hello\nWorld"));
    }

    public function testSanitizeTextTruncatesLongText(): void
    {
        $long_text = str_repeat('a', 600);
        $sanitized = sanitize_text($long_text, 500);
        $this->assertEquals(500, strlen($sanitized));
    }
}
