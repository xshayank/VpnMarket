<?php

namespace App\Filament\Resources\ResellerResource\Pages;

use App\Filament\Resources\ResellerResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditReseller extends EditRecord
{
    protected static string $resource = ResellerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('extend_window')
                ->label('تمدید بازه (Extend Window)')
                ->icon('heroicon-o-calendar')
                ->color('info')
                ->visible(fn () => $this->record->type === 'traffic')
                ->requiresConfirmation()
                ->modalHeading('تمدید بازه زمانی (Extend Time Window)')
                ->modalDescription('آیا مطمئن هستید که می‌خواهید بازه زمانی این ریسلر را تمدید کنید؟ / Are you sure you want to extend this reseller\'s time window?')
                ->modalSubmitActionLabel('تمدید (Extend)')
                ->modalCancelActionLabel('انصراف (Cancel)')
                ->form([
                    \Filament\Forms\Components\TextInput::make('days_to_extend')
                        ->label('افزایش روز (Extend by days)')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->maxValue(3650)
                        ->integer()
                        ->helperText('تعداد روزی که می‌خواهید به بازه زمانی اضافه کنید'),
                ])
                ->action(function (array $data) {
                    try {
                        $daysToExtend = (int) $data['days_to_extend'];
                        $oldEndDate = $this->record->window_ends_at;

                        // Use model method to get base date
                        $baseDate = $this->record->getExtendWindowBaseDate();
                        // Normalize to start of day for calendar-day boundaries
                        $newEndDate = $baseDate->copy()->addDays($daysToExtend)->startOfDay();

                        $this->record->update([
                            'window_ends_at' => $newEndDate,
                            'window_starts_at' => $this->record->window_starts_at ?? now()->startOfDay(),
                        ]);

                        // Create audit log
                        \App\Models\AuditLog::log(
                            action: 'reseller_window_extended',
                            targetType: 'reseller',
                            targetId: $this->record->id,
                            reason: 'admin_action',
                            meta: [
                                'old_window_ends_at' => $oldEndDate?->toDateTimeString(),
                                'new_window_ends_at' => $newEndDate->toDateTimeString(),
                                'days_added' => $daysToExtend,
                            ]
                        );

                        // If reseller was suspended and now has remaining quota and valid window,
                        // dispatch job to re-enable configs
                        if ($this->record->status === 'suspended' && $this->record->hasTrafficRemaining() && $this->record->isWindowValid()) {
                            \Illuminate\Support\Facades\Log::info("Dispatching ReenableResellerConfigsJob after window extension for reseller {$this->record->id}");
                            \Modules\Reseller\Jobs\ReenableResellerConfigsJob::dispatch($this->record->id);
                        }

                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title('بازه زمانی با موفقیت تمدید شد')
                            ->body("{$daysToExtend} روز به بازه زمانی ریسلر اضافه شد")
                            ->send();
                    } catch (\Exception $e) {
                        \Filament\Notifications\Notification::make()
                            ->danger()
                            ->title('خطا در تمدید بازه')
                            ->body('خطایی رخ داده است: '.$e->getMessage())
                            ->send();
                    }
                }),
            Actions\Action::make('reset_usage')
                ->label('بازنشانی مصرف (Reset Usage)')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn () => $this->record->type === 'traffic' && auth()->user()?->is_admin)
                ->requiresConfirmation()
                ->modalHeading('بازنشانی مصرف ترافیک (Reset Traffic Usage)')
                ->modalDescription('این عملیات شمارنده کل مصرف ترافیک ریسلر را به صفر تنظیم می‌کند (بخشودگی سهمیه). کانفیگ‌های فردی ریسلر دست نخورده باقی می‌مانند. این تغییر محدودیت کل ترافیک را تغییر نمی‌دهد. آیا مطمئن هستید؟ / This resets the reseller\'s aggregate traffic counter to 0 (quota forgiveness). Individual configs remain untouched. This does not change the total traffic limit. Continue?')
                ->modalSubmitActionLabel('بله، بازنشانی شود (Yes, Reset)')
                ->modalCancelActionLabel('انصراف (Cancel)')
                ->action(function () {
                    try {
                        $oldUsedBytes = $this->record->traffic_used_bytes;

                        // Calculate the actual usage from configs
                        $totalUsageFromConfigs = $this->record->configs()
                            ->get()
                            ->sum(function ($config) {
                                return $config->usage_bytes + (int) data_get($config->meta, 'settled_usage_bytes', 0);
                            });

                        // Set admin_forgiven_bytes to the total config usage
                        // This way, effective usage = totalUsageFromConfigs - admin_forgiven_bytes = 0
                        $this->record->update([
                            'admin_forgiven_bytes' => $totalUsageFromConfigs,
                            'traffic_used_bytes' => 0,  // Sync job will maintain this at 0
                        ]);

                        // Create audit log
                        \App\Models\AuditLog::log(
                            action: 'reseller_usage_admin_reset',
                            targetType: 'reseller',
                            targetId: $this->record->id,
                            reason: 'admin_action',
                            meta: [
                                'old_traffic_used_bytes' => $oldUsedBytes,
                                'new_traffic_used_bytes' => 0,
                                'admin_forgiven_bytes' => $totalUsageFromConfigs,
                                'traffic_total_bytes' => $this->record->traffic_total_bytes,
                                'note' => 'Admin quota forgiveness - config usage intact, forgiven bytes tracked',
                            ]
                        );

                        // If reseller was suspended and now has remaining quota and valid window,
                        // dispatch job to re-enable configs
                        if ($this->record->status === 'suspended' && $this->record->hasTrafficRemaining() && $this->record->isWindowValid()) {
                            \Illuminate\Support\Facades\Log::info("Dispatching ReenableResellerConfigsJob after usage reset for reseller {$this->record->id}");
                            \Modules\Reseller\Jobs\ReenableResellerConfigsJob::dispatch($this->record->id);
                        }

                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title('مصرف ترافیک با موفقیت بازنشانی شد')
                            ->body('شمارنده مصرف ریسلر به صفر تنظیم شد (کانفیگ‌ها دست نخورده)')
                            ->send();
                    } catch (\Exception $e) {
                        \Filament\Notifications\Notification::make()
                            ->danger()
                            ->title('خطا در بازنشانی مصرف')
                            ->body('خطایی رخ داده است: '.$e->getMessage())
                            ->send();
                    }
                }),
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Convert traffic bytes to GB for display if type is traffic
        if ($data['type'] === 'traffic' && isset($data['traffic_total_bytes'])) {
            $data['traffic_total_gb'] = $data['traffic_total_bytes'] / (1024 * 1024 * 1024);
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Convert traffic GB to bytes if type is traffic
        if ($data['type'] === 'traffic' && isset($data['traffic_total_gb'])) {
            $data['traffic_total_bytes'] = (int) ($data['traffic_total_gb'] * 1024 * 1024 * 1024);
            unset($data['traffic_total_gb']);
        }

        // Treat config_limit of 0 as null (unlimited)
        if (isset($data['config_limit']) && $data['config_limit'] === 0) {
            $data['config_limit'] = null;
        }

        // Validate wallet reseller requirements
        if ($data['type'] === 'wallet') {
            if (isset($data['panels']) && empty($data['panels'])) {
                throw new \Exception('At least one panel must be selected for wallet-based resellers.');
            }

            if (isset($data['config_limit']) && ($data['config_limit'] === null || $data['config_limit'] < 1)) {
                throw new \Exception('Config limit must be at least 1 for wallet-based resellers.');
            }
        }

        // Validate traffic reseller requirements
        if ($data['type'] === 'traffic' && isset($data['panels']) && empty($data['panels'])) {
            throw new \Exception('At least one panel must be selected for traffic-based resellers.');
        }

        return $data;
    }
}
