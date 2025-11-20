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
            if (empty($data['panels'])) {
                throw new \Exception('At least one panel must be selected for wallet-based resellers.');
            }

            if (empty($data['config_limit']) || $data['config_limit'] < 1) {
                throw new \Exception('Config limit must be at least 1 for wallet-based resellers.');
            }
        }

        // Validate traffic reseller requirements
        if ($data['type'] === 'traffic' && empty($data['panels'])) {
            throw new \Exception('At least one panel must be selected for traffic-based resellers.');
        }

        return $data;
    }
}
