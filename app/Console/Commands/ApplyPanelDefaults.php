<?php

namespace App\Console\Commands;

use App\Models\Panel;
use App\Models\Reseller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ApplyPanelDefaults extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reseller:apply-panel-defaults 
                            {--dry : Display changes without applying them}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Apply panel default node/service IDs to existing resellers missing allowed lists';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry');
        $force = $this->option('force');

        $this->info('Panel Defaults Backfill Command');
        $this->info('================================');
        $this->newLine();

        if ($dryRun) {
            $this->warn('🔍 DRY RUN MODE - No changes will be applied');
            $this->newLine();
        }

        // Confirm before proceeding
        if (!$dryRun && !$force) {
            if (!$this->confirm('This will apply panel defaults to existing resellers. Continue?')) {
                $this->info('Operation cancelled.');
                return self::SUCCESS;
            }
            $this->newLine();
        }

        // Get all resellers with primary panel
        $resellers = Reseller::whereNotNull('primary_panel_id')->get();
        
        $stats = [
            'total' => $resellers->count(),
            'applied_eylandoo' => 0,
            'applied_marzneshin' => 0,
            'skipped_has_ids' => 0,
            'skipped_no_defaults' => 0,
            'errors' => 0,
        ];

        $this->info("Found {$stats['total']} resellers with primary panels");
        $this->newLine();

        $progressBar = $this->output->createProgressBar($stats['total']);
        $progressBar->start();

        foreach ($resellers as $reseller) {
            try {
                $panel = $reseller->primaryPanel;
                
                if (!$panel) {
                    $stats['errors']++;
                    $progressBar->advance();
                    continue;
                }

                $panelType = strtolower(trim($panel->panel_type ?? ''));
                $applied = false;

                // Handle Eylandoo panels
                if ($panelType === 'eylandoo') {
                    // Skip if reseller already has allowed node IDs
                    if (!empty($reseller->eylandoo_allowed_node_ids)) {
                        $stats['skipped_has_ids']++;
                        $progressBar->advance();
                        continue;
                    }

                    $defaultNodes = $panel->getRegistrationDefaultNodeIds();
                    
                    if (empty($defaultNodes)) {
                        $stats['skipped_no_defaults']++;
                        $progressBar->advance();
                        continue;
                    }

                    if (!$dryRun) {
                        $reseller->eylandoo_allowed_node_ids = $defaultNodes;
                        $reseller->save();

                        Log::info('defaults_backfill_applied', [
                            'reseller_id' => $reseller->id,
                            'panel_id' => $panel->id,
                            'panel_type' => 'eylandoo',
                            'node_count' => count($defaultNodes),
                            'node_ids' => $defaultNodes,
                        ]);
                    }

                    $stats['applied_eylandoo']++;
                    $applied = true;
                }

                // Handle Marzneshin panels
                if ($panelType === 'marzneshin') {
                    // Skip if reseller already has allowed service IDs
                    if (!empty($reseller->marzneshin_allowed_service_ids)) {
                        $stats['skipped_has_ids']++;
                        $progressBar->advance();
                        continue;
                    }

                    $defaultServices = $panel->getRegistrationDefaultServiceIds();
                    
                    if (empty($defaultServices)) {
                        $stats['skipped_no_defaults']++;
                        $progressBar->advance();
                        continue;
                    }

                    if (!$dryRun) {
                        $reseller->marzneshin_allowed_service_ids = $defaultServices;
                        $reseller->save();

                        Log::info('defaults_backfill_applied', [
                            'reseller_id' => $reseller->id,
                            'panel_id' => $panel->id,
                            'panel_type' => 'marzneshin',
                            'service_count' => count($defaultServices),
                            'service_ids' => $defaultServices,
                        ]);
                    }

                    $stats['applied_marzneshin']++;
                    $applied = true;
                }

                // Skip other panel types
                if (!$applied && !in_array($panelType, ['eylandoo', 'marzneshin'])) {
                    $stats['skipped_no_defaults']++;
                }

            } catch (\Exception $e) {
                $stats['errors']++;
                Log::error('defaults_backfill_error', [
                    'reseller_id' => $reseller->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Display summary
        $this->info('Backfill Summary');
        $this->info('================');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Resellers Processed', $stats['total']],
                ['Eylandoo Defaults Applied', $stats['applied_eylandoo']],
                ['Marzneshin Defaults Applied', $stats['applied_marzneshin']],
                ['Skipped (Already Has IDs)', $stats['skipped_has_ids']],
                ['Skipped (No Panel Defaults)', $stats['skipped_no_defaults']],
                ['Errors', $stats['errors']],
            ]
        );

        Log::info('defaults_backfill_summary', array_merge(['dry_run' => $dryRun], $stats));

        if ($dryRun) {
            $this->newLine();
            $this->warn('This was a DRY RUN. No changes were applied.');
            $this->info('Run without --dry flag to apply changes.');
        } elseif ($stats['applied_eylandoo'] + $stats['applied_marzneshin'] > 0) {
            $this->newLine();
            $this->info('✅ Defaults applied successfully!');
        } else {
            $this->newLine();
            $this->info('ℹ️  No resellers needed defaults applied.');
        }

        return self::SUCCESS;
    }
}
