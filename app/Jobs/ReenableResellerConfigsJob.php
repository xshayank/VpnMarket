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

        $panel = $this->reseller->primaryPanel ?? $this->reseller->panel;
        $panelType = $panel ? strtolower(trim($panel->panel_type ?? '')) : null;

        $successCount = 0;
        $failCount = 0;

        foreach ($configs as $config) {
            try {
                // For Eylandoo panels, attempt remote enable first (remote-first gating)
                if ($panelType === 'eylandoo' && $panel) {
                    try {
                        $provisioner = $this->getEylandooProvisioner($panel);
                        $remoteEnabled = $provisioner->enableUser($config->panel_user_id ?? $config->username);

                        Log::info('eylandoo_remote_enable_' . ($remoteEnabled ? 'success' : 'failed'), [
                            'reseller_id' => $this->reseller->id,
                            'config_id' => $config->id,
                            'panel_user_id' => $config->panel_user_id,
                        ]);

                        if (!$remoteEnabled) {
                            throw new \Exception('Remote enable failed for Eylandoo config');
                        }
                    } catch (\Exception $e) {
                        Log::error('eylandoo_remote_enable_failed', [
                            'reseller_id' => $this->reseller->id,
                            'config_id' => $config->id,
                            'error' => $e->getMessage(),
                        ]);
                        $failCount++;
                        continue; // Don't update local state if remote enable failed
                    }
                }

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
                    'panel_type' => $panelType,
                ]);

                $successCount++;
            } catch (\Exception $e) {
                Log::error('config_reenable_failed', [
                    'reseller_id' => $this->reseller->id,
                    'config_id' => $config->id,
                    'error' => $e->getMessage(),
                ]);
                $failCount++;
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
}
