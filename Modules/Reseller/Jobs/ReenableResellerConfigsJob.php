<?php

namespace Modules\Reseller\Jobs;

use App\Models\AuditLog;
use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\ResellerConfigEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Reseller\Services\ResellerProvisioner;

class ReenableResellerConfigsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public $timeout = 600;

    public ?int $resellerId = null;

    /**
     * Create a new job instance.
     *
     * @param  int|null  $resellerId  Specific reseller ID to process, or null to process all eligible
     */
    public function __construct(?int $resellerId = null)
    {
        $this->resellerId = $resellerId;
    }

    public function handle(ResellerProvisioner $provisioner): void
    {
        Log::info('Starting reseller config re-enable job', ['reseller_id' => $this->resellerId]);

        // Process both traffic-based and wallet-based resellers
        $this->processTrafficResellers($provisioner);
        $this->processWalletResellers();

        Log::info('Reseller config re-enable job completed');
    }

    /**
     * Process traffic-based resellers for re-enabling
     */
    protected function processTrafficResellers(ResellerProvisioner $provisioner): void
    {
        Log::info('Processing traffic-based resellers for re-enable');

        // If specific reseller ID provided, load that reseller directly
        // When called with a specific ID, we trust the caller's intent (e.g., admin activation)
        // and skip defensive checks - just verify reseller exists and is traffic-based
        if ($this->resellerId !== null) {
            Log::info('Processing specific reseller', ['reseller_id' => $this->resellerId]);

            $reseller = Reseller::where('id', $this->resellerId)
                ->where('type', 'traffic')
                ->first();

            if (! $reseller) {
                Log::info('Reseller not found or not traffic-based', ['reseller_id' => $this->resellerId]);

                return;
            }

            // Log reseller state for debugging
            Log::info("Re-enabling configs for reseller {$reseller->id}", [
                'reseller_status' => $reseller->status,
                'has_traffic_remaining' => $reseller->hasTrafficRemaining(),
                'is_window_valid' => $reseller->isWindowValid(),
                'traffic_used_bytes' => $reseller->traffic_used_bytes,
                'traffic_total_bytes' => $reseller->traffic_total_bytes,
                'window_ends_at' => $reseller->window_ends_at?->toDateTimeString(),
            ]);

            $resellers = collect([$reseller]);
        } else {
            // When no specific reseller ID, find all suspended traffic-based resellers
            $resellers = Reseller::where('status', 'suspended')
                ->where('type', 'traffic')
                ->get()
                ->filter(function ($reseller) {
                    // Apply grace thresholds for consistency with disable logic
                    $resellerGrace = $this->getResellerGraceSettings();
                    $effectiveResellerLimit = $this->applyGrace(
                        $reseller->traffic_total_bytes,
                        $resellerGrace['percent'],
                        $resellerGrace['bytes']
                    );

                    $hasTrafficRemaining = $reseller->traffic_used_bytes < $effectiveResellerLimit;
                    $isWindowValid = $reseller->isWindowValid();

                    // Log decision for debugging
                    if (! $hasTrafficRemaining || ! $isWindowValid) {
                        Log::info("Skipping reseller {$reseller->id} - not eligible for re-enable", [
                            'has_traffic_remaining' => $hasTrafficRemaining,
                            'is_window_valid' => $isWindowValid,
                            'traffic_used_bytes' => $reseller->traffic_used_bytes,
                            'traffic_total_bytes' => $reseller->traffic_total_bytes,
                            'effective_limit' => $effectiveResellerLimit,
                            'window_ends_at' => $reseller->window_ends_at?->toDateTimeString(),
                        ]);
                    }

                    return $hasTrafficRemaining && $isWindowValid;
                });
        }

        if ($resellers->isEmpty()) {
            Log::info('No eligible resellers for re-enable', ['reseller_id' => $this->resellerId]);

            return;
        }

        Log::info("Found {$resellers->count()} eligible resellers for re-enable");

        foreach ($resellers as $reseller) {
            // Reactivate the reseller if still suspended
            if ($reseller->status === 'suspended') {
                $reseller->update(['status' => 'active']);
                Log::info("Reseller {$reseller->id} reactivated after recovery");

                // Create audit log for reseller activation
                AuditLog::log(
                    action: 'reseller_activated',
                    targetType: 'reseller',
                    targetId: $reseller->id,
                    reason: 'reseller_recovered',
                    meta: [
                        'traffic_used_bytes' => $reseller->traffic_used_bytes,
                        'traffic_total_bytes' => $reseller->traffic_total_bytes,
                        'window_ends_at' => $reseller->window_ends_at?->toDateTimeString(),
                    ],
                    actorType: null,
                    actorId: null  // System action
                );
            } else {
                Log::info("Reseller {$reseller->id} already active, skipping reactivation");
            }

            $this->reenableResellerConfigs($reseller, $provisioner);
        }
    }

    /**
     * Process wallet-based resellers for re-enabling
     */
    protected function processWalletResellers(): void
    {
        Log::info('Processing wallet-based resellers for re-enable');

        // Find suspended_wallet resellers with positive balance above suspension threshold
        $suspensionThreshold = config('billing.wallet.suspension_threshold', -1000);
        
        $walletResellers = Reseller::where('status', 'suspended_wallet')
            ->where('type', 'wallet')
            ->where('wallet_balance', '>', $suspensionThreshold)
            ->get();

        if ($walletResellers->isEmpty()) {
            Log::info('No eligible wallet resellers for re-enable');
            return;
        }

        Log::info("Found {$walletResellers->count()} eligible wallet resellers for re-enable");

        $walletReenableService = new \App\Services\WalletResellerReenableService();

        foreach ($walletResellers as $reseller) {
            try {
                Log::info("wallet_reseller_reenable_start", [
                    'reseller_id' => $reseller->id,
                    'wallet_balance' => $reseller->wallet_balance,
                    'suspension_threshold' => $suspensionThreshold,
                ]);

                // Reactivate the reseller if still suspended_wallet
                if ($reseller->status === 'suspended_wallet') {
                    $reseller->update(['status' => 'active']);
                    Log::info("Wallet reseller {$reseller->id} reactivated after wallet recharge");

                    // Create audit log for reseller activation
                    AuditLog::log(
                        action: 'reseller_activated',
                        targetType: 'reseller',
                        targetId: $reseller->id,
                        reason: 'wallet_balance_recovered',
                        meta: [
                            'wallet_balance' => $reseller->wallet_balance,
                            'suspension_threshold' => $suspensionThreshold,
                        ],
                        actorType: null,
                        actorId: null  // System action
                    );
                }

                // Re-enable configs that were disabled by wallet suspension
                $result = $walletReenableService->reenableWalletSuspendedConfigs($reseller);

                Log::info("wallet_reseller_reenable_complete", [
                    'reseller_id' => $reseller->id,
                    'configs_enabled' => $result['enabled'],
                    'configs_failed' => $result['failed'],
                ]);
            } catch (\Exception $e) {
                Log::error("wallet_reseller_reenable_error", [
                    'reseller_id' => $reseller->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }
    }

    protected function reenableResellerConfigs(Reseller $reseller, ResellerProvisioner $provisioner): void
    {
        // Find configs that were auto-disabled by reseller suspension using JSON queries
        // Query for configs with meta->disabled_by_reseller_suspension = true (handle mixed types: true, '1', 1, 'true')
        // We use whereRaw to handle all truthy variations (boolean true, string '1', integer 1, string 'true')
        $configsFromQuery = ResellerConfig::where('reseller_id', $reseller->id)
            ->where(function ($query) {
                // Match configs where disabled_by_reseller_suspension is truthy (handles true, '1', 1, 'true')
                $query->whereRaw("JSON_EXTRACT(meta, '$.disabled_by_reseller_suspension') = TRUE")
                    ->orWhereRaw("JSON_EXTRACT(meta, '$.disabled_by_reseller_suspension') = '1'")
                    ->orWhereRaw("JSON_EXTRACT(meta, '$.disabled_by_reseller_suspension') = 1")
                    ->orWhereRaw("JSON_EXTRACT(meta, '$.disabled_by_reseller_suspension') = 'true'")
                    // Also handle time window suspension marker
                    ->orWhereRaw("JSON_EXTRACT(meta, '$.suspended_by_time_window') = TRUE")
                    ->orWhereRaw("JSON_EXTRACT(meta, '$.suspended_by_time_window') = '1'")
                    ->orWhereRaw("JSON_EXTRACT(meta, '$.suspended_by_time_window') = 1")
                    ->orWhereRaw("JSON_EXTRACT(meta, '$.suspended_by_time_window') = 'true'");
            })
            ->get();

        // FALLBACK: Also check all disabled configs and filter in PHP to catch edge cases
        // where JSON query might miss due to type coercion or unusual values
        // Use chunking to avoid loading all configs into memory at once
        $allDisabledConfigs = collect();
        ResellerConfig::where('reseller_id', $reseller->id)
            ->where('status', 'disabled')
            ->chunk(500, function ($configs) use ($reseller, &$allDisabledConfigs) {
                $filtered = $configs->filter(function ($config) use ($reseller) {
                    $meta = $config->meta ?? [];
                    // Check if any suspension marker exists and is truthy
                    $disabledByReseller = $meta['disabled_by_reseller_suspension'] ?? null;
                    $suspendedByWindow = $meta['suspended_by_time_window'] ?? null;
                    $disabledByResellerId = $meta['disabled_by_reseller_id'] ?? null;

                    // Consider truthy if: true, 1, '1', 'true' or if disabled_by_reseller_id matches this reseller
                    $isMarkedByReseller = $disabledByReseller === true
                        || $disabledByReseller === 1
                        || $disabledByReseller === '1'
                        || $disabledByReseller === 'true';

                    $isMarkedByWindow = $suspendedByWindow === true
                        || $suspendedByWindow === 1
                        || $suspendedByWindow === '1'
                        || $suspendedByWindow === 'true';

                    return $isMarkedByReseller || $isMarkedByWindow || ($disabledByResellerId === $reseller->id);
                });
                $allDisabledConfigs = $allDisabledConfigs->concat($filtered);
            });

        // Merge and deduplicate configs from both sources
        $configs = $configsFromQuery->merge($allDisabledConfigs)->unique('id');

        Log::info("Config detection for reseller {$reseller->id}", [
            'from_json_query' => $configsFromQuery->count(),
            'from_php_filter' => $allDisabledConfigs->count(),
            'total_unique' => $configs->count(),
        ]);

        if ($configs->isEmpty()) {
            Log::info("No configs marked for re-enable for reseller {$reseller->id}");

            return;
        }

        Log::info("Re-enabling {$configs->count()} configs for reseller {$reseller->id}");

        $enabledCount = 0;
        $failedCount = 0;

        foreach ($configs as $config) {
            try {
                // Apply micro-sleep rate limiting: 3 ops/sec evenly distributed
                $provisioner->applyRateLimit($enabledCount);

                // Detailed logging before enable attempt
                $panel = $config->panel_id ? Panel::find($config->panel_id) : null;
                $metaSnapshot = $config->meta ?? [];

                Log::info('Attempting to re-enable config', [
                    'reseller_id' => $reseller->id,
                    'config_id' => $config->id,
                    'panel_id' => $config->panel_id,
                    'panel_type' => $panel?->panel_type ?? $config->panel_type,
                    'panel_user_id' => $config->panel_user_id,
                    'current_status' => $config->status,
                    'meta_snapshot' => [
                        'disabled_by_reseller_suspension' => $metaSnapshot['disabled_by_reseller_suspension'] ?? null,
                        'disabled_by_reseller_id' => $metaSnapshot['disabled_by_reseller_id'] ?? null,
                        'disabled_at' => $metaSnapshot['disabled_at'] ?? null,
                        'suspended_by_time_window' => $metaSnapshot['suspended_by_time_window'] ?? null,
                    ],
                ]);

                // Enable on remote panel - use the proven path for Eylandoo
                // For Eylandoo, use the same ResellerProvisioner->enableUser() path as the reseller panel
                // For other providers, continue using enableConfig()
                if ($panel && strtolower($panel->panel_type) === 'eylandoo') {
                    try {
                        $credentials = $panel->getCredentials();

                        // Validate credentials before attempting
                        if (empty($credentials['url']) || empty($credentials['api_token'])) {
                            Log::warning('ReenableJob: Eylandoo missing credentials', [
                                'action' => 'eylandoo_enable_failed',
                                'config_id' => $config->id,
                                'panel_id' => $panel->id,
                                'reseller_id' => $reseller->id,
                            ]);
                            $remoteResult = ['success' => false, 'attempts' => 0, 'last_error' => 'Missing credentials (url or api_token)'];
                        } else {
                            Log::info('ReenableJob: calling Eylandoo enableUser', [
                                'action' => 'eylandoo_enable_toggle',
                                'config_id' => $config->id,
                                'panel_id' => $panel->id,
                                'reseller_id' => $reseller->id,
                                'panel_user_id' => $config->panel_user_id,
                                'url' => $credentials['url'],
                            ]);

                            $remoteResult = $provisioner->enableUser($panel->panel_type, $credentials, $config->panel_user_id);

                            Log::info('ReenableJob: Eylandoo enableUser result', [
                                'config_id' => $config->id,
                                'panel_id' => $panel->id,
                                'panel_user_id' => $config->panel_user_id,
                                'remote_success' => $remoteResult['success'],
                                'attempts' => $remoteResult['attempts'],
                            ]);
                        }
                    } catch (\Exception $e) {
                        Log::error("Failed to get Eylandoo credentials for re-enable: {$e->getMessage()}", [
                            'config_id' => $config->id,
                            'panel_id' => $panel->id,
                        ]);
                        $remoteResult = ['success' => false, 'attempts' => 0, 'last_error' => $e->getMessage()];
                    }
                } else {
                    // For Marzban, Marzneshin, XUI, and other providers - use enableConfig
                    $remoteResult = $provisioner->enableConfig($config);
                }

                // Log remote enable result
                Log::info("Remote enable result for config {$config->id}", [
                    'remote_success' => $remoteResult['success'],
                    'attempts' => $remoteResult['attempts'],
                    'last_error' => $remoteResult['last_error'],
                ]);

                // Only update local status if remote succeeded
                if ($remoteResult['success']) {
                    // Update local status and clear suspension markers
                    $meta = $config->meta ?? [];
                    unset($meta['disabled_by_reseller_suspension']);
                    unset($meta['disabled_by_reseller_suspension_reason']);
                    unset($meta['disabled_by_reseller_suspension_at']);
                    unset($meta['disabled_by_reseller_id']);
                    unset($meta['disabled_at']);
                    unset($meta['suspended_by_time_window']);

                    $config->update([
                        'status' => 'active',
                        'disabled_at' => null,
                        'meta' => $meta,
                    ]);

                    Log::info("Config {$config->id} re-enabled (status set to active, meta flags cleared)", [
                        'remote_success' => true,
                    ]);

                    $enabledCount++;
                } else {
                    // Remote enable failed - keep config disabled
                    Log::warning("Failed to enable config {$config->id} on remote panel after {$remoteResult['attempts']} attempts: {$remoteResult['last_error']}. Keeping config disabled.", [
                        'action' => 'config_reenable_failed',
                        'config_id' => $config->id,
                        'reseller_id' => $reseller->id,
                        'panel_id' => $config->panel_id,
                        'panel_type' => $panel?->panel_type,
                    ]);
                    $failedCount++;
                }

                ResellerConfigEvent::create([
                    'reseller_config_id' => $config->id,
                    'type' => $remoteResult['success'] ? 'auto_enabled' : 'auto_enable_failed',
                    'meta' => [
                        'reason' => 'reseller_recovered',
                        'remote_success' => $remoteResult['success'],
                        'attempts' => $remoteResult['attempts'],
                        'last_error' => $remoteResult['last_error'],
                        'panel_id' => $config->panel_id,
                        'panel_type_used' => $config->panel_id ? Panel::find($config->panel_id)?->panel_type : null,
                    ],
                ]);

                // Create audit log entry
                AuditLog::log(
                    action: $remoteResult['success'] ? 'config_auto_enabled' : 'config_auto_enable_failed',
                    targetType: 'config',
                    targetId: $config->id,
                    reason: 'reseller_recovered',
                    meta: [
                        'remote_success' => $remoteResult['success'],
                        'attempts' => $remoteResult['attempts'],
                        'last_error' => $remoteResult['last_error'],
                        'panel_id' => $config->panel_id,
                        'panel_type_used' => $config->panel_id ? Panel::find($config->panel_id)?->panel_type : null,
                    ],
                    actorType: null,
                    actorId: null  // System action
                );
            } catch (\Exception $e) {
                Log::error("Exception enabling config {$config->id}: ".$e->getMessage());
                $failedCount++;
            }
        }

        Log::info("Auto-enable completed for reseller {$reseller->id}: {$enabledCount} enabled, {$failedCount} failed");
    }

    /**
     * Calculate the effective limit with grace threshold applied
     *
     * @param  int  $limit  The base limit in bytes
     * @param  float  $gracePercent  Grace percentage (e.g., 2.0 for 2%)
     * @param  int  $graceBytes  Grace in bytes (e.g., 50MB)
     * @return int The limit plus maximum grace
     */
    protected function applyGrace(int $limit, float $gracePercent, int $graceBytes): int
    {
        $percentGrace = (int) ($limit * ($gracePercent / 100));
        $maxGrace = max($percentGrace, $graceBytes);

        return $limit + $maxGrace;
    }

    /**
     * Get grace settings for reseller-level checks
     *
     * @return array ['percent' => float, 'bytes' => int]
     */
    protected function getResellerGraceSettings(): array
    {
        return [
            'percent' => (float) \App\Models\Setting::get('reseller.auto_disable_grace_percent', 2.0),
            'bytes' => (int) \App\Models\Setting::get('reseller.auto_disable_grace_bytes', 50 * 1024 * 1024), // 50MB
        ];
    }
}
