<?php

namespace App\Console\Commands;

use App\Models\Reseller;
use App\Models\ResellerUsageSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class WalletDiagnoseCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wallet:diagnose';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Diagnose wallet reseller billing health and detect anomalies';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('=== Wallet Reseller Billing Health Check ===');
        $this->newLine();

        $anomaliesFound = false;
        $suspensionThreshold = config('billing.wallet.suspension_threshold', -1000);
        $firstTopupMin = config('billing.reseller.first_topup.wallet_min', 150000);

        // 1. Find active resellers with negative balance
        $this->info('1. Checking for active resellers with negative balance...');
        $negativeBalanceActive = Reseller::where('type', 'wallet')
            ->where('status', 'active')
            ->where('wallet_balance', '<', 0)
            ->get();

        if ($negativeBalanceActive->count() > 0) {
            $anomaliesFound = true;
            $this->warn("   ⚠ Found {$negativeBalanceActive->count()} active resellers with negative balance:");
            $this->table(
                ['ID', 'User ID', 'Balance', 'Status', 'Last Updated'],
                $negativeBalanceActive->map(fn($r) => [
                    $r->id,
                    $r->user_id,
                    number_format($r->wallet_balance) . ' تومان',
                    $r->status,
                    $r->updated_at->format('Y-m-d H:i:s'),
                ])->toArray()
            );
        } else {
            $this->info('   ✓ No active resellers with negative balance found');
        }
        $this->newLine();

        // 2. Find active resellers with balance below suspension threshold
        $this->info('2. Checking for active resellers with balance below suspension threshold...');
        $belowThresholdActive = Reseller::where('type', 'wallet')
            ->where('status', 'active')
            ->where('wallet_balance', '<=', $suspensionThreshold)
            ->get();

        if ($belowThresholdActive->count() > 0) {
            $anomaliesFound = true;
            $this->warn("   ⚠ Found {$belowThresholdActive->count()} active resellers with balance <= threshold ({$suspensionThreshold}):");
            $this->table(
                ['ID', 'User ID', 'Balance', 'Threshold', 'Status'],
                $belowThresholdActive->map(fn($r) => [
                    $r->id,
                    $r->user_id,
                    number_format($r->wallet_balance) . ' تومان',
                    number_format($suspensionThreshold) . ' تومان',
                    $r->status,
                ])->toArray()
            );
        } else {
            $this->info('   ✓ No active resellers below suspension threshold');
        }
        $this->newLine();

        // 3. Find suspended_wallet resellers with balance above threshold (stuck cases)
        $this->info('3. Checking for suspended_wallet resellers with positive balance...');
        $suspendedWithBalance = Reseller::where('type', 'wallet')
            ->where('status', 'suspended_wallet')
            ->where('wallet_balance', '>', $suspensionThreshold)
            ->get();

        if ($suspendedWithBalance->count() > 0) {
            $anomaliesFound = true;
            $this->warn("   ⚠ Found {$suspendedWithBalance->count()} suspended_wallet resellers with balance > threshold:");
            $this->table(
                ['ID', 'User ID', 'Balance', 'Threshold', 'Status'],
                $suspendedWithBalance->map(fn($r) => [
                    $r->id,
                    $r->user_id,
                    number_format($r->wallet_balance) . ' تومان',
                    number_format($suspensionThreshold) . ' تومان',
                    $r->status,
                ])->toArray()
            );
        } else {
            $this->info('   ✓ No suspended_wallet resellers with positive balance');
        }
        $this->newLine();

        // 4. Check recent charge activity (last 3 hours)
        $this->info('4. Checking recent charge activity (last 3 hours)...');
        $threeHoursAgo = now()->subHours(3);
        $recentCharges = ResellerUsageSnapshot::where('measured_at', '>=', $threeHoursAgo)
            ->whereRaw("JSON_EXTRACT(meta, '$.cycle_charge_applied') = TRUE")
            ->with('reseller')
            ->get();

        $this->info("   ℹ Found {$recentCharges->count()} charge snapshots in last 3 hours");
        
        $chargesByReseller = $recentCharges->groupBy('reseller_id');
        if ($chargesByReseller->count() > 0) {
            $this->table(
                ['Reseller ID', 'Charge Count', 'Latest Charge', 'Total Cost'],
                $chargesByReseller->map(function($snapshots, $resellerId) {
                    $totalCost = $snapshots->sum(fn($s) => $s->meta['cost'] ?? 0);
                    $latestCharge = $snapshots->sortByDesc('measured_at')->first();
                    return [
                        $resellerId,
                        $snapshots->count(),
                        $latestCharge->measured_at->format('Y-m-d H:i:s'),
                        number_format($totalCost) . ' تومان',
                    ];
                })->toArray()
            );
        }
        $this->newLine();

        // 5. Count snapshots per reseller to identify potential idempotency issues
        $this->info('5. Checking snapshot counts per reseller (top 10)...');
        $snapshotCounts = DB::table('reseller_usage_snapshots')
            ->select('reseller_id', DB::raw('COUNT(*) as snapshot_count'))
            ->groupBy('reseller_id')
            ->orderByDesc('snapshot_count')
            ->limit(10)
            ->get();

        if ($snapshotCounts->count() > 0) {
            $this->table(
                ['Reseller ID', 'Total Snapshots', 'Avg per Day (est)'],
                $snapshotCounts->map(function($row) {
                    $reseller = Reseller::find($row->reseller_id);
                    $daysSinceCreated = $reseller ? now()->diffInDays($reseller->created_at) : 1;
                    $avgPerDay = $daysSinceCreated > 0 ? round($row->snapshot_count / $daysSinceCreated, 2) : $row->snapshot_count;
                    return [
                        $row->reseller_id,
                        $row->snapshot_count,
                        $avgPerDay,
                    ];
                })->toArray()
            );
        }
        $this->newLine();

        // 6. Last charge run timestamp (estimate from most recent snapshot with charge)
        $this->info('6. Checking last charge run timestamp...');
        $lastChargeSnapshot = ResellerUsageSnapshot::whereRaw("JSON_EXTRACT(meta, '$.cycle_charge_applied') = TRUE")
            ->orderBy('measured_at', 'desc')
            ->first();

        if ($lastChargeSnapshot) {
            $minutesAgo = now()->diffInMinutes($lastChargeSnapshot->measured_at);
            $this->info("   ℹ Last charge run: {$lastChargeSnapshot->measured_at->format('Y-m-d H:i:s')} ({$minutesAgo} minutes ago)");
            
            if ($minutesAgo > 90) {
                $anomaliesFound = true;
                $this->warn("   ⚠ WARNING: Last charge was more than 90 minutes ago!");
            }
        } else {
            $this->warn('   ⚠ No charge snapshots found (this might be expected for new installations)');
        }
        $this->newLine();

        // Summary
        $this->info('=== Summary ===');
        if ($anomaliesFound) {
            $this->error('✗ Anomalies detected! Review the warnings above.');
            return Command::FAILURE;
        } else {
            $this->info('✓ No anomalies detected. Wallet billing appears healthy.');
            return Command::SUCCESS;
        }
    }
}
