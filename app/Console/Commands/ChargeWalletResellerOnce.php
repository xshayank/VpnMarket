<?php

namespace App\Console\Commands;

use App\Models\Reseller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ChargeWalletResellerOnce extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reseller:charge-wallet-once 
                            {--reseller= : The ID of the reseller to charge}
                            {--dry-run : Show cost estimate without applying charges}
                            {--force : Force charge even if within idempotency window}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Charge a single wallet-based reseller (supports dry-run and force modes)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $resellerId = $this->option('reseller');
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        if (!$resellerId) {
            $this->error('Error: --reseller option is required');
            $this->info('');
            $this->info('Usage:');
            $this->info('  php artisan reseller:charge-wallet-once --reseller=<id>');
            $this->info('  php artisan reseller:charge-wallet-once --reseller=<id> --dry-run');
            $this->info('  php artisan reseller:charge-wallet-once --reseller=<id> --force');
            $this->info('');
            $this->info('Options:');
            $this->info('  --reseller=<id>  Required. The ID of the reseller to charge');
            $this->info('  --dry-run        Show cost estimate without applying charges or creating snapshot');
            $this->info('  --force          Force charge even if within idempotency window');
            
            return Command::FAILURE;
        }

        // Find the reseller
        $reseller = Reseller::find($resellerId);

        if (!$reseller) {
            $this->error("Error: Reseller with ID {$resellerId} not found");
            return Command::FAILURE;
        }

        if ($reseller->type !== 'wallet') {
            $this->error("Error: Reseller {$resellerId} is not a wallet-based reseller (type: {$reseller->type})");
            return Command::FAILURE;
        }

        $this->info("Processing wallet charge for reseller {$resellerId}...");
        if ($dryRun) {
            $this->info('[DRY RUN MODE - No changes will be made]');
        }
        if ($force) {
            $this->info('[FORCE MODE - Bypassing idempotency window]');
        }
        $this->info('');

        $cycleHour = now()->startOfHour()->toIso8601String();

        try {
            // Use the shared logic from ChargeWalletResellersHourly
            $hourlyCommand = new ChargeWalletResellersHourly();
            $result = $hourlyCommand->chargeResellerWithSafeguards($reseller, $cycleHour, $force, $dryRun);

            // Display results in a table
            $this->displayResults($reseller, $result, $dryRun);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");
            Log::error('wallet_charge_once_error', [
                'reseller_id' => $resellerId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        }
    }

    /**
     * Display charge results in a formatted table
     */
    protected function displayResults(Reseller $reseller, array $result, bool $dryRun): void
    {
        // Calculate current usage
        $currentTotalBytes = $reseller->configs()
            ->get()
            ->sum(function ($config) {
                return $config->usage_bytes + (int) data_get($config->meta, 'settled_usage_bytes', 0);
            });

        $lastSnapshot = $reseller->usageSnapshots()
            ->orderBy('measured_at', 'desc')
            ->first();

        $lastSnapshotBytes = $lastSnapshot ? $lastSnapshot->total_bytes : 0;
        $deltaBytes = $result['delta_bytes'] ?? max(0, $currentTotalBytes - $lastSnapshotBytes);
        $deltaGB = $deltaBytes / (1024 * 1024 * 1024);

        // Display summary table
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info('                 WALLET CHARGE SUMMARY');
        $this->info('═══════════════════════════════════════════════════════════════');
        
        $tableData = [
            ['Reseller ID', $reseller->id],
            ['Reseller Name', $reseller->name ?? 'N/A'],
            ['Type', 'Wallet-based'],
            ['Current Total Usage', number_format($currentTotalBytes) . ' bytes'],
            ['Last Snapshot Usage', number_format($lastSnapshotBytes) . ' bytes'],
            ['Last Snapshot Time', $lastSnapshot ? $lastSnapshot->measured_at->format('Y-m-d H:i:s') : 'Never'],
            ['─────────────────────', '───────────────────────────────────────'],
            ['Delta Bytes', number_format($deltaBytes) . ' bytes'],
            ['Delta GB', number_format($deltaGB, 4) . ' GB'],
            ['Price per GB', number_format($reseller->getWalletPricePerGb()) . ' تومان'],
            ['Cost', number_format($result['cost']) . ' تومان'],
            ['─────────────────────', '───────────────────────────────────────'],
            ['Current Balance', number_format($reseller->wallet_balance) . ' تومان'],
        ];

        if (isset($result['balance_after_charge'])) {
            $tableData[] = ['Balance After Charge', number_format($result['balance_after_charge']) . ' تومان'];
        } elseif (isset($result['new_balance'])) {
            $tableData[] = ['New Balance', number_format($result['new_balance']) . ' تومان'];
        }

        $this->table(['Field', 'Value'], $tableData);

        // Display status
        $this->info('');
        if ($result['status'] === 'skipped') {
            $this->warn('⚠ SKIPPED: Recent snapshot exists within idempotency window');
            $this->info("  Use --force to bypass idempotency check");
        } elseif ($result['status'] === 'lock_failed') {
            $this->warn('⚠ LOCK FAILED: Another process is currently charging this reseller');
        } elseif ($result['status'] === 'dry_run') {
            $this->info('✓ DRY RUN COMPLETE: No changes were made');
            $this->info('  Remove --dry-run flag to apply charges');
        } elseif ($result['status'] === 'charged') {
            $this->info('✓ CHARGE APPLIED SUCCESSFULLY');
            if (isset($result['snapshot_id'])) {
                $this->info("  Snapshot ID: {$result['snapshot_id']}");
            }
            if ($result['suspended']) {
                $this->warn('  Reseller has been SUSPENDED due to low balance');
            }
        }
        
        $this->info('═══════════════════════════════════════════════════════════════');
    }
}
