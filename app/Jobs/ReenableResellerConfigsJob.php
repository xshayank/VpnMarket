<?php

namespace App\Jobs;

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ReenableResellerConfigsJob implements ShouldQueue
{
    use Queueable;

    public Reseller $reseller;
    public string $suspensionReason;

    /**
     * Create a new job instance.
     */
    public function __construct(Reseller $reseller, string $suspensionReason)
    {
        $this->reseller = $reseller;
        $this->suspensionReason = $suspensionReason; // 'wallet' or 'traffic'
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('config_reenable_job_started', [
            'reseller_id' => $this->reseller->id,
            'user_id' => $this->reseller->user_id,
            'suspension_reason' => $this->suspensionReason,
        ]);

        // Check if auto re-enable is enabled
        if (!config('billing.wallet.auto_reenable_enabled', true)) {
            Log::info('wallet_reenable_skipped', [
                'reseller_id' => $this->reseller->id,
                'reason' => 'auto_reenable_disabled',
                'suspension_reason' => $this->suspensionReason,
            ]);
            return;
        }

        // Guard clause for wallet suspensions: ensure reseller is active and balance is above threshold
        if ($this->suspensionReason === 'wallet') {
            // Use first_topup threshold for consistency with activation logic
            $reactivationThreshold = config('billing.reseller.first_topup.wallet_min', 150000);
            
            if ($this->reseller->status !== 'active') {
                Log::info('wallet_reenable_skipped', [
                    'reseller_id' => $this->reseller->id,
                    'reason' => 'reseller_not_active',
                    'status' => $this->reseller->status,
                    'balance' => $this->reseller->wallet_balance,
                    'threshold' => $reactivationThreshold,
                ]);
                return;
            }

            if ($this->reseller->wallet_balance < $reactivationThreshold) {
                Log::info('wallet_reenable_skipped', [
                    'reseller_id' => $this->reseller->id,
                    'reason' => 'balance_below_threshold',
                    'status' => $this->reseller->status,
                    'balance' => $this->reseller->wallet_balance,
                    'threshold' => $reactivationThreshold,
                ]);
                return;
            }
        }

        $metaKey = $this->suspensionReason === 'wallet' 
            ? 'disabled_by_wallet_suspension' 
            : 'disabled_by_traffic_suspension';

        // Get all configs that were disabled due to this suspension
        $configs = ResellerConfig::where('reseller_id', $this->reseller->id)
            ->where('status', 'disabled')
            ->get()
            ->filter(function ($config) use ($metaKey) {
                $meta = $config->meta ?? [];
                return isset($meta[$metaKey]) && $meta[$metaKey] === true;
            });

        if ($configs->isEmpty()) {
            Log::info('config_reenable_job_no_configs', [
                'reseller_id' => $this->reseller->id,
                'suspension_reason' => $this->suspensionReason,
            ]);
            return;
        }

        Log::info('config_reenable_job_processing', [
            'reseller_id' => $this->reseller->id,
            'config_count' => $configs->count(),
            'suspension_reason' => $this->suspensionReason,
        ]);

        // Group configs by panel for efficient processing
        $configsByPanel = $configs->groupBy('panel_id');

        $successCount = 0;
        $failCount = 0;

        foreach ($configsByPanel as $panelId => $panelConfigs) {
            $panel = Panel::find($panelId);
            
            if (!$panel) {
                Log::warning('Panel not found for configs', [
                    'panel_id' => $panelId,
                    'config_count' => $panelConfigs->count(),
                ]);
                $failCount += $panelConfigs->count();
                continue;
            }

            $panelType = strtolower(trim($panel->panel_type ?? ''));

            Log::info('reenable_panel_batch_start', [
                'reseller_id' => $this->reseller->id,
                'panel_id' => $panel->id,
                'panel_type' => $panelType,
                'config_count' => $panelConfigs->count(),
            ]);

            foreach ($panelConfigs as $config) {
                try {
                    // For all panels, attempt remote enable first (remote-first gating)
                    $remoteEnabled = $this->enableConfigOnPanel($config, $panel);

                    if ($remoteEnabled) {
                        // Update local config status
                        $config->status = 'active';
                        
                        // Clear suspension metadata
                        $meta = $config->meta ?? [];
                        unset($meta[$metaKey]);
                        $config->meta = $meta;
                        
                        $config->save();

                        Log::info('config_reenable_success', [
                            'reseller_id' => $this->reseller->id,
                            'config_id' => $config->id,
                            'panel_id' => $panel->id,
                            'panel_type' => $panelType,
                        ]);

                        $successCount++;
                    } else {
                        Log::warning('config_reenable_remote_failed', [
                            'reseller_id' => $this->reseller->id,
                            'config_id' => $config->id,
                            'panel_id' => $panel->id,
                            'panel_type' => $panelType,
                        ]);
                        $failCount++;
                    }
                } catch (\Exception $e) {
                    Log::error('config_reenable_failed', [
                        'reseller_id' => $this->reseller->id,
                        'config_id' => $config->id,
                        'panel_id' => $panel->id,
                        'error' => $e->getMessage(),
                    ]);
                    $failCount++;
                }
            }
        }

        Log::info('config_reenable_job_result', [
            'reseller_id' => $this->reseller->id,
            'total_configs' => $configs->count(),
            'success' => $successCount,
            'failed' => $failCount,
        ]);
    }

    /**
     * Enable a config on its remote panel
     *
     * @param ResellerConfig $config
     * @param Panel $panel
     * @return bool True if enabled successfully
     */
    protected function enableConfigOnPanel(ResellerConfig $config, Panel $panel): bool
    {
        $panelType = strtolower(trim($panel->panel_type ?? ''));
        $credentials = $panel->getCredentials();

        try {
            // Use ResellerProvisioner's enableUser method
            $provisioner = new \Modules\Reseller\Services\ResellerProvisioner();
            $result = $provisioner->enableUser(
                $panel->panel_type,
                $credentials,
                $config->panel_user_id
            );

            return $result['success'] ?? false;
        } catch (\Exception $e) {
            Log::error('Exception enabling config on panel', [
                'config_id' => $config->id,
                'panel_id' => $panel->id,
                'panel_type' => $panelType,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
