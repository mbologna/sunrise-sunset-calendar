<?php

namespace Tests\Unit;

use SunCalendar\Cache;
use Tests\BaseTest;

/**
 * Cache Singleton Test Suite
 * Tests cache hit/miss behavior
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

    public function testWeekSummaryIsCached(): void
    {
        $cache = Cache::getInstance();
        $weekStart = mktime(0, 0, 0, 2, 1, 2026);

        $summary = $cache->getWeekSummary($weekStart, 40.0, 15.0, 1.0);

        $this->assertSame($summary, $cache->getWeekSummary($weekStart, 40.0, 15.0, 1.0));
        $this->assertSame($weekStart, $summary['shortest_day'], 'Days lengthen in February');
        $this->assertSame('Increasing', $summary['trend']);
    }
}
