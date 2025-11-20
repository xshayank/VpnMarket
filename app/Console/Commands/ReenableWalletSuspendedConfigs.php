<?php

namespace App\Console\Commands;

use App\Jobs\ReenableResellerConfigsJob;
use App\Models\Reseller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReenableWalletSuspendedConfigs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reseller:reenable-wallet-disabled';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Find eligible wallet resellers and queue re-enable jobs for their suspended configs';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Check if auto re-enable is enabled
        if (!config('billing.wallet.auto_reenable_enabled', true)) {
            $this->info('Wallet auto re-enable is disabled via config');
            Log::info('Wallet auto re-enable skipped: disabled via WALLET_AUTO_REENABLE_ENABLED');
            return Command::SUCCESS;
        }

        $suspensionThreshold = config('billing.wallet.suspension_threshold', -1000);

        // Find wallet resellers that are:
        // 1. Active status
        // 2. Wallet balance > suspension threshold
        // 3. Have disabled configs with wallet suspension meta flag
        $eligibleResellers = Reseller::where('type', 'wallet')
            ->where('status', 'active')
            ->where('wallet_balance', '>', $suspensionThreshold)
            ->whereHas('configs', function ($query) {
                $query->where('status', 'disabled')
                    ->whereRaw("JSON_EXTRACT(meta, '$.disabled_by_wallet_suspension') = TRUE");
            })
            ->get();

        $count = $eligibleResellers->count();
        
        if ($count === 0) {
            $this->info('No eligible wallet resellers found for re-enable');
            Log::info('wallet_reenable_no_candidates', [
                'suspension_threshold' => $suspensionThreshold,
            ]);
            return Command::SUCCESS;
        }

        $this->info("Found {$count} eligible wallet resellers for re-enable");
        
        Log::info('wallet_reenable_batch_start', [
            'candidate_count' => $count,
            'suspension_threshold' => $suspensionThreshold,
        ]);

        $queued = 0;
        foreach ($eligibleResellers as $reseller) {
            try {
                ReenableResellerConfigsJob::dispatch($reseller, 'wallet');
                $queued++;
                
                Log::info('wallet_reenable_candidate_found', [
                    'reseller_id' => $reseller->id,
                    'wallet_balance' => $reseller->wallet_balance,
                    'status' => $reseller->status,
                ]);
            } catch (\Exception $e) {
                Log::error('wallet_reenable_dispatch_failed', [
                    'reseller_id' => $reseller->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Queued {$queued} re-enable jobs");
        
        Log::info('wallet_reenable_batch_complete', [
            'queued' => $queued,
            'total_candidates' => $count,
        ]);

        return Command::SUCCESS;
    }
}
