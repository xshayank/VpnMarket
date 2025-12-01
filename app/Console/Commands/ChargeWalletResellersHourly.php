<?php

namespace App\Console\Commands;

use App\Models\Reseller;
use App\Services\Reseller\WalletChargingService;
use Carbon\Carbon;
use Illuminate\Console\Command;
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
     * The wallet charging service instance.
     */
    protected WalletChargingService $chargingService;

    /**
     * Create a new command instance.
     */
    public function __construct(WalletChargingService $chargingService)
    {
        parent::__construct();
        $this->chargingService = $chargingService;
    }

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

        // Find all wallet-based resellers (cursor to avoid skipping any during iteration)
        $walletResellersQuery = Reseller::where('type', 'wallet')->orderBy('id');
        $walletResellers = $walletResellersQuery->cursor();
        $walletResellerCount = $walletResellersQuery->count();

        $this->info("Found {$walletResellerCount} wallet-based resellers");
        Log::info('wallet_charge_resellers_found', [
            'cycle_started_at' => $cycleStartedAt,
            'count' => $walletResellerCount,
        ]);

        $charged = 0;
        $skipped = 0;
        $suspended = 0;
        $totalCost = 0;

        foreach ($walletResellers as $reseller) {
            try {
                $result = $this->chargeResellerWithSafeguards($reseller, $cycleStartedAt);

                if ($result['status'] === 'charged') {
                    $charged++;
                    $totalCost += $result['cost'];
                } elseif ($result['status'] === 'skipped') {
                    $skipped++;
                }

                if ($result['suspended']) {
                    $suspended++;
                    $this->warn("Suspended reseller {$reseller->id} (balance: {$reseller->wallet_balance} تومان)");
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

        $summary = "Wallet charging completed: {$charged} charged, {$skipped} skipped, {$suspended} suspended, total cost: {$totalCost} تومان";
        $this->info($summary);

        Log::info('wallet_charge_cycle_complete', [
            'cycle_started_at' => $cycleStartedAt,
            'charged' => $charged,
            'skipped' => $skipped,
            'suspended' => $suspended,
            'total_cost' => $totalCost,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Charge a single reseller with safeguards
     * Public method to allow single-reseller command to reuse logic
     */
    public function chargeResellerWithSafeguards(Reseller $reseller, string $cycleStartedAt, bool $dryRun = false): array
    {
        $referenceTime = Carbon::parse($cycleStartedAt);

        return $this->chargingService->chargeForReseller($reseller, $referenceTime, $dryRun, 'command');
    }
}
