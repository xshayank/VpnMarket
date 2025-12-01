<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

class RouteHelper
{
    /**
     * Safely generate route URL, returning null if route doesn't exist
     *
     * @param  string  $name  Route name
     * @param  array  $params  Route parameters
     * @return string|null Route URL or null if unavailable
     */
    public static function safeRoute(string $name, array $params = []): ?string
    {
        try {
            if (Route::has($name)) {
                return route($name, $params);
            }
        } catch (\Exception $e) {
            Log::debug('Route not available: '.$name, ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Check if a module is enabled safely
     *
     * @param  string  $moduleName  Module name to check
     * @return bool True if module is enabled, false otherwise
     */
    public static function moduleEnabled(string $moduleName): bool
    {
        try {
            return class_exists('\Nwidart\Modules\Facades\Module')
                && \Nwidart\Modules\Facades\Module::isEnabled($moduleName);
        } catch (\Exception $e) {
            return false;
        }
    }
}
