<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Log;

/**
 * Unified helper for normalizing expiry/duration values across different panel APIs.
 * Handles all expiry strategies: fixed_date, start_on_first_use, never.
 *
 * This class centralizes all expiry-related conversion logic to prevent
 * compounded conversions and ensure consistency across the application.
 */
class ExpiryNormalizer
{
    /**
     * Number of seconds in a day
     */
    public const SECONDS_PER_DAY = 86400;

    /**
     * Number of seconds in an hour
     */
    public const SECONDS_PER_HOUR = 3600;

    /**
     * Maximum allowed duration in days (~10 years)
     * Values exceeding this are capped or flagged as suspicious
     */
    public const MAX_DURATION_DAYS = 3650;

    /**
     * Minimum duration that could reasonably be in seconds (2 days worth)
     * Values below this AND divisible by 3600 are likely hours
     * 
     * Note: This is disabled by default since the API spec clearly states
     * usage_duration is in seconds. Bots should send seconds, not hours.
     * The auto-detection is kept for backward compatibility but not triggered
     * automatically - only when explicitly requested.
     */
    public const HOUR_DETECTION_THRESHOLD = 172800; // 2 days in seconds
    
    /**
     * Whether to auto-detect hours input (disabled by default)
     * Set to true only if you need backward compatibility with bots sending hours
     */
    public const AUTO_DETECT_HOURS = false;

    /**
     * Result structure from normalization
     *
     * @var array{
     *   expire_strategy: string,
     *   normalized_usage_days: int|null,
     *   fixed_date: \Carbon\Carbon|null,
     *   original_usage_duration_seconds: int|null,
     *   final_payload_usage_duration_seconds: int|null,
     *   warnings: array<string>,
     *   was_hours_converted: bool
     * }
     */
    protected array $result;

    public function __construct()
    {
        $this->resetResult();
    }

    /**
     * Reset the result structure for a new normalization
     */
    protected function resetResult(): void
    {
        $this->result = [
            'expire_strategy' => 'fixed_date',
            'normalized_usage_days' => null,
            'fixed_date' => null,
            'original_usage_duration_seconds' => null,
            'final_payload_usage_duration_seconds' => null,
            'warnings' => [],
            'was_hours_converted' => false,
        ];
    }

    /**
     * Normalize expiry data based on the strategy.
     *
     * @param  string  $expireStrategy  One of: 'fixed_date', 'start_on_first_use', 'never'
     * @param  array  $input  Input data containing relevant fields based on strategy:
     *                        - fixed_date: 'expire_date' (ISO-8601) or 'expire' (unix timestamp)
     *                        - start_on_first_use: 'usage_duration' (seconds) or 'usage_duration_seconds'
     *                        - never: no additional fields required
     * @return array Normalized result with keys:
     *               - expire_strategy: string
     *               - normalized_usage_days: int|null (for start_on_first_use)
     *               - fixed_date: Carbon|null (for fixed_date strategy)
     *               - original_usage_duration_seconds: int|null
     *               - final_payload_usage_duration_seconds: int|null
     *               - warnings: array of warning messages
     *               - was_hours_converted: bool
     */
    public function normalize(string $expireStrategy, array $input): array
    {
        $this->resetResult();
        $this->result['expire_strategy'] = $expireStrategy;

        switch ($expireStrategy) {
            case 'start_on_first_use':
                $this->normalizeStartOnFirstUse($input);
                break;

            case 'never':
                $this->normalizeNever();
                break;

            case 'fixed_date':
            default:
                $this->normalizeFixedDate($input);
                break;
        }

        // Log for traceability (debug level, no PII)
        Log::debug('ExpiryNormalizer: Normalization complete', [
            'expire_strategy' => $this->result['expire_strategy'],
            'original_usage_duration_seconds' => $this->result['original_usage_duration_seconds'],
            'normalized_usage_days' => $this->result['normalized_usage_days'],
            'final_payload_usage_duration_seconds' => $this->result['final_payload_usage_duration_seconds'],
            'was_hours_converted' => $this->result['was_hours_converted'],
            'warnings_count' => count($this->result['warnings']),
        ]);

        return $this->result;
    }

    /**
     * Normalize start_on_first_use strategy.
     *
     * Accepts usage_duration in seconds. Can optionally auto-detect if the value might be hours
     * and convert accordingly (disabled by default since API spec states seconds).
     *
     * Conversion: days = ceil(seconds / 86400), minimum 1 day
     */
    protected function normalizeStartOnFirstUse(array $input): void
    {
        // Accept both 'usage_duration_seconds' (new) and 'usage_duration' (legacy)
        $usageDuration = $input['usage_duration_seconds'] ?? $input['usage_duration'] ?? 0;
        $usageDuration = (int) $usageDuration;

        $this->result['original_usage_duration_seconds'] = $usageDuration;

        if ($usageDuration <= 0) {
            $this->result['warnings'][] = 'usage_duration must be greater than 0 for start_on_first_use strategy';
            $this->result['normalized_usage_days'] = 1; // Minimum fallback

            return;
        }

        // Optional: Auto-detect if value might be hours instead of seconds
        // This is disabled by default since the API spec clearly states seconds
        // Only enable if backward compatibility with hour-based bots is needed
        if (self::AUTO_DETECT_HOURS && $usageDuration < self::HOUR_DETECTION_THRESHOLD && ($usageDuration % self::SECONDS_PER_HOUR) === 0) {
            $detectedHours = $usageDuration / self::SECONDS_PER_HOUR;
            $convertedSeconds = $usageDuration * self::SECONDS_PER_HOUR;

            Log::info('ExpiryNormalizer: Auto-detected hours input, converting to seconds', [
                'original_value' => $usageDuration,
                'detected_hours' => $detectedHours,
                'converted_seconds' => $convertedSeconds,
            ]);

            $usageDuration = $convertedSeconds;
            $this->result['was_hours_converted'] = true;
            $this->result['warnings'][] = "Value {$this->result['original_usage_duration_seconds']} was detected as hours and converted to {$convertedSeconds} seconds";
        }

        // Convert seconds to days using ceiling (round up)
        $days = (int) ceil($usageDuration / self::SECONDS_PER_DAY);

        // Ensure minimum of 1 day
        $days = max(1, $days);

        // Check for unrealistic durations (> 10 years)
        if ($days > self::MAX_DURATION_DAYS) {
            $this->result['warnings'][] = "Duration {$days} days exceeds maximum (".self::MAX_DURATION_DAYS." days), capping to maximum";
            $days = self::MAX_DURATION_DAYS;
        }

        $this->result['normalized_usage_days'] = $days;
        $this->result['final_payload_usage_duration_seconds'] = $days * self::SECONDS_PER_DAY;
    }

    /**
     * Normalize fixed_date strategy.
     *
     * Accepts either expire_date (ISO-8601) or expire (unix timestamp).
     * Does NOT convert the date to days - keeps it as-is.
     */
    protected function normalizeFixedDate(array $input): void
    {
        if (isset($input['expire_date']) && ! empty($input['expire_date'])) {
            try {
                $this->result['fixed_date'] = \Carbon\Carbon::parse($input['expire_date']);
            } catch (\Exception $e) {
                $this->result['warnings'][] = "Invalid expire_date format: {$input['expire_date']}";
            }
        } elseif (isset($input['expire']) && ! empty($input['expire'])) {
            $timestamp = (int) $input['expire'];
            if ($timestamp > 0) {
                $this->result['fixed_date'] = \Carbon\Carbon::createFromTimestamp($timestamp);
            } else {
                $this->result['warnings'][] = "Invalid expire timestamp: {$input['expire']}";
            }
        } else {
            // Default to 30 days if no expire specified
            $this->result['fixed_date'] = now()->addDays(30);
            $this->result['warnings'][] = 'No expire_date or expire provided, defaulting to 30 days';
        }
    }

    /**
     * Normalize never strategy.
     *
     * Sets a far-future date (10 years) for panels that don't support "never" natively.
     */
    protected function normalizeNever(): void
    {
        // 10 years in the future - consistent far-future date
        $this->result['fixed_date'] = now()->addYears(10);
        $this->result['normalized_usage_days'] = self::MAX_DURATION_DAYS;
    }

    /**
     * Get the maximum allowed duration in days.
     */
    public function getMaxDurationDays(): int
    {
        return self::MAX_DURATION_DAYS;
    }

    /**
     * Validate usage_duration input and return validation result.
     *
     * @param  mixed  $value  The usage_duration value to validate
     * @return array{valid: bool, message: string|null, converted_value: int|null}
     */
    public static function validateUsageDuration($value): array
    {
        if ($value === null || $value === '') {
            return [
                'valid' => false,
                'message' => 'usage_duration is required',
                'converted_value' => null,
            ];
        }

        if (! is_numeric($value)) {
            return [
                'valid' => false,
                'message' => 'usage_duration must be a numeric value',
                'converted_value' => null,
            ];
        }

        $intValue = (int) $value;

        if ($intValue <= 0) {
            return [
                'valid' => false,
                'message' => 'usage_duration must be greater than 0',
                'converted_value' => null,
            ];
        }

        return [
            'valid' => true,
            'message' => null,
            'converted_value' => $intValue,
        ];
    }

    /**
     * Convert seconds to days using ceiling (round up to nearest full day).
     * This is a static helper for use in other parts of the application.
     *
     * @param  int  $seconds  Duration in seconds
     * @return int Duration in days (minimum 1 if seconds > 0)
     */
    public static function secondsToDays(int $seconds): int
    {
        if ($seconds <= 0) {
            return 0;
        }

        $days = (int) ceil($seconds / self::SECONDS_PER_DAY);

        return max(1, $days);
    }

    /**
     * Prepare data for panel-specific API calls.
     *
     * @param  string  $panelType  One of: 'marzneshin', 'marzban', 'eylandoo'
     * @param  array  $normalizedResult  Result from normalize()
     * @return array Panel-specific payload fields for the expiry
     */
    public static function prepareForPanel(string $panelType, array $normalizedResult): array
    {
        $payload = [];
        $expireStrategy = $normalizedResult['expire_strategy'];

        switch ($expireStrategy) {
            case 'start_on_first_use':
                $payload['expire_strategy'] = 'start_on_first_use';

                // Marzneshin expects usage_duration in DAYS
                // The panel itself stores and displays days
                if ($panelType === 'marzneshin') {
                    $payload['usage_duration'] = $normalizedResult['normalized_usage_days'];
                } elseif ($panelType === 'marzban') {
                    // Marzban may expect seconds - use the back-converted seconds
                    $payload['usage_duration'] = $normalizedResult['final_payload_usage_duration_seconds'];
                } elseif ($panelType === 'eylandoo') {
                    // Eylandoo typically uses fixed_date, translate to that
                    $payload['activation_type'] = 'fixed_date';
                    if ($normalizedResult['normalized_usage_days']) {
                        $payload['expiry_date_str'] = now()->addDays($normalizedResult['normalized_usage_days'])->format('Y-m-d');
                    }
                }
                break;

            case 'never':
                if ($panelType === 'marzneshin') {
                    $payload['expire_strategy'] = 'never';
                } elseif ($panelType === 'marzban') {
                    $payload['expire_strategy'] = 'never';
                } elseif ($panelType === 'eylandoo') {
                    // Eylandoo doesn't support "never", use far-future date
                    $payload['activation_type'] = 'fixed_date';
                    $payload['expiry_date_str'] = now()->addYears(10)->format('Y-m-d');
                }
                break;

            case 'fixed_date':
            default:
                if ($panelType === 'marzneshin') {
                    $payload['expire_strategy'] = 'fixed_date';
                    if ($normalizedResult['fixed_date']) {
                        $payload['expire_date'] = $normalizedResult['fixed_date']->toIso8601String();
                    }
                } elseif ($panelType === 'marzban') {
                    if ($normalizedResult['fixed_date']) {
                        $payload['expire'] = $normalizedResult['fixed_date']->timestamp;
                    }
                } elseif ($panelType === 'eylandoo') {
                    $payload['activation_type'] = 'fixed_date';
                    if ($normalizedResult['fixed_date']) {
                        $payload['expiry_date_str'] = $normalizedResult['fixed_date']->format('Y-m-d');
                    }
                }
                break;
        }

        return $payload;
    }
}
