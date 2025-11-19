<?php

namespace Modules\Reseller\Jobs;

use App\Models\AuditLog;
use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\ResellerConfigEvent;
use App\Models\Setting;
use App\Services\EylandooService;
use App\Services\MarzbanService;
use App\Services\MarzneshinService;
use App\Services\MultiPanelUsageAggregator;
use App\Services\XUIService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncResellerUsageJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public $timeout = 600;

    public $uniqueFor = 300; // 5 minutes

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
     * Get grace settings for config-level checks
     *
     * @return array ['percent' => float, 'bytes' => int]
     */
    protected function getConfigGraceSettings(): array
    {
        return [
            'percent' => (float) Setting::get('config.auto_disable_grace_percent', 2.0),
            'bytes' => (int) Setting::get('config.auto_disable_grace_bytes', 50 * 1024 * 1024), // 50MB
        ];
    }

    /**
     * Get grace settings for reseller-level checks
     *
     * @return array ['percent' => float, 'bytes' => int]
     */
    protected function getResellerGraceSettings(): array
    {
        return [
            'percent' => (float) Setting::get('reseller.auto_disable_grace_percent', 2.0),
            'bytes' => (int) Setting::get('reseller.auto_disable_grace_bytes', 50 * 1024 * 1024), // 50MB
        ];
    }

    /**
     * Get time expiry grace in minutes
     *
     * @return int Grace minutes (always 0 for calendar-day enforcement)
     */
    protected function getTimeExpiryGraceMinutes(): int
    {
        // Time-expiry grace is not used with calendar-day enforcement
        return 0;
    }

    public function handle(): void
    {
        Log::notice('Starting reseller usage sync');

        // Get all active resellers with config management support (traffic-based and wallet-based)
        // Both types need usage tracking - traffic for quota enforcement, wallet for hourly billing
        $resellers = Reseller::where('status', 'active')
            ->whereIn('type', ['traffic', 'wallet'])
            ->get();

        $totalEylandooConfigs = 0;

        foreach ($resellers as $reseller) {
            $eylandooCount = $this->syncResellerUsage($reseller);
            $totalEylandooConfigs += $eylandooCount;
        }

        Log::notice('Reseller usage sync completed', ['eylandoo_configs_processed' => $totalEylandooConfigs]);
    }

    protected function syncResellerUsage(Reseller $reseller): int
    {
        Log::info("Syncing usage for reseller {$reseller->id}");

        // Use multi-panel aggregator if enabled
        if (config('multi_panel.usage_enabled', true)) {
            return $this->syncResellerUsageMultiPanel($reseller);
        }

        // Legacy single-panel logic
        return $this->syncResellerUsageLegacy($reseller);
    }

    /**
     * Multi-panel usage sync with aggregation
     */
    protected function syncResellerUsageMultiPanel(Reseller $reseller): int
    {
        $aggregator = new MultiPanelUsageAggregator();
        $result = $aggregator->aggregateUsage($reseller);

        // Update reseller's total usage
        $aggregator->updateResellerTotalUsage($reseller, $result['total_usage_bytes']);

        // Skip quota/window enforcement for wallet-based resellers
        if ($reseller->isWalletBased()) {
            Log::info("Skipping quota/window enforcement for wallet-based reseller {$reseller->id}");
            return 0; // Return 0 as we're not tracking Eylandoo count separately anymore
        }

        // Check reseller-level limits with grace (traffic-based resellers only)
        $resellerGrace = $this->getResellerGraceSettings();
        $effectiveResellerLimit = $this->applyGrace(
            $reseller->traffic_total_bytes,
            $resellerGrace['percent'],
            $resellerGrace['bytes']
        );

        $effectiveUsageBytes = $result['total_usage_bytes'];
        $hasTrafficRemaining = $effectiveUsageBytes < $effectiveResellerLimit;
        $isWindowValid = $reseller->isWindowValid();

        if (! $hasTrafficRemaining || ! $isWindowValid) {
            // Suspend the reseller if not already suspended
            if ($reseller->status !== 'suspended') {
                $reason = ! $hasTrafficRemaining ? 'reseller_quota_exhausted' : 'reseller_window_expired';
                $reseller->update(['status' => 'suspended']);
                Log::info("Reseller {$reseller->id} suspended due to quota/window exhaustion");

                // Create audit log for reseller suspension
                AuditLog::log(
                    action: 'reseller_suspended',
                    targetType: 'reseller',
                    targetId: $reseller->id,
                    reason: $reason,
                    meta: [
                        'traffic_used_bytes' => $effectiveUsageBytes,
                        'traffic_total_bytes' => $reseller->traffic_total_bytes,
                        'window_ends_at' => $reseller->window_ends_at?->toDateTimeString(),
                    ],
                    actorType: null,
                    actorId: null  // System action
                );
            }
            $this->disableResellerConfigs($reseller);
        }

        return 0; // Return 0 as we're not tracking Eylandoo count separately
    }

    /**
     * Legacy single-panel usage sync (backward compatibility)
     */
    protected function syncResellerUsageLegacy(Reseller $reseller): int
    {

        $configs = $reseller->configs()
            ->where('status', 'active')
            ->get();

        $allowConfigOverrun = Setting::getBool('reseller.allow_config_overrun', true);
        $configGrace = $this->getConfigGraceSettings();
        $timeGraceMinutes = $this->getTimeExpiryGraceMinutes();

        $eylandooCount = 0;

        // Sync usage for all active configs
        foreach ($configs as $config) {
            try {
                if ($config->panel_type === 'eylandoo') {
                    $eylandooCount++;
                }

                $usage = $this->fetchConfigUsage($config);

                if ($usage !== null) {
                    // Update usage_bytes
                    $config->update(['usage_bytes' => $usage]);

                    // For Eylandoo configs, also persist usage in meta for compatibility
                    if ($config->panel_type === 'eylandoo') {
                        $meta = $config->meta ?? [];
                        $meta['used_traffic'] = $usage;
                        $meta['data_used'] = $usage;
                        $config->update(['meta' => $meta]);
                    }

                    // Only check per-config limits if config overrun is NOT allowed
                    if (! $allowConfigOverrun) {
                        // Apply grace threshold to traffic limit
                        $effectiveTrafficLimit = $this->applyGrace(
                            $config->traffic_limit_bytes,
                            $configGrace['percent'],
                            $configGrace['bytes']
                        );

                        // Check if config exceeded its own limits (with grace)
                        if ($config->usage_bytes >= $effectiveTrafficLimit) {
                            $this->disableConfig($config, 'traffic_exceeded');
                        } elseif ($this->isExpiredByTimeWithGrace($config->expires_at, $timeGraceMinutes)) {
                            $this->disableConfig($config, 'time_expired');
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::error("Error syncing config {$config->id}: ".$e->getMessage());
            }
        }

        // Update reseller's total traffic usage from ALL configs (not just active)
        // This ensures reseller suspension decision is based on complete usage picture
        // Include settled_usage_bytes to prevent abuse (resellers resetting to bypass quota)
        // Subtract admin_forgiven_bytes to honor admin quota forgiveness
        // For display purposes, use getCurrentTrafficUsedBytes() which excludes settled
        $totalUsageBytesFromDB = $reseller->configs()
            ->get()
            ->sum(function ($config) {
                return $config->usage_bytes + (int) data_get($config->meta, 'settled_usage_bytes', 0);
            });

        // Subtract admin forgiven bytes to get effective usage
        $adminForgivenBytes = $reseller->admin_forgiven_bytes ?? 0;
        $effectiveUsageBytes = max(0, $totalUsageBytesFromDB - $adminForgivenBytes);

        $reseller->update(['traffic_used_bytes' => $effectiveUsageBytes]);

        Log::info("Reseller {$reseller->id} usage updated", [
            'reseller_id' => $reseller->id,
            'total_from_configs' => $totalUsageBytesFromDB,
            'admin_forgiven_bytes' => $adminForgivenBytes,
            'effective_usage_bytes' => $effectiveUsageBytes,
            'traffic_used_bytes' => $effectiveUsageBytes,
            'traffic_used_gb' => round($effectiveUsageBytes / (1024 * 1024 * 1024), 2),
        ]);

        // Skip quota/window enforcement for wallet-based resellers
        // They are managed via wallet balance in ChargeWalletResellersHourly
        if ($reseller->isWalletBased()) {
            Log::info("Skipping quota/window enforcement for wallet-based reseller {$reseller->id}");
            return $eylandooCount;
        }

        // Check reseller-level limits with grace (traffic-based resellers only)
        $resellerGrace = $this->getResellerGraceSettings();
        $effectiveResellerLimit = $this->applyGrace(
            $reseller->traffic_total_bytes,
            $resellerGrace['percent'],
            $resellerGrace['bytes']
        );

        $hasTrafficRemaining = $effectiveUsageBytes < $effectiveResellerLimit;
        $isWindowValid = $reseller->isWindowValid();

        if (! $hasTrafficRemaining || ! $isWindowValid) {
            // Suspend the reseller if not already suspended
            if ($reseller->status !== 'suspended') {
                $reason = ! $hasTrafficRemaining ? 'reseller_quota_exhausted' : 'reseller_window_expired';
                $reseller->update(['status' => 'suspended']);
                Log::info("Reseller {$reseller->id} suspended due to quota/window exhaustion");

                // Create audit log for reseller suspension
                AuditLog::log(
                    action: 'reseller_suspended',
                    targetType: 'reseller',
                    targetId: $reseller->id,
                    reason: $reason,
                    meta: [
                        'traffic_used_bytes' => $effectiveUsageBytes,
                        'traffic_total_bytes' => $reseller->traffic_total_bytes,
                        'window_ends_at' => $reseller->window_ends_at?->toDateTimeString(),
                    ],
                    actorType: null,
                    actorId: null  // System action
                );
            }
            $this->disableResellerConfigs($reseller);
        }

        return $eylandooCount;
    }

    /**
     * Check if a datetime is expired considering grace period (calendar-day basis)
     *
     * @param  \Carbon\Carbon  $expiresAt
     * @param  int  $graceMinutes  (ignored - always 0)
     */
    protected function isExpiredByTimeWithGrace($expiresAt, int $graceMinutes): bool
    {
        // Calendar-day expiration: expired when now >= expiresAt (start of day)
        // Grace is not applied for time expiry
        if (! $expiresAt) {
            return false;
        }

        return now() >= $expiresAt->copy()->startOfDay();
    }

    /**
     * Fetch usage for a specific config (public for diagnostic tools)
     *
     * @return int|null Usage in bytes, or null on failure
     */
    public function fetchConfigUsage(ResellerConfig $config): ?int
    {
        try {
            // Find the panel for this config - use exact panel_id if available, otherwise fallback to type
            if ($config->panel_id) {
                $panel = Panel::find($config->panel_id);
            } else {
                $panel = Panel::where('panel_type', $config->panel_type)->first();
            }

            if (! $panel) {
                Log::warning("No panel found for config {$config->id} (panel_id: {$config->panel_id}, type: {$config->panel_type})");

                return null;
            }

            $credentials = $panel->getCredentials();

            switch ($config->panel_type) {
                case 'marzban':
                    return $this->fetchMarzbanUsage($credentials, $config->panel_user_id);

                case 'marzneshin':
                    return $this->fetchMarzneshinUsage($credentials, $config->panel_user_id);

                case 'xui':
                    return $this->fetchXUIUsage($credentials, $config->panel_user_id);

                case 'eylandoo':
                    // For Eylandoo, use panel_user_id or fallback to external_username
                    $username = $config->panel_user_id ?: $config->external_username;

                    return $this->fetchEylandooUsage($credentials, $username, $config->id);

                default:
                    return null;
            }
        } catch (\Exception $e) {
            Log::error("Failed to fetch usage for config {$config->id}: ".$e->getMessage());

            return null;
        }
    }

    protected function fetchMarzbanUsage(array $credentials, string $username): ?int
    {
        $nodeHostname = $credentials['extra']['node_hostname'] ?? '';

        $service = new MarzbanService(
            $credentials['url'],
            $credentials['username'],
            $credentials['password'],
            $nodeHostname
        );

        if (! $service->login()) {
            return null;
        }

        $user = $service->getUser($username);

        if (! $user) {
            return null;
        }

        // Defensive cast to avoid null arithmetic
        return isset($user['used_traffic']) ? (int) $user['used_traffic'] : null;
    }

    protected function fetchMarzneshinUsage(array $credentials, string $username): ?int
    {
        $nodeHostname = $credentials['extra']['node_hostname'] ?? '';

        $service = new MarzneshinService(
            $credentials['url'],
            $credentials['username'],
            $credentials['password'],
            $nodeHostname
        );

        if (! $service->login()) {
            return null;
        }

        $user = $service->getUser($username);

        if (! $user) {
            return null;
        }

        // Defensive cast to avoid null arithmetic
        return isset($user['used_traffic']) ? (int) $user['used_traffic'] : null;
    }

    protected function fetchXUIUsage(array $credentials, string $username): ?int
    {
        $service = new XUIService(
            $credentials['url'],
            $credentials['username'],
            $credentials['password']
        );

        if (! $service->login()) {
            return null;
        }

        $user = $service->getUser($username);

        if (! $user) {
            return null;
        }

        // Safely compute usage with proper type casting
        $up = (int) ($user['up'] ?? 0);
        $down = (int) ($user['down'] ?? 0);

        return $up + $down;
    }

    protected function fetchEylandooUsage(array $credentials, string $username, ?int $configId = null): ?int
    {
        if (empty($username)) {
            Log::warning('Eylandoo usage fetch skipped: empty username', ['config_id' => $configId]);

            return null;
        }

        $nodeHostname = $credentials['extra']['node_hostname'] ?? '';
        $panelUrl = $credentials['url'];

        // Log the resolved username for diagnostics
        Log::info('Eylandoo usage fetch starting', [
            'config_id' => $configId,
            'panel_url' => $panelUrl,
            'username' => $username,
        ]);

        $service = new EylandooService(
            $panelUrl,
            $credentials['api_token'],
            $nodeHostname
        );

        // Fetch usage using ONLY getUserUsageBytes (per requirement)
        $usage = $service->getUserUsageBytes($username);

        if ($usage === null) {
            Log::warning("Eylandoo usage fetch failed for user {$username} (hard failure)", [
                'config_id' => $configId,
                'panel_url' => $panelUrl,
            ]);

            return null;
        }

        Log::info("Eylandoo usage for user {$username}: {$usage} bytes", [
            'config_id' => $configId,
        ]);

        return $usage;
    }

    protected function disableConfig(ResellerConfig $config, string $reason): void
    {
        // Attempt remote disable first using ResellerProvisioner
        $remoteResult = ['success' => false, 'attempts' => 0, 'last_error' => 'No panel configured'];

        if ($config->panel_id) {
            try {
                $panel = Panel::find($config->panel_id);
                if ($panel) {
                    $provisioner = new \Modules\Reseller\Services\ResellerProvisioner;
                    $remoteResult = $provisioner->disableUser(
                        $panel->panel_type,  // Use panel's panel_type, not config's
                        $panel->getCredentials(),
                        $config->panel_user_id
                    );

                    if (! $remoteResult['success']) {
                        Log::warning("Failed to disable config {$config->id} on remote panel {$panel->id} after {$remoteResult['attempts']} attempts");
                    }
                } else {
                    Log::warning("Panel {$config->panel_id} not found for config {$config->id}");
                }
            } catch (\Exception $e) {
                Log::error("Exception disabling config {$config->id} on panel: ".$e->getMessage());
                $remoteResult['last_error'] = $e->getMessage();
            }
        }

        // Update local state only after remote attempt (success or definitive failure)
        $config->update([
            'status' => $reason === 'time_expired' ? 'expired' : 'disabled',
            'disabled_at' => now(),
        ]);

        ResellerConfigEvent::create([
            'reseller_config_id' => $config->id,
            'type' => 'auto_disabled',
            'meta' => [
                'reason' => $reason,
                'remote_success' => $remoteResult['success'],
                'attempts' => $remoteResult['attempts'],
                'last_error' => $remoteResult['last_error'],
                'panel_id' => $config->panel_id,
                'panel_type_used' => $config->panel_id ? Panel::find($config->panel_id)?->panel_type : null,
            ],
        ]);

        // Create audit log entry
        AuditLog::log(
            action: 'config_auto_disabled',
            targetType: 'config',
            targetId: $config->id,
            reason: $reason,
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

        Log::notice("Config {$config->id} disabled due to: {$reason} (remote_success: ".($remoteResult['success'] ? 'true' : 'false').", panel_id: {$config->panel_id})");
    }

    protected function disableResellerConfigs(Reseller $reseller): void
    {
        $reason = ! $reseller->hasTrafficRemaining() ? 'reseller_quota_exhausted' : 'reseller_window_expired';

        $configs = $reseller->configs()->where('status', 'active')->get();

        if ($configs->isEmpty()) {
            return;
        }

        Log::info("Starting auto-disable for reseller {$reseller->id}: {$configs->count()} configs, reason: {$reason}");

        $disabledCount = 0;
        $failedCount = 0;
        $provisioner = new \Modules\Reseller\Services\ResellerProvisioner;

        foreach ($configs as $config) {
            try {
                // Apply micro-sleep rate limiting: 3 ops/sec evenly distributed
                $provisioner->applyRateLimit($disabledCount);

                // Disable on remote panel first using the stored panel_id
                $remoteResult = ['success' => false, 'attempts' => 0, 'last_error' => 'No panel configured'];

                if ($config->panel_id) {
                    $panel = Panel::find($config->panel_id);
                    if ($panel) {
                        $remoteResult = $provisioner->disableUser(
                            $panel->panel_type,  // Use panel's panel_type, not config's
                            $panel->getCredentials(),
                            $config->panel_user_id
                        );

                        if (! $remoteResult['success']) {
                            Log::warning("Failed to disable config {$config->id} on remote panel {$panel->id} after {$remoteResult['attempts']} attempts: {$remoteResult['last_error']}");
                            $failedCount++;
                        }
                    }
                }

                // Update local status after remote attempt (regardless of result)
                // Mark config with appropriate flag based on actual reason
                $meta = $config->meta ?? [];
                
                // Set appropriate flag based on the actual reason (same logic as ResellerTimeWindowEnforcer)
                if (str_contains($reason, 'window') || $reason === 'reseller_window_expired') {
                    // Time window related suspension
                    $meta['suspended_by_time_window'] = true;
                } else {
                    // Quota exhaustion or other reseller suspension reasons
                    $meta['disabled_by_reseller_suspension'] = true;
                    $meta['disabled_by_reseller_suspension_reason'] = $reason;
                    $meta['disabled_by_reseller_suspension_at'] = now()->toIso8601String();
                }
                
                // Common fields for all suspension types
                $meta['disabled_by_reseller_id'] = $reseller->id;
                $meta['disabled_at'] = now()->toIso8601String();

                $config->update([
                    'status' => 'disabled',
                    'disabled_at' => now(),
                    'meta' => $meta,
                ]);

                // Log per-config disable
                Log::info("Config {$config->id} auto-disabled by reseller suspension", [
                    'reseller_id' => $reseller->id,
                    'config_id' => $config->id,
                    'reason' => $reason,
                    'panel_id' => $config->panel_id,
                    'remote_success' => $remoteResult['success'],
                ]);

                ResellerConfigEvent::create([
                    'reseller_config_id' => $config->id,
                    'type' => 'auto_disabled',
                    'meta' => [
                        'reason' => $reason,
                        'remote_success' => $remoteResult['success'],
                        'attempts' => $remoteResult['attempts'],
                        'last_error' => $remoteResult['last_error'],
                        'panel_id' => $config->panel_id,
                        'panel_type_used' => $config->panel_id ? Panel::find($config->panel_id)?->panel_type : null,
                    ],
                ]);

                // Create audit log entry
                AuditLog::log(
                    action: 'config_auto_disabled',
                    targetType: 'config',
                    targetId: $config->id,
                    reason: $reason,
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

                $disabledCount++;
            } catch (\Exception $e) {
                Log::error("Exception disabling config {$config->id}: ".$e->getMessage());
                $failedCount++;
            }
        }

        Log::info("Auto-disable completed for reseller {$reseller->id}: {$disabledCount} disabled, {$failedCount} failed");
    }
}
