<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WalletTopUpTransactionResource\Pages;
use App\Jobs\ReenableResellerConfigsJob;
use App\Models\Reseller;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\WalletResellerReenableService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram;

class WalletTopUpTransactionResource extends Resource
{
    protected static ?string $model = Transaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'مدیریت مالی';

    protected static ?string $navigationLabel = 'تاییدیه شارژ کیف پول';

    protected static ?string $pluralModelLabel = 'تاییدیه‌های شارژ کیف پول';

    protected static ?string $modelLabel = 'تاییدیه شارژ';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        // Only show deposit transactions (wallet top-ups)
        return parent::getEloquentQuery()
            ->where('type', Transaction::TYPE_DEPOSIT);
    }

    public static function getNavigationBadge(): ?string
    {
        // Show count of pending wallet top-ups
        return (string) Transaction::where('type', Transaction::TYPE_DEPOSIT)
            ->where('status', Transaction::STATUS_PENDING)
            ->count();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $count = Transaction::where('type', Transaction::TYPE_DEPOSIT)
            ->where('status', Transaction::STATUS_PENDING)
            ->count();

        return $count > 0 ? 'warning' : 'success';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('اطلاعات تراکنش')
                    ->schema([
                        Forms\Components\TextInput::make('user.name')
                            ->label('کاربر')
                            ->disabled(),

                        Forms\Components\TextInput::make('amount')
                            ->label('مبلغ (تومان)')
                            ->disabled()
                            ->numeric()
                            ->formatStateUsing(fn ($state) => number_format($state)),

                        Forms\Components\Select::make('status')
                            ->label('وضعیت')
                            ->options([
                                Transaction::STATUS_PENDING => 'در انتظار تایید',
                                Transaction::STATUS_COMPLETED => 'تایید شده',
                                Transaction::STATUS_FAILED => 'رد شده',
                            ])
                            ->disabled(),

                        Forms\Components\Textarea::make('description')
                            ->label('توضیحات')
                            ->disabled()
                            ->rows(3),

                        Forms\Components\TextInput::make('created_at')
                            ->label('تاریخ درخواست')
                            ->disabled()
                            ->formatStateUsing(fn ($state) => $state?->format('Y-m-d H:i')),

                        Forms\Components\ViewField::make('proof_image_path')
                            ->label('رسید پرداخت')
                            ->view('filament.forms.components.proof-image-preview')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('شناسه')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('کاربر')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Transaction $record): string => $record->user->email ?? ''),

                Tables\Columns\TextColumn::make('amount')
                    ->label('مبلغ (تومان)')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => number_format($state))
                    ->color('success')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('status')
                    ->label('وضعیت')
                    ->badge()
                    ->sortable()
                    ->color(fn (string $state): string => match ($state) {
                        Transaction::STATUS_PENDING => 'warning',
                        Transaction::STATUS_COMPLETED => 'success',
                        Transaction::STATUS_FAILED => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Transaction::STATUS_PENDING => 'در انتظار تایید',
                        Transaction::STATUS_COMPLETED => 'تایید شده',
                        Transaction::STATUS_FAILED => 'رد شده',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('description')
                    ->label('توضیحات')
                    ->limit(50)
                    ->wrap(),

                Tables\Columns\ImageColumn::make('proof_image_path')
                    ->label('رسید')
                    ->disk('public')
                    ->width(60)
                    ->height(60)
                    ->defaultImageUrl(asset('images/no-image.png'))
                    ->extraImgAttributes(['class' => 'rounded-lg']),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاریخ درخواست')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('وضعیت')
                    ->options([
                        Transaction::STATUS_PENDING => 'در انتظار تایید',
                        Transaction::STATUS_COMPLETED => 'تایید شده',
                        Transaction::STATUS_FAILED => 'رد شده',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\Action::make('view_proof')
                    ->label('مشاهده رسید')
                    ->icon('heroicon-o-photo')
                    ->color('info')
                    ->modalHeading('رسید پرداخت')
                    ->modalContent(fn (Transaction $record): \Illuminate\Contracts\View\View => view(
                        'filament.modals.proof-image',
                        ['record' => $record]
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('بستن')
                    ->visible(fn (Transaction $record): bool => ! empty($record->proof_image_path)),

                Tables\Actions\Action::make('approve')
                    ->label('تایید')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('تایید شارژ کیف پول')
                    ->modalDescription(fn (Transaction $record) => 'آیا از تایید شارژ کیف پول به مبلغ '.number_format($record->amount).' تومان برای کاربر '.($record->user->name ?? $record->user->email).' اطمینان دارید؟'
                    )
                    ->visible(fn (Transaction $record): bool => $record->status === Transaction::STATUS_PENDING)
                    ->action(function (Transaction $record) {
                        DB::transaction(function () use ($record) {
                            $user = $record->user;
                            $reseller = $user->reseller;

                            // Update transaction status
                            $record->update(['status' => Transaction::STATUS_COMPLETED]);

                            // Credit the appropriate wallet
                            if ($reseller && method_exists($reseller, 'isWalletBased') && $reseller->isWalletBased()) {
                                // For wallet-based resellers, charge their reseller wallet
                                $reseller->increment('wallet_balance', $record->amount);

                                Log::info('Wallet charge approved for reseller', [
                                    'transaction_id' => $record->id,
                                    'reseller_id' => $reseller->id,
                                    'user_id' => $user->id,
                                    'amount' => $record->amount,
                                    'new_balance' => $reseller->fresh()->wallet_balance,
                                ]);

                                // Check if reseller was suspended and should be reactivated
                                if (method_exists($reseller, 'isSuspendedWallet') &&
                                    $reseller->isSuspendedWallet() &&
                                    $reseller->wallet_balance > config('billing.wallet.suspension_threshold', -1000)) {
                                    $reseller->update(['status' => 'active']);
                                    $reseller->refresh();

                                    Cache::forget("reseller_status:{$reseller->id}");

                                    Log::info('wallet_topup_completed_reseller_activated', [
                                        'action' => 'wallet_topup_completed_reseller_activated',
                                        'reseller_id' => $reseller->id,
                                        'user_id' => $user->id,
                                        'wallet_balance' => $reseller->wallet_balance,
                                    ]);

                                    // Re-enable configs that were auto-disabled due to wallet suspension
                                    $reenableService = new WalletResellerReenableService();
                                    $reenableStats = $reenableService->reenableWalletSuspendedConfigs($reseller);

                                    dispatch(new ReenableResellerConfigsJob($reseller, 'wallet'));

                                    Log::info('wallet_reenable_job_dispatched', [
                                        'action' => 'wallet_reenable_job_dispatched',
                                        'reseller_id' => $reseller->id,
                                        'user_id' => $user->id,
                                        'suspension_reason' => 'wallet',
                                    ]);

                                    Notification::make()
                                        ->title('ریسلر به طور خودکار فعال شد.')
                                        ->body($reenableStats['enabled'] > 0
                                            ? "{$reenableStats['enabled']} کانفیگ به طور خودکار فعال شد."
                                            : 'هیچ کانفیگی برای فعال‌سازی مجدد یافت نشد.')
                                        ->success()
                                        ->send();
                                }

                                Notification::make()
                                    ->title('کیف پول ریسلر با موفقیت شارژ شد.')
                                    ->body('مبلغ '.number_format($record->amount).' تومان به کیف پول ریسلر اضافه شد.')
                                    ->success()
                                    ->send();
                            } else {
                                // For regular users, charge their user balance
                                $user->increment('balance', $record->amount);

                                Log::info('Wallet charge approved for user', [
                                    'transaction_id' => $record->id,
                                    'user_id' => $user->id,
                                    'amount' => $record->amount,
                                    'new_balance' => $user->fresh()->balance,
                                ]);

                                Notification::make()
                                    ->title('کیف پول کاربر با موفقیت شارژ شد.')
                                    ->body('مبلغ '.number_format($record->amount).' تومان به کیف پول کاربر اضافه شد.')
                                    ->success()
                                    ->send();
                            }

                            // Send Telegram notification
                            if ($user->telegram_chat_id) {
                                try {
                                    $settings = Setting::all()->pluck('value', 'key');
                                    $balance = ($reseller && method_exists($reseller, 'isWalletBased') && $reseller->isWalletBased())
                                        ? $reseller->fresh()->wallet_balance
                                        : $user->fresh()->balance;

                                    $telegramMessage = '✅ کیف پول شما به مبلغ *'.number_format($record->amount)." تومان* با موفقیت شارژ شد.\n\n";
                                    $telegramMessage .= 'موجودی جدید شما: *'.number_format($balance).' تومان*';

                                    Telegram::setAccessToken($settings->get('telegram_bot_token'));
                                    Telegram::sendMessage([
                                        'chat_id' => $user->telegram_chat_id,
                                        'text' => $telegramMessage,
                                        'parse_mode' => 'Markdown',
                                    ]);
                                } catch (\Exception $e) {
                                    Log::error('Failed to send wallet charge notification via Telegram: '.$e->getMessage());
                                }
                            }
                        });
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('رد')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('رد شارژ کیف پول')
                    ->modalDescription(fn (Transaction $record) => 'آیا از رد شارژ کیف پول به مبلغ '.number_format($record->amount).' تومان برای کاربر '.($record->user->name ?? $record->user->email).' اطمینان دارید؟'
                    )
                    ->visible(fn (Transaction $record): bool => $record->status === Transaction::STATUS_PENDING)
                    ->action(function (Transaction $record) {
                        $record->update(['status' => Transaction::STATUS_FAILED]);

                        Log::info('Wallet charge rejected', [
                            'transaction_id' => $record->id,
                            'user_id' => $record->user_id,
                            'amount' => $record->amount,
                        ]);

                        Notification::make()
                            ->title('درخواست شارژ رد شد.')
                            ->body('درخواست شارژ کیف پول به مبلغ '.number_format($record->amount).' تومان رد شد.')
                            ->warning()
                            ->send();
                    }),
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
            'index' => Pages\ListWalletTopUpTransactions::route('/'),
            'view' => Pages\ViewWalletTopUpTransaction::route('/{record}'),
        ];
    }
}
