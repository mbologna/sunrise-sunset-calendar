<?php

namespace Tests\Unit;

use Tests\BaseTest;

/**
 * Format and Helper Functions Test Suite
 * Tests formatting, validation, and utility functions.
 */
class FormatTest extends BaseTest
{
    public function testShortDurationFormatting2Hours30Minutes(): void
    {
        $this->assertEquals('2h 30m', format_duration_short(2 * 3600 + 30 * 60));
    }

    public function testShortDurationFormatting45Minutes30Seconds(): void
    {
        $this->assertEquals('45m 30s', format_duration_short(45 * 60 + 30));
    }

    public function testShortDurationFormatting30Seconds(): void
    {
        $this->assertEquals('30s', format_duration_short(30));
    }

    public function testDayLengthComparisonPositive(): void
    {
        $this->assertEquals(
            '+2m 30s',
            format_day_length_comparison(150, 'day')
        );
    }

    public function testDayLengthComparisonNegative(): void
    {
        $this->assertEquals(
            '-1m 45s',
            format_day_length_comparison(-105, 'day')
        );
    }

    public function testDayLengthComparisonZero(): void
    {
        $this->assertEquals(
            'same length as yesterday',
            format_day_length_comparison(0, 'day')
        );
    }

    public function testDayLengthComparisonNight(): void
    {
        $this->assertEquals(
            '+5m 00s',
            format_day_length_comparison(300, 'night')
        );
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
