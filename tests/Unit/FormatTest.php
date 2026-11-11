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
        $this->assertSame('2m 30s (0.3%) longer than yesterday', format_comparison(150, 43200, 'yesterday', get_strings()));
    }

    public function testComparisonShorter(): void
    {
        $this->assertSame('1m 45s (0.2%) shorter than last night', format_comparison(-105, 43200, 'last night', get_strings()));
    }

    public function testComparisonSame(): void
    {
        $this->assertSame('Same length as yesterday', format_comparison(0, 43200, 'yesterday', get_strings()));
    }

    public function testComparisonPercentageRoundsAboveTenAndSkipsAZeroReferent(): void
    {
        $S = get_strings();
        $this->assertSame('5h 15m (35%) shorter than the summer solstice', format_comparison(-18900, 54000, 'the summer solstice', $S, false));
        $this->assertSame('3h 00m longer than the winter solstice', format_comparison(10800, 0, 'the winter solstice', $S, false));
    }

    public function testRankNamesTheExtremes(): void
    {
        $S = get_strings();
        $this->assertSame('Longest day of the year', format_rank(100.0, 'daylight', $S));
        $this->assertSame('Shortest night of the year', format_rank(0.0, 'night', $S));
    }

    public function testRankNeverRoundsToAnExtreme(): void
    {
        $S = get_strings();
        $this->assertSame("Longer than 99% of this year's days", format_rank(99.7, 'daylight', $S));
        $this->assertSame("Longer than 1% of this year's nights", format_rank(0.3, 'night', $S));
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
