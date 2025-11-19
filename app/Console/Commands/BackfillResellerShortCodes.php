<?php

namespace App\Console\Commands;

use App\Models\Reseller;
use Illuminate\Console\Command;

class BackfillResellerShortCodes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'configs:backfill-short-codes';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill short_code for resellers using base36 encoding';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting backfill of reseller short codes...');

        $resellers = Reseller::whereNull('short_code')->get();
        $totalCount = $resellers->count();

        if ($totalCount === 0) {
            $this->info('No resellers found without short_code. Nothing to backfill.');
            return self::SUCCESS;
        }

        $this->info("Found {$totalCount} resellers without short_code.");

        $progressBar = $this->output->createProgressBar($totalCount);
        $progressBar->start();

        $successCount = 0;
        $failureCount = 0;

        foreach ($resellers as $reseller) {
            try {
                $shortCode = $this->generateShortCode($reseller->id);
                $reseller->update(['short_code' => $shortCode]);
                $successCount++;
            } catch (\Exception $e) {
                $this->error("\nFailed to update reseller {$reseller->id}: {$e->getMessage()}");
                $failureCount++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        $this->info("Backfill completed!");
        $this->info("Successfully updated: {$successCount}");
        
        if ($failureCount > 0) {
            $this->warn("Failed: {$failureCount}");
        }

        return self::SUCCESS;
    }

    /**
     * Generate a short code from reseller ID using base36
     *
     * @param int $resellerId
     * @return string
     */
    protected function generateShortCode(int $resellerId): string
    {
        // Convert to base36 (0-9, a-z)
        $base36 = strtolower(base_convert((string)$resellerId, 10, 36));
        
        // Pad to at least 3 characters
        return str_pad($base36, 3, '0', STR_PAD_LEFT);
    }
}
