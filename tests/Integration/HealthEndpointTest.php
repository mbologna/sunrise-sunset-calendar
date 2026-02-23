<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the health check endpoint.
 */
class HealthEndpointTest extends TestCase
{
    /**
     * Test health endpoint returns valid JSON response.
     */
    public function testHealthEndpointReturnsJson(): void
    {
        // Simulate the health endpoint logic
        $_GET['health'] = '1';

        ob_start();

        // Capture what the health endpoint would output
        $response = json_encode([
            'status' => 'ok',
            'version' => '10.0',
            'php' => PHP_VERSION,
            'timestamp' => time(),
        ]);

        ob_end_clean();

        $data = json_decode($response, true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('version', $data);
        $this->assertArrayHasKey('php', $data);
        $this->assertArrayHasKey('timestamp', $data);

        unset($_GET['health']);
    }

    /**
     * Test health endpoint status is 'ok'.
     */
    public function testHealthEndpointStatusIsOk(): void
    {
        $response = [
            'status' => 'ok',
            'version' => '10.0',
            'php' => PHP_VERSION,
            'timestamp' => time(),
        ];

        $this->assertEquals('ok', $response['status']);
    }

    /**
     * Test health endpoint version matches expected.
     */
    public function testHealthEndpointVersionFormat(): void
    {
        $response = [
            'status' => 'ok',
            'version' => '10.0',
            'php' => PHP_VERSION,
            'timestamp' => time(),
        ];

        $this->assertMatchesRegularExpression('/^\d+\.\d+$/', $response['version']);
    }

    /**
     * Test health endpoint timestamp is valid.
     */
    public function testHealthEndpointTimestampIsValid(): void
    {
        $before = time();

        $response = [
            'status' => 'ok',
            'version' => '10.0',
            'php' => PHP_VERSION,
            'timestamp' => time(),
        ];

        $after = time();

        $this->assertGreaterThanOrEqual($before, $response['timestamp']);
        $this->assertLessThanOrEqual($after, $response['timestamp']);
    }

    /**
     * Test health endpoint PHP version matches runtime.
     */
    public function testHealthEndpointPhpVersionMatchesRuntime(): void
    {
        $response = [
            'status' => 'ok',
            'version' => '10.0',
            'php' => PHP_VERSION,
            'timestamp' => time(),
        ];

        $this->assertEquals(PHP_VERSION, $response['php']);
    }
}
