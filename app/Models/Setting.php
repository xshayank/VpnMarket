<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'key',
        'value',
    ];

    private const CACHE_KEY = 'settings.map';
    private const CACHE_TTL = 300; // 5 minutes

    public function inbounds()
    {
        return $this->hasMany(\App\Models\Inbound::class);
    }

    /**
     * Get a setting value by key (alias for getValue)
     */
    public static function get(string $key, $default = null)
    {
        return self::getValue($key, $default);
    }

    /**
     * Get all settings as a cached key/value collection
     */
    public static function getCachedMap()
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return self::all()->pluck('value', 'key');
        });
    }

    /**
     * Clear cached settings map
     */
    public static function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Get a setting value by key
     */
    public static function getValue(string $key, $default = null)
    {
        $cached = Cache::get(self::CACHE_KEY);
        if ($cached && $cached->has($key)) {
            return $cached->get($key, $default);
        }

        $setting = self::where('key', $key)->first();

        return $setting ? $setting->value : $default;
    }

    /**
     * Set a setting value by key
     */
    public static function setValue(string $key, $value): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value]);
        self::clearCache();
    }

    /**
     * Get a boolean setting value
     */
    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::getValue($key);
        if ($value === null) {
            return $default;
        }

        return $value === 'true' || $value === '1' || $value === true;
    }

    /**
     * Get an integer setting value
     */
    public static function getInt(string $key, int $default = 0): int
    {
        $value = self::getValue($key);

        return $value !== null ? (int) $value : $default;
    }
}
