<?php

namespace App\Console\Commands;

use App\Models\Reseller;
use App\Services\MultiPanelUsageAggregator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RecalculateResellerUsageCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'resellers:recalc-usage
                            {--reseller_id= : Process a specific reseller by ID}
                            {--all : Process all resellers}
                            {--traffic : Process only traffic-based resellers}
                            {--wallet : Process only wallet-based resellers}
                            {--chunk=200 : Number of resellers to process in each chunk}
                            {--force : Force recalculation even if recently updated}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalculate usage for resellers across all assigned panels';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (!$this->option('all') && !$this->option('reseller_id')) {
            $this->error('You must specify either --all or --reseller_id=<id>');
            return self::FAILURE;
        }

        $aggregator = new MultiPanelUsageAggregator();

        if ($resellerId = $this->option('reseller_id')) {
            return $this->processSingleReseller($resellerId, $aggregator);
        }

        return $this->processMultipleResellers($aggregator);
    }

    /**
     * Process a single reseller
     */
    protected function processSingleReseller(int $resellerId, MultiPanelUsageAggregator $aggregator): int
    {
        $reseller = Reseller::find($resellerId);

        if (!$reseller) {
            $this->error("Reseller {$resellerId} not found");
            return self::FAILURE;
        }

        $this->info("Processing reseller {$reseller->id} ({$reseller->user->name})");

        try {
            $result = $aggregator->aggregateUsage($reseller);
            $aggregator->updateResellerTotalUsage($reseller, $result['total_usage_bytes']);

            $this->info("✓ Reseller {$reseller->id} processed successfully");
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Total Usage (GB)', round($result['total_usage_bytes'] / (1024 ** 3), 2)],
                    ['Remaining (GB)', round($result['remaining_bytes'] / (1024 ** 3), 2)],
                    ['Panels Processed', $result['panels_processed']],
                ]
            );

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to process reseller {$reseller->id}: {$e->getMessage()}");
            Log::error("Recalc usage command failed for reseller {$reseller->id}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return self::FAILURE;
        }
    }

    /**
     * Process multiple resellers
     */
    protected function processMultipleResellers(MultiPanelUsageAggregator $aggregator): int
    {
        $query = Reseller::query();

        // Apply type filters
        if ($this->option('traffic')) {
            $query->where('type', Reseller::TYPE_TRAFFIC);
            $this->info('Filtering: traffic-based resellers only');
        } elseif ($this->option('wallet')) {
            $query->where('type', Reseller::TYPE_WALLET);
            $this->info('Filtering: wallet-based resellers only');
        } else {
            // Default: both traffic and wallet (exclude plan-based)
            $query->whereIn('type', [Reseller::TYPE_TRAFFIC, Reseller::TYPE_WALLET]);
        }

        $totalResellers = $query->count();
        $this->info("Found {$totalResellers} resellers to process");

        if ($totalResellers === 0) {
            $this->warn('No resellers found matching criteria');
            return self::SUCCESS;
        }

        $chunkSize = (int) $this->option('chunk');
        $processed = 0;
        $succeeded = 0;
        $failed = 0;

        $bar = $this->output->createProgressBar($totalResellers);
        $bar->start();

        $query->chunk($chunkSize, function ($resellers) use ($aggregator, &$processed, &$succeeded, &$failed, $bar) {
            foreach ($resellers as $reseller) {
                try {
                    $result = $aggregator->aggregateUsage($reseller);
                    $aggregator->updateResellerTotalUsage($reseller, $result['total_usage_bytes']);
                    $succeeded++;
                } catch (\Exception $e) {
                    $failed++;
                    Log::error("Recalc usage failed for reseller {$reseller->id}", [
                        'error' => $e->getMessage(),
                    ]);
                }

                $processed++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info("Processing complete!");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Processed', $processed],
                ['Succeeded', $succeeded],
                ['Failed', $failed],
            ]
        );

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
