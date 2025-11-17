<?php

namespace App\Console\Commands;

use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\Panel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BackfillResellerPrimaryPanel extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'resellers:backfill-primary-panel {--dry : Dry run - show what would be changed without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill primary_panel_id for resellers from configs, meta, or other sources';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry');
        
        if ($dryRun) {
            $this->info('🔍 DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        $resellers = Reseller::whereNull('primary_panel_id')->get();
        
        if ($resellers->isEmpty()) {
            $this->info('✅ All resellers already have primary_panel_id set. Nothing to backfill.');
            return Command::SUCCESS;
        }

        $this->info("Found {$resellers->count()} resellers without primary_panel_id");
        $this->newLine();

        $fixed = 0;
        $skipped = 0;
        $ambiguous = 0;

        foreach ($resellers as $reseller) {
            $this->line("Processing Reseller #{$reseller->id} (User: {$reseller->user->email})");

            // Strategy 1: Check if panel_id column has a value (from old data)
            if ($reseller->getRawOriginal('panel_id')) {
                $panelId = $reseller->getRawOriginal('panel_id');
                if (Panel::find($panelId)) {
                    $this->info("  ✓ Found panel_id={$panelId} in old column");
                    if (!$dryRun) {
                        $reseller->update([
                            'primary_panel_id' => $panelId,
                            'panel_id' => $panelId,
                        ]);
                        Log::info("Backfilled primary_panel_id for reseller {$reseller->id} from panel_id column", [
                            'reseller_id' => $reseller->id,
                            'panel_id' => $panelId,
                        ]);
                    }
                    $fixed++;
                    continue;
                }
            }

            // Strategy 2: Get most common panel_id from reseller's configs
            $panelId = $this->getMostCommonPanelFromConfigs($reseller);
            if ($panelId) {
                $this->info("  ✓ Found panel_id={$panelId} from configs (most common)");
                if (!$dryRun) {
                    $reseller->update([
                        'primary_panel_id' => $panelId,
                        'panel_id' => $panelId,
                    ]);
                    Log::info("Backfilled primary_panel_id for reseller {$reseller->id} from configs", [
                        'reseller_id' => $reseller->id,
                        'panel_id' => $panelId,
                        'source' => 'configs',
                    ]);
                }
                $fixed++;
                continue;
            }

            // Strategy 3: Check reseller meta for panel reference
            if ($reseller->meta && isset($reseller->meta['panel_id'])) {
                $panelId = $reseller->meta['panel_id'];
                if (Panel::find($panelId)) {
                    $this->info("  ✓ Found panel_id={$panelId} from meta");
                    if (!$dryRun) {
                        $reseller->update([
                            'primary_panel_id' => $panelId,
                            'panel_id' => $panelId,
                        ]);
                        Log::info("Backfilled primary_panel_id for reseller {$reseller->id} from meta", [
                            'reseller_id' => $reseller->id,
                            'panel_id' => $panelId,
                        ]);
                    }
                    $fixed++;
                    continue;
                }
            }

            // Strategy 4: If reseller has no configs and is plan-based, they may not need a panel
            if ($reseller->type === 'plan') {
                $this->line("  ⊘ Skipped - Plan-based reseller with no panel reference");
                $skipped++;
                continue;
            }

            // No source found
            $this->warn("  ⚠ Could not determine panel for reseller #{$reseller->id}");
            $ambiguous++;
        }

        $this->newLine();
        $this->info('Summary:');
        $this->table(
            ['Status', 'Count'],
            [
                ['Fixed', $fixed],
                ['Skipped', $skipped],
                ['Ambiguous', $ambiguous],
            ]
        );

        if ($dryRun && $fixed > 0) {
            $this->newLine();
            $this->info("Run without --dry to apply {$fixed} changes");
        }

        return Command::SUCCESS;
    }

    /**
     * Get the most common panel_id from reseller's configs
     *
     * @param Reseller $reseller
     * @return int|null
     */
    private function getMostCommonPanelFromConfigs(Reseller $reseller): ?int
    {
        $panelCounts = ResellerConfig::where('reseller_id', $reseller->id)
            ->whereNotNull('panel_id')
            ->select('panel_id', DB::raw('COUNT(*) as count'))
            ->groupBy('panel_id')
            ->orderByDesc('count')
            ->first();

        if ($panelCounts && Panel::find($panelCounts->panel_id)) {
            return $panelCounts->panel_id;
        }

        return null;
    }
}
