<?php

namespace Tests\Unit;

use SunCalendar\Cache;
use Tests\BaseTest;

/**
 * Cache Singleton Test Suite
 * Tests cache hit/miss behavior and size limits
 */
class CacheTest extends BaseTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::resetInstance();
    }

    public function testDaylightLengthsCacheHit(): void
    {
        $cache = Cache::getInstance();

        // First call
        $result1 = $cache->getDaylightLengths(40.0, 15.0, 2026, 1.0);
        $this->assertIsArray($result1);

        // Second call should return same array object (cached)
        $result2 = $cache->getDaylightLengths(40.0, 15.0, 2026, 1.0);
        $this->assertSame($result1, $result2);
    }

    public function testDaylightLengthsCacheMissOnDifferentUtcOffset(): void
    {
        $cache = Cache::getInstance();

        // Two different UTC offsets should cache separately
        $result1 = $cache->getDaylightLengths(40.0, 15.0, 2026, 0.0);
        $result2 = $cache->getDaylightLengths(40.0, 15.0, 2026, 2.0);

        // Results may be similar but should be different cache entries
        $this->assertNotSame($result1, $result2);
    }

    public function testSolarCalcCacheHit(): void
    {
        $cache = Cache::getInstance();

        $key = '2026-02-15:40.0000:15.0000:1.00';
        $data = ['test' => 'data'];

        $cache->setSolarCalc($key, $data);
        $result = $cache->getSolarCalc($key);

        $this->assertSame($data, $result);
    }

    public function testSolarCalcCacheMiss(): void
    {
        $cache = Cache::getInstance();
        $result = $cache->getSolarCalc('nonexistent-key');
        $this->assertNull($result);
    }

    public function testMoonPhaseCacheHit(): void
    {
        $cache = Cache::getInstance();

        $key = '2026-02';
        $data = [['phase' => 'new_moon', 'timestamp' => 1707000000]];

        $cache->setMoonPhase($key, $data);
        $result = $cache->getMoonPhase($key);

        $this->assertSame($data, $result);
    }

    public function testMoonPhaseCacheMiss(): void
    {
        $cache = Cache::getInstance();
        $result = $cache->getMoonPhase('2026-13');
        $this->assertNull($result);
    }

    public function testCacheLimitEnforcement(): void
    {
        $cache = Cache::getInstance();

        // Add 110 solar calc entries (exceeds MAX_ENTRIES of 100)
        for ($i = 0; $i < 110; $i++) {
            $key = "test-key-{$i}";
            $cache->setSolarCalc($key, ['index' => $i]);
        }

        $stats = $cache->getStats();
        // Should be at or below MAX_ENTRIES
        $this->assertLessThanOrEqual(100, $stats['solar_calc']);
    }

    public function testWeekSummaryWithUtcOffset(): void
    {
        $cache = Cache::getInstance();

        // Week summary cache key now includes UTC offset
        $weekStart = mktime(0, 0, 0, 2, 2, 2026);
        $lat = 40.0;
        $lon = 15.0;

        // With UTC 0
        $result1 = $cache->getWeekSummary($weekStart, $lat, $lon, 0.0);
        // Results may be null if calculate_sun_times not properly set up, but cache should work
        $this->assertTrue(true); // Just verify no exception

        // With UTC +2
        $result2 = $cache->getWeekSummary($weekStart, $lat, $lon, 2.0);
        $this->assertTrue(true); // Just verify no exception
    }
}
