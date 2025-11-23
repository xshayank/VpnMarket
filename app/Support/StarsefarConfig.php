<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;

class StarsefarConfig
{
    protected static function getSetting(string $key, $default = null)
    {
        if (! Schema::hasTable('settings')) {
            return $default;
        }

        return Setting::getValue($key, $default);
    }

    public static function isEnabled(): bool
    {
        $setting = self::getSetting('starsefar_enabled');
        if ($setting !== null) {
            return filter_var($setting, FILTER_VALIDATE_BOOLEAN);
        }

        return (bool) config('starsefar.enabled', false);
    }

    public static function getApiKey(): ?string
    {
        return self::getSetting('starsefar_api_key', config('starsefar.api_key'));
    }

    public static function getBaseUrl(): string
    {
        return self::getSetting('starsefar_base_url', config('starsefar.base_url'));
    }

    public static function getCallbackPath(): string
    {
        return self::getSetting('starsefar_callback_path', config('starsefar.callback_path', '/webhooks/Stars-Callback'));
    }

    public static function getDefaultTargetAccount(): ?string
    {
        $default = config('starsefar.default_target_account', '@xShayank');

        $value = self::getSetting('starsefar_default_target_account', $default);

        return $value ?: '@xShayank';
    }

    public static function getMinAmountToman(): int
    {
        $default = (int) config('starsefar.min_amount_toman', 25000);

        $value = self::getSetting('starsefar_min_amount_toman');

        if ($value !== null) {
            return (int) $value;
        }

        return $default;
    }

    public static function getTrustedHost(): string
    {
        $host = parse_url(self::getBaseUrl(), PHP_URL_HOST);

        return $host ?: 'starsefar.xyz';
    }
}
