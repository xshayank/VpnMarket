<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Jobs\ReenableResellerConfigsJob;
use App\Models\Reseller;
use App\Models\Transaction;
use App\Services\Payments\Tetra98Client;
use App\Services\WalletResellerReenableService;
use App\Support\Tetra98Config;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class Tetra98Controller extends Controller
{
    public function __construct(
        private readonly Tetra98Client $client,
        private readonly WalletResellerReenableService $walletResellerReenableService
    ) {}

    public function initiate(Request $request)
    {
        if (! Tetra98Config::isAvailable()) {
            abort(SymfonyResponse::HTTP_FORBIDDEN, 'درگاه Tetra98 فعال نیست.');
        }

        $minAmount = Tetra98Config::getMinAmountToman();

        $user = Auth::user();
        $reseller = $user->reseller;
        $chargeMode = $reseller && $reseller->isTrafficBased() ? 'traffic' : 'wallet';
        $minAmountGb = null;

        $rules = [
            'phone' => ['required', 'regex:/^09\d{9}$/'],
        ];

        if ($chargeMode === 'wallet') {
            $rules['amount'] = ['required', 'integer', 'min:'.$minAmount];
        } else {
            $minAmountGb = $reseller && $reseller->isAnySuspended()
                ? config('billing.min_first_traffic_topup_gb', config('billing.reseller.first_topup.traffic_min_gb', 250))
                : config('billing.min_traffic_topup_gb', config('billing.reseller.min_topup.traffic_gb', 50));
            $rules['traffic_gb'] = ['required', 'integer', 'min:'.$minAmountGb];
        }

        $validated = $request->validate($rules, [
            'amount.required' => 'وارد کردن مبلغ الزامی است.',
            'amount.integer' => 'مبلغ باید به صورت عددی وارد شود.',
            'amount.min' => 'حداقل مبلغ مجاز برای پرداخت '.number_format($minAmount).' تومان است.',
            'traffic_gb.required' => 'مقدار ترافیک الزامی است.',
            'traffic_gb.integer' => 'ترافیک باید به صورت عدد صحیح وارد شود.',
            'traffic_gb.min' => 'حداقل مقدار خرید '.($minAmountGb ?? 0).' گیگابایت است.',
            'phone.required' => 'وارد کردن شماره موبایل برای پرداخت Tetra98 الزامی است.',
            'phone.regex' => 'شماره موبایل باید با 09 شروع شده و 11 رقم باشد.',
        ]);

        $trafficGb = $chargeMode === 'traffic' ? (int) ($validated['traffic_gb'] ?? 0) : null;
        $amountToman = $chargeMode === 'traffic'
            ? (int) ($trafficGb * config('billing.traffic_rate_per_gb', config('billing.reseller.traffic.price_per_gb', 750)))
            : (int) $validated['amount'];

        try {
            $hashId = 'tetra98-'.$user->id.'-'.Str::uuid()->toString();
            $metadata = [
                'payment_method' => 'tetra98',
                'phone' => $validated['phone'],
                'email' => $user->email,
                'deposit_mode' => $chargeMode,
                'type' => $chargeMode === 'traffic' ? Transaction::SUBTYPE_DEPOSIT_TRAFFIC : Transaction::SUBTYPE_DEPOSIT_WALLET,
                'traffic_gb' => $trafficGb,
                'rate_per_gb' => config('billing.traffic_rate_per_gb', config('billing.reseller.traffic.price_per_gb', 750)),
                'computed_amount_toman' => $amountToman,
                'first_topup' => $reseller?->isAnySuspended() ?? false,
                'tetra98' => [
                    'hash_id' => $hashId,
                    'amount_toman' => $amountToman,
                    'state' => 'created',
                ],
            ];

            $transaction = Transaction::create([
                'user_id' => $user->id,
                'order_id' => null,
                'amount' => $amountToman,
                'type' => Transaction::TYPE_DEPOSIT,
                'status' => Transaction::STATUS_PENDING,
                'description' => $chargeMode === 'traffic'
                    ? 'خرید ترافیک (درگاه Tetra98) - در انتظار پرداخت'
                    : 'شارژ کیف پول (درگاه Tetra98) - در انتظار پرداخت',
                'metadata' => $metadata,
            ]);

            Log::info('traffic_topup_initiated', [
                'action' => 'traffic_topup_initiated',
                'method' => 'tetra98',
                'reseller_id' => $reseller?->id,
                'user_id' => $user->id,
                'traffic_gb' => $trafficGb,
                'rate_per_gb' => $metadata['rate_per_gb'] ?? null,
                'amount_toman' => $amountToman,
                'charge_mode' => $chargeMode,
            ]);
        } catch (Throwable $exception) {
            Log::error('tetra98_initiate_transaction_failed', [
                'action' => 'tetra98_initiate_transaction_failed',
                'user_id' => $user->id,
                'amount' => $amountToman,
                'message' => $exception->getMessage(),
            ]);

            return back()->withErrors(['tetra98' => 'خطایی در ثبت تراکنش رخ داد. لطفاً دوباره تلاش کنید.'])->withInput();
        }

        $callbackUrl = URL::to(Tetra98Config::getCallbackPath());
        $description = Tetra98Config::getDefaultDescription();

        Log::info('tetra98_initiate_request', [
            'action' => 'tetra98_initiate_request',
            'transaction_id' => $transaction->id,
            'user_id' => $user->id,
            'amount' => $transaction->amount,
            'hash_id' => Arr::get($transaction->metadata, 'tetra98.hash_id'),
            'callback_url' => $callbackUrl,
        ]);

        try {
            $response = $this->client->createOrder(
                Arr::get($transaction->metadata, 'tetra98.hash_id'),
                (int) $transaction->amount,
                $description,
                $user->email,
                $validated['phone'],
                $callbackUrl
            );
        } catch (Throwable $exception) {
            $this->markTransactionFailed($transaction, [
                'error' => $exception->getMessage(),
            ]);

            Log::error('tetra98_initiate_exception', [
                'action' => 'tetra98_initiate_exception',
                'transaction_id' => $transaction->id,
                'user_id' => $user->id,
                'message' => $exception->getMessage(),
            ]);

            return back()->with('tetra98_error', 'امکان برقراری ارتباط با درگاه Tetra98 وجود ندارد. لطفاً بعداً تلاش کنید.')->withInput();
        }

        $responseData = $response->json();

        Log::info('tetra98_initiate_response', [
            'action' => 'tetra98_initiate_response',
            'transaction_id' => $transaction->id,
            'user_id' => $user->id,
            'http_status' => $response->status(),
            'response' => $this->sanitizePayload($responseData),
        ]);

        if (! $response->successful() || (string) Arr::get($responseData, 'status') !== '100') {
            $this->markTransactionFailed($transaction, [
                'initiate_response' => $this->sanitizePayload($responseData),
                'http_status' => $response->status(),
            ]);

            return back()->with('tetra98_error', 'درگاه Tetra98 در حال حاضر در دسترس نیست. لطفاً دوباره تلاش کنید.')->withInput();
        }

        $paymentUrl = Arr::get($responseData, 'payment_url_web');
        $authority = Arr::get($responseData, 'Authority');

        if (! $paymentUrl || ! $authority) {
            $this->markTransactionFailed($transaction, [
                'initiate_response' => $this->sanitizePayload($responseData),
                'missing_fields' => true,
            ]);

            return back()->with('tetra98_error', 'پاسخ نامعتبر از درگاه Tetra98 دریافت شد.')->withInput();
        }

        $metadata = $transaction->metadata ?? [];
        $metadata['tetra98'] = array_merge($metadata['tetra98'] ?? [], [
            'authority' => $authority,
            'tracking_id' => Arr::get($responseData, 'tracking_id'),
            'payment_url_web' => $paymentUrl,
            'payment_url_bot' => Arr::get($responseData, 'payment_url_bot'),
            'initiate_response' => $this->sanitizePayload($responseData),
            'state' => 'redirected',
        ]);

        $transaction->update(['metadata' => $metadata]);

        return redirect()->away($paymentUrl);
    }

    public function callback(Request $request)
    {
        $payload = $request->all();

        if (count(array_filter($payload, static fn ($value) => $value !== null && $value !== '')) === 0) {
            Log::info('tetra98_callback_empty_payload', [
                'action' => 'tetra98_callback_empty_payload',
                'http_status' => SymfonyResponse::HTTP_FOUND,
            ]);

            return redirect('/wallet/charge');
        }

        $hashId = (string) Arr::get($payload, 'hashid');
        $authority = (string) Arr::get($payload, 'authority', Arr::get($payload, 'Authority'));
        $statusValue = Arr::get($payload, 'status');
        $statusInt = is_numeric($statusValue) ? (int) $statusValue : (int) ((string) $statusValue === '100');

        Log::info('tetra98_callback_received', [
            'action' => 'tetra98_callback_received',
            'hash_id' => $hashId,
            'authority' => $authority,
            'status' => $statusValue,
            'payload_size' => strlen(json_encode($payload)),
            'http_status' => SymfonyResponse::HTTP_OK,
        ]);

        // Check for missing authority
        if ($authority === '') {
            Log::warning('tetra98_callback_missing_authority', [
                'action' => 'tetra98_callback_missing_authority',
                'payload' => $this->sanitizePayload($payload),
            ]);

            return response()->json(['message' => 'authority missing'], SymfonyResponse::HTTP_BAD_REQUEST);
        }

        // Find pending transaction by authority (primary lookup)
        $transaction = Transaction::whereJsonContains('metadata->tetra98->authority', $authority)
            ->where('status', Transaction::STATUS_PENDING)
            ->first();

        // Fallback: try hash_id if authority lookup fails (for backward compatibility)
        if (! $transaction && $hashId !== '') {
            $transaction = Transaction::whereJsonContains('metadata->tetra98->hash_id', $hashId)
                ->where('status', Transaction::STATUS_PENDING)
                ->first();
        }

        // Check if transaction not found OR already processed (idempotency check)
        if (! $transaction) {
            // Check if it was already completed
            $completedTransaction = Transaction::whereJsonContains('metadata->tetra98->authority', $authority)
                ->where('status', Transaction::STATUS_COMPLETED)
                ->first();

            if ($completedTransaction) {
                Log::info('tetra98_callback_transaction_not_found_or_already_processed', [
                    'action' => 'tetra98_callback_transaction_not_found_or_already_processed',
                    'authority' => $authority,
                    'transaction_id' => $completedTransaction->id,
                    'reason' => 'already_completed',
                ]);

                return response('OK', 200);
            }

            Log::warning('tetra98_callback_transaction_not_found_or_already_processed', [
                'action' => 'tetra98_callback_transaction_not_found_or_already_processed',
                'authority' => $authority,
                'hash_id' => $hashId,
                'reason' => 'not_found',
            ]);

            return response('OK', 200);
        }

        // Additional idempotency check: verify transaction metadata state
        $metadata = $transaction->metadata ?? [];
        $tetraMeta = $metadata['tetra98'] ?? [];
        if (($tetraMeta['state'] ?? null) === 'completed') {
            Log::info('tetra98_callback_transaction_not_found_or_already_processed', [
                'action' => 'tetra98_callback_transaction_not_found_or_already_processed',
                'authority' => $authority,
                'transaction_id' => $transaction->id,
                'reason' => 'metadata_state_completed',
            ]);

            return response('OK', 200);
        }

        // Verify payment with Tetra98
        $verifyResponse = null;
        $verifyData = null;
        $verifySuccessful = false;
        $verifyHttpStatus = null;

        if ($statusInt === 100 && $authority !== '') {
            try {
                $verifyResponse = $this->client->verify($authority);
                $verifyHttpStatus = $verifyResponse->status();
                $verifyData = $verifyResponse->json();
                $verifySuccessful = $verifyResponse->successful() && (string) Arr::get($verifyData, 'status') === '100';

                Log::info('tetra98_verify_response', [
                    'action' => 'tetra98_verify_response',
                    'transaction_id' => $transaction->id,
                    'authority' => $authority,
                    'http_status' => $verifyHttpStatus,
                    'verify_status' => Arr::get($verifyData, 'status'),
                    'verify_successful' => $verifySuccessful,
                    'response' => $this->sanitizePayload($verifyData),
                ]);
            } catch (Throwable $exception) {
                Log::error('tetra98_verify_exception', [
                    'action' => 'tetra98_verify_exception',
                    'transaction_id' => $transaction->id,
                    'authority' => $authority,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $resellerIdForReenable = null;

        DB::transaction(function () use (
            $transaction,
            $payload,
            $authority,
            $statusValue,
            $statusInt,
            $verifySuccessful,
            $verifyData,
            $verifyHttpStatus,
            &$resellerIdForReenable
        ) {
            $fresh = Transaction::whereKey($transaction->id)->lockForUpdate()->first();

            $metadata = $fresh->metadata ?? [];
            $tetraMeta = $metadata['tetra98'] ?? [];

            if ($authority !== '') {
                $tetraMeta['authority'] = $authority;
            }

            $tetraMeta['last_status'] = $statusValue;
            $tetraMeta['callback_payload'] = $this->sanitizePayload($payload);
            if ($verifyData !== null) {
                $tetraMeta['verify_response'] = $this->sanitizePayload($verifyData);
                $tetraMeta['verify_http_status'] = $verifyHttpStatus;
                $tetraMeta['verified_at'] = now()->toIso8601String();
            }

            if ($fresh->status === Transaction::STATUS_COMPLETED) {
                $tetraMeta['state'] = 'completed';
                $tetraMeta['verification_status'] = $tetraMeta['verification_status'] ?? 'success';
                $metadata['tetra98'] = $tetraMeta;
                $fresh->update(['metadata' => $metadata]);

                Log::info('tetra98_verify_success', [
                    'action' => 'tetra98_verify_success',
                    'transaction_id' => $fresh->id,
                    'user_id' => $fresh->user_id,
                    'authority' => $authority,
                    'amount' => $fresh->amount,
                    'idempotent' => true,
                ]);

                return;
            }

            if ($statusInt !== 100 || ! $verifySuccessful) {
                $tetraMeta['state'] = 'failed';
                $tetraMeta['verification_status'] = 'failed';
                $metadata['tetra98'] = $tetraMeta;
                $fresh->update([
                    'status' => Transaction::STATUS_FAILED,
                    'metadata' => $metadata,
                ]);

                Log::warning('tetra98_verify_failed', [
                    'action' => 'tetra98_verify_failed',
                    'transaction_id' => $fresh->id,
                    'user_id' => $fresh->user_id,
                    'authority' => $authority,
                    'amount' => $fresh->amount,
                    'status_value' => $statusValue,
                    'verify_http_status' => $verifyHttpStatus,
                ]);

                return;
            }

            $user = $fresh->user()->lockForUpdate()->first();
            $reseller = $user?->reseller()->lockForUpdate()->first();

            $tetraMeta['state'] = 'completed';
            $tetraMeta['verification_status'] = 'success';
            $tetraMeta['wallet_credited_at'] = now()->toIso8601String();
            $metadata['tetra98'] = $tetraMeta;

            $depositMode = $metadata['deposit_mode'] ?? ($reseller?->isTrafficBased() ? 'traffic' : 'wallet');
            $ratePerGb = (int) ($metadata['rate_per_gb'] ?? config('billing.traffic_rate_per_gb', config('billing.reseller.traffic.price_per_gb', 750)));
            $trafficGb = (int) ($metadata['traffic_gb'] ?? 0);
            $computedAmount = (int) ($metadata['computed_amount_toman'] ?? $fresh->amount);

            Log::info('tetra98_callback_verified', [
                'action' => 'tetra98_callback_verified',
                'transaction_id' => $fresh->id,
                'user_id' => $fresh->user_id,
                'authority' => $authority,
                'amount' => $fresh->amount,
                'idempotent' => false,
                'deposit_mode' => $depositMode,
            ]);

            if ($depositMode === 'traffic' && $reseller instanceof Reseller) {
                $bytes = (int) ($trafficGb * 1024 * 1024 * 1024);
                $oldTotalBytes = $reseller->traffic_total_bytes;
                $reseller->traffic_total_bytes += $bytes;
                $reseller->save();

                $metadata['traffic_topup'] = [
                    'traffic_gb' => $trafficGb,
                    'credited_bytes' => $bytes,
                    'computed_amount_toman' => $computedAmount,
                ];

                $fresh->update([
                    'status' => Transaction::STATUS_COMPLETED,
                    'description' => 'خرید ترافیک (درگاه Tetra98)',
                    'metadata' => $metadata,
                    'amount' => $computedAmount,
                ]);

                Log::info('tetra98_traffic_purchased', [
                    'action' => 'tetra98_traffic_purchased',
                    'reseller_id' => $reseller->id,
                    'transaction_id' => $fresh->id,
                    'traffic_gb' => $trafficGb,
                    'added_gb' => $trafficGb,
                    'old_total_bytes' => $oldTotalBytes,
                    'new_total_bytes' => $reseller->traffic_total_bytes,
                    'amount_toman' => $computedAmount,
                ]);

                if ($reseller->isSuspendedTraffic()) {
                    $reseller->status = 'active';
                    $reseller->save();

                    dispatch(new ReenableResellerConfigsJob($reseller, 'traffic'));

                    Log::info('reseller_activated_from_first_topup', [
                        'action' => 'reseller_activated_from_first_topup',
                        'reseller_id' => $reseller->id,
                        'transaction_id' => $fresh->id,
                        'traffic_gb' => $trafficGb,
                    ]);
                }
            } else {
                $oldBalance = null;
                $newBalance = null;

                if ($reseller instanceof Reseller && method_exists($reseller, 'isWalletBased') && $reseller->isWalletBased()) {
                    $oldBalance = $reseller->wallet_balance;
                    $reseller->increment('wallet_balance', $fresh->amount);
                    $reseller->refresh();
                    $newBalance = $reseller->wallet_balance;
                    $resellerIdForReenable = $reseller->id;
                } else {
                    $oldBalance = $user?->balance ?? 0;
                    $user?->increment('balance', $fresh->amount);
                    $user?->refresh();
                    $newBalance = $user?->balance ?? 0;
                }

                $metadata['computed_amount_toman'] = $computedAmount;
                $fresh->update([
                    'status' => Transaction::STATUS_COMPLETED,
                    'description' => 'شارژ کیف پول (درگاه Tetra98)',
                    'metadata' => $metadata,
                ]);

                Log::info('tetra98_wallet_credited', [
                    'action' => 'tetra98_wallet_credited',
                    'transaction_id' => $fresh->id,
                    'user_id' => $fresh->user_id,
                    'reseller_id' => $reseller?->id,
                    'amount' => $fresh->amount,
                    'old_balance' => $oldBalance,
                    'new_balance' => $newBalance,
                    'authority' => $authority,
                ]);
            }
        });

        if ($resellerIdForReenable) {
            $reseller = Reseller::find($resellerIdForReenable);
            $user = $transaction->user()->first();

            if ($reseller && method_exists($reseller, 'isWalletBased') && $reseller->isWalletBased()) {
                $reseller->refresh();

                // NEW REQUIREMENT: Only reactivate if balance reaches 150,000 toman minimum
                $reactivationThreshold = config('billing.reseller.first_topup.wallet_min', 150000);

                if (method_exists($reseller, 'isSuspendedWallet') &&
                    $reseller->isSuspendedWallet() &&
                    $reseller->wallet_balance >= $reactivationThreshold) {

                    Log::info('tetra98_reseller_reactivation_start', [
                        'action' => 'tetra98_reseller_reactivation_start',
                        'reseller_id' => $reseller->id,
                        'user_id' => $user?->id,
                        'wallet_balance' => $reseller->wallet_balance,
                        'reactivation_threshold' => $reactivationThreshold,
                    ]);

                    // Update reseller status to active and clear any cached state
                    $reseller->status = 'active';
                    $reseller->save();
                    $reseller->refresh();

                    Cache::forget("reseller_status:{$reseller->id}");

                    Log::info('wallet_topup_completed_reseller_activated', [
                        'action' => 'wallet_topup_completed_reseller_activated',
                        'reseller_id' => $reseller->id,
                        'user_id' => $user?->id,
                        'wallet_balance' => $reseller->wallet_balance,
                    ]);

                    Log::info('tetra98_wallet_reseller_reactivated', [
                        'action' => 'tetra98_wallet_reseller_reactivated',
                        'reseller_id' => $reseller->id,
                        'user_id' => $user?->id,
                        'wallet_balance' => $reseller->wallet_balance,
                        'old_status' => 'suspended_wallet',
                        'new_status' => 'active',
                    ]);

                    // Re-enable wallet-suspended configs synchronously
                    try {
                        $stats = $this->walletResellerReenableService->reenableWalletSuspendedConfigs($reseller);

                        Log::info('tetra98_wallet_configs_reenabled', [
                            'action' => 'tetra98_wallet_configs_reenabled',
                            'reseller_id' => $reseller->id,
                            'user_id' => $user?->id,
                            'configs_enabled' => $stats['enabled'] ?? 0,
                            'configs_failed' => $stats['failed'] ?? 0,
                        ]);
                    } catch (Throwable $e) {
                        Log::error('tetra98_wallet_reenable_failed', [
                            'action' => 'tetra98_wallet_reenable_failed',
                            'reseller_id' => $reseller->id,
                            'user_id' => $user?->id,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    dispatch(new ReenableResellerConfigsJob($reseller, 'wallet'));

                    Log::info('wallet_reenable_job_dispatched', [
                        'action' => 'wallet_reenable_job_dispatched',
                        'reseller_id' => $reseller->id,
                        'user_id' => $user?->id,
                        'suspension_reason' => 'wallet',
                    ]);
                } else {
                    // Log why reactivation was not triggered
                    if ($reseller->isSuspendedWallet()) {
                        Log::warning('wallet_activation_condition_failed', [
                            'action' => 'tetra98_reactivation_threshold_not_met',
                            'reseller_id' => $reseller->id,
                            'user_id' => $user?->id,
                            'wallet_balance' => $reseller->wallet_balance,
                            'reactivation_threshold' => $reactivationThreshold,
                            'shortfall' => $reactivationThreshold - $reseller->wallet_balance,
                            'reason' => 'balance_below_first_topup_minimum',
                        ]);
                    } else {
                        Log::info('tetra98_reactivation_threshold_not_met', [
                            'action' => 'tetra98_reactivation_threshold_not_met',
                            'reseller_id' => $reseller->id,
                            'user_id' => $user?->id,
                            'wallet_balance' => $reseller->wallet_balance,
                            'reactivation_threshold' => $reactivationThreshold,
                            'shortfall' => $reactivationThreshold - $reseller->wallet_balance,
                            'reseller_status' => $reseller->status,
                        ]);
                    }
                }
            }
        }

        return response('OK', 200);
    }

    protected function markTransactionFailed(Transaction $transaction, array $extraMeta = []): void
    {
        $metadata = $transaction->metadata ?? [];
        $tetraMeta = $metadata['tetra98'] ?? [];
        $tetraMeta['state'] = 'failed';
        $tetraMeta = array_merge($tetraMeta, $extraMeta);
        $metadata['tetra98'] = $tetraMeta;

        $transaction->update([
            'status' => Transaction::STATUS_FAILED,
            'metadata' => $metadata,
        ]);
    }

    protected function sanitizePayload($payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $sanitized = [];

        foreach ($payload as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $sanitized[$key] = is_string($value)
                    ? mb_strimwidth($value, 0, 200, '…')
                    : $value;
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizePayload($value);
            }
        }

        return $sanitized;
    }
}
