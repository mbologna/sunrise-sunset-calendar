<?php

/**
 * Cache class for managing application caches.
 *
 * Per-request memoization of expensive astronomical calculations. Each PHP
 * request starts with an empty cache, so entries are bounded by the feed window.
 */

declare(strict_types=1);

namespace SunCalendar;

class Cache
{
    /** @var Cache|null Singleton instance */
    private static ?Cache $instance = null;

    /** @var array|null Strings configuration */
    private ?array $strings = null;

    /** @var array<string, array<float>> Percentile cache keyed by lat:lon:year */
    private array $percentileCache = [];

    /** @var array<string, array> Week summary cache */
    private array $weekSummaryCache = [];

    /** @var array<string, array> Solar calculation cache */
    private array $solarCalcCache = [];

    /** @var array<string, array> Moon phase cache keyed by "year-month" */
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
        $cacheKey = sprintf('%.4f:%.4f:%d:%.2f', $lat, $lon, $year, $utcOffset);

        if (!isset($this->percentileCache[$cacheKey])) {
            $daylightLengths = [];
            $daysInYear = checkdate(2, 29, $year) ? 366 : 365;

            for ($day = 1; $day <= $daysInYear; $day++) {
                // mktime normalizes day-of-year overflow into the right month
                $date = getdate(mktime(12, 0, 0, 1, $day, $year));
                $result = calculate_sun_times($date['year'], $date['mon'], $date['mday'], $lat, $lon, $utcOffset);
                $daylightLengths[] = $result['daylength_h'];
            }
            sort($daylightLengths);

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
     * @return array Week summary data
     */
    public function getWeekSummary(int $weekStart, float $lat, float $lon, float $utcOffset): array
    {
        $strings = $this->getStrings();
        $cacheKey = sprintf('%d:%.4f:%.4f:%.2f', $weekStart, $lat, $lon, $utcOffset);

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
        $this->solarCalcCache[$key] = $data;
    }

    /**
     * Get cached moon phase.
     *
     * @param string $key Cache key in format "year-month"
     * @return array|null Cached data or null
     */
    public function getMoonPhase(string $key): ?array
    {
        return $this->moonPhaseCache[$key] ?? null;
    }

    /**
     * Set cached moon phase.
     *
     * @param string $key Cache key in format "year-month"
     * @param array $data Data to cache
     */
    public function setMoonPhase(string $key, array $data): void
    {
        $this->moonPhaseCache[$key] = $data;
    }

    /**
     * Reset singleton instance (for testing).
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }
}
