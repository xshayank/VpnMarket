<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ResellerResource\Pages;
use App\Filament\Resources\ResellerResource\RelationManagers;
use App\Models\Plan;
use App\Models\Reseller;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ResellerResource extends Resource
{
    protected static ?string $model = Reseller::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'مدیریت کاربران';

    protected static ?string $navigationLabel = 'ریسلرها';

    protected static ?string $pluralModelLabel = 'ریسلرها';

    protected static ?string $modelLabel = 'ریسلر';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('user_id')
                    ->relationship('user', 'name')
                    ->label('کاربر')
                    ->searchable()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->getSearchResultsUsing(fn (string $search) => \App\Models\User::query()
                        ->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%')
                        ->orWhere('email', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%')
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(fn ($user) => [
                            $user->id => ($user->name ?? 'بدون نام').' ('.$user->email.')',
                        ])
                    )
                    ->getOptionLabelFromRecordUsing(fn ($record) => ($record->name ?? 'بدون نام').' ('.$record->email.')'
                    )
                    ->preload()
                    ->loadingMessage('در حال بارگذاری کاربران...')
                    ->noSearchResultsMessage('کاربری یافت نشد')
                    ->searchPrompt('جستجو بر اساس نام یا ایمیل'),

                Forms\Components\Select::make('type')
                    ->label('نوع ریسلر')
                    ->options([
                        'plan' => 'پلن‌محور',
                        'traffic' => 'ترافیک‌محور',
                        'wallet' => 'کیف پول‌محور',
                    ])
                    ->required()
                    ->live()
                    ->default('traffic'),

                Forms\Components\Section::make('تنظیمات کیف پول')
                    ->visible(fn (Forms\Get $get) => $get('type') === 'wallet')
                    ->schema([
                        Forms\Components\Select::make('panels')
                            ->label('پنل‌ها')
                            ->relationship('panels', 'name')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->live()
                            ->reactive()
                            ->required()
                            ->helperText('پنل‌هایی که این ریسلر می‌تواند از آنها استفاده کند. حداقل یک پنل باید انتخاب شود.'),

                        Forms\Components\TextInput::make('config_limit')
                            ->label('محدودیت تعداد کانفیگ')
                            ->numeric()
                            ->minValue(1)
                            ->required()
                            ->helperText('تعداد کانفیگ‌هایی که می‌توان ایجاد کرد.'),

                        Forms\Components\TextInput::make('wallet_balance')
                            ->label('موجودی کیف پول (تومان)')
                            ->numeric()
                            ->default(0)
                            ->helperText('موجودی فعلی کیف پول به تومان'),

                        Forms\Components\TextInput::make('wallet_price_per_gb')
                            ->label('قیمت هر گیگابایت (تومان) - اختیاری')
                            ->numeric()
                            ->minValue(0)
                            ->nullable()
                            ->helperText('قیمت سفارشی برای هر گیگابایت. اگر خالی باشد از قیمت پیش‌فرض ('.config('billing.wallet.price_per_gb', 780).' تومان) استفاده می‌شود'),

                        // Note: Panel-specific node/service configuration moved to multi-panel system
                        // These can be configured when assigning panels to resellers
                        Forms\Components\Placeholder::make('multi_panel_note')
                            ->label('')
                            ->content('برای پیکربندی نودها و سرویس‌های خاص هر پنل، از بخش "مدیریت پنل‌ها" در زیر استفاده کنید.')
                            ->helperText('پس از ایجاد ریسلر، می‌توانید تنظیمات هر پنل را جداگانه مدیریت کنید.'),
                    ]),

                Forms\Components\Section::make('تنظیمات API')
                    ->description('مدیریت دسترسی API برای این ریسلر')
                    ->schema([
                        Forms\Components\Toggle::make('api_enabled')
                            ->label('فعال‌سازی API')
                            ->helperText('اجازه می‌دهد این ریسلر کلیدهای API برای دسترسی برنامه‌ای ایجاد کند')
                            ->default(false),
                    ]),

                Forms\Components\Select::make('status')
                    ->label('وضعیت')
                    ->options([
                        'active' => 'فعال',
                        'suspended' => 'معلق',
                    ])
                    ->required()
                    ->default('active'),

                Forms\Components\TextInput::make('username_prefix')
                    ->label('پیشوند نام کاربری')
                    ->helperText('اگر خالی باشد از پیشوند پیش‌فرض استفاده می‌شود'),

                Forms\Components\Section::make('تنظیمات ترافیک‌محور')
                    ->visible(fn (Forms\Get $get) => $get('type') === 'traffic')
                    ->schema([
                        Forms\Components\Select::make('panels')
                            ->label('پنل‌ها')
                            ->relationship('panels', 'name')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->live()
                            ->reactive()
                            ->required()
                            ->helperText('پنل‌هایی که این ریسلر می‌تواند از آنها استفاده کند'),

                        Forms\Components\TextInput::make('traffic_total_gb')
                            ->label('ترافیک کل (GB)')
                            ->numeric()
                            ->minValue(1)
                            ->step(1)
                            ->maxValue(10000000)
                            ->helperText('مقدار را به گیگابایت وارد کنید (حداکثر: 10,000,000 GB)'),

                        Forms\Components\TextInput::make('config_limit')
                            ->label('محدودیت تعداد کانفیگ')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('تعداد کانفیگ‌هایی که می‌توان ایجاد کرد. 0 یا خالی = نامحدود'),

                        Forms\Components\TextInput::make('window_days')
                            ->label('مدت بازه (روز)')
                            ->helperText('Window duration (days) - تعداد روزهای بازه زمانی')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(3650)
                            ->integer()
                            ->visible(fn (string $operation) => $operation === 'create'),

                        Forms\Components\DateTimePicker::make('window_starts_at')
                            ->label('تاریخ شروع (اختیاری)')
                            ->helperText('اگر خالی باشد، محدودیت زمانی ندارد')
                            ->visible(fn (string $operation) => $operation === 'edit'),

                        Forms\Components\DateTimePicker::make('window_ends_at')
                            ->label('تاریخ پایان (اختیاری)')
                            ->helperText('اگر خالی باشد، محدودیت زمانی ندارد')
                            ->visible(fn (string $operation) => $operation === 'edit'),

                        // Note: Panel-specific node/service configuration moved to multi-panel system
                        Forms\Components\Placeholder::make('multi_panel_note_traffic')
                            ->label('')
                            ->content('برای پیکربندی نودها و سرویس‌های خاص هر پنل، از قسمت "Panels" در صفحه ویرایش استفاده کنید.')
                            ->helperText('تنظیمات هر پنل را می‌توانید جداگانه مدیریت کنید.'),
                    ]),

                Forms\Components\Section::make('پلن‌های مجاز')
                    ->visible(fn (Forms\Get $get) => $get('type') === 'plan')
                    ->schema([
                        Forms\Components\Repeater::make('allowedPlans')
                            ->relationship('allowedPlans')
                            ->label('پلن‌های مجاز')
                            ->schema([
                                Forms\Components\Select::make('plan_id')
                                    ->label('پلن')
                                    ->options(Plan::where('reseller_visible', true)->pluck('name', 'id'))
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->distinct()
                                    ->disableOptionWhen(function ($value, $state, Forms\Get $get) {
                                        // Disable options that are already selected in other items
                                        $selectedPlans = collect($get('../../allowedPlans'))
                                            ->pluck('plan_id')
                                            ->filter()
                                            ->toArray();

                                        return in_array($value, $selectedPlans) && $value != $state;
                                    }),

                                Forms\Components\Select::make('override_type')
                                    ->label('نوع تخفیف')
                                    ->options([
                                        'price' => 'قیمت ثابت',
                                        'percent' => 'درصد تخفیف',
                                    ])
                                    ->live(),

                                Forms\Components\TextInput::make('override_value')
                                    ->label('مقدار')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(fn (Forms\Get $get) => $get('override_type') === 'percent' ? 100 : null),

                                Forms\Components\Toggle::make('active')
                                    ->label('فعال')
                                    ->default(true),
                            ])
                            ->columns(4)
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => Plan::find($state['plan_id'] ?? null)?->name ?? 'پلن جدید'
                            )
                            ->defaultItems(0)
                            ->addActionLabel('افزودن پلن'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('کاربر')
                    ->description(fn (Reseller $record): string => $record->user->email ?? '')
                    ->searchable(['name', 'email'])
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('نوع')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'plan' => 'info',
                        'traffic' => 'warning',
                        'wallet' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'plan' => 'پلن‌محور',
                        'traffic' => 'ترافیک‌محور',
                        'wallet' => 'کیف پول‌محور',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->label('وضعیت')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'suspended' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'active' => 'فعال',
                        'suspended' => 'معلق',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('traffic')
                    ->label('ترافیک')
                    ->visible(fn ($record): bool => $record && $record->type === 'traffic')
                    ->formatStateUsing(function (Reseller $record): string {
                        if ($record->type !== 'traffic') {
                            return '-';
                        }
                        $usedGB = round($record->traffic_used_bytes / (1024 * 1024 * 1024), 2);
                        $totalGB = round($record->traffic_total_bytes / (1024 * 1024 * 1024), 2);
                        $percent = $totalGB > 0 ? round(($record->traffic_used_bytes / $record->traffic_total_bytes) * 100, 1) : 0;

                        return "{$usedGB} / {$totalGB} GB ({$percent}%)";
                    })
                    ->description(function (Reseller $record): ?string {
                        if ($record->type !== 'traffic' || ! $record->traffic_total_bytes) {
                            return null;
                        }
                        $percent = round(($record->traffic_used_bytes / $record->traffic_total_bytes) * 100, 1);

                        return "استفاده شده: {$percent}%";
                    }),

                Tables\Columns\TextColumn::make('window')
                    ->label('بازه زمانی')
                    ->visible(fn ($record): bool => $record && $record->type === 'traffic')
                    ->formatStateUsing(function (Reseller $record): string {
                        if ($record->type !== 'traffic' || ! $record->window_starts_at) {
                            return '-';
                        }

                        return $record->window_starts_at->format('Y-m-d').' تا '.$record->window_ends_at->format('Y-m-d');
                    }),

                Tables\Columns\TextColumn::make('panels_list')
                    ->label('پنل‌ها')
                    ->formatStateUsing(function (Reseller $record): string {
                        $panels = $record->panels;
                        if ($panels->isEmpty()) {
                            return '-';
                        }

                        return $panels->pluck('name')->join(', ');
                    })
                    ->description(function (Reseller $record): string {
                        $panels = $record->panels;
                        if ($panels->isEmpty()) {
                            return 'هیچ پنلی تخصیص داده نشده';
                        }

                        $types = $panels->pluck('panel_type')->unique()->join(', ');

                        return "تعداد: {$panels->count()} | نوع: {$types}";
                    })
                    ->wrap(),

                Tables\Columns\IconColumn::make('api_enabled')
                    ->label('API')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاریخ ایجاد')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('نوع')
                    ->options([
                        'plan' => 'پلن‌محور',
                        'traffic' => 'ترافیک‌محور',
                        'wallet' => 'کیف پول‌محور',
                    ]),

                Tables\Filters\SelectFilter::make('status')
                    ->label('وضعیت')
                    ->options([
                        'active' => 'فعال',
                        'suspended' => 'معلق',
                    ]),

                Tables\Filters\SelectFilter::make('panels')
                    ->label('فیلتر بر اساس پنل')
                    ->relationship('panels', 'name')
                    ->multiple()
                    ->preload(),

                Tables\Filters\SelectFilter::make('panel_type')
                    ->label('نوع پنل')
                    ->query(function ($query, $state) {
                        if ($state['value'] ?? false) {
                            $query->whereHas('panels', function ($q) use ($state) {
                                $q->where('panel_type', $state['value']);
                            });
                        }
                    })
                    ->options([
                        'marzban' => 'Marzban',
                        'marzneshin' => 'Marzneshin',
                        'xui' => 'X-UI',
                    ])
                    ->visible(fn (): bool => Reseller::where('type', 'traffic')->exists()),
            ])
            ->actions([
                Tables\Actions\Action::make('users')
                    ->label('کاربران')
                    ->icon('heroicon-o-users')
                    ->color('info')
                    ->url(fn (Reseller $record): string => static::getUrl('edit', ['record' => $record]))
                    ->visible(fn (Reseller $record): bool => $record->type === 'traffic'),

                Tables\Actions\Action::make('suspend')
                    ->label('تعلیق')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Reseller $record): bool => $record->status === 'active')
                    ->action(function (Reseller $record) {
                        $record->update(['status' => 'suspended']);
                        Notification::make()
                            ->success()
                            ->title('ریسلر با موفقیت معلق شد')
                            ->send();
                    }),

                Tables\Actions\Action::make('activate')
                    ->label('فعال‌سازی')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Reseller $record): bool => $record->status === 'suspended')
                    ->action(function (Reseller $record) {
                        $record->update(['status' => 'active']);

                        // Create audit log
                        \App\Models\AuditLog::log(
                            action: 'reseller_manually_activated',
                            targetType: 'reseller',
                            targetId: $record->id,
                            reason: 'admin_action',
                            meta: [
                                'previous_status' => 'suspended',
                            ]
                        );

                        // Always dispatch re-enable job for traffic resellers when manually activated
                        // Trust admin's judgment - they explicitly chose to activate this reseller
                        if ($record->type === 'traffic') {
                            \Illuminate\Support\Facades\Log::info("Dispatching ReenableResellerConfigsJob after manual activation for reseller {$record->id}");
                            \Modules\Reseller\Jobs\ReenableResellerConfigsJob::dispatch($record->id);
                        }

                        Notification::make()
                            ->success()
                            ->title('ریسلر با موفقیت فعال شد')
                            ->send();
                    }),

                Tables\Actions\Action::make('topup')
                    ->label('افزایش ترافیک')
                    ->icon('heroicon-o-arrow-up-circle')
                    ->color('warning')
                    ->visible(fn (Reseller $record): bool => $record->type === 'traffic')
                    ->form([
                        Forms\Components\TextInput::make('traffic_gb')
                            ->label('مقدار ترافیک (GB)')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->maxValue(100000)
                            ->helperText('مقدار ترافیک که می‌خواهید به ریسلر اضافه کنید'),
                    ])
                    ->action(function (Reseller $record, array $data) {
                        $additionalBytes = $data['traffic_gb'] * 1024 * 1024 * 1024;
                        $oldTotalBytes = $record->traffic_total_bytes;

                        $record->update([
                            'traffic_total_bytes' => $record->traffic_total_bytes + $additionalBytes,
                        ]);

                        // Create audit log for traffic top-up
                        \App\Models\AuditLog::log(
                            action: 'reseller_traffic_topup',
                            targetType: 'reseller',
                            targetId: $record->id,
                            reason: 'admin_action',
                            meta: [
                                'old_traffic_total_bytes' => $oldTotalBytes,
                                'new_traffic_total_bytes' => $record->traffic_total_bytes,
                                'additional_bytes' => $additionalBytes,
                                'additional_gb' => $data['traffic_gb'],
                            ]
                        );

                        // Dispatch job to re-enable configs if reseller was suspended
                        // After adding traffic, attempt to re-enable configs
                        if ($record->status === 'suspended') {
                            \Illuminate\Support\Facades\Log::info("Dispatching ReenableResellerConfigsJob after traffic topup for reseller {$record->id}");
                            \Modules\Reseller\Jobs\ReenableResellerConfigsJob::dispatch($record->id);
                        }

                        Notification::make()
                            ->success()
                            ->title('ترافیک با موفقیت افزایش یافت')
                            ->body("{$data['traffic_gb']} گیگابایت به ترافیک ریسلر اضافه شد")
                            ->send();
                    }),

                Tables\Actions\Action::make('extend')
                    ->label('تمدید بازه')
                    ->icon('heroicon-o-calendar')
                    ->color('info')
                    ->visible(fn (Reseller $record): bool => $record->type === 'traffic')
                    ->requiresConfirmation()
                    ->modalHeading('تمدید بازه زمانی')
                    ->modalDescription('آیا مطمئن هستید که می‌خواهید بازه زمانی این ریسلر را تمدید کنید؟')
                    ->modalSubmitActionLabel('تمدید')
                    ->modalCancelActionLabel('انصراف')
                    ->form([
                        Forms\Components\TextInput::make('days_to_extend')
                            ->label('افزایش روز (Extend by days)')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->maxValue(3650)
                            ->integer()
                            ->helperText('تعداد روزی که می‌خواهید به بازه زمانی اضافه کنید'),
                    ])
                    ->action(function (Reseller $record, array $data) {
                        try {
                            $daysToExtend = (int) $data['days_to_extend'];
                            $oldEndDate = $record->window_ends_at;

                            // Use model method to get base date
                            $baseDate = $record->getExtendWindowBaseDate();
                            $newEndDate = $baseDate->copy()->addDays($daysToExtend);

                            $record->update([
                                'window_ends_at' => $newEndDate,
                                'window_starts_at' => $record->window_starts_at ?? now(),
                            ]);

                            // Create audit log
                            \App\Models\AuditLog::log(
                                action: 'reseller_window_extended',
                                targetType: 'reseller',
                                targetId: $record->id,
                                reason: 'admin_action',
                                meta: [
                                    'old_window_ends_at' => $oldEndDate?->toDateTimeString(),
                                    'new_window_ends_at' => $newEndDate->toDateTimeString(),
                                    'days_added' => $daysToExtend,
                                ]
                            );

                            // Dispatch job to re-enable configs if reseller was suspended and now recovered
                            if ($record->status === 'suspended' && $record->hasTrafficRemaining() && $record->isWindowValid()) {
                                \Illuminate\Support\Facades\Log::info("Dispatching ReenableResellerConfigsJob after window extension (table action) for reseller {$record->id}");
                                \Modules\Reseller\Jobs\ReenableResellerConfigsJob::dispatch($record->id);
                            }

                            Notification::make()
                                ->success()
                                ->title('بازه زمانی با موفقیت تمدید شد')
                                ->body("{$daysToExtend} روز به بازه زمانی ریسلر اضافه شد")
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->danger()
                                ->title('خطا در تمدید بازه')
                                ->body('خطایی رخ داده است: '.$e->getMessage())
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('reset_usage')
                    ->label('بازنشانی مصرف')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (Reseller $record): bool => $record->type === 'traffic' && auth()->user()?->is_admin)
                    ->requiresConfirmation()
                    ->modalHeading('بازنشانی مصرف ترافیک (Reset Traffic Usage)')
                    ->modalDescription('این عملیات شمارنده کل مصرف ترافیک ریسلر را به صفر تنظیم می‌کند (بخشودگی سهمیه). کانفیگ‌های فردی ریسلر دست نخورده باقی می‌مانند. این تغییر محدودیت کل ترافیک را تغییر نمی‌دهد. آیا مطمئن هستید؟ / This resets the reseller\'s aggregate traffic counter to 0 (quota forgiveness). Individual configs remain untouched. This does not change the total traffic limit. Continue?')
                    ->modalSubmitActionLabel('بله، بازنشانی شود (Yes, Reset)')
                    ->modalCancelActionLabel('انصراف (Cancel)')
                    ->action(function (Reseller $record) {
                        try {
                            $oldUsedBytes = $record->traffic_used_bytes;

                            // Calculate the actual usage from configs
                            $totalUsageFromConfigs = $record->configs()
                                ->get()
                                ->sum(function ($config) {
                                    return $config->usage_bytes + (int) data_get($config->meta, 'settled_usage_bytes', 0);
                                });

                            // Set admin_forgiven_bytes to the total config usage
                            // This way, effective usage = totalUsageFromConfigs - admin_forgiven_bytes = 0
                            $record->update([
                                'admin_forgiven_bytes' => $totalUsageFromConfigs,
                                'traffic_used_bytes' => 0,  // Sync job will maintain this at 0
                            ]);

                            // Create audit log
                            \App\Models\AuditLog::log(
                                action: 'reseller_usage_admin_reset',
                                targetType: 'reseller',
                                targetId: $record->id,
                                reason: 'admin_action',
                                meta: [
                                    'old_traffic_used_bytes' => $oldUsedBytes,
                                    'new_traffic_used_bytes' => 0,
                                    'admin_forgiven_bytes' => $totalUsageFromConfigs,
                                    'traffic_total_bytes' => $record->traffic_total_bytes,
                                    'note' => 'Admin quota forgiveness - config usage intact, forgiven bytes tracked',
                                ]
                            );

                            // If reseller was suspended and now has remaining quota and valid window,
                            // dispatch job to re-enable configs
                            if ($record->status === 'suspended' && $record->hasTrafficRemaining() && $record->isWindowValid()) {
                                \Illuminate\Support\Facades\Log::info("Dispatching ReenableResellerConfigsJob after usage reset (table action) for reseller {$record->id}");
                                \Modules\Reseller\Jobs\ReenableResellerConfigsJob::dispatch($record->id);
                            }

                            Notification::make()
                                ->success()
                                ->title('مصرف ترافیک با موفقیت بازنشانی شد')
                                ->body('شمارنده مصرف ریسلر به صفر تنظیم شد (کانفیگ‌ها دست نخورده)')
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->danger()
                                ->title('خطا در بازنشانی مصرف')
                                ->body('خطایی رخ داده است: '.$e->getMessage())
                                ->send();
                        }
                    }),

                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('suspend')
                        ->label('تعلیق گروهی')
                        ->icon('heroicon-o-no-symbol')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            $records->each(fn (Reseller $record) => $record->update(['status' => 'suspended']));
                            Notification::make()
                                ->success()
                                ->title('ریسلرهای انتخاب شده با موفقیت معلق شدند')
                                ->send();
                        }),

                    Tables\Actions\BulkAction::make('activate')
                        ->label('فعال‌سازی گروهی')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            $activatedCount = 0;
                            $reenableJobsDispatched = 0;

                            foreach ($records as $record) {
                                if ($record->status !== 'active') {
                                    $previousStatus = $record->status;
                                    $record->update(['status' => 'active']);
                                    $activatedCount++;

                                    // Create audit log
                                    \App\Models\AuditLog::log(
                                        action: 'reseller_bulk_activated',
                                        targetType: 'reseller',
                                        targetId: $record->id,
                                        reason: 'admin_bulk_action',
                                        meta: [
                                            'previous_status' => $previousStatus,
                                        ]
                                    );

                                    // Always dispatch re-enable job for traffic resellers
                                    // Trust admin's bulk activation intent
                                    if ($record->type === 'traffic') {
                                        \Illuminate\Support\Facades\Log::info("Dispatching ReenableResellerConfigsJob after bulk activation for reseller {$record->id}");
                                        \Modules\Reseller\Jobs\ReenableResellerConfigsJob::dispatch($record->id);
                                        $reenableJobsDispatched++;
                                    }
                                }
                            }

                            Notification::make()
                                ->success()
                                ->title("ریسلرهای انتخاب شده با موفقیت فعال شدند ({$activatedCount} فعال شد، {$reenableJobsDispatched} کانفیگ برای فعال‌سازی)")
                                ->send();
                        }),

                    Tables\Actions\ExportBulkAction::make()
                        ->label('خروجی CSV'),
                ]),
            ])
            ->searchable()
            ->defaultSort('id', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ConfigsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListResellers::route('/'),
            'create' => Pages\CreateReseller::route('/create'),
            'view' => Pages\ViewReseller::route('/{record}'),
            'edit' => Pages\EditReseller::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = parent::getEloquentQuery();

        $user = auth()->user();

        // Super admins and admins see all resellers
        if ($user->hasAnyRole(['super-admin', 'admin'])) {
            return $query;
        }

        // Resellers can only see their own record
        if ($user->hasRole('reseller') && $user->reseller) {
            return $query->where('id', $user->reseller->id);
        }

        // Regular users see nothing
        return $query->whereRaw('1 = 0');
    }
}
