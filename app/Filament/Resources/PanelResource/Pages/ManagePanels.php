<?php

namespace App\Filament\Resources\PanelResource\Pages;

use App\Filament\Resources\PanelResource;
use App\Models\Reseller;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\Log;

class ManagePanels extends ManageRecords
{
    protected static string $resource = PanelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->after(function ($record) {
                    // When a panel is created with auto_assign_to_resellers enabled,
                    // attach it to all existing resellers
                    if ($record->auto_assign_to_resellers) {
                        $this->assignPanelToAllResellers($record);
                    }
                }),
            Actions\Action::make('editAction')
                ->hidden() // This is just for the mutateFormDataBeforeSave hook
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Check if auto_assign_to_resellers was just enabled
        if (isset($data['auto_assign_to_resellers']) && $data['auto_assign_to_resellers']) {
            // Store a flag to trigger assignment after save
            $this->shouldAssignToResellers = true;
        }
        
        return $data;
    }

    protected function afterSave(): void
    {
        // If auto_assign_to_resellers was enabled, assign panel to all resellers
        if (isset($this->shouldAssignToResellers) && $this->shouldAssignToResellers) {
            $this->assignPanelToAllResellers($this->record);
        }
    }

    protected function assignPanelToAllResellers($panel): void
    {
        $count = 0;
        Reseller::query()->chunkById(500, function ($chunk) use ($panel, &$count) {
            foreach ($chunk as $reseller) {
                $reseller->panels()->syncWithoutDetaching([
                    $panel->id => [
                        'allowed_node_ids' => json_encode($panel->getRegistrationDefaultNodeIds()),
                        'allowed_service_ids' => json_encode($panel->getRegistrationDefaultServiceIds()),
                    ],
                ]);
                $count++;
            }
        });

        Log::info('Panel auto-assigned to resellers', [
            'panel_id' => $panel->id,
            'panel_name' => $panel->name,
            'reseller_count' => $count,
        ]);

        Notification::make()
            ->title("پنل به {$count} ریسلر اختصاص یافت")
            ->success()
            ->send();
    }
}
