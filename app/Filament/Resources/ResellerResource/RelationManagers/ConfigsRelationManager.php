<?php

namespace App\Filament\Resources\ResellerResource\RelationManagers;

use App\Models\AuditLog;
use App\Models\ResellerConfig;
use App\Models\ResellerConfigEvent;
use App\Services\MarzbanService;
use App\Services\MarzneshinService;
use App\Services\Reseller\WalletChargingService;
use App\Services\XUIService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConfigsRelationManager extends RelationManager
{
    protected static string $relationship = 'configs';

    protected static ?string $title = 'کانفیگ‌های کاربران';

    protected static ?string $modelLabel = 'کانفیگ';

    protected static ?string $pluralModelLabel = 'کانفیگ‌ها';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('external_username')
                    ->label('نام کاربری')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('external_username')
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                Tables\Columns\TextColumn::make('external_username')
                    ->label('نام کاربری')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage('نام کاربری کپی شد')
                    ->description(fn (ResellerConfig $record): ?string => $record->comment),

                Tables\Columns\TextColumn::make('usage')
                    ->label('استفاده / محدودیت')
                    ->formatStateUsing(function (ResellerConfig $record): string {
                        $usageBytes = $record->usage_bytes ?? 0;
                        $usedGB = round($usageBytes / (1024 * 1024 * 1024), 2);
                        $limitGB = round($record->traffic_limit_bytes / (1024 * 1024 * 1024), 2);
                        $percent = $record->traffic_limit_bytes > 0 ? round(($usageBytes / $record->traffic_limit_bytes) * 100, 1) : 0;

                        return "{$usedGB} / {$limitGB} GB ({$percent}%)";
                    })
                    ->html()
                    ->description(function (ResellerConfig $record): string {
                        $usageBytes = $record->usage_bytes ?? 0;
                        $percent = $record->traffic_limit_bytes > 0
                            ? round(($usageBytes / $record->traffic_limit_bytes) * 100, 1)
                            : 0;

                        // Generate progress bar HTML
                        $colorClass = $percent >= 90 ? 'bg-red-500' : ($percent >= 70 ? 'bg-yellow-500' : 'bg-green-500');

                        return "<div class='mt-1'><div class='w-full bg-gray-200 rounded-full h-2'><div class='{$colorClass} h-2 rounded-full' style='width: {$percent}%'></div></div></div>";
                    }),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('تاریخ انقضا')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->color(fn (ResellerConfig $record): string => $record->expires_at < now() ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('status')
                    ->label('وضعیت')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'disabled' => 'warning',
                        'expired' => 'danger',
                        'deleted' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'active' => 'فعال',
                        'disabled' => 'غیرفعال',
                        'expired' => 'منقضی',
                        'deleted' => 'حذف شده',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('panel_type')
                    ->label('نوع پنل')
                    ->badge(),

                Tables\Columns\TextColumn::make('connections')
                    ->label('اتصالات همزمان')
                    ->formatStateUsing(fn (?int $state): string => $state ? (string) $state : '-')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn (?ResellerConfig $record): bool => $record?->panel_type === 'eylandoo'),

                Tables\Columns\TextColumn::make('panel_user_id')
                    ->label('شناسه پنل')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاریخ ایجاد')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('وضعیت')
                    ->options([
                        'active' => 'فعال',
                        'disabled' => 'غیرفعال',
                        'expired' => 'منقضی',
                        'deleted' => 'حذف شده',
                    ]),

                Tables\Filters\SelectFilter::make('panel_type')
                    ->label('نوع پنل')
                    ->options([
                        'marzban' => 'Marzban',
                        'marzneshin' => 'Marzneshin',
                        'xui' => 'X-UI',
                        'eylandoo' => 'Eylandoo',
                    ]),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('disable')
                    ->label('غیرفعال')
                    ->icon('heroicon-o-pause')
                    ->color('warning')
                    ->visible(fn (?ResellerConfig $record): bool => $record?->status === 'active')
                    ->requiresConfirmation()
                    ->action(function (ResellerConfig $record) {
                        $this->disableConfig($record);
                    }),

                Tables\Actions\Action::make('enable')
                    ->label('فعال')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->visible(fn (?ResellerConfig $record): bool => $record?->status === 'disabled')
                    ->requiresConfirmation()
                    ->action(function (ResellerConfig $record) {
                        $this->enableConfig($record);
                    }),

                Tables\Actions\Action::make('reset_usage')
                    ->label('ریست مصرف')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->requiresConfirmation()
                    ->action(function (ResellerConfig $record) {
                        $this->resetConfigUsage($record);
                    }),

                Tables\Actions\Action::make('extend_time')
                    ->label('تمدید زمان')
                    ->icon('heroicon-o-calendar')
                    ->color('info')
                    ->form([
                        Forms\Components\TextInput::make('days')
                            ->label('تعداد روز')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->maxValue(365),
                    ])
                    ->action(function (ResellerConfig $record, array $data) {
                        $this->extendConfigTime($record, $data['days']);
                    }),

                Tables\Actions\Action::make('increase_traffic')
                    ->label('افزایش ترافیک')
                    ->icon('heroicon-o-arrow-up-circle')
                    ->color('warning')
                    ->form([
                        Forms\Components\TextInput::make('traffic_gb')
                            ->label('مقدار ترافیک (GB)')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->maxValue(10000),
                    ])
                    ->action(function (ResellerConfig $record, array $data) {
                        $this->increaseConfigTraffic($record, $data['traffic_gb']);
                    }),

                Tables\Actions\Action::make('copy_url')
                    ->label('کپی لینک')
                    ->icon('heroicon-o-clipboard')
                    ->color('info')
                    ->action(function (ResellerConfig $record) {
                        Notification::make()
                            ->success()
                            ->title('لینک کپی شد')
                            ->body($record->subscription_url ?? 'لینک موجود نیست')
                            ->send();
                    }),

                Tables\Actions\DeleteAction::make()
                    ->label('حذف')
                    ->action(function (ResellerConfig $record) {
                        $this->deleteConfig($record);
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('disable')
                        ->label('غیرفعال‌سازی گروهی')
                        ->icon('heroicon-o-pause')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            foreach ($records as $record) {
                                $this->disableConfig($record);
                            }
                        }),

                    Tables\Actions\BulkAction::make('enable')
                        ->label('فعال‌سازی گروهی')
                        ->icon('heroicon-o-play')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            foreach ($records as $record) {
                                $this->enableConfig($record);
                            }
                        }),

                    Tables\Actions\DeleteBulkAction::make()
                        ->action(function ($records) {
                            foreach ($records as $record) {
                                $this->deleteConfig($record);
                            }
                        }),

                    Tables\Actions\ExportBulkAction::make()
                        ->label('خروجی CSV'),
                ]),
            ])
            ->defaultSort('id', 'desc');
    }

    protected function disableConfig(ResellerConfig $config): void
    {
        try {
            // Attempt remote disable first
            $remoteResult = ['success' => false, 'attempts' => 0, 'last_error' => 'No panel configured'];
            $panel = null;
            $panelTypeUsed = null;

            if ($config->panel_id && $config->panel_user_id) {
                // Try relationship first, fall back to Panel::find()
                $panel = $config->panel ?? \App\Models\Panel::find($config->panel_id);
                if ($panel) {
                    $panelTypeUsed = $panel->panel_type;
                    $credentials = $panel->getCredentials();
                    $provisioner = new \Modules\Reseller\Services\ResellerProvisioner;

                    $remoteResult = $provisioner->disableUser(
                        $panel->panel_type,
                        $credentials,
                        $config->panel_user_id
                    );

                    if (! $remoteResult['success']) {
                        Log::warning("Failed to disable config {$config->id} on remote panel {$panel->id} after {$remoteResult['attempts']} attempts: {$remoteResult['last_error']}");
                    } else {
                        Log::info("Config {$config->id} disabled successfully on panel {$panel->id}", [
                            'config_id' => $config->id,
                            'panel_id' => $panel->id,
                            'reason' => 'admin_action',
                        ]);
                    }
                }
            }

            // Update local state after remote attempt
            $config->update([
                'status' => 'disabled',
                'disabled_at' => now(),
            ]);

            // Create standardized event with telemetry
            ResellerConfigEvent::create([
                'reseller_config_id' => $config->id,
                'type' => 'manual_disabled',
                'meta' => [
                    'reason' => 'admin_action',
                    'remote_success' => $remoteResult['success'],
                    'attempts' => $remoteResult['attempts'],
                    'last_error' => $remoteResult['last_error'],
                    'panel_id' => $config->panel_id,
                    'panel_type_used' => $panelTypeUsed,
                ],
            ]);

            // Create audit log entry
            AuditLog::log(
                action: 'config_manual_disabled',
                targetType: 'config',
                targetId: $config->id,
                reason: 'admin_action',
                meta: [
                    'remote_success' => $remoteResult['success'],
                    'attempts' => $remoteResult['attempts'],
                    'last_error' => $remoteResult['last_error'],
                    'panel_id' => $config->panel_id,
                    'panel_type_used' => $panelTypeUsed,
                ]
            );

            Notification::make()
                ->success()
                ->title('کانفیگ با موفقیت غیرفعال شد')
                ->send();
        } catch (\Exception $e) {
            Log::error('Error disabling config: '.$e->getMessage(), ['config_id' => $config->id]);
            Notification::make()
                ->danger()
                ->title('خطا در غیرفعال‌سازی کانفیگ')
                ->body($e->getMessage())
                ->send();
        }
    }

    protected function enableConfig(ResellerConfig $config): void
    {
        try {
            // Attempt remote enable first
            $remoteResult = ['success' => false, 'attempts' => 0, 'last_error' => 'No panel configured'];
            $panel = null;
            $panelTypeUsed = null;

            if ($config->panel_id && $config->panel_user_id) {
                // Try relationship first, fall back to Panel::find()
                $panel = $config->panel ?? \App\Models\Panel::find($config->panel_id);
                if ($panel) {
                    $panelTypeUsed = $panel->panel_type;
                    $credentials = $panel->getCredentials();
                    $provisioner = new \Modules\Reseller\Services\ResellerProvisioner;

                    $remoteResult = $provisioner->enableUser(
                        $panel->panel_type,
                        $credentials,
                        $config->panel_user_id
                    );

                    if (! $remoteResult['success']) {
                        Log::warning("Failed to enable config {$config->id} on remote panel {$panel->id} after {$remoteResult['attempts']} attempts: {$remoteResult['last_error']}");
                    } else {
                        Log::info("Config {$config->id} enabled successfully on panel {$panel->id}", [
                            'config_id' => $config->id,
                            'panel_id' => $panel->id,
                            'reason' => 'admin_action',
                        ]);
                    }
                }
            }

            // Update local state after remote attempt
            $config->update([
                'status' => 'active',
                'disabled_at' => null,
            ]);

            // Create standardized event with telemetry
            ResellerConfigEvent::create([
                'reseller_config_id' => $config->id,
                'type' => 'manual_enabled',
                'meta' => [
                    'reason' => 'admin_action',
                    'remote_success' => $remoteResult['success'],
                    'attempts' => $remoteResult['attempts'],
                    'last_error' => $remoteResult['last_error'],
                    'panel_id' => $config->panel_id,
                    'panel_type_used' => $panelTypeUsed,
                ],
            ]);

            // Create audit log entry
            AuditLog::log(
                action: 'config_manual_enabled',
                targetType: 'config',
                targetId: $config->id,
                reason: 'admin_action',
                meta: [
                    'remote_success' => $remoteResult['success'],
                    'attempts' => $remoteResult['attempts'],
                    'last_error' => $remoteResult['last_error'],
                    'panel_id' => $config->panel_id,
                    'panel_type_used' => $panelTypeUsed,
                ]
            );

            Notification::make()
                ->success()
                ->title('کانفیگ با موفقیت فعال شد')
                ->send();
        } catch (\Exception $e) {
            Log::error('Error enabling config: '.$e->getMessage(), ['config_id' => $config->id]);
            Notification::make()
                ->danger()
                ->title('خطا در فعال‌سازی کانفیگ')
                ->body($e->getMessage())
                ->send();
        }
    }

    protected function resetConfigUsage(ResellerConfig $config): void
    {
        try {
            DB::transaction(function () use ($config) {
                $oldUsageBytes = $config->usage_bytes;
                $oldSettledBytes = (int) data_get($config->meta, 'settled_usage_bytes', 0);

                // Perform final settlement BEFORE reset for wallet-based resellers
                $settlementResult = ['status' => 'skipped', 'cost' => 0, 'charged_bytes' => 0];
                $reseller = $config->reseller;
                if ($reseller && $reseller->type === 'wallet') {
                    $chargingService = app(WalletChargingService::class);
                    $settlementResult = $chargingService->finalSettlementForConfig($config, 'reset_traffic');
                }

                // Reset usage and update meta
                $meta = $config->meta ?? [];
                $meta['settled_usage_bytes'] = $oldSettledBytes + $oldUsageBytes;
                $meta['last_reset_at'] = now()->toIso8601String();

                $config->update([
                    'usage_bytes' => 0,
                    'meta' => $meta,
                ]);

                ResellerConfigEvent::create([
                    'reseller_config_id' => $config->id,
                    'type' => 'usage_reset',
                    'meta' => [
                        'previous_usage' => $oldUsageBytes,
                        'previous_settled_bytes' => $oldSettledBytes,
                        'new_settled_bytes' => $oldSettledBytes + $oldUsageBytes,
                        'settlement_status' => $settlementResult['status'],
                        'settlement_cost' => $settlementResult['cost'],
                        'settlement_charged_bytes' => $settlementResult['charged_bytes'],
                        'reset_at' => now()->toDateTimeString(),
                        'admin_action' => true,
                    ],
                ]);

                // Create audit log entry
                AuditLog::log(
                    action: 'config_usage_reset',
                    targetType: 'config',
                    targetId: $config->id,
                    reason: 'admin_action',
                    meta: [
                        'previous_usage' => $oldUsageBytes,
                        'settlement_status' => $settlementResult['status'],
                        'settlement_cost' => $settlementResult['cost'],
                    ]
                );
            });

            Notification::make()
                ->success()
                ->title('مصرف با موفقیت ریست شد')
                ->send();
        } catch (\Exception $e) {
            Log::error('Error resetting config usage: '.$e->getMessage(), ['config_id' => $config->id]);
            Notification::make()
                ->danger()
                ->title('خطا در ریست مصرف')
                ->body($e->getMessage())
                ->send();
        }
    }

    protected function deleteConfig(ResellerConfig $config): void
    {
        try {
            DB::transaction(function () use ($config) {
                // Perform final settlement BEFORE deletion for wallet-based resellers
                $settlementResult = ['status' => 'skipped', 'cost' => 0, 'charged_bytes' => 0];
                $reseller = $config->reseller;
                if ($reseller && $reseller->type === 'wallet') {
                    $chargingService = app(WalletChargingService::class);
                    $settlementResult = $chargingService->finalSettlementForConfig($config, 'delete_config');
                }

                if ($config->panel && $config->panel_user_id) {
                    $credentials = $config->panel->getCredentials();
                    $provisioner = new \Modules\Reseller\Services\ResellerProvisioner;

                    $success = $provisioner->deleteUser(
                        $config->panel_type,
                        $credentials,
                        $config->panel_user_id
                    );

                    if (! $success) {
                        Log::warning('Failed to delete config on panel', ['config_id' => $config->id]);
                    }
                }

                $config->update(['status' => 'deleted']);
                $config->delete(); // Soft delete

                ResellerConfigEvent::create([
                    'reseller_config_id' => $config->id,
                    'type' => 'deleted',
                    'meta' => [
                        'deleted_at' => now()->toDateTimeString(),
                        'settlement_status' => $settlementResult['status'],
                        'settlement_cost' => $settlementResult['cost'],
                        'settlement_charged_bytes' => $settlementResult['charged_bytes'],
                    ],
                ]);

                // Create audit log entry
                AuditLog::log(
                    action: 'config_deleted',
                    targetType: 'config',
                    targetId: $config->id,
                    reason: 'admin_action',
                    meta: [
                        'deleted_at' => now()->toDateTimeString(),
                        'panel_id' => $config->panel_id,
                        'settlement_status' => $settlementResult['status'],
                        'settlement_cost' => $settlementResult['cost'],
                    ]
                );
            });

            Notification::make()
                ->success()
                ->title('کانفیگ با موفقیت حذف شد')
                ->send();
        } catch (\Exception $e) {
            Log::error('Error deleting config: '.$e->getMessage(), ['config_id' => $config->id]);
            Notification::make()
                ->danger()
                ->title('خطا در حذف کانفیگ')
                ->body($e->getMessage())
                ->send();
        }
    }

    protected function extendConfigTime(ResellerConfig $config, int $days): void
    {
        try {
            $newExpiry = $config->expires_at->addDays($days);

            if ($config->panel && $config->panel_user_id) {
                $credentials = $config->panel->getCredentials();

                switch ($config->panel_type) {
                    case 'marzban':
                        $service = new MarzbanService(
                            $credentials['url'],
                            $credentials['username'],
                            $credentials['password'],
                            $credentials['extra']['node_hostname'] ?? ''
                        );
                        if ($service->login()) {
                            $service->updateUser($config->panel_user_id, [
                                'expire' => $newExpiry->timestamp,
                                'data_limit' => $config->traffic_limit_bytes,
                            ]);
                        }
                        break;

                    case 'marzneshin':
                        $service = new MarzneshinService(
                            $credentials['url'],
                            $credentials['username'],
                            $credentials['password'],
                            $credentials['extra']['node_hostname'] ?? ''
                        );
                        if ($service->login()) {
                            $service->updateUser($config->panel_user_id, [
                                'expire' => $newExpiry->timestamp,
                                'data_limit' => $config->traffic_limit_bytes,
                            ]);
                        }
                        break;

                    case 'xui':
                        $service = new XUIService(
                            $credentials['url'],
                            $credentials['username'],
                            $credentials['password']
                        );
                        if ($service->login()) {
                            $service->updateUser($config->panel_user_id, [
                                'expire' => $newExpiry->timestamp,
                            ]);
                        }
                        break;
                }
            }

            $config->update(['expires_at' => $newExpiry]);

            ResellerConfigEvent::create([
                'reseller_config_id' => $config->id,
                'type' => 'time_extended',
                'meta' => [
                    'days_added' => $days,
                    'new_expiry' => $newExpiry->toDateTimeString(),
                ],
            ]);

            Notification::make()
                ->success()
                ->title('زمان با موفقیت تمدید شد')
                ->body("{$days} روز به زمان کانفیگ اضافه شد")
                ->send();
        } catch (\Exception $e) {
            Log::error('Error extending config time: '.$e->getMessage(), ['config_id' => $config->id]);
            Notification::make()
                ->danger()
                ->title('خطا در تمدید زمان')
                ->body($e->getMessage())
                ->send();
        }
    }

    protected function increaseConfigTraffic(ResellerConfig $config, float $trafficGB): void
    {
        try {
            $additionalBytes = $trafficGB * 1024 * 1024 * 1024;
            $newLimit = $config->traffic_limit_bytes + $additionalBytes;

            if ($config->panel && $config->panel_user_id) {
                $credentials = $config->panel->getCredentials();

                switch ($config->panel_type) {
                    case 'marzban':
                        $service = new MarzbanService(
                            $credentials['url'],
                            $credentials['username'],
                            $credentials['password'],
                            $credentials['extra']['node_hostname'] ?? ''
                        );
                        if ($service->login()) {
                            $service->updateUser($config->panel_user_id, [
                                'expire' => $config->expires_at->timestamp,
                                'data_limit' => $newLimit,
                            ]);
                        }
                        break;

                    case 'marzneshin':
                        $service = new MarzneshinService(
                            $credentials['url'],
                            $credentials['username'],
                            $credentials['password'],
                            $credentials['extra']['node_hostname'] ?? ''
                        );
                        if ($service->login()) {
                            $service->updateUser($config->panel_user_id, [
                                'expire' => $config->expires_at->timestamp,
                                'data_limit' => $newLimit,
                            ]);
                        }
                        break;

                    case 'xui':
                        $service = new XUIService(
                            $credentials['url'],
                            $credentials['username'],
                            $credentials['password']
                        );
                        if ($service->login()) {
                            $service->updateUser($config->panel_user_id, [
                                'data_limit' => $newLimit,
                            ]);
                        }
                        break;
                }
            }

            $config->update(['traffic_limit_bytes' => $newLimit]);

            ResellerConfigEvent::create([
                'reseller_config_id' => $config->id,
                'type' => 'traffic_increased',
                'meta' => [
                    'gb_added' => $trafficGB,
                    'new_limit_gb' => round($newLimit / (1024 * 1024 * 1024), 2),
                ],
            ]);

            Notification::make()
                ->success()
                ->title('ترافیک با موفقیت افزایش یافت')
                ->body("{$trafficGB} گیگابایت به ترافیک کانفیگ اضافه شد")
                ->send();
        } catch (\Exception $e) {
            Log::error('Error increasing config traffic: '.$e->getMessage(), ['config_id' => $config->id]);
            Notification::make()
                ->danger()
                ->title('خطا در افزایش ترافیک')
                ->body($e->getMessage())
                ->send();
        }
    }
}
