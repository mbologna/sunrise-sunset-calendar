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
        $result = get_moon_phase_info(time());

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

    /**
     * End-to-end test: Generate actual calendar output and validate RFC 5545 compliance.
     */
    public function testEndToEndCalendarGenerationRfc5545Compliance(): void
    {
        $lat = 45.68;
        $lon = 9.55;
        $utc_offset = 1.0;
        $year = 2026;
        $month = 2;
        $day = 9;

        $STRINGS = get_strings();

        // Simulate calendar generation for a single day
        $current_day = strtotime("{$year}-{$month}-{$day}");
        $date_str = date('Ymd', $current_day);

        // Calculate sun times
        $sun_calc = calculate_sun_times($year, $month, $day, $lat, $lon, $utc_offset);

        // Get timestamps
        $sunrise = fraction_to_timestamp($year, $month, $day, $sun_calc['sunrise_frac']);
        $sunset = fraction_to_timestamp($year, $month, $day, $sun_calc['sunset_frac']);

        // Build a complete VEVENT
        $output = "BEGIN:VCALENDAR\r\n";
        $output .= "VERSION:2.0\r\n";
        $output .= "PRODID:{$STRINGS['calendar_prodid']}\r\n";
        $output .= "CALSCALE:GREGORIAN\r\n";
        $output .= "METHOD:PUBLISH\r\n";

        $output .= "BEGIN:VEVENT\r\n";
        $output .= "UID:test-event-{$date_str}-{$lat}-{$lon}@sun-calendar\r\n";
        $output .= 'DTSTAMP:' . gmdate('Ymd\THis\Z') . "\r\n";
        $output .= 'DTSTART:' . gmdate('Ymd\THis\Z', $sunrise) . "\r\n";
        $output .= 'DTEND:' . gmdate('Ymd\THis\Z', $sunset) . "\r\n";
        $output .= "SUMMARY:{$STRINGS['summaries']['daylight']}\r\n";

        // Build description with all our helpers
        $daylight_seconds = round($sun_calc['daylength_h'] * 3600);
        $daylight_duration = format_duration_full((int) $daylight_seconds);
        $daylight_percentile = calculate_daylight_percentile(
            $sun_calc['daylength_h'], $lat, $lon, $year, $utc_offset
        );
        $day_info = get_day_of_year($current_day);
        $separator = format_separator();

        $desc = "{$STRINGS['headers']['at_a_glance']}\n";
        $desc .= $separator . "\n";
        $desc .= "{$STRINGS['labels']['duration']}: {$daylight_duration}\n";
        $desc .= "{$STRINGS['labels']['progress']}: " . format_percentile_bar($daylight_percentile) . "\n";
        $desc .= "{$STRINGS['labels']['day_of_year']}: {$day_info['formatted']}\n";

        $output .= format_ical_description($desc);
        $output .= "TRANSP:TRANSPARENT\r\n";
        $output .= "END:VEVENT\r\n";
        $output .= "END:VCALENDAR\r\n";

        // RFC 5545 validations
        $this->assertStringContainsString('BEGIN:VCALENDAR', $output);
        $this->assertStringContainsString('END:VCALENDAR', $output);
        $this->assertStringContainsString('VERSION:2.0', $output);
        $this->assertStringContainsString('PRODID:', $output);
        $this->assertStringContainsString('BEGIN:VEVENT', $output);
        $this->assertStringContainsString('END:VEVENT', $output);
        $this->assertStringContainsString('UID:', $output);
        $this->assertStringContainsString('DTSTAMP:', $output);
        $this->assertStringContainsString('DTSTART:', $output);
        $this->assertStringContainsString('DTEND:', $output);
        $this->assertStringContainsString('SUMMARY:', $output);
        $this->assertStringContainsString('DESCRIPTION:', $output);

        // Check CRLF line endings
        $this->assertMatchesRegularExpression('/\r\n/', $output, 'Should use CRLF line endings');

        // Check that each line is <= 75 octets when unfolded
        $lines = explode("\r\n", $output);
        foreach ($lines as $i => $line) {
            if ($line === '' || $line[0] === ' ') {
                continue; // Skip empty lines and continuation lines
            }
            // For non-continuation lines, length should be <= 75
            $byteLength = strlen($line);
            $this->assertLessThanOrEqual(
                75,
                $byteLength,
                "Line $i exceeds 75 octets: '$line' ($byteLength bytes)"
            );
        }

        // Validate no null bytes
        $this->assertStringNotContainsString("\0", $output, 'Should not contain null bytes');

        // Validate UTF-8
        $this->assertTrue(mb_check_encoding($output, 'UTF-8'), 'Output should be valid UTF-8');
    }

    /**
     * Test that all event types can be generated without errors.
     */
    public function testAllEventTypesGenerateValidOutput(): void
    {
        $lat = 45.68;
        $lon = 9.55;
        $utc_offset = 1.0;
        $year = 2026;
        $month = 2;
        $day = 9;
        $time_format = 'H:i';

        $STRINGS = get_strings();
        $current_day = strtotime("{$year}-{$month}-{$day}");

        // Calculate all sun times
        $sun_calc = calculate_sun_times($year, $month, $day, $lat, $lon, $utc_offset);

        $sunrise = fraction_to_timestamp($year, $month, $day, $sun_calc['sunrise_frac']);
        $sunset = fraction_to_timestamp($year, $month, $day, $sun_calc['sunset_frac']);
        $civil_begin = fraction_to_timestamp($year, $month, $day, $sun_calc['civil_begin_frac']);
        $civil_end = fraction_to_timestamp($year, $month, $day, $sun_calc['civil_end_frac']);
        $nautical_begin = fraction_to_timestamp($year, $month, $day, $sun_calc['nautical_begin_frac']);
        $nautical_end = fraction_to_timestamp($year, $month, $day, $sun_calc['nautical_end_frac']);
        $astro_begin = fraction_to_timestamp($year, $month, $day, $sun_calc['astro_begin_frac']);
        $astro_end = fraction_to_timestamp($year, $month, $day, $sun_calc['astro_end_frac']);

        $daylight_seconds = round($sun_calc['daylength_h'] * 3600);
        $daylight_pct = round(($daylight_seconds / 86400) * 100, 1);
        $night_seconds = 86400 - $daylight_seconds;
        $night_pct = round(($night_seconds / 86400) * 100, 1);

        $daylight_percentile = calculate_daylight_percentile(
            $sun_calc['daylength_h'], $lat, $lon, $year, $utc_offset
        );
        $night_percentile = 100 - $daylight_percentile;

        $moon_info = get_moon_phase_info($current_day);

        // Test all duration calculations
        $durations = [
            'civil_dawn' => format_duration_full($sunrise - $civil_begin),
            'nautical_dawn' => format_duration_full($civil_begin - $nautical_begin),
            'astronomical_dawn' => format_duration_full($nautical_begin - $astro_begin),
            'daylight' => format_duration_full((int) $daylight_seconds),
            'civil_dusk' => format_duration_full($civil_end - $sunset),
            'nautical_dusk' => format_duration_full($nautical_end - $civil_end),
            'astronomical_dusk' => format_duration_full($astro_end - $nautical_end),
            'night' => format_duration_full((int) $night_seconds),
        ];

        foreach ($durations as $event => $duration) {
            $this->assertIsString($duration, "$event duration should be string");
            $this->assertNotEmpty($duration, "$event duration should not be empty");
            // Duration should contain 'hour' or 'minute'
            $this->assertTrue(
                str_contains($duration, 'hour') || str_contains($duration, 'minute'),
                "$event duration '$duration' should contain time unit"
            );
        }

        // Test percentile bar generation
        $daylight_bar = format_percentile_bar($daylight_percentile);
        $this->assertStringContainsString('%', $daylight_bar);
        $this->assertStringContainsString('[', $daylight_bar);

        $night_bar = format_percentile_bar($night_percentile);
        $this->assertStringContainsString('%', $night_bar);

        // Test day of year
        $day_info = get_day_of_year($current_day);
        $this->assertArrayHasKey('formatted', $day_info);
        $this->assertStringContainsString('of', $day_info['formatted']);

        // Test moon phase emoji
        $moon_emoji = get_moon_phase_emoji($moon_info['phase_name']);
        $this->assertNotEmpty($moon_emoji);

        // Test separator
        $separator = format_separator();
        $this->assertNotEmpty($separator);
    }

    /**
     * Test special astronomical events output structure.
     */
    public function testSpecialAstronomicalEventsOutput(): void
    {
        $year = 2026;
        $events = get_special_astronomical_events($year);

        $this->assertCount(4, $events, 'Should have 4 special events (equinoxes and solstices)');

        $expectedNames = ['March Equinox', 'June Solstice', 'September Equinox', 'December Solstice'];
        $actualNames = array_column($events, 'name');

        foreach ($expectedNames as $name) {
            $this->assertContains($name, $actualNames, "Missing event: $name");
        }

        foreach ($events as $event) {
            $this->assertArrayHasKey('date', $event);
            $this->assertArrayHasKey('name', $event);
            $this->assertArrayHasKey('emoji', $event);
            $this->assertArrayHasKey('description', $event);

            // Date should be in 2026
            $eventYear = (int) date('Y', $event['date']);
            $this->assertEquals(2026, $eventYear);

            // Emoji should not be empty
            $this->assertNotEmpty($event['emoji']);

            // Description should not be empty
            $this->assertNotEmpty($event['description']);
        }
    }

    /**
     * Test week summary data structure.
     */
    public function testWeekSummaryOutputStructure(): void
    {
        $lat = 45.68;
        $lon = 9.55;
        $utc_offset = 1.0;
        $STRINGS = get_strings();

        // Find next Sunday
        $current = strtotime('today');
        while (date('w', $current) != 0) {
            $current = strtotime('+1 day', $current);
        }

        $week_data = get_cached_week_summary($current, $lat, $lon, $utc_offset, $STRINGS);

        $this->assertIsArray($week_data);
        $this->assertArrayHasKey('avg_length', $week_data);
        $this->assertArrayHasKey('min_length', $week_data);
        $this->assertArrayHasKey('max_length', $week_data);
        $this->assertArrayHasKey('total_change', $week_data);
        $this->assertArrayHasKey('trend', $week_data);
        $this->assertArrayHasKey('trend_emoji', $week_data);
        $this->assertArrayHasKey('moon_phase', $week_data);

        // Test formatting
        $avg_formatted = format_duration_full((int) $week_data['avg_length']);
        $this->assertStringContainsString('hour', $avg_formatted);

        // Test trend emoji
        $this->assertNotEmpty($week_data['trend_emoji']);
    }
}
