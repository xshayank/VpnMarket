<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfigEvent;
use App\Models\ResellerUsageSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ChargeWalletResellersHourly extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reseller:charge-wallet-hourly';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Charge wallet-based resellers for recent traffic usage (minute-level)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Check if wallet charging is enabled
        if (! config('billing.wallet.charge_enabled', true)) {
            $this->info('Wallet charging is disabled via config');
            Log::info('Wallet charging skipped: disabled via WALLET_CHARGE_ENABLED');
            return Command::SUCCESS;
        }

        $cycleStartedAt = now()->startOfMinute()->toIso8601String();

        Log::info('wallet_charge_cycle_start', [
            'cycle_started_at' => $cycleStartedAt,
            'timestamp' => now()->toIso8601String(),
        ]);

        // Find all wallet-based resellers
        $walletResellers = Reseller::where('type', 'wallet')->get();

        $this->info("Found {$walletResellers->count()} wallet-based resellers");
        Log::info('wallet_charge_resellers_found', [
            'cycle_started_at' => $cycleStartedAt,
            'count' => $walletResellers->count(),
        ]);

        $charged = 0;
        $skipped = 0;
        $suspended = 0;
        $lockFailed = 0;
        $totalCost = 0;

        foreach ($walletResellers as $reseller) {
            try {
                $result = $this->chargeResellerWithSafeguards($reseller, $cycleStartedAt);

                if ($result['status'] === 'charged') {
                    $charged++;
                    $totalCost += $result['cost'];
                } elseif ($result['status'] === 'skipped') {
                    $skipped++;
                } elseif ($result['status'] === 'lock_failed') {
                    $lockFailed++;
                }

                if ($result['suspended']) {
                    $suspended++;
                }
            } catch (\Exception $e) {
                Log::error('wallet_charge_error', [
                    'reseller_id' => $reseller->id,
                    'cycle_started_at' => $cycleStartedAt,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $this->error("Error charging reseller {$reseller->id}: ".$e->getMessage());
            }
        }

        $summary = "Wallet charging completed: {$charged} charged, {$skipped} skipped (recent snapshot/threshold), {$lockFailed} lock failed, {$suspended} suspended, total cost: {$totalCost} تومان";
        $this->info($summary);

        Log::info('wallet_charge_cycle_complete', [
            'cycle_started_at' => $cycleStartedAt,
            'charged' => $charged,
            'skipped' => $skipped,
            'lock_failed' => $lockFailed,
            'suspended' => $suspended,
            'total_cost' => $totalCost,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Charge a single reseller with safeguards (idempotency, locking)
     * Public method to allow single-reseller command to reuse logic
     */
    public function chargeResellerWithSafeguards(Reseller $reseller, string $cycleStartedAt, bool $force = false, bool $dryRun = false): array
    {
        // Check idempotency window
        if (!$force && !$dryRun) {
            // Only consider snapshots where a charge was actually applied
            $lastSnapshot = $reseller->usageSnapshots()
                ->whereRaw("JSON_EXTRACT(meta, '$.cycle_charge_applied') = TRUE")
                ->orderBy('measured_at', 'desc')
                ->first();

            if ($lastSnapshot) {
                $secondsSinceLastSnapshot = now()->diffInSeconds($lastSnapshot->measured_at);
                $idempotencyWindow = config('billing.wallet.charge_idempotency_seconds', 50);

                if ($secondsSinceLastSnapshot < $idempotencyWindow) {
                    Log::info('wallet_charge_idempotent_skip', [
                        'reseller_id' => $reseller->id,
                        'cycle_started_at' => $cycleStartedAt,
                        'seconds_since_last_applied' => $secondsSinceLastSnapshot,
                        'idempotency_window' => $idempotencyWindow,
                        'last_snapshot_at' => $lastSnapshot->measured_at->toIso8601String(),
                        'last_snapshot_had_charge' => true,
                    ]);

                    return [
                        'status' => 'skipped',
                        'reason' => 'recent_snapshot',
                        'charged' => false,
                        'cost' => 0,
                        'suspended' => false,
                    ];
                }
            }
        }

        // Try to acquire lock (skip if dry run)
        if (!$dryRun) {
            $lockKey = config('billing.wallet.charge_lock_key_prefix', 'wallet_charge') . ":reseller:{$reseller->id}";
            $lockTtl = config('billing.wallet.charge_lock_ttl_seconds', 20);
            $lock = Cache::lock($lockKey, $lockTtl);

            if (!$lock->get()) {
                Log::warning('wallet_charge_lock_failed', [
                    'reseller_id' => $reseller->id,
                    'cycle_started_at' => $cycleStartedAt,
                    'lock_key' => $lockKey,
                ]);

                return [
                    'status' => 'lock_failed',
                    'reason' => 'concurrent_execution',
                    'charged' => false,
                    'cost' => 0,
                    'suspended' => false,
                ];
            }

            try {
                $result = $this->chargeReseller($reseller, $cycleStartedAt, $dryRun);
                return $result;
            } finally {
                $lock->release();
            }
        } else {
            // Dry run - no locking needed
            return $this->chargeReseller($reseller, $cycleStartedAt, $dryRun);
        }
    }

    /**
     * Charge a single wallet-based reseller
     */
    protected function chargeReseller(Reseller $reseller, string $cycleStartedAt, bool $dryRun = false): array
    {
        // Calculate total current usage from all configs
        $currentTotalBytes = $reseller->configs()
            ->get()
            ->sum(function ($config) {
                return $config->usage_bytes + (int) data_get($config->meta, 'settled_usage_bytes', 0);
            });

        // Get the last snapshot
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

        if ($deltaBytes <= 0) {
            Log::info('wallet_charge_skip_no_delta', [
                'reseller_id' => $reseller->id,
                'cycle_started_at' => $cycleStartedAt,
            ]);

            return [
                'status' => 'skipped',
                'reason' => 'no_usage_delta',
                'charged' => false,
                'cost' => 0,
                'suspended' => false,
            ];
        }

        if ($minimumDeltaBytes > 0 && $deltaBytes < $minimumDeltaBytes) {
            Log::info('wallet_charge_skip_below_threshold', [
                'reseller_id' => $reseller->id,
                'cycle_started_at' => $cycleStartedAt,
                'delta_bytes' => $deltaBytes,
                'minimum_delta_bytes' => $minimumDeltaBytes,
            ]);

            return [
                'status' => 'skipped',
                'reason' => 'below_minimum_delta',
                'charged' => false,
                'cost' => 0,
                'suspended' => false,
                'delta_bytes' => $deltaBytes,
            ];
        }

        // Convert bytes to GB and calculate cost
        $deltaGB = $deltaBytes / (1024 * 1024 * 1024);
        $pricePerGB = $reseller->getWalletPricePerGb();

        // Calculate cost in تومان (use ceiling to avoid undercharging)
        $cost = (int) ceil($deltaGB * $pricePerGB);

        Log::info('wallet_charge_cycle_start', [
            'reseller_id' => $reseller->id,
            'cycle_started_at' => $cycleStartedAt,
            'current_total_bytes' => $currentTotalBytes,
            'last_snapshot_total_bytes' => $lastSnapshot ? $lastSnapshot->total_bytes : 0,
            'last_snapshot_at' => $lastSnapshot ? $lastSnapshot->measured_at->toIso8601String() : null,
            'delta_bytes' => $deltaBytes,
            'delta_gb' => round($deltaGB, 4),
            'price_per_gb' => $pricePerGB,
            'cost_estimate' => $cost,
        ]);

        if ($dryRun) {
            Log::info('wallet_charge_dry_run', [
                'reseller_id' => $reseller->id,
                'cycle_started_at' => $cycleStartedAt,
                'delta_bytes' => $deltaBytes,
                'delta_gb' => round($deltaGB, 4),
                'cost_estimate' => $cost,
                'current_balance' => $reseller->wallet_balance,
                'balance_after_charge' => $reseller->wallet_balance - $cost,
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
        ]);

        // Check if reseller should be suspended
        $suspensionThreshold = config('billing.wallet.suspension_threshold', -1000);
        $wasSuspended = false;

        if ($newBalance <= $suspensionThreshold && ! $reseller->isSuspendedWallet()) {
            $this->suspendWalletReseller($reseller, $cycleStartedAt);
            $wasSuspended = true;

            Log::warning('wallet_reseller_suspended', [
                'reseller_id' => $reseller->id,
                'cycle_started_at' => $cycleStartedAt,
                'balance' => $newBalance,
                'threshold' => $suspensionThreshold,
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
     * Suspend a wallet-based reseller and disable all their configs
     */
    protected function suspendWalletReseller(Reseller $reseller, string $cycleStartedAt): void
    {
        // Update reseller status
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
                'cycle_started_at' => $cycleStartedAt,
            ],
            actorType: null,
            actorId: null  // System action
        );

        // Disable all active configs
        $this->disableResellerConfigs($reseller, $cycleStartedAt);

        $this->warn("Suspended reseller {$reseller->id} (balance: {$reseller->wallet_balance} تومان)");
    }

    /**
     * Disable all active configs for a reseller
     */
    protected function disableResellerConfigs(Reseller $reseller, string $cycleStartedAt): void
    {
        $configs = $reseller->configs()->where('status', 'active')->get();

        if ($configs->isEmpty()) {
            return;
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
    }
}
