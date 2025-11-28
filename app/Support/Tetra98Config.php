<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class Tetra98Config
{
    /**
     * Regex pattern for validating Iranian mobile phone numbers.
     * Must start with 09 and be exactly 11 digits.
     */
    public const PHONE_REGEX = '/^09\d{9}$/';

    private const CACHE_KEY_ENABLED = 'tetra98.enabled';
    private const CACHE_KEY_API_KEY = 'tetra98.api_key';
    private const CACHE_KEY_BASE_URL = 'tetra98.base_url';
    private const CACHE_KEY_CALLBACK_PATH = 'tetra98.callback_path';
    private const CACHE_KEY_DEFAULT_PHONE = 'tetra98.default_phone';

    protected static function getSetting(string $key, $default = null)
    {
        if (! Schema::hasTable('settings')) {
            return $default;
        }

        return Setting::getValue($key, $default);
    }

    protected static function canUsePersistentCache(): bool
    {
        return Cache::getDefaultDriver() !== 'database';
    }

    protected static function rememberForever(string $cacheKey, callable $callback)
    {
        if (! self::canUsePersistentCache()) {
            return $callback();
        }

        return Cache::rememberForever($cacheKey, $callback);
    }

    public static function isEnabled(): bool
    {
        return self::rememberForever(self::CACHE_KEY_ENABLED, function () {
            $setting = self::getSetting('payment.tetra98.enabled');

            if ($setting !== null) {
                return filter_var($setting, FILTER_VALIDATE_BOOLEAN);
            }

            return (bool) config('tetra98.enabled', false);
        });
    }

    public static function getApiKey(): ?string
    {
        return self::rememberForever(self::CACHE_KEY_API_KEY, function () {
            $value = self::getSetting('payment.tetra98.api_key');

            if ($value !== null && $value !== '') {
                return $value;
            }

            return config('tetra98.api_key');
        });
    }

    public static function hasApiKey(): bool
    {
        $apiKey = self::getApiKey();

        return ! empty($apiKey);
    }

    public static function isAvailable(): bool
    {
        return self::isEnabled() && self::hasApiKey();
    }

    public static function getBaseUrl(): string
    {
        return self::rememberForever(self::CACHE_KEY_BASE_URL, function () {
            $value = self::getSetting('payment.tetra98.base_url');

            if ($value) {
                return $value;
            }

            return config('tetra98.base_url', 'https://tetra98.ir');
        });
    }

    public static function getCallbackPath(): string
    {
        return self::rememberForever(self::CACHE_KEY_CALLBACK_PATH, function () {
            $value = self::getSetting('payment.tetra98.callback_path');

            if ($value) {
                return $value;
            }

            return config('tetra98.callback_path', '/webhooks/tetra98/callback');
        });
    }

    public static function getDefaultDescription(): string
    {
        $fromSettings = self::getSetting('payment.tetra98.description');

        if ($fromSettings) {
            return $fromSettings;
        }

        return config('tetra98.default_description', 'شارژ کیف پول از طریق Tetra98');
    }

    public static function getMinAmountToman(): int
    {
        $value = self::getSetting('payment.tetra98.min_amount');

        if ($value !== null && is_numeric($value)) {
            return max(1000, (int) $value);
        }

        return (int) config('tetra98.min_amount_toman', 10000);
    }

    public static function getDefaultPhone(): ?string
    {
        return self::rememberForever(self::CACHE_KEY_DEFAULT_PHONE, function () {
            $value = self::getSetting('payment.tetra98.default_phone');

            if ($value !== null && $value !== '') {
                return $value;
            }

            return null;
        });
    }

    public static function clearCache(): void
    {
        if (! self::canUsePersistentCache()) {
            return;
        }

        Cache::forget(self::CACHE_KEY_ENABLED);
        Cache::forget(self::CACHE_KEY_API_KEY);
        Cache::forget(self::CACHE_KEY_BASE_URL);
        Cache::forget(self::CACHE_KEY_CALLBACK_PATH);
        Cache::forget(self::CACHE_KEY_DEFAULT_PHONE);
    }
}
