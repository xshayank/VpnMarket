<?php

namespace App\Services;

use App\Models\ResellerConfig;
use Illuminate\Support\Facades\Log;

/**
 * Marzneshin API-style username generator
 *
 * This generator creates unique usernames specifically for configs created
 * via the Marzneshin-style API adapter. It produces shorter, cleaner usernames
 * with a clean prefix model.
 *
 * Format: <sanitized_prefix><short_suffix>
 * Example: "ali" becomes "alixy7k" (no underscore, more compact)
 *
 * This only affects users/configs created via the Marzneshin-style API,
 * not those created manually or by other adapters.
 */
class MarzneshinUsernameGenerator
{
    /**
     * Configuration values specific to Marzneshin API style
     */
    protected int $prefixMaxLen;

    protected int $suffixLen;

    protected string $allowedCharsRegex;

    protected string $fallbackPrefix;

    protected int $maxTotalLen;

    protected int $collisionRetryLimit;

    public function __construct()
    {
        // Marzneshin-specific config with defaults optimized for shorter usernames
        $this->prefixMaxLen = (int) config('marzneshin_username.prefix_max_len', 8);
        $this->suffixLen = (int) config('marzneshin_username.suffix_len', 4);
        $this->allowedCharsRegex = config('marzneshin_username.allowed_chars_regex', '/[^a-zA-Z0-9]/');
        $this->fallbackPrefix = config('marzneshin_username.fallback_prefix', 'mz');
        $this->maxTotalLen = (int) config('marzneshin_username.max_total_len', 14);
        $this->collisionRetryLimit = (int) config('marzneshin_username.collision_retry_limit', 5);
    }

    /**
     * Sanitize a raw username prefix to conform to allowed characters and length
     *
     * @param  string  $raw  The original requested username
     * @return string The sanitized prefix
     */
    public function sanitizePrefix(string $raw): string
    {
        // Strip non-allowed characters (keep only alphanumeric)
        $sanitized = preg_replace($this->allowedCharsRegex, '', $raw);

        // Trim to max length
        $sanitized = substr($sanitized, 0, $this->prefixMaxLen);

        // If empty after sanitization, use fallback
        if (empty($sanitized)) {
            $sanitized = $this->fallbackPrefix;
        }

        return strtolower($sanitized);
    }

    /**
     * Generate a short random suffix using base36 characters
     *
     * @return string A random suffix of configured length
     */
    protected function generateSuffix(): string
    {
        // Use alphanumeric characters for suffix (more compact than underscore-separated)
        $chars = '0123456789abcdefghijklmnopqrstuvwxyz';
        $suffix = '';

        for ($i = 0; $i < $this->suffixLen; $i++) {
            $suffix .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $suffix;
    }

    /**
     * Generate a unique panel username from a requested username prefix
     *
     * Format: <sanitized_prefix><shortSuffix> (no underscore separator for compactness)
     *
     * @param  string  $requestedUsername  The original username requested by user/bot
     * @param  callable|null  $existsChecker  Optional callback to check if username exists
     * @return array ['panel_username' => string, 'prefix' => string, 'original_username' => string]
     *
     * @throws \RuntimeException If unable to generate unique username after max retries
     */
    public function generate(string $requestedUsername, ?callable $existsChecker = null): array
    {
        $originalUsername = $requestedUsername;
        $sanitizedPrefix = $this->sanitizePrefix($requestedUsername);

        // Calculate max prefix length to ensure total stays within limit
        // Total = prefix + suffix (no underscore in Marzneshin format)
        $maxPrefixForTotal = $this->maxTotalLen - $this->suffixLen;
        if (strlen($sanitizedPrefix) > $maxPrefixForTotal) {
            $sanitizedPrefix = substr($sanitizedPrefix, 0, $maxPrefixForTotal);
        }

        // If no exists checker provided, create default database checker
        if ($existsChecker === null) {
            $existsChecker = $this->createDatabaseExistsChecker();
        }

        // Try to generate a unique username
        for ($attempt = 1; $attempt <= $this->collisionRetryLimit; $attempt++) {
            $suffix = $this->generateSuffix();
            $panelUsername = "{$sanitizedPrefix}{$suffix}";

            // Check if username already exists
            if (! $existsChecker($panelUsername)) {
                Log::info('marzneshin_username_generated', [
                    'original' => $originalUsername,
                    'prefix' => $sanitizedPrefix,
                    'panel_username' => $panelUsername,
                    'attempt' => $attempt,
                ]);

                return [
                    'panel_username' => $panelUsername,
                    'prefix' => $sanitizedPrefix,
                    'original_username' => $originalUsername,
                ];
            }

            Log::warning('marzneshin_username_collision', [
                'panel_username' => $panelUsername,
                'attempt' => $attempt,
            ]);
        }

        // All random suffix attempts failed, fall back to numeric increment
        return $this->generateWithNumericFallback($sanitizedPrefix, $originalUsername, $existsChecker);
    }

    /**
     * Generate username with numeric increment as fallback
     *
     * @param  string  $sanitizedPrefix  The sanitized prefix
     * @param  string  $originalUsername  The original username
     * @param  callable  $existsChecker  Callback to check existence
     * @return array
     *
     * @throws \RuntimeException If unable to generate unique username
     */
    protected function generateWithNumericFallback(string $sanitizedPrefix, string $originalUsername, callable $existsChecker): array
    {
        // Calculate the maximum numeric suffix based on available space
        // Available space = maxTotalLen - prefix length
        $availableSpace = $this->maxTotalLen - strlen($sanitizedPrefix);
        $maxNumericSuffix = min(9999, (int) pow(10, $availableSpace) - 1);
        
        // Try numeric suffixes starting from 1
        for ($num = 1; $num <= $maxNumericSuffix; $num++) {
            $numSuffix = str_pad((string) $num, min(4, $availableSpace), '0', STR_PAD_LEFT);
            $panelUsername = "{$sanitizedPrefix}{$numSuffix}";

            // Ensure total length doesn't exceed limit
            if (strlen($panelUsername) > $this->maxTotalLen) {
                break;
            }

            if (! $existsChecker($panelUsername)) {
                Log::info('marzneshin_username_generated_numeric_fallback', [
                    'original' => $originalUsername,
                    'prefix' => $sanitizedPrefix,
                    'panel_username' => $panelUsername,
                    'numeric_suffix' => $numSuffix,
                ]);

                return [
                    'panel_username' => $panelUsername,
                    'prefix' => $sanitizedPrefix,
                    'original_username' => $originalUsername,
                ];
            }
        }

        // This should be extremely rare
        Log::error('marzneshin_username_generation_failed_all_attempts', [
            'original' => $originalUsername,
            'prefix' => $sanitizedPrefix,
        ]);

        throw new \RuntimeException("Failed to generate unique panel username for prefix: {$sanitizedPrefix}");
    }

    /**
     * Check if a panel username exists in the local database
     *
     * @param  string  $panelUsername  The username to check
     * @return bool True if exists, false otherwise
     */
    public function existsInDatabase(string $panelUsername): bool
    {
        return ResellerConfig::where('panel_username', $panelUsername)
            ->orWhere('external_username', $panelUsername)
            ->exists();
    }

    /**
     * Create an exists checker callback that checks the local database
     *
     * @return callable
     */
    public function createDatabaseExistsChecker(): callable
    {
        return function (string $panelUsername): bool {
            return $this->existsInDatabase($panelUsername);
        };
    }

    /**
     * Extract the prefix from a Marzneshin-style panel username
     *
     * Since Marzneshin format doesn't use underscore, we need to work backwards
     * from the suffix length to find the prefix boundary.
     *
     * Note: This method assumes the username follows the generated format.
     * For usernames not generated by this service, results may be inaccurate.
     * Use isGeneratedUsername() to validate before calling this method.
     *
     * @param  string  $panelUsername  The full panel username
     * @return string The extracted prefix
     */
    public function extractPrefix(string $panelUsername): string
    {
        // If username is shorter than or equal to suffix length, return as-is
        // This handles edge cases where the username doesn't match our format
        if (strlen($panelUsername) <= $this->suffixLen) {
            return $panelUsername;
        }

        // Take everything except the last suffixLen characters
        return substr($panelUsername, 0, -$this->suffixLen);
    }

    /**
     * Check if a username appears to be generated by this service
     *
     * This performs a heuristic check based on length and character patterns.
     * It cannot guarantee the username was generated by this service.
     *
     * @param  string  $username  The username to check
     * @return bool True if the username appears to match the generated format
     */
    public function isGeneratedUsername(string $username): bool
    {
        // Must be all lowercase alphanumeric
        if (! preg_match('/^[a-z0-9]+$/', $username)) {
            return false;
        }

        // Must be at least suffix length + 1 char for prefix
        if (strlen($username) <= $this->suffixLen) {
            return false;
        }

        // Must not exceed max total length
        if (strlen($username) > $this->maxTotalLen) {
            return false;
        }

        return true;
    }

    /**
     * Get the configured prefix max length
     */
    public function getPrefixMaxLen(): int
    {
        return $this->prefixMaxLen;
    }

    /**
     * Get the configured suffix length
     */
    public function getSuffixLen(): int
    {
        return $this->suffixLen;
    }

    /**
     * Get the configured max total length
     */
    public function getMaxTotalLen(): int
    {
        return $this->maxTotalLen;
    }
}
