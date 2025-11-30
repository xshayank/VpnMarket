<?php

namespace App\Helpers;

/**
 * Helper class for normalizing duration and data limit values
 * across different panel APIs (Marzneshin, Eylandoo, etc.)
 */
class DurationNormalization
{
    /**
     * Number of seconds in a day
     */
    public const SECONDS_PER_DAY = 86400;

    /**
     * Number of bytes in a megabyte
     */
    public const BYTES_PER_MB = 1048576; // 1024 * 1024

    /**
     * Number of bytes in a gigabyte
     */
    public const BYTES_PER_GB = 1073741824; // 1024 * 1024 * 1024

    /**
     * Convert seconds to days using ceiling (round up to nearest full day)
     * For start_on_first_use expire strategy, panels expect day-based durations.
     *
     * Conversion rule: days = ceil(seconds / 86400)
     * Any partial day is rounded up to a full day for panel compatibility.
     * Minimum of 1 day if any positive seconds are provided.
     *
     * Examples:
     * - 1 hour (3600 seconds) → 1 day
     * - 25 hours (90000 seconds) → 2 days
     * - 30 days (2592000 seconds) → 30 days
     *
     * @param  int  $seconds  Duration in seconds
     * @return int Duration in days (minimum 1 if seconds > 0)
     */
    public static function normalizeUsageDurationSecondsToDays(int $seconds): int
    {
        if ($seconds <= 0) {
            return 0;
        }

        // Use ceiling to round up - any partial day becomes a full day
        // This ensures no compatibility issues with panels that expect full days
        $days = (int) ceil($seconds / self::SECONDS_PER_DAY);

        // Minimum of 1 day for any positive value
        return max(1, $days);
    }

    /**
     * Prepare data limit for Eylandoo API
     * Eylandoo supports both MB and GB units. For values < 1 GB, we use MB.
     *
     * @param  int  $bytes  Data limit in bytes
     * @return array{value: int|float, unit: string} Array with 'value' and 'unit' keys
     */
    public static function prepareEylandooDataLimit(int $bytes): array
    {
        // If bytes is 0 or negative, return 0 GB (unlimited or invalid)
        if ($bytes <= 0) {
            return [
                'value' => 0,
                'unit' => 'GB',
            ];
        }

        // Threshold: 1 GB
        if ($bytes < self::BYTES_PER_GB) {
            // Convert to MB using floor, minimum 1 MB
            $valueMB = (int) floor($bytes / self::BYTES_PER_MB);

            // Ensure minimum of 1 MB for any positive bytes
            $valueMB = max(1, $valueMB);

            return [
                'value' => $valueMB,
                'unit' => 'MB',
            ];
        }

        // >= 1 GB: Convert to GB with 2 decimal places for precision
        $valueGB = round($bytes / self::BYTES_PER_GB, 2);

        return [
            'value' => $valueGB,
            'unit' => 'GB',
        ];
    }
}
