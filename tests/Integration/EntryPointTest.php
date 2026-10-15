<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Runs sunrise-sunset-calendar.php in a separate PHP process, as a web request would.
 */
class EntryPointTest extends TestCase
{
    private const TOKEN = 'entry-point-test-token';

    /**
     * @param array<string, string|array<string>> $get Query parameters
     * @return string Response body
     */
    private function request(array $get): string
    {
        $code = '$_GET = ' . var_export($get, true) . '; $_SERVER["REQUEST_METHOD"] = "GET";'
            . ' require ' . var_export(dirname(__DIR__, 2) . '/sunrise-sunset-calendar.php', true) . ';';

        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['AUTH_TOKEN' => self::TOKEN, 'CALENDAR_WINDOW_DAYS' => '7', 'CALENDAR_PAST_DAYS' => '0'] + getenv()
        );
        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        proc_close($process);

        $this->assertSame('', $stderr, 'No PHP warnings or errors');

        return (string) $stdout;
    }

    public function testHealthEndpoint(): void
    {
        $data = json_decode($this->request(['health' => '1']), true);

        $this->assertSame('ok', $data['status']);
        $this->assertSame(PHP_VERSION, $data['php']);
        $this->assertEqualsWithDelta(time(), $data['timestamp'], 60);
    }

    public function testFeedRejectsWrongToken(): void
    {
        $this->assertSame('Invalid authentication token', $this->request(['feed' => '1', 'token' => 'wrong']));
    }

    public function testFeedRejectsNonStringToken(): void
    {
        $this->assertSame('Invalid authentication token', $this->request(['feed' => '1', 'token' => ['x']]));
    }

    public function testFeedReturnsCalendar(): void
    {
        $body = $this->request([
            'feed' => '1',
            'token' => self::TOKEN,
            'lat' => '40.7128',
            'lon' => '-74.0060',
            'zone' => 'America/New_York',
            'location' => 'New York',
            'daynight' => '1',
        ]);

        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $body);
        $this->assertStringContainsString("X-WR-TIMEZONE:America/New_York\r\n", $body);
        $this->assertSame(8, substr_count($body, 'SUMMARY:☀️ Daylight'));
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $body);
    }

    public function testWebInterfaceRenders(): void
    {
        $body = $this->request([]);

        $this->assertStringContainsString('<title>Sun &amp; Twilight Calendar</title>', $body);
        $this->assertStringContainsString('<option value="Europe/Rome" selected>', $body);
    }
}
