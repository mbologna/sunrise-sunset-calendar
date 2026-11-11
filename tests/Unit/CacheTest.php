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

    public function testYearProfileIsCachedPerUtcOffset(): void
    {
        $cache = Cache::getInstance();

        $profile = $cache->getYearProfile(40.0, 15.0, 2026, 1.0);
        $this->assertCount(365, $profile);
        $this->assertSame($profile, $cache->getYearProfile(40.0, 15.0, 2026, 1.0));
        $this->assertNotSame($profile, $cache->getYearProfile(40.0, 15.0, 2026, 2.0));
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
        $this->assertGreaterThan($summary['start_length'], $summary['end_length'], 'Days lengthen in February');
        $this->assertSame('increasing', $summary['trend']);
    }
}
