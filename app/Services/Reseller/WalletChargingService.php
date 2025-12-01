<?php

namespace App\Services\Reseller;

use App\Models\AuditLog;
use App\Models\BillingLedgerEntry;
use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\ResellerConfigEvent;
use App\Models\ResellerUsageSnapshot;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WalletChargingService
{
    /**
     * Charge a wallet-based reseller for their traffic usage.
     *
     * This method calculates the traffic delta since the last snapshot,
     * applies the appropriate wallet deduction, and creates a new snapshot.
     * It includes idempotency guards to prevent double-charging.
     *
     * @param  Reseller  $reseller  The reseller to charge
     * @param  Carbon|null  $referenceTime  Reference time for the charge cycle (defaults to now)
     * @param  bool  $dryRun  If true, calculate but don't apply charges
     * @param  string|null  $source  Source of the charge request (e.g., 'command', 'panel')
     * @return array Result of the charge operation
     */
    public function chargeForReseller(Reseller $reseller, ?Carbon $referenceTime = null, bool $dryRun = false, ?string $source = null): array
    {
        // Use current time if no reference time provided
        $referenceTime = $referenceTime ?? now();
        $cycleStartedAt = $referenceTime->startOfMinute()->toIso8601String();

        // Validate reseller type
        if ($reseller->type !== 'wallet') {
            Log::info('wallet_charge_skip_non_wallet', [
                'reseller_id' => $reseller->id,
                'reseller_type' => $reseller->type,
                'source' => $source,
            ]);

            return [
                'status' => 'skipped',
                'reason' => 'not_wallet_type',
                'charged' => false,
                'cost' => 0,
                'suspended' => false,
            ];
        }

        // Calculate total current usage from all configs
        // This includes both current usage_bytes and any settled_usage_bytes from resets
        $currentTotalBytes = $this->calculateTotalUsageBytes($reseller);

        // Get the last snapshot to calculate delta
        $lastSnapshot = $reseller->usageSnapshots()
            ->orderBy('measured_at', 'desc')
            ->first();

        // Calculate delta (traffic used since last snapshot)
        $deltaBytes = 0;
        if ($lastSnapshot) {
            $deltaBytes = max(0, $currentTotalBytes - $lastSnapshot->total_bytes);
        } else {
            // First snapshot - charge for all current usage
            $deltaBytes = $currentTotalBytes;
        }

        $minimumDeltaBytes = (int) config('billing.wallet.minimum_delta_bytes_to_charge', 5 * 1024 * 1024);

        // Skip if no new usage
        if ($deltaBytes <= 0) {
            Log::info('wallet_charge_skip_no_delta', [
                'reseller_id' => $reseller->id,
                'cycle_started_at' => $cycleStartedAt,
                'source' => $source,
            ]);

            $wasSuspended = $this->evaluateSuspension($reseller, $cycleStartedAt);

            return [
                'status' => 'skipped',
                'reason' => 'no_usage_delta',
                'charged' => false,
                'cost' => 0,
                'suspended' => $wasSuspended,
            ];
        }

        // Skip if below minimum threshold
        if ($minimumDeltaBytes > 0 && $deltaBytes < $minimumDeltaBytes) {
            Log::info('wallet_charge_skip_below_threshold', [
                'reseller_id' => $reseller->id,
                'cycle_started_at' => $cycleStartedAt,
                'delta_bytes' => $deltaBytes,
                'minimum_delta_bytes' => $minimumDeltaBytes,
                'source' => $source,
            ]);

            $wasSuspended = $this->evaluateSuspension($reseller, $cycleStartedAt);

            return [
                'status' => 'skipped',
                'reason' => 'below_minimum_delta',
                'charged' => false,
                'cost' => 0,
                'suspended' => $wasSuspended,
                'delta_bytes' => $deltaBytes,
            ];
        }

        // Convert bytes to GB and calculate cost
        $deltaGB = $deltaBytes / (1024 * 1024 * 1024);
        $pricePerGB = $reseller->getWalletPricePerGb();

        // Calculate cost in Toman currency
        // Uses ceiling to avoid undercharging on fractional GB usage
        // Example: 0.1 GB at 1000 per GB = 100, but 0.01 GB at 1000 = 10 (ceiling ensures minimum charge)
        $cost = (int) ceil($deltaGB * $pricePerGB);

        Log::info('wallet_charge_calculation', [
            'reseller_id' => $reseller->id,
            'cycle_started_at' => $cycleStartedAt,
            'current_total_bytes' => $currentTotalBytes,
            'last_snapshot_total_bytes' => $lastSnapshot ? $lastSnapshot->total_bytes : 0,
            'last_snapshot_at' => $lastSnapshot ? $lastSnapshot->measured_at->toIso8601String() : null,
            'delta_bytes' => $deltaBytes,
            'delta_gb' => round($deltaGB, 4),
            'price_per_gb' => $pricePerGB,
            'cost_estimate' => $cost,
            'source' => $source,
        ]);

        // Handle dry run mode
        if ($dryRun) {
            Log::info('wallet_charge_dry_run', [
                'reseller_id' => $reseller->id,
                'cycle_started_at' => $cycleStartedAt,
                'delta_bytes' => $deltaBytes,
                'delta_gb' => round($deltaGB, 4),
                'cost_estimate' => $cost,
                'current_balance' => $reseller->wallet_balance,
                'balance_after_charge' => $reseller->wallet_balance - $cost,
                'source' => $source,
            ]);

            return [
                'status' => 'dry_run',
                'charged' => false,
                'cost' => $cost,
                'suspended' => false,
                'delta_bytes' => $deltaBytes,
                'delta_gb' => round($deltaGB, 4),
                'current_balance' => $reseller->wallet_balance,
                'balance_after_charge' => $reseller->wallet_balance - $cost,
            ];
        }

        // Create new snapshot with metadata
        $snapshot = ResellerUsageSnapshot::create([
            'reseller_id' => $reseller->id,
            'total_bytes' => $currentTotalBytes,
            'measured_at' => now(),
            'meta' => [
                'cycle_started_at' => $cycleStartedAt,
                'cycle_charge_applied' => true,
                'delta_bytes' => $deltaBytes,
                'delta_gb' => round($deltaGB, 4),
                'cost' => $cost,
                'price_per_gb' => $pricePerGB,
                'source' => $source,
            ],
        ]);

        // Deduct from wallet balance
        $oldBalance = $reseller->wallet_balance;
        $newBalance = $oldBalance - $cost;

        $reseller->update(['wallet_balance' => $newBalance]);

        Log::info('wallet_charge_applied', [
            'reseller_id' => $reseller->id,
            'cycle_started_at' => $cycleStartedAt,
            'snapshot_id' => $snapshot->id,
            'delta_bytes' => $deltaBytes,
            'delta_gb' => round($deltaGB, 4),
            'price_per_gb' => $pricePerGB,
            'cost' => $cost,
            'old_balance' => $oldBalance,
            'new_balance' => $newBalance,
            'source' => $source,
        ]);

        // Check if reseller should be suspended
        $suspensionThreshold = config('billing.wallet.suspension_threshold', -1000);
        $wasSuspended = false;

        if ($newBalance <= $suspensionThreshold && ! $reseller->isSuspendedWallet()) {
            $this->suspendWalletReseller($reseller, $cycleStartedAt, $snapshot);
            $wasSuspended = true;

            Log::warning('wallet_reseller_suspended', [
                'reseller_id' => $reseller->id,
                'cycle_started_at' => $cycleStartedAt,
                'balance' => $newBalance,
                'threshold' => $suspensionThreshold,
                'source' => $source,
            ]);
        }

        return [
            'status' => 'charged',
            'charged' => $cost > 0,
            'cost' => $cost,
            'suspended' => $wasSuspended,
            'delta_bytes' => $deltaBytes,
            'new_balance' => $newBalance,
            'snapshot_id' => $snapshot->id,
        ];
    }

    /**
     * Calculate total usage bytes for a reseller.
     * Includes both current usage and settled usage from traffic resets.
     * Works with configs regardless of naming pattern (new or legacy).
     *
     * @param  Reseller  $reseller
     * @return int
     */
    public function calculateTotalUsageBytes(Reseller $reseller): int
    {
        // Query all configs belonging to this reseller, regardless of naming pattern
        // This ensures new naming system configs are included
        return $reseller->configs()
            ->get()
            ->sum(function ($config) {
                // Handle null usage_bytes (treat as 0)
                $usageBytes = $config->usage_bytes ?? 0;
                // Get settled usage bytes from meta (for traffic resets)
                $settledUsageBytes = (int) data_get($config->meta, 'settled_usage_bytes', 0);

                return $usageBytes + $settledUsageBytes;
            });
    }

    /**
     * Resolve the cycle marker for tracking charge operations.
     * Priority: snapshot meta > provided cycleStartedAt > current timestamp
     *
     * @param  ResellerUsageSnapshot|null  $snapshot
     * @param  string|null  $cycleStartedAt
     * @return string
     */
    protected function resolveCycleMarker(?ResellerUsageSnapshot $snapshot, ?string $cycleStartedAt = null): string
    {
        // Try to get from snapshot meta first
        if ($snapshot && isset($snapshot->meta['cycle_started_at'])) {
            return $snapshot->meta['cycle_started_at'];
        }

        // Fall back to provided cycle started at
        if ($cycleStartedAt) {
            return $cycleStartedAt;
        }

        // Last resort: use current time
        return now()->startOfMinute()->toIso8601String();
    }

    /**
     * Evaluate whether a wallet reseller should be suspended based on balance.
     */
    protected function evaluateSuspension(Reseller $reseller, string $cycleStartedAt, ?ResellerUsageSnapshot $snapshot = null, ?int $balanceOverride = null): bool
    {
        $balance = $balanceOverride ?? $reseller->wallet_balance;
        $suspensionThreshold = config('billing.wallet.suspension_threshold', -1000);

        if ($balance > $suspensionThreshold || $reseller->isSuspendedWallet()) {
            return false;
        }

        $this->suspendWalletReseller($reseller, $cycleStartedAt, $snapshot);

        Log::warning('wallet_reseller_suspended', [
            'reseller_id' => $reseller->id,
            'cycle_started_at' => $cycleStartedAt,
            'balance' => $balance,
            'threshold' => $suspensionThreshold,
        ]);

        return true;
    }

    /**
     * Suspend a wallet-based reseller and disable all their configs.
     */
    protected function suspendWalletReseller(Reseller $reseller, string $cycleStartedAt, ?ResellerUsageSnapshot $snapshot = null): void
    {
        $cycleMarker = $this->resolveCycleMarker($snapshot, $cycleStartedAt);

        DB::transaction(function () use ($reseller, $cycleMarker) {
            $reseller->update(['status' => 'suspended_wallet']);

            // Create audit log for suspension
            AuditLog::log(
                action: 'reseller_suspended_wallet',
                targetType: 'reseller',
                targetId: $reseller->id,
                reason: 'wallet_balance_exhausted',
                meta: [
                    'wallet_balance' => $reseller->wallet_balance,
                    'suspension_threshold' => config('billing.wallet.suspension_threshold', -1000),
                    'cycle_started_at' => $cycleMarker,
                ],
                actorType: null,
                actorId: null  // System action
            );
        });

        Log::warning('wallet_reseller_suspended_charge', [
            'reseller_id' => $reseller->id,
            'cycle_started_at' => $cycleMarker,
            'wallet_balance' => $reseller->wallet_balance,
        ]);

        // Disable all active configs
        $disabledCount = $this->disableResellerConfigs($reseller, $cycleMarker);

        Log::info('wallet_disable_configs_count', [
            'reseller_id' => $reseller->id,
            'cycle_started_at' => $cycleMarker,
            'disabled_count' => $disabledCount,
        ]);
    }

    /**
     * Disable all active configs for a reseller.
     */
    protected function disableResellerConfigs(Reseller $reseller, string $cycleStartedAt): int
    {
        $configs = $reseller->configs()->where('status', 'active')->get();

        if ($configs->isEmpty()) {
            return 0;
        }

        Log::info("Disabling {$configs->count()} configs for suspended wallet reseller {$reseller->id}");

        $provisioner = new \Modules\Reseller\Services\ResellerProvisioner;
        $disabledCount = 0;

        foreach ($configs as $config) {
            try {
                // Apply rate limiting
                $provisioner->applyRateLimit($disabledCount);

                // Check if already disabled in this cycle to prevent double-disable
                $meta = $config->meta ?? [];
                if (isset($meta['disabled_by_wallet_suspension_cycle_at']) &&
                    $meta['disabled_by_wallet_suspension_cycle_at'] === $cycleStartedAt) {
                    Log::info("Config {$config->id} already disabled in this cycle, skipping");

                    continue;
                }

                // Disable on remote panel if possible
                $remoteResult = ['success' => false, 'attempts' => 0, 'last_error' => 'No panel configured'];

                if ($config->panel_id) {
                    $panel = Panel::find($config->panel_id);
                    if ($panel) {
                        $remoteResult = $provisioner->disableUser(
                            $panel->panel_type,
                            $panel->getCredentials(),
                            $config->panel_user_id
                        );
                    }
                }

                // Update local status with cycle tracking
                $meta['disabled_by_wallet_suspension'] = true;
                $meta['disabled_by_wallet_suspension_cycle_at'] = $cycleStartedAt;
                $meta['disabled_by_reseller_id'] = $reseller->id;
                $meta['disabled_at'] = now()->toIso8601String();

                $config->update([
                    'status' => 'disabled',
                    'disabled_at' => now(),
                    'meta' => $meta,
                ]);

                // Log event
                ResellerConfigEvent::create([
                    'reseller_config_id' => $config->id,
                    'type' => 'auto_disabled',
                    'meta' => [
                        'reason' => 'wallet_balance_exhausted',
                        'cycle_started_at' => $cycleStartedAt,
                        'remote_success' => $remoteResult['success'],
                        'attempts' => $remoteResult['attempts'],
                        'last_error' => $remoteResult['last_error'],
                    ],
                ]);

                // Create audit log
                AuditLog::log(
                    action: 'config_auto_disabled',
                    targetType: 'config',
                    targetId: $config->id,
                    reason: 'wallet_balance_exhausted',
                    meta: [
                        'reseller_id' => $reseller->id,
                        'cycle_started_at' => $cycleStartedAt,
                        'remote_success' => $remoteResult['success'],
                    ],
                    actorType: null,
                    actorId: null
                );

                $disabledCount++;
            } catch (\Exception $e) {
                Log::error("Error disabling config {$config->id}: ".$e->getMessage());
            }
        }

        Log::info("Disabled {$disabledCount} configs for wallet reseller {$reseller->id}");

        return $disabledCount;
    }

    /**
     * Perform final settlement for a single config before reset or deletion.
     * This ensures any outstanding usage is charged before the operation.
     *
     * Uses cache lock for idempotency to prevent double-charging within a short window.
     *
     * @param  ResellerConfig  $config  The config to settle
     * @param  string  $actionType  The action type ('reset_traffic' or 'delete_config')
     * @return array Result of the settlement operation
     */
    public function finalSettlementForConfig(ResellerConfig $config, string $actionType = 'reset_traffic'): array
    {
        $reseller = $config->reseller;

        // Skip non-wallet resellers
        if (! $reseller || $reseller->type !== 'wallet') {
            Log::info('final_settlement_skip_non_wallet', [
                'config_id' => $config->id,
                'reseller_id' => $reseller?->id,
                'action_type' => $actionType,
            ]);

            return [
                'status' => 'skipped',
                'reason' => 'not_wallet_type',
                'charged' => false,
                'cost' => 0,
                'charged_bytes' => 0,
            ];
        }

        // Skip if wallet charging is disabled
        if (! config('billing.wallet.charge_enabled', true)) {
            Log::info('final_settlement_skip_disabled', [
                'config_id' => $config->id,
                'reseller_id' => $reseller->id,
                'action_type' => $actionType,
            ]);

            return [
                'status' => 'skipped',
                'reason' => 'charging_disabled',
                'charged' => false,
                'cost' => 0,
                'charged_bytes' => 0,
            ];
        }

        // Idempotency guard using cache lock
        $lockKey = "final_settlement:{$config->id}:{$actionType}";
        $lockTtl = 30; // 30 seconds lock

        // Check if this config was recently settled for this action
        if (Cache::has($lockKey)) {
            Log::info('final_settlement_skip_idempotency', [
                'config_id' => $config->id,
                'reseller_id' => $reseller->id,
                'action_type' => $actionType,
                'lock_key' => $lockKey,
            ]);

            return [
                'status' => 'skipped',
                'reason' => 'idempotency_guard',
                'charged' => false,
                'cost' => 0,
                'charged_bytes' => 0,
            ];
        }

        // Acquire lock
        Cache::put($lockKey, true, $lockTtl);

        // Calculate outstanding usage using snapshot-based approach (same as chargeForReseller)
        // This ensures consistency with the hourly charging mechanism
        $currentTotalBytes = $this->calculateTotalUsageBytes($reseller);

        // Get the last snapshot to calculate delta
        $lastSnapshot = $reseller->usageSnapshots()
            ->orderBy('measured_at', 'desc')
            ->first();

        // Calculate delta (traffic used since last snapshot)
        $outstandingBytes = 0;
        if ($lastSnapshot) {
            $outstandingBytes = max(0, $currentTotalBytes - $lastSnapshot->total_bytes);
        } else {
            // First snapshot - all current usage is outstanding
            $outstandingBytes = $currentTotalBytes;
        }

        // Skip if no outstanding usage
        if ($outstandingBytes <= 0) {
            Log::info('final_settlement_skip_no_delta', [
                'config_id' => $config->id,
                'reseller_id' => $reseller->id,
                'action_type' => $actionType,
                'current_total_bytes' => $currentTotalBytes,
                'last_snapshot_bytes' => $lastSnapshot ? $lastSnapshot->total_bytes : 0,
            ]);

            return [
                'status' => 'skipped',
                'reason' => 'no_outstanding_usage',
                'charged' => false,
                'cost' => 0,
                'charged_bytes' => 0,
            ];
        }

        // Calculate cost
        $outstandingGB = $outstandingBytes / (1024 * 1024 * 1024);
        $pricePerGB = $reseller->getWalletPricePerGb();
        $cost = (int) ceil($outstandingGB * $pricePerGB);

        Log::info('final_settlement_calculation', [
            'config_id' => $config->id,
            'reseller_id' => $reseller->id,
            'action_type' => $actionType,
            'current_total_bytes' => $currentTotalBytes,
            'last_snapshot_bytes' => $lastSnapshot ? $lastSnapshot->total_bytes : 0,
            'outstanding_bytes' => $outstandingBytes,
            'outstanding_gb' => round($outstandingGB, 4),
            'price_per_gb' => $pricePerGB,
            'cost' => $cost,
        ]);

        // Apply charge within transaction
        return DB::transaction(function () use ($config, $reseller, $actionType, $outstandingBytes, $cost, $pricePerGB, $currentTotalBytes) {
            $oldBalance = $reseller->wallet_balance;
            $newBalance = $oldBalance - $cost;

            // Deduct from wallet
            $reseller->update(['wallet_balance' => $newBalance]);

            // Update config meta for tracking purposes
            $meta = $config->meta ?? [];
            $meta['last_settlement_at'] = now()->toIso8601String();
            $meta['last_settlement_action'] = $actionType;
            $meta['last_settlement_bytes'] = $outstandingBytes;
            $meta['last_settlement_cost'] = $cost;
            $config->update(['meta' => $meta]);

            // Create billing ledger entry
            BillingLedgerEntry::create([
                'reseller_id' => $reseller->id,
                'reseller_config_id' => $config->id,
                'action_type' => $actionType,
                'charged_bytes' => $outstandingBytes,
                'amount_charged' => $cost,
                'price_per_gb' => $pricePerGB,
                'wallet_balance_before' => $oldBalance,
                'wallet_balance_after' => $newBalance,
                'meta' => [
                    'config_external_username' => $config->external_username,
                    'usage_bytes_at_settlement' => $config->usage_bytes,
                    'settled_usage_bytes_at_settlement' => (int) data_get($config->meta, 'settled_usage_bytes', 0),
                    'timestamp' => now()->toIso8601String(),
                ],
            ]);

            // Create config event for audit trail
            ResellerConfigEvent::create([
                'reseller_config_id' => $config->id,
                'type' => 'final_settlement',
                'meta' => [
                    'action_type' => $actionType,
                    'charged_bytes' => $outstandingBytes,
                    'cost' => $cost,
                    'price_per_gb' => $pricePerGB,
                    'old_balance' => $oldBalance,
                    'new_balance' => $newBalance,
                ],
            ]);

            Log::info('final_settlement_applied', [
                'config_id' => $config->id,
                'reseller_id' => $reseller->id,
                'action_type' => $actionType,
                'charged_bytes' => $outstandingBytes,
                'cost' => $cost,
                'old_balance' => $oldBalance,
                'new_balance' => $newBalance,
            ]);

            // Create a snapshot to sync with the hourly charging system
            // This ensures chargeForReseller doesn't double-charge for this usage
            $currentTotalBytes = $this->calculateTotalUsageBytes($reseller);
            ResellerUsageSnapshot::create([
                'reseller_id' => $reseller->id,
                'total_bytes' => $currentTotalBytes,
                'measured_at' => now(),
                'meta' => [
                    'cycle_started_at' => now()->startOfMinute()->toIso8601String(),
                    'cycle_charge_applied' => true,
                    'delta_bytes' => $outstandingBytes,
                    'delta_gb' => round($outstandingBytes / (1024 * 1024 * 1024), 4),
                    'cost' => $cost,
                    'price_per_gb' => $pricePerGB,
                    'source' => "final_settlement:{$actionType}",
                    'config_id' => $config->id,
                ],
            ]);

            return [
                'status' => 'charged',
                'charged' => true,
                'cost' => $cost,
                'charged_bytes' => $outstandingBytes,
                'new_balance' => $newBalance,
            ];
        });
    }

    /**
     * Trigger immediate wallet charge from a panel action.
     * Logs the source as 'panel' for audit purposes.
     *
     * @param  Reseller  $reseller
     * @param  string  $action  The panel action that triggered the charge (e.g., 'edit', 'reset_traffic')
     * @return array
     */
    public function chargeFromPanel(Reseller $reseller, string $action = 'panel_action'): array
    {
        // Check if wallet charging is enabled
        if (! config('billing.wallet.charge_enabled', true)) {
            Log::info('wallet_charge_panel_skipped_disabled', [
                'reseller_id' => $reseller->id,
                'action' => $action,
            ]);

            return [
                'status' => 'skipped',
                'reason' => 'charging_disabled',
                'charged' => false,
                'cost' => 0,
                'suspended' => false,
            ];
        }

        Log::info('wallet_charge_panel_triggered', [
            'reseller_id' => $reseller->id,
            'action' => $action,
            'timestamp' => now()->toIso8601String(),
        ]);

        $result = $this->chargeForReseller($reseller, null, false, "panel:{$action}");

        // Log the result of the immediate charge
        if ($result['status'] === 'charged') {
            Log::info('wallet_charge_panel_applied', [
                'reseller_id' => $reseller->id,
                'action' => $action,
                'delta_bytes' => $result['delta_bytes'] ?? 0,
                'cost' => $result['cost'],
                'new_balance' => $result['new_balance'] ?? null,
            ]);
        }

        return $result;
    }
}
