<?php

namespace App\Jobs;

use App\Models\Reseller;
use App\Models\ResellerConfig;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ReenableResellerConfigsJob implements ShouldQueue
{
    use Queueable;

    protected Reseller $reseller;
    protected string $suspensionReason;

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

        try {
            switch ($panelType) {
                case 'eylandoo':
                    $provisioner = $this->getEylandooProvisioner($panel);
                    return $provisioner->enableUser($config->panel_user_id ?? $config->external_username);

                case 'marzneshin':
                    $provisioner = $this->getMarzneshinProvisioner($panel);
                    return $provisioner->enableUser($config->panel_user_id);

                case 'marzban':
                    $provisioner = $this->getMarzbanProvisioner($panel);
                    return $provisioner->enableUser($config->panel_user_id);

                case 'xui':
                case '3x-ui':
                    $provisioner = $this->getXUIProvisioner($panel);
                    return $provisioner->enableUser($config->panel_user_id);

                default:
                    Log::warning('Unknown panel type for re-enable', [
                        'panel_type' => $panelType,
                        'panel_id' => $panel->id,
                    ]);
                    return false;
            }
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

    /**
     * Get Eylandoo provisioner instance
     */
    protected function getEylandooProvisioner($panel)
    {
        $credentials = $panel->getCredentials();
        
        return new \App\Provisioners\EylandooProvisioner(
            $credentials['url'],
            $credentials['api_token'],
            $credentials['extra']['node_hostname'] ?? ''
        );
    }

    /**
     * Get Marzneshin provisioner instance
     */
    protected function getMarzneshinProvisioner($panel)
    {
        $credentials = $panel->getCredentials();
        
        return new \App\Provisioners\MarzneshinProvisioner(
            $credentials['url'],
            $credentials['username'],
            $credentials['password'],
            $credentials['extra']['node_hostname'] ?? ''
        );
    }

    /**
     * Get Marzban provisioner instance
     */
    protected function getMarzbanProvisioner($panel)
    {
        $credentials = $panel->getCredentials();
        
        return new \App\Provisioners\MarzbanProvisioner(
            $credentials['url'],
            $credentials['username'],
            $credentials['password']
        );
    }

    /**
     * Get XUI provisioner instance
     */
    protected function getXUIProvisioner($panel)
    {
        $credentials = $panel->getCredentials();
        
        return new \App\Provisioners\XUIProvisioner(
            $credentials['url'],
            $credentials['username'],
            $credentials['password']
        );
    }
}
