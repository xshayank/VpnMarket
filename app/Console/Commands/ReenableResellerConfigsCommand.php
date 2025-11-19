<?php

namespace App\Console\Commands;

use App\Jobs\ReenableResellerConfigsJob;
use App\Models\Reseller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReenableResellerConfigsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'resellers:reenable-configs
                            {--reseller_id= : Process a specific reseller by ID}
                            {--all : Process all eligible resellers}
                            {--batch=100 : Maximum number of resellers to process}
                            {--reason=wallet : Suspension reason (wallet or traffic)}
                            {--queue : Queue the jobs instead of running synchronously}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Re-enable configs that were auto-disabled due to reseller suspension';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (!$this->option('all') && !$this->option('reseller_id')) {
            $this->error('You must specify either --all or --reseller_id=<id>');
            return self::FAILURE;
        }

        $reason = $this->option('reason');
        if (!in_array($reason, ['wallet', 'traffic'])) {
            $this->error('Reason must be either "wallet" or "traffic"');
            return self::FAILURE;
        }

        if ($resellerId = $this->option('reseller_id')) {
            return $this->processSingleReseller($resellerId, $reason);
        }

        return $this->processMultipleResellers($reason);
    }

    /**
     * Process a single reseller
     */
    protected function processSingleReseller(int $resellerId, string $reason): int
    {
        $reseller = Reseller::find($resellerId);

        if (!$reseller) {
            $this->error("Reseller {$resellerId} not found");
            return self::FAILURE;
        }

        $this->info("Processing reseller {$reseller->id} ({$reseller->user->name})");

        try {
            if ($this->option('queue')) {
                ReenableResellerConfigsJob::dispatch($reseller, $reason);
                $this->info("✓ Re-enable job queued for reseller {$reseller->id}");
            } else {
                $job = new ReenableResellerConfigsJob($reseller, $reason);
                $job->handle();
                $this->info("✓ Re-enable completed for reseller {$reseller->id}");
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to process reseller {$reseller->id}: {$e->getMessage()}");
            Log::error("Reenable configs command failed for reseller {$reseller->id}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return self::FAILURE;
        }
    }

    /**
     * Process multiple resellers
     */
    protected function processMultipleResellers(string $reason): int
    {
        // Find resellers that are currently active and have suspended configs
        $metaKey = $reason === 'wallet' 
            ? 'disabled_by_wallet_suspension' 
            : 'disabled_by_traffic_suspension';

        // Get resellers with disabled configs matching the suspension reason
        $query = Reseller::whereHas('configs', function ($query) use ($metaKey) {
            $query->where('status', 'disabled')
                ->whereRaw("JSON_EXTRACT(meta, '$.{$metaKey}') = TRUE");
        });

        $batch = (int) $this->option('batch');
        $resellers = $query->limit($batch)->get();

        $totalResellers = $resellers->count();
        $this->info("Found {$totalResellers} resellers with {$reason}-suspended configs");

        if ($totalResellers === 0) {
            $this->warn('No resellers found with suspended configs');
            return self::SUCCESS;
        }

        $processed = 0;
        $queued = 0;
        $failed = 0;
        $useQueue = $this->option('queue');

        $bar = $this->output->createProgressBar($totalResellers);
        $bar->start();

        foreach ($resellers as $reseller) {
            try {
                if ($useQueue) {
                    ReenableResellerConfigsJob::dispatch($reseller, $reason);
                    $queued++;
                } else {
                    $job = new ReenableResellerConfigsJob($reseller, $reason);
                    $job->handle();
                }
                $processed++;
            } catch (\Exception $e) {
                $failed++;
                Log::error("Reenable configs failed for reseller {$reseller->id}", [
                    'error' => $e->getMessage(),
                ]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Processing complete!");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Resellers', $totalResellers],
                ['Processed', $processed],
                [$useQueue ? 'Queued' : 'Completed', $queued > 0 ? $queued : $processed],
                ['Failed', $failed],
            ]
        );

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
