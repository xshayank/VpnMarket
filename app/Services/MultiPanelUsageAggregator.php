<?php

namespace App\Services;

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\ResellerPanelUsageSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MultiPanelUsageAggregator
{
    /**
     * Aggregate usage across all panels for a reseller
     *
     * @param Reseller $reseller
     * @return array ['total_usage_bytes' => int, 'remaining_bytes' => int, 'panels_processed' => int]
     */
    public function aggregateUsage(Reseller $reseller): array
    {
        if (!config('multi_panel.usage_enabled', true)) {
            Log::debug('Multi-panel usage aggregation disabled, using legacy single-panel logic', [
                'reseller_id' => $reseller->id,
            ]);
            return $this->legacySinglePanelAggregation($reseller);
        }

        Log::info('multi_panel_usage_start', [
            'reseller_id' => $reseller->id,
            'panel_ids' => $reseller->panels->pluck('id')->toArray(),
        ]);

        $totalUsageBytes = 0;
        $panelsProcessed = 0;

        // Get all panels assigned to this reseller
        $panels = $reseller->panels;

        // Fallback to primary panel if no panels assigned (backward compatibility)
        if ($panels->isEmpty() && $reseller->primary_panel_id) {
            $primaryPanel = Panel::find($reseller->primary_panel_id);
            if ($primaryPanel) {
                $panels = collect([$primaryPanel]);
            }
        }

        // Process each panel
        foreach ($panels as $panel) {
            try {
                $panelUsage = $this->fetchPanelUsage($reseller, $panel);
                $totalUsageBytes += $panelUsage['total_usage_bytes'];

                // Update snapshot
                $this->updateSnapshot($reseller, $panel, $panelUsage);

                // Only increment counter after successful processing
                $panelsProcessed++;

                Log::info('panel_usage_fetched', [
                    'reseller_id' => $reseller->id,
                    'panel_id' => $panel->id,
                    'panel_name' => $panel->name,
                    'total_usage_bytes' => $panelUsage['total_usage_bytes'],
                    'config_count' => $panelUsage['config_count'],
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to fetch usage for panel', [
                    'reseller_id' => $reseller->id,
                    'panel_id' => $panel->id,
                    'panel_name' => $panel->name,
                    'error' => $e->getMessage(),
                ]);
                // Continue processing other panels
            }
        }

        // Calculate remaining bytes for traffic-based resellers
        $remainingBytes = 0;
        if ($reseller->isTrafficBased()) {
            $remainingBytes = max(0, $reseller->traffic_total_bytes - $totalUsageBytes);
        }

        Log::info('multi_panel_usage_aggregate', [
            'reseller_id' => $reseller->id,
            'total_usage_bytes' => $totalUsageBytes,
            'remaining_bytes' => $remainingBytes,
            'panels_processed' => $panelsProcessed,
        ]);

        return [
            'total_usage_bytes' => $totalUsageBytes,
            'remaining_bytes' => $remainingBytes,
            'panels_processed' => $panelsProcessed,
        ];
    }

    /**
     * Fetch usage for all configs on a specific panel for a reseller
     *
     * @param Reseller $reseller
     * @param Panel $panel
     * @return array ['total_usage_bytes' => int, 'config_count' => int, 'configs' => array]
     */
    protected function fetchPanelUsage(Reseller $reseller, Panel $panel): array
    {
        $configs = ResellerConfig::where('reseller_id', $reseller->id)
            ->where('panel_id', $panel->id)
            ->whereIn('status', ['active', 'disabled']) // Include disabled to get full picture
            ->get();

        $totalUsageBytes = 0;
        $configsData = [];

        foreach ($configs as $config) {
            try {
                $usage = $this->fetchConfigUsageFromPanel($config, $panel);
                
                if ($usage !== null) {
                    // Update config usage
                    $config->update(['usage_bytes' => $usage]);

                    Log::info('config_usage_updated', [
                        'config_id' => $config->id,
                        'panel_id' => $panel->id,
                        'usage_bytes' => $usage,
                    ]);

                    $totalUsageBytes += $usage;
                    $configsData[] = [
                        'config_id' => $config->id,
                        'usage_bytes' => $usage,
                    ];
                }
            } catch (\Exception $e) {
                Log::warning('Failed to fetch usage for config', [
                    'config_id' => $config->id,
                    'panel_id' => $panel->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'total_usage_bytes' => $totalUsageBytes,
            'config_count' => $configs->count(),
            'configs' => $configsData,
        ];
    }

    /**
     * Fetch usage for a single config from its panel
     *
     * @param ResellerConfig $config
     * @param Panel $panel
     * @return int|null Usage in bytes or null if failed
     */
    protected function fetchConfigUsageFromPanel(ResellerConfig $config, Panel $panel): ?int
    {
        $credentials = $panel->getCredentials();
        $panelType = strtolower(trim($panel->panel_type ?? ''));

        try {
            switch ($panelType) {
                case 'marzneshin':
                    $service = new MarzneshinService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password'],
                        $credentials['extra']['node_hostname'] ?? ''
                    );
                    $userData = $service->getUser($config->panel_user_id);
                    return $userData['used_traffic'] ?? $userData['data_used'] ?? 0;

                case 'eylandoo':
                    $service = new EylandooService(
                        $credentials['url'],
                        $credentials['api_token'],
                        $credentials['extra']['node_hostname'] ?? ''
                    );
                    return $service->getUserUsageBytes($config->panel_user_id);

                case 'marzban':
                    $service = new MarzbanService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password']
                    );
                    $userData = $service->getUser($config->panel_user_id);
                    return $userData['used_traffic'] ?? 0;

                case 'xui':
                case '3x-ui':
                    $service = new XUIService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password']
                    );
                    $userData = $service->getUser($config->panel_user_id);
                    return $userData['up'] + $userData['down'];

                default:
                    Log::warning('Unknown panel type for usage fetch', [
                        'panel_type' => $panelType,
                        'panel_id' => $panel->id,
                    ]);
                    return null;
            }
        } catch (\Exception $e) {
            Log::error('Error fetching config usage from panel', [
                'config_id' => $config->id,
                'panel_id' => $panel->id,
                'panel_type' => $panelType,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Update or create usage snapshot for reseller-panel combination
     *
     * @param Reseller $reseller
     * @param Panel $panel
     * @param array $usageData
     * @return void
     */
    protected function updateSnapshot(Reseller $reseller, Panel $panel, array $usageData): void
    {
        ResellerPanelUsageSnapshot::updateOrCreate(
            [
                'reseller_id' => $reseller->id,
                'panel_id' => $panel->id,
            ],
            [
                'total_usage_bytes' => $usageData['total_usage_bytes'],
                'active_config_count' => $usageData['config_count'],
                'captured_at' => now(),
            ]
        );
    }

    /**
     * Legacy single-panel aggregation for backward compatibility
     *
     * @param Reseller $reseller
     * @return array
     */
    protected function legacySinglePanelAggregation(Reseller $reseller): array
    {
        // Sum usage from all configs (existing behavior)
        $totalUsageBytes = $reseller->configs()
            ->get()
            ->sum(function ($config) {
                return $config->usage_bytes + (int) data_get($config->meta, 'settled_usage_bytes', 0);
            });

        // Subtract admin forgiven bytes
        $adminForgivenBytes = $reseller->admin_forgiven_bytes ?? 0;
        $effectiveUsageBytes = max(0, $totalUsageBytes - $adminForgivenBytes);

        $remainingBytes = 0;
        if ($reseller->isTrafficBased()) {
            $remainingBytes = max(0, $reseller->traffic_total_bytes - $effectiveUsageBytes);
        }

        return [
            'total_usage_bytes' => $effectiveUsageBytes,
            'remaining_bytes' => $remainingBytes,
            'panels_processed' => 1,
        ];
    }

    /**
     * Update reseller's total usage bytes in a transaction
     *
     * @param Reseller $reseller
     * @param int $totalUsageBytes
     * @return void
     */
    public function updateResellerTotalUsage(Reseller $reseller, int $totalUsageBytes): void
    {
        DB::transaction(function () use ($reseller, $totalUsageBytes) {
            // Subtract admin forgiven bytes
            $adminForgivenBytes = $reseller->admin_forgiven_bytes ?? 0;
            $effectiveUsageBytes = max(0, $totalUsageBytes - $adminForgivenBytes);

            $reseller->update(['traffic_used_bytes' => $effectiveUsageBytes]);

            Log::debug('Reseller total usage updated', [
                'reseller_id' => $reseller->id,
                'total_usage_bytes' => $totalUsageBytes,
                'admin_forgiven_bytes' => $adminForgivenBytes,
                'effective_usage_bytes' => $effectiveUsageBytes,
            ]);
        });
    }
}
