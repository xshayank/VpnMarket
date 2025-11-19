<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\ResellerConfigEvent;
use Illuminate\Support\Facades\Log;

class WalletResellerReenableService
{
    /**
     * Re-enable configs that were auto-disabled due to wallet suspension
     *
     * @param  Reseller  $reseller  The reseller whose configs should be re-enabled
     * @return array Statistics about the re-enable operation
     */
    public function reenableWalletSuspendedConfigs(Reseller $reseller): array
    {
        // Find configs that were auto-disabled by wallet suspension
        $configs = ResellerConfig::where('reseller_id', $reseller->id)
            ->where('status', 'disabled')
            ->where(function ($query) {
                // Match configs where disabled_by_wallet_suspension is truthy
                $query->whereRaw("JSON_EXTRACT(meta, '$.disabled_by_wallet_suspension') = TRUE")
                    ->orWhereRaw("JSON_EXTRACT(meta, '$.disabled_by_wallet_suspension') = '1'")
                    ->orWhereRaw("JSON_EXTRACT(meta, '$.disabled_by_wallet_suspension') = 1")
                    ->orWhereRaw("JSON_EXTRACT(meta, '$.disabled_by_wallet_suspension') = 'true'");
            })
            ->get();

        if ($configs->isEmpty()) {
            Log::info("No wallet-suspended configs to re-enable for reseller {$reseller->id}");

            return ['enabled' => 0, 'failed' => 0];
        }

        Log::info("Re-enabling {$configs->count()} wallet-suspended configs for reseller {$reseller->id}");

        $provisioner = new \Modules\Reseller\Services\ResellerProvisioner;
        $enabledCount = 0;
        $failedCount = 0;

        // Group configs by panel for efficient processing
        $configsByPanel = $configs->groupBy('panel_id');

        foreach ($configsByPanel as $panelId => $panelConfigs) {
            $panel = Panel::find($panelId);
            
            if (!$panel) {
                Log::warning('Panel not found for configs', [
                    'panel_id' => $panelId,
                    'config_count' => $panelConfigs->count(),
                ]);
                $failedCount += $panelConfigs->count();
                continue;
            }

            Log::info('reenable_panel_batch_start', [
                'reseller_id' => $reseller->id,
                'panel_id' => $panel->id,
                'panel_name' => $panel->name,
                'config_count' => $panelConfigs->count(),
            ]);

            foreach ($panelConfigs as $config) {
                try {
                    // Apply rate limiting
                    $provisioner->applyRateLimit($enabledCount);

                    // Enable on remote panel if possible (remote-first gating)
                    $remoteResult = $this->enableConfigOnPanel($config, $panel, $provisioner);

                    // Only update local status if remote enable succeeded
                    if ($remoteResult['success']) {
                        // Update local status and clear wallet suspension markers
                        $meta = $config->meta ?? [];
                        unset($meta['disabled_by_wallet_suspension']);
                        unset($meta['disabled_by_reseller_id']);
                        unset($meta['disabled_at']);

                        $config->update([
                            'status' => 'active',
                            'disabled_at' => null,
                            'meta' => $meta,
                        ]);

                        $enabledCount++;

                        Log::info('reenable_success', [
                            'action' => 'wallet_topup_reenable_success',
                            'config_id' => $config->id,
                            'reseller_id' => $reseller->id,
                            'panel_id' => $panel->id,
                            'panel_type' => $panel->panel_type ?? 'unknown',
                            'remote_success' => true,
                        ]);
                    } else {
                        // Remote enable failed - keep config disabled
                        $failedCount++;

                        Log::warning('reenable_failed', [
                            'action' => 'wallet_topup_reenable_remote_failed',
                            'config_id' => $config->id,
                            'reseller_id' => $reseller->id,
                            'panel_id' => $config->panel_id,
                            'panel_type' => $panel->panel_type ?? 'unknown',
                            'panel_user_id' => $config->panel_user_id,
                            'attempts' => $remoteResult['attempts'],
                            'last_error' => $remoteResult['last_error'],
                        ]);
                    }

                    // Log event regardless of success
                    ResellerConfigEvent::create([
                        'reseller_config_id' => $config->id,
                        'type' => $remoteResult['success'] ? 'auto_enabled' : 'auto_enable_failed',
                        'meta' => [
                            'reason' => 'wallet_recharged',
                            'remote_success' => $remoteResult['success'],
                            'attempts' => $remoteResult['attempts'],
                            'last_error' => $remoteResult['last_error'],
                        ],
                    ]);

                    // Create audit log
                    AuditLog::log(
                        action: $remoteResult['success'] ? 'config_auto_enabled' : 'config_auto_enable_failed',
                        targetType: 'config',
                        targetId: $config->id,
                        reason: 'wallet_recharged',
                        meta: [
                            'reseller_id' => $reseller->id,
                            'panel_id' => $panel->id,
                            'remote_success' => $remoteResult['success'],
                            'attempts' => $remoteResult['attempts'],
                            'last_error' => $remoteResult['last_error'],
                        ],
                        actorType: null,
                        actorId: null
                    );
                } catch (\Exception $e) {
                    Log::error("Exception enabling config {$config->id}: ".$e->getMessage());
                    $failedCount++;
                }
            }
        }

        Log::info("Wallet config re-enable completed for reseller {$reseller->id}: {$enabledCount} enabled, {$failedCount} failed");

        return ['enabled' => $enabledCount, 'failed' => $failedCount];
    }

    /**
     * Enable a config on its remote panel
     *
     * @param ResellerConfig $config
     * @param Panel $panel
     * @param \Modules\Reseller\Services\ResellerProvisioner $provisioner
     * @return array ['success' => bool, 'attempts' => int, 'last_error' => ?string]
     */
    protected function enableConfigOnPanel(ResellerConfig $config, Panel $panel, $provisioner): array
    {
        $credentials = $panel->getCredentials();
        $panelType = strtolower($panel->panel_type);

        // Validate credentials before attempting
        if ($panelType === 'eylandoo') {
            if (empty($credentials['url']) || empty($credentials['api_token'])) {
                Log::warning('reenable_attempt', [
                    'action' => 'wallet_topup_reenable_eylandoo_credentials_missing',
                    'config_id' => $config->id,
                    'panel_id' => $panel->id,
                    'panel_user_id' => $config->panel_user_id,
                ]);
                return ['success' => false, 'attempts' => 0, 'last_error' => 'Missing credentials (url or api_token)'];
            }
        } elseif (in_array($panelType, ['marzban', 'marzneshin', 'xui'])) {
            if (empty($credentials['url']) || empty($credentials['username']) || empty($credentials['password'])) {
                Log::warning('reenable_attempt', [
                    'action' => 'wallet_topup_reenable_credentials_missing',
                    'config_id' => $config->id,
                    'panel_id' => $panel->id,
                    'panel_type' => $panelType,
                ]);
                return ['success' => false, 'attempts' => 0, 'last_error' => 'Missing credentials'];
            }
        }

        Log::info('reenable_attempt', [
            'action' => 'wallet_topup_reenable_request',
            'config_id' => $config->id,
            'panel_id' => $panel->id,
            'panel_type' => $panelType,
            'panel_user_id' => $config->panel_user_id,
        ]);

        return $provisioner->enableUser(
            $panel->panel_type,
            $credentials,
            $config->panel_user_id
        );
    }
}
