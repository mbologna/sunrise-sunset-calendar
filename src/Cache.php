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

    /** @var array<string, array<array>> Per-day solar data keyed by lat:lon:year:offset */
    private array $yearProfileCache = [];

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
            $daylightLengths = array_column($this->getYearProfile($lat, $lon, $year, $utcOffset), 'daylength_h');
            sort($daylightLengths);
            $this->percentileCache[$cacheKey] = $daylightLengths;
        }

        return $this->percentileCache[$cacheKey];
    }

    /**
     * Get the solar calculations for every day of a year, in calendar order.
     *
     * One fixed UTC offset applies to the whole year, so day fractions compare
     * across DST changes. Each record adds 'timestamp' (local noon) to the
     * calculate_sun_times() result.
     *
     * @param float $lat Latitude
     * @param float $lon Longitude
     * @param int $year Year
     * @param float $utcOffset UTC offset in hours
     * @return array<array> One record per day
     */
    public function getYearProfile(float $lat, float $lon, int $year, float $utcOffset): array
    {
        $cacheKey = sprintf('%.4f:%.4f:%d:%.2f', $lat, $lon, $year, $utcOffset);

        if (!isset($this->yearProfileCache[$cacheKey])) {
            $profile = [];
            $daysInYear = checkdate(2, 29, $year) ? 366 : 365;

            for ($day = 1; $day <= $daysInYear; $day++) {
                // mktime normalizes day-of-year overflow into the right month
                $timestamp = mktime(12, 0, 0, 1, $day, $year);
                $date = getdate($timestamp);
                $profile[] = ['timestamp' => $timestamp]
                    + calculate_sun_times($date['year'], $date['mon'], $date['mday'], $lat, $lon, $utcOffset);
            }

            $this->yearProfileCache[$cacheKey] = $profile;
        }

        return $this->yearProfileCache[$cacheKey];
    }

    /**
     * Get cached week summary data.
     *
     * @param int $weekStart Week start timestamp
     * @param float $lat Latitude
     * @param float $lon Longitude
     * @param float $utcOffset UTC offset
     * @return array{avg_length: float, start_length: float, end_length: float, total_change: float, trend: string}
     *     Lengths in seconds; trend is 'increasing', 'decreasing' or 'stable'
     */
    public function getWeekSummary(int $weekStart, float $lat, float $lon, float $utcOffset): array
    {
        $cacheKey = sprintf('%d:%.4f:%.4f:%.2f', $weekStart, $lat, $lon, $utcOffset);

        if (isset($this->weekSummaryCache[$cacheKey])) {
            return $this->weekSummaryCache[$cacheKey];
        }

        $lengths = [];
        for ($i = 0; $i < 7; $i++) {
            $dateParts = getdate(strtotime("+{$i} days", $weekStart));
            $result = calculate_sun_times(
                $dateParts['year'],
                $dateParts['mon'],
                $dateParts['mday'],
                $lat,
                $lon,
                $utcOffset
            );
            $lengths[] = $result['daylength_h'] * 3600;
        }

        $totalChange = end($lengths) - $lengths[0];
        $trend = match (true) {
            $totalChange > 300 => 'increasing',
            $totalChange < -300 => 'decreasing',
            default => 'stable',
        };

        $summary = [
            'avg_length' => array_sum($lengths) / count($lengths),
            'start_length' => $lengths[0],
            'end_length' => end($lengths),
            'total_change' => $totalChange,
            'trend' => $trend,
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
