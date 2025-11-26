<?php

namespace App\Services\Api;

use App\Models\Panel;
use App\Services\EylandooService;
use App\Services\MarzneshinService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Service to check and monitor panel health status
 */
class PanelHealthService
{
    /**
     * Cache key prefix for panel health status
     */
    protected const CACHE_PREFIX = 'panel_health:';

    /**
     * Default cache TTL in seconds (5 minutes)
     */
    protected const DEFAULT_CACHE_TTL = 300;

    /**
     * Get cache TTL from config or use default
     */
    protected function getCacheTtl(): int
    {
        return config('api.panel_health_cache_ttl', self::DEFAULT_CACHE_TTL);
    }

    /**
     * Check health status for a panel
     */
    public function checkHealth(Panel $panel): array
    {
        $cacheKey = self::CACHE_PREFIX . $panel->id;

        // Return cached result if available
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $result = $this->performHealthCheck($panel);

        // Cache the result
        Cache::put($cacheKey, $result, $this->getCacheTtl());

        return $result;
    }

    /**
     * Force refresh health status for a panel
     */
    public function refreshHealth(Panel $panel): array
    {
        $cacheKey = self::CACHE_PREFIX . $panel->id;
        Cache::forget($cacheKey);

        return $this->checkHealth($panel);
    }

    /**
     * Perform actual health check
     */
    protected function performHealthCheck(Panel $panel): array
    {
        $startTime = microtime(true);

        try {
            $credentials = $panel->getCredentials();
            $panelType = strtolower(trim($panel->panel_type ?? ''));

            $isHealthy = false;
            $details = [];

            switch ($panelType) {
                case 'marzneshin':
                    $result = $this->checkMarzneshinHealth($credentials);
                    $isHealthy = $result['healthy'];
                    $details = $result['details'];
                    break;

                case 'eylandoo':
                    $result = $this->checkEylandooHealth($credentials);
                    $isHealthy = $result['healthy'];
                    $details = $result['details'];
                    break;

                default:
                    $details['error'] = "Unsupported panel type: {$panelType}";
            }

            $responseTime = round((microtime(true) - $startTime) * 1000);

            return [
                'panel_id' => $panel->id,
                'panel_name' => $panel->name,
                'panel_type' => $panel->panel_type,
                'is_healthy' => $isHealthy,
                'response_time_ms' => $responseTime,
                'details' => $details,
                'checked_at' => now()->toIso8601String(),
            ];

        } catch (\Exception $e) {
            Log::error('Panel health check failed', [
                'panel_id' => $panel->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'panel_id' => $panel->id,
                'panel_name' => $panel->name,
                'panel_type' => $panel->panel_type,
                'is_healthy' => false,
                'response_time_ms' => round((microtime(true) - $startTime) * 1000),
                'details' => ['error' => $e->getMessage()],
                'checked_at' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Check Marzneshin panel health
     */
    protected function checkMarzneshinHealth(array $credentials): array
    {
        if (empty($credentials['url']) || empty($credentials['username']) || empty($credentials['password'])) {
            return [
                'healthy' => false,
                'details' => ['error' => 'Missing credentials'],
            ];
        }

        try {
            $service = new MarzneshinService(
                $credentials['url'],
                $credentials['username'],
                $credentials['password'],
                $credentials['extra']['node_hostname'] ?? ''
            );

            $loggedIn = $service->login();

            if (!$loggedIn) {
                return [
                    'healthy' => false,
                    'details' => ['error' => 'Authentication failed'],
                ];
            }

            // Try to list services to verify connectivity
            $services = $service->listServices();

            return [
                'healthy' => true,
                'details' => [
                    'services_count' => count($services),
                    'authenticated' => true,
                ],
            ];

        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'details' => ['error' => $e->getMessage()],
            ];
        }
    }

    /**
     * Check Eylandoo panel health
     */
    protected function checkEylandooHealth(array $credentials): array
    {
        if (empty($credentials['url']) || empty($credentials['api_token'])) {
            return [
                'healthy' => false,
                'details' => ['error' => 'Missing credentials'],
            ];
        }

        try {
            $service = new EylandooService(
                $credentials['url'],
                $credentials['api_token'],
                $credentials['extra']['node_hostname'] ?? ''
            );

            // Try to list nodes to verify connectivity
            $nodes = $service->listNodes();

            return [
                'healthy' => true,
                'details' => [
                    'nodes_count' => count($nodes),
                    'authenticated' => true,
                ],
            ];

        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'details' => ['error' => $e->getMessage()],
            ];
        }
    }

    /**
     * Get health status for all panels
     */
    public function getAllPanelsHealth(): array
    {
        $panels = Panel::where('is_active', true)->get();
        $results = [];

        foreach ($panels as $panel) {
            $results[] = $this->checkHealth($panel);
        }

        return [
            'panels' => $results,
            'summary' => [
                'total' => count($results),
                'healthy' => collect($results)->where('is_healthy', true)->count(),
                'unhealthy' => collect($results)->where('is_healthy', false)->count(),
            ],
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Clear cached health status for a panel
     */
    public function clearCache(Panel $panel): void
    {
        Cache::forget(self::CACHE_PREFIX . $panel->id);
    }

    /**
     * Clear all cached health statuses
     */
    public function clearAllCache(): void
    {
        $panels = Panel::all();
        foreach ($panels as $panel) {
            $this->clearCache($panel);
        }
    }
}
