<?php

namespace App\Filament\Resources\ResellerResource\Pages;

use App\Filament\Resources\ResellerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateReseller extends CreateRecord
{
    protected static string $resource = ResellerResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Convert traffic GB to bytes if type is traffic
        if ($data['type'] === 'traffic' && isset($data['traffic_total_gb'])) {
            $data['traffic_total_bytes'] = (int) ($data['traffic_total_gb'] * 1024 * 1024 * 1024);
            unset($data['traffic_total_gb']);
        }

        // Handle window_days - calculate window dates automatically
        if ($data['type'] === 'traffic' && isset($data['window_days']) && $data['window_days'] > 0) {
            $windowDays = (int) $data['window_days'];
            $data['window_starts_at'] = now()->startOfDay();
            // Normalize to start of day for calendar-day boundaries
            $data['window_ends_at'] = now()->addDays($windowDays)->startOfDay();
            unset($data['window_days']); // Remove virtual field
        }

        // Treat config_limit of 0 as null (unlimited)
        if (isset($data['config_limit']) && $data['config_limit'] === 0) {
            $data['config_limit'] = null;
        }

        // Validate wallet reseller requirements
        if ($data['type'] === 'wallet') {
            if (empty($data['primary_panel_id'])) {
                throw new \Exception('Panel selection is required for wallet-based resellers.');
            }

            if (empty($data['config_limit']) || $data['config_limit'] < 1) {
                throw new \Exception('Config limit must be at least 1 for wallet-based resellers.');
            }

            // Validate node selections belong to the selected panel
            if (! empty($data['eylandoo_allowed_node_ids'])) {
                $panel = \App\Models\Panel::find($data['primary_panel_id']);
                if ($panel && $panel->panel_type === 'eylandoo') {
                    // Validate nodes exist in the panel
                    $validNodeIds = [];
                    try {
                        $panelNodes = $panel->getCachedEylandooNodes();
                        $validNodeIds = array_map(fn ($node) => (int) $node['id'], $panelNodes);
                    } catch (\Exception $e) {
                        \Illuminate\Support\Facades\Log::warning('Failed to validate Eylandoo nodes during reseller creation: '.$e->getMessage());
                    }

                    foreach ($data['eylandoo_allowed_node_ids'] as $nodeId) {
                        if (! in_array((int) $nodeId, $validNodeIds, true)) {
                            throw new \Exception("Selected node ID {$nodeId} does not belong to the selected panel.");
                        }
                    }
                }
            }
        }

        return $data;
    }
}
