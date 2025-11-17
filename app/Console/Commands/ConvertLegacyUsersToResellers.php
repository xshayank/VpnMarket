<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Reseller;
use App\Models\Panel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConvertLegacyUsersToResellers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reseller:convert-legacy-users 
                            {--dry-run : Run without making changes}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Convert legacy users without reseller records to wallet-based resellers';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $isDryRun = $this->option('dry-run');
        $force = $this->option('force');

        $this->info('=== Legacy User to Reseller Conversion ===');
        $this->info('');

        // Get users without reseller records
        $usersWithoutReseller = User::doesntHave('reseller')
            ->whereNotIn('id', function($query) {
                $query->select('user_id')->from('resellers');
            })
            ->get();

        if ($usersWithoutReseller->isEmpty()) {
            $this->info('✓ No legacy users found. All users already have reseller records.');
            return 0;
        }

        $this->info("Found {$usersWithoutReseller->count()} users without reseller records.");
        $this->newLine();

        // Get a default panel for assignment
        $defaultPanel = Panel::where('is_active', true)->first();
        
        if (!$defaultPanel) {
            $this->error('✗ No active panels found. Please create at least one active panel first.');
            return 1;
        }

        $this->info("Default panel for assignment: {$defaultPanel->name} (ID: {$defaultPanel->id})");
        $this->newLine();

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        // Get threshold for determining active status
        $walletThreshold = config('billing.reseller.first_topup.wallet_min', 150000);
        $this->info("Wallet threshold for active status: " . number_format($walletThreshold) . " تومان");
        $this->newLine();

        // Preview conversion
        $this->table(
            ['User ID', 'Email', 'Balance', 'New Status', 'Type'],
            $usersWithoutReseller->map(function ($user) use ($walletThreshold) {
                $balance = (int) $user->balance;
                $status = $balance >= $walletThreshold ? 'active' : 'suspended_wallet';
                
                return [
                    $user->id,
                    $user->email,
                    number_format($balance) . ' تومان',
                    $status,
                    'wallet'
                ];
            })->toArray()
        );

        $this->newLine();

        // Confirm before proceeding
        if (!$isDryRun && !$force) {
            if (!$this->confirm('Do you want to proceed with the conversion?')) {
                $this->info('Conversion cancelled.');
                return 0;
            }
        }

        if ($isDryRun) {
            $this->info('✓ Dry run complete. No changes were made.');
            return 0;
        }

        // Perform conversion
        $this->info('Starting conversion...');
        $this->newLine();

        $converted = 0;
        $failed = 0;

        $progressBar = $this->output->createProgressBar($usersWithoutReseller->count());
        $progressBar->start();

        foreach ($usersWithoutReseller as $user) {
            try {
                DB::transaction(function () use ($user, $defaultPanel, $walletThreshold) {
                    $balance = (int) $user->balance;
                    $status = $balance >= $walletThreshold ? 'active' : 'suspended_wallet';

                    Reseller::create([
                        'user_id' => $user->id,
                        'type' => Reseller::TYPE_WALLET,
                        'status' => $status,
                        'primary_panel_id' => $defaultPanel->id,
                        'wallet_balance' => $balance,
                        'max_configs' => config('billing.reseller.config_limits.wallet', 1000),
                        'traffic_total_bytes' => 0,
                        'traffic_used_bytes' => 0,
                        'meta' => [
                            'converted_from_legacy' => true,
                            'converted_at' => now()->toIso8601String(),
                            'original_balance' => $balance,
                        ],
                    ]);

                    Log::info('legacy_user_converted', [
                        'user_id' => $user->id,
                        'email' => $user->email,
                        'balance' => $balance,
                        'status' => $status,
                        'panel_id' => $defaultPanel->id,
                    ]);
                });

                $converted++;
            } catch (\Exception $e) {
                $failed++;
                Log::error('Failed to convert legacy user', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'error' => $e->getMessage(),
                ]);
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Summary
        $this->info('=== Conversion Summary ===');
        $this->info("✓ Successfully converted: {$converted}");
        
        if ($failed > 0) {
            $this->error("✗ Failed conversions: {$failed}");
            $this->warn('  Check logs for details about failed conversions.');
        }

        $this->newLine();
        $this->info('Conversion complete!');

        return $failed > 0 ? 1 : 0;
    }
}
