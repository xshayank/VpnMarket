<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PanelResource\Pages;
use App\Models\Panel;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PanelResource extends Resource
{
    protected static ?string $model = Panel::class;

    protected static ?string $navigationIcon = 'heroicon-o-server-stack';

    protected static ?string $navigationGroup = 'مدیریت پنل‌ها';

    protected static ?string $navigationLabel = 'پنل‌های V2Ray';

    protected static ?string $pluralModelLabel = 'پنل‌های V2Ray';

    protected static ?string $modelLabel = 'پنل V2Ray';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('نام پنل')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('url')
                    ->label('آدرس URL پنل')
                    ->required()
                    ->url()
                    ->maxLength(255)
                    ->helperText('مثال: https://panel.example.com'),
                Forms\Components\Select::make('panel_type')
                    ->label('نوع پنل')
                    ->options([
                        'marzban' => 'مرزبان',
                        'marzneshin' => 'مرزنشین',
                        'xui' => 'سنایی / X-UI',
                        'v2ray' => 'V2Ray',
                        'eylandoo' => 'Eylandoo',
                        'other' => 'سایر',
                    ])
                    ->required()
                    ->default('marzban'),
                Forms\Components\TextInput::make('username')
                    ->label('نام کاربری')
                    ->maxLength(255)
                    ->helperText('نام کاربری ادمین پنل'),
                Forms\Components\TextInput::make('password')
                    ->label('رمز عبور')
                    ->password()
                    ->dehydrated(fn ($state) => filled($state))
                    ->helperText('رمز عبور به صورت رمزنگاری شده ذخیره می‌شود'),
                Forms\Components\TextInput::make('api_token')
                    ->label('توکن API')
                    ->password()
                    ->dehydrated(fn ($state) => filled($state))
                    ->helperText('در صورت نیاز، توکن API پنل را وارد کنید'),
                Forms\Components\KeyValue::make('extra')
                    ->label('تنظیمات اضافی')
                    ->helperText('تنظیمات خاص پنل (مثل node_hostname، default_inbound_id و...)'),
                Forms\Components\Toggle::make('is_active')
                    ->label('فعال')
                    ->default(true),
                
                Forms\Components\Toggle::make('auto_assign_to_resellers')
                    ->label('اتصال خودکار به همه ریسلرها')
                    ->helperText('با فعالسازی، این پنل به همه ریسلرهای فعلی و جدید اختصاص می‌یابد.')
                    ->default(false)
                    ->reactive(),
                
                // Eylandoo panel: Default Nodes for New Resellers
                Forms\Components\Select::make('registration_default_node_ids')
                    ->label('نودهای پیش‌فرض برای نمایندگان جدید (Eylandoo)')
                    ->multiple()
                    ->searchable()
                    ->visible(fn ($get) => strtolower(trim($get('panel_type') ?? '')) === 'eylandoo')
                    ->options(function ($record) {
                        if (!$record || strtolower(trim($record->panel_type ?? '')) !== 'eylandoo') {
                            return [];
                        }
                        
                        try {
                            $nodes = $record->getCachedEylandooNodes();
                            $options = [];
                            foreach ($nodes as $node) {
                                $options[$node['id']] = $node['name'];
                            }
                            return $options;
                        } catch (\Exception $e) {
                            return [];
                        }
                    })
                    ->helperText('نودهایی که به صورت خودکار به نمایندگان جدید اختصاص داده می‌شوند. در صورت خطا در دریافت لیست نودها، این فیلد خالی خواهد بود.')
                    ->placeholder('انتخاب نودهای پیش‌فرض'),
                
                // Marzneshin panel: Default Services for New Resellers
                Forms\Components\Select::make('registration_default_service_ids')
                    ->label('سرویس‌های پیش‌فرض برای نمایندگان جدید (Marzneshin)')
                    ->multiple()
                    ->searchable()
                    ->visible(fn ($get) => strtolower(trim($get('panel_type') ?? '')) === 'marzneshin')
                    ->options(function ($record) {
                        if (!$record || strtolower(trim($record->panel_type ?? '')) !== 'marzneshin') {
                            return [];
                        }
                        
                        try {
                            $services = $record->getCachedMarzneshinServices();
                            $options = [];
                            foreach ($services as $service) {
                                $options[$service['id']] = $service['name'];
                            }
                            return $options;
                        } catch (\Exception $e) {
                            return [];
                        }
                    })
                    ->helperText('سرویس‌هایی که به صورت خودکار به نمایندگان جدید اختصاص داده می‌شوند. در صورت خطا در دریافت لیست سرویس‌ها، این فیلد خالی خواهد بود.')
                    ->placeholder('انتخاب سرویس‌های پیش‌فرض'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('نام پنل')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('url')
                    ->label('آدرس URL')
                    ->searchable()
                    ->limit(50),
                Tables\Columns\TextColumn::make('panel_type')
                    ->label('نوع پنل')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'marzban' => 'success',
                        'marzneshin' => 'info',
                        'xui' => 'warning',
                        'v2ray' => 'primary',
                        'eylandoo' => 'primary',
                        'other' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'marzban' => 'مرزبان',
                        'marzneshin' => 'مرزنشین',
                        'xui' => 'سنایی / X-UI',
                        'v2ray' => 'V2Ray',
                        'eylandoo' => 'Eylandoo',
                        'other' => 'سایر',
                        default => $state,
                    }),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('فعال')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاریخ ایجاد')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManagePanels::route('/'),
        ];
    }
}
