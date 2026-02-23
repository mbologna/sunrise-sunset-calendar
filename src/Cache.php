<?php

/**
 * Cache class for managing application caches.
 *
 * Replaces $GLOBALS-based caching with a cleaner singleton pattern.
 * Provides lazy loading, size limits, and cache clearing.
 */

declare(strict_types=1);

namespace SunCalendar;

use DateTime;

class Cache
{
    /** @var int Maximum entries per cache type */
    private const MAX_ENTRIES = 100;

    /** @var Cache|null Singleton instance */
    private static ?Cache $instance = null;

    /** @var array|null Strings configuration */
    private ?array $strings = null;

    /** @var array<string, array<float>> Percentile cache keyed by lat:lon:year */
    private array $percentileCache = [];

    /** @var array<string, array|null> Week summary cache */
    private array $weekSummaryCache = [];

    /** @var array<string, array> Solar calculation cache */
    private array $solarCalcCache = [];

    /** @var array<int, array> Moon phase cache */
    private array $moonPhaseCache = [];

    /**
     * Private constructor for singleton pattern.
     */
    private function __construct()
    {
    }

    /**
     * Get the singleton cache instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get strings configuration with lazy loading.
     *
     * @return array Strings configuration
     */
    public function getStrings(): array
    {
        if ($this->strings === null) {
            $this->strings = require __DIR__ . '/strings.php';
        }
        return $this->strings;
    }

    /**
     * Clear strings cache.
     */
    public function clearStrings(): void
    {
        $this->strings = null;
    }

    /**
     * Get cached daylight lengths for a location/year.
     *
     * @param float $lat Latitude
     * @param float $lon Longitude
     * @param int $year Year
     * @param float $utcOffset UTC offset in hours
     * @return array<float> Sorted array of daylight lengths in hours
     */
    public function getDaylightLengths(float $lat, float $lon, int $year, float $utcOffset): array
    {
        $cacheKey = sprintf('%.4f:%.4f:%d', $lat, $lon, $year);

        if (!isset($this->percentileCache[$cacheKey])) {
            $daylightLengths = [];
            $daysInYear = (($year % 4 == 0 && $year % 100 != 0) || $year % 400 == 0) ? 366 : 365;

            for ($day = 1; $day <= $daysInYear; $day++) {
                $date = new DateTime("$year-01-01");
                $date->modify('+' . ($day - 1) . ' days');
                $result = calculate_sun_times(
                    (int) $date->format('Y'),
                    (int) $date->format('m'),
                    (int) $date->format('d'),
                    $lat,
                    $lon,
                    $utcOffset
                );
                $daylightLengths[] = $result['daylength_h'];
            }
            sort($daylightLengths);

            $this->enforceCacheLimit('percentileCache');
            $this->percentileCache[$cacheKey] = $daylightLengths;
        }

        return $this->percentileCache[$cacheKey];
    }

    /**
     * Get cached week summary data.
     *
     * @param int $weekStart Week start timestamp
     * @param float $lat Latitude
     * @param float $lon Longitude
     * @param float $utcOffset UTC offset
     * @return array|null Week summary data
     */
    public function getWeekSummary(int $weekStart, float $lat, float $lon, float $utcOffset): ?array
    {
        $strings = $this->getStrings();
        $cacheKey = sprintf('%d:%.4f:%.4f', $weekStart, $lat, $lon);

        if (isset($this->weekSummaryCache[$cacheKey])) {
            return $this->weekSummaryCache[$cacheKey];
        }

        $weekEnd = strtotime('+6 days', $weekStart);
        $dayLengths = [];
        $current = $weekStart;

        while ($current <= $weekEnd) {
            $dateParts = getdate($current);
            $result = calculate_sun_times(
                $dateParts['year'],
                $dateParts['mon'],
                $dateParts['mday'],
                $lat,
                $lon,
                $utcOffset
            );
            $dayLengths[] = [
                'timestamp' => $current,
                'length' => $result['daylength_h'] * 3600,
            ];
            $current = strtotime('+1 day', $current);
        }

        if (empty($dayLengths)) {
            return null;
        }

        $lengths = array_column($dayLengths, 'length');
        $avgLength = array_sum($lengths) / count($lengths);
        $minLength = min($lengths);
        $maxLength = max($lengths);
        $totalChange = end($lengths) - $lengths[0];

        if ($totalChange > 300) {
            $trend = $strings['trends']['increasing'];
            $trendEmoji = $strings['trend_emojis']['increasing'];
        } elseif ($totalChange < -300) {
            $trend = $strings['trends']['decreasing'];
            $trendEmoji = $strings['trend_emojis']['decreasing'];
        } else {
            $trend = $strings['trends']['stable'];
            $trendEmoji = $strings['trend_emojis']['stable'];
        }

        $shortestIdx = array_search($minLength, $lengths);
        $longestIdx = array_search($maxLength, $lengths);
        $moonInfo = get_accurate_moon_phase($weekStart);

        $summary = [
            'avg_length' => $avgLength,
            'min_length' => $minLength,
            'max_length' => $maxLength,
            'total_change' => $totalChange,
            'trend' => $trend,
            'trend_emoji' => $trendEmoji,
            'shortest_day' => $dayLengths[$shortestIdx]['timestamp'],
            'longest_day' => $dayLengths[$longestIdx]['timestamp'],
            'moon_phase' => $moonInfo['phase_name'],
        ];

        $this->enforceCacheLimit('weekSummaryCache');
        $this->weekSummaryCache[$cacheKey] = $summary;
        return $summary;
    }

    /**
     * Get cached solar calculation.
     *
     * @param string $key Cache key
     * @return array|null Cached data or null
     */
    public function getSolarCalc(string $key): ?array
    {
        return $this->solarCalcCache[$key] ?? null;
    }

    /**
     * Set cached solar calculation.
     *
     * @param string $key Cache key
     * @param array $data Data to cache
     */
    public function setSolarCalc(string $key, array $data): void
    {
        $this->enforceCacheLimit('solarCalcCache');
        $this->solarCalcCache[$key] = $data;
    }

    /**
     * Get cached moon phase.
     *
     * @param int $timestamp Timestamp
     * @return array|null Cached data or null
     */
    public function getMoonPhase(int $timestamp): ?array
    {
        return $this->moonPhaseCache[$timestamp] ?? null;
    }

    /**
     * Set cached moon phase.
     *
     * @param int $timestamp Timestamp
     * @param array $data Data to cache
     */
    public function setMoonPhase(int $timestamp, array $data): void
    {
        $this->enforceCacheLimit('moonPhaseCache');
        $this->moonPhaseCache[$timestamp] = $data;
    }

    /**
     * Clear all caches.
     *
     * @param bool $includeStrings Also clear strings cache
     */
    public function clearAll(bool $includeStrings = false): void
    {
        $this->percentileCache = [];
        $this->weekSummaryCache = [];
        $this->solarCalcCache = [];
        $this->moonPhaseCache = [];

        if ($includeStrings) {
            $this->strings = null;
        }
    }

    /**
     * Get cache statistics.
     *
     * @return array<string, int> Cache sizes
     */
    public function getStats(): array
    {
        return [
            'strings' => $this->strings !== null ? 1 : 0,
            'percentile' => count($this->percentileCache),
            'week_summary' => count($this->weekSummaryCache),
            'solar_calc' => count($this->solarCalcCache),
            'moon_phase' => count($this->moonPhaseCache),
        ];
    }

    /**
     * Enforce cache size limit.
     *
     * @param string $cacheName Name of the cache property
     */
    private function enforceCacheLimit(string $cacheName): void
    {
        if (count($this->$cacheName) > self::MAX_ENTRIES) {
            // Remove oldest entries (first 10% of cache)
            $removeCount = (int) ceil(self::MAX_ENTRIES * 0.1);
            $this->$cacheName = array_slice($this->$cacheName, $removeCount, null, true);
        }
    }

    /**
     * Reset singleton instance (for testing).
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }
}
