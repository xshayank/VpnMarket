<?php

namespace App\Console\Commands;

use App\Models\Panel;
use App\Models\Reseller;
use Illuminate\Console\Command;

class AssignPanelsToResellers extends Command
{
    protected $signature = 'resellers:assign-panels 
                            {--panel_id=* : Specific panel IDs to assign (repeatable)} 
                            {--all-panels : Assign all panels with auto_assign_to_resellers=true} 
                            {--reseller_id=* : Specific reseller IDs to attach (repeatable)}';

    protected $description = 'Attach panels to resellers based on flags or specific lists (idempotent).';

    public function handle(): int
    {
        $panels = collect();

        if ($this->option('all-panels')) {
            $panels = Panel::where('is_active', true)->where('auto_assign_to_resellers', true)->get();
            $this->info('Found ' . $panels->count() . ' panels with auto-assign enabled.');
        } elseif ($ids = $this->option('panel_id')) {
            $panels = Panel::whereIn('id', $ids)->get();
            $this->info('Selected ' . $panels->count() . ' specific panels.');
        }

        if ($panels->isEmpty()) {
            $this->warn('No panels selected. Use --all-panels or --panel_id options.');
            return self::SUCCESS;
        }

        $resellerQuery = Reseller::query();
        if ($rIds = $this->option('reseller_id')) {
            $resellerQuery->whereIn('id', $rIds);
        }

        $resellerCount = $resellerQuery->count();
        $this->info("Processing {$resellerCount} resellers...");

        $count = 0;
        $bar = $this->output->createProgressBar($resellerCount * $panels->count());

        $resellerQuery->chunkById(500, function ($chunk) use ($panels, &$count, $bar) {
            foreach ($chunk as $reseller) {
                foreach ($panels as $panel) {
                    $reseller->panels()->syncWithoutDetaching([
                        $panel->id => [
                            'allowed_node_ids' => json_encode($panel->getRegistrationDefaultNodeIds()),
                            'allowed_service_ids' => json_encode($panel->getRegistrationDefaultServiceIds()),
                        ],
                    ]);
                    $count++;
                    $bar->advance();
                }
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("✓ Attached {$count} panel-reseller relations.");
        
        return self::SUCCESS;
    }
}
