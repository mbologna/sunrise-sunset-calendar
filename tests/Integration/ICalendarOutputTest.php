<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for iCalendar output generation.
 * Validates RFC 5545 compliance and proper formatting.
 */
class ICalendarOutputTest extends TestCase
{
    /**
     * Test that calculate_sun_times returns expected keys.
     */
    public function testCalculateSunTimesReturnStructure(): void
    {
        $result = calculate_sun_times(2026, 1, 29, 41.9, 12.5, 1);

        $expectedKeys = [
            'declination_deg',
            'equation_of_time_min',
            'sunrise_frac',
            'sunset_frac',
            'solar_noon_frac',
            'daylength_h',
            'civil_begin_frac',
            'civil_end_frac',
            'nautical_begin_frac',
            'nautical_end_frac',
            'astro_begin_frac',
            'astro_end_frac',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: $key");
        }
    }

    /**
     * Test that sunrise is before sunset.
     */
    public function testSunriseBeforeSunset(): void
    {
        $result = calculate_sun_times(2026, 6, 21, 41.9, 12.5, 2);

        $this->assertLessThan(
            $result['sunset_frac'],
            $result['sunrise_frac'],
            'Sunrise should be before sunset'
        );
    }

    /**
     * Test twilight ordering.
     */
    public function testTwilightOrdering(): void
    {
        $result = calculate_sun_times(2026, 1, 29, 41.9, 12.5, 1);

        // Morning: astro < nautical < civil < sunrise
        $this->assertLessThan(
            $result['nautical_begin_frac'],
            $result['astro_begin_frac'],
            'Astronomical dawn before nautical'
        );
        $this->assertLessThan(
            $result['civil_begin_frac'],
            $result['nautical_begin_frac'],
            'Nautical dawn before civil'
        );
        $this->assertLessThan(
            $result['sunrise_frac'],
            $result['civil_begin_frac'],
            'Civil dawn before sunrise'
        );

        // Evening: sunset < civil < nautical < astro
        $this->assertLessThan(
            $result['civil_end_frac'],
            $result['sunset_frac'],
            'Sunset before civil dusk'
        );
        $this->assertLessThan(
            $result['nautical_end_frac'],
            $result['civil_end_frac'],
            'Civil dusk before nautical'
        );
        $this->assertLessThan(
            $result['astro_end_frac'],
            $result['nautical_end_frac'],
            'Nautical dusk before astronomical'
        );
    }

    /**
     * Test moon phase info structure.
     */
    public function testMoonPhaseInfoStructure(): void
    {
        $result = get_accurate_moon_phase(time());

        $this->assertArrayHasKey('phase_name', $result);
        $this->assertArrayHasKey('illumination', $result);
        $this->assertArrayHasKey('prev_phase', $result);
        $this->assertArrayHasKey('next_phase', $result);
    }

    /**
     * Test equinox/solstice calculation structure.
     */
    public function testEquinoxSolsticeStructure(): void
    {
        $result = calculate_equinox_solstice(2026);

        $this->assertArrayHasKey('march_equinox', $result);
        $this->assertArrayHasKey('june_solstice', $result);
        $this->assertArrayHasKey('september_equinox', $result);
        $this->assertArrayHasKey('december_solstice', $result);

        // All should be valid timestamps
        foreach ($result as $event => $timestamp) {
            $this->assertGreaterThan(0, $timestamp, "$event should be positive timestamp");
        }
    }

    /**
     * Test format_ical_description escaping per RFC 5545.
     */
    public function testIcalDescriptionEscaping(): void
    {
        // Test that special characters are escaped
        $input = "Line 1\nLine 2, with comma; and semicolon\\backslash";
        $result = format_ical_description($input);

        // Newlines should become \n (literal backslash-n)
        $this->assertStringContainsString('\n', $result);

        // Commas should be escaped
        $this->assertStringContainsString('\,', $result);

        // Semicolons should be escaped
        $this->assertStringContainsString('\;', $result);

        // Backslashes should be escaped (doubled)
        $this->assertStringContainsString('\\\\', $result);

        // Should start with DESCRIPTION:
        $this->assertStringStartsWith('DESCRIPTION:', $result);

        // Should end with CRLF
        $this->assertStringEndsWith("\r\n", $result);
    }

    /**
     * Test format_ical_description line folding at 75 octets.
     */
    public function testIcalDescriptionLineFolding(): void
    {
        // Create a long string that will need folding
        $input = str_repeat('A', 100);
        $result = format_ical_description($input);

        // Split into lines
        $lines = explode("\r\n", trim($result));

        // First line: DESCRIPTION: (12 chars) + content
        // Should fold at 75 octets
        foreach ($lines as $i => $line) {
            $byteLength = strlen($line);
            $this->assertLessThanOrEqual(
                75,
                $byteLength,
                "Line $i exceeds 75 octets: $byteLength bytes"
            );
        }

        // Continuation lines should start with space
        for ($i = 1; $i < count($lines); $i++) {
            $this->assertStringStartsWith(' ', $lines[$i], "Continuation line must start with space");
        }
    }

    /**
     * Test format_ical_description with UTF-8 multibyte characters.
     * Line folding must not split multibyte characters.
     */
    public function testIcalDescriptionUtf8Handling(): void
    {
        // Create string with multibyte characters (emojis are 4 bytes each)
        $input = str_repeat('☀️🌙', 20);
        $result = format_ical_description($input);

        // Should still be valid UTF-8
        $this->assertTrue(mb_check_encoding($result, 'UTF-8'), 'Result should be valid UTF-8');

        // Each line should be valid UTF-8 (no split characters)
        $lines = explode("\r\n", trim($result));
        foreach ($lines as $i => $line) {
            $this->assertTrue(
                mb_check_encoding($line, 'UTF-8'),
                "Line $i should be valid UTF-8"
            );
        }
    }
}
