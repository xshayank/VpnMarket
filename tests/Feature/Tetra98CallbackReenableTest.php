<?php

use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\Setting;
use App\Models\Panel;
use App\Models\Transaction;
use App\Models\User;
use App\Support\PaymentMethodConfig;
use App\Support\Tetra98Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;
use function Pest\Laravel\get;

beforeEach(function () {
    PaymentMethodConfig::clearCache();
    Tetra98Config::clearCache();
    enableTetra98();
});

function enableTetra98(): void
{
    Setting::updateOrCreate(['key' => 'payment.tetra98.enabled'], ['value' => '1']);
    Setting::updateOrCreate(['key' => 'payment.tetra98.api_key'], ['value' => 'test-api-key']);
    Setting::updateOrCreate(['key' => 'payment.tetra98.base_url'], ['value' => 'https://tetra98.ir']);
    Setting::updateOrCreate(['key' => 'payment.tetra98.callback_path'], ['value' => '/webhooks/tetra98/callback']);
    Setting::updateOrCreate(['key' => 'payment.tetra98.min_amount'], ['value' => '10000']);
    PaymentMethodConfig::clearCache();
    Tetra98Config::clearCache();
}

it('credits wallet and reactivates suspended_wallet reseller when balance reaches 150000', function () {
    $user = User::factory()->create(['balance' => 0]);
    $panel = Panel::factory()->create();

    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => Reseller::TYPE_WALLET,
        'status' => 'suspended_wallet',
        'wallet_balance' => 0,
        'primary_panel_id' => $panel->id,
        'panel_id' => $panel->id,
    ]);

    DB::table('resellers')->whereKey($reseller->id)->update([
        'primary_panel_id' => $panel->id,
        'panel_id' => $panel->id,
    ]);

    // Create a disabled config with wallet suspension metadata
    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'disabled',
        'panel_id' => null, // No panel = local-only config
        'meta' => [
            'disabled_by_wallet_suspension' => true,
            'disabled_at' => now()->toIso8601String(),
        ],
    ]);

    // Create pending transaction for 150,000 toman
    $authority = 'AUTH_WALLET_150K';
    $transaction = Transaction::create([
        'user_id' => $user->id,
        'order_id' => null,
        'amount' => 150000,
        'type' => Transaction::TYPE_DEPOSIT,
        'status' => Transaction::STATUS_PENDING,
        'description' => 'شارژ کیف پول (درگاه Tetra98) - در انتظار پرداخت',
        'metadata' => [
            'payment_method' => 'tetra98',
            'phone' => '09123456789',
            'email' => $user->email,
            'deposit_mode' => 'wallet',
            'tetra98' => [
                'hash_id' => 'tetra98-wallet-150k',
                'authority' => $authority,
                'amount_toman' => 150000,
                'state' => 'redirected',
            ],
        ],
    ]);

    Http::fake([
        'https://tetra98.ir/api/verify' => Http::response([
            'status' => '100',
            'authority' => $authority,
        ], 200),
    ]);

    // Simulate callback
    post(Tetra98Config::getCallbackPath(), [
        'status' => 100,
        'authority' => $authority,
    ])->assertOk();

    // Refresh models
    $transaction->refresh();
    $reseller->refresh();
    $config->refresh();

    // Assert transaction completed
    expect($transaction->status)->toBe(Transaction::STATUS_COMPLETED);
    expect($transaction->metadata['tetra98']['state'])->toBe('completed');

    // Assert wallet credited
    expect($reseller->wallet_balance)->toBe(150000);

    // Assert reseller reactivated
    expect($reseller->status)->toBe('active');

    // Assert config re-enabled and meta cleared
    expect($config->status)->toBe('active');
    expect($config->meta['disabled_by_wallet_suspension'] ?? null)->toBeNull();
});

it('allows activated wallet reseller to access reseller routes after callback', function () {
    $user = User::factory()->create(['balance' => 0]);
    $panel = Panel::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => Reseller::TYPE_WALLET,
        'status' => 'suspended_wallet',
        'wallet_balance' => 0,
        'primary_panel_id' => $panel->id,
        'panel_id' => $panel->id,
    ]);

    $authority = 'AUTH_WALLET_ACCESS';
    $transaction = Transaction::create([
        'user_id' => $user->id,
        'order_id' => null,
        'amount' => 200000,
        'type' => Transaction::TYPE_DEPOSIT,
        'status' => Transaction::STATUS_PENDING,
        'description' => 'شارژ کیف پول (درگاه Tetra98) - در انتظار پرداخت',
        'metadata' => [
            'payment_method' => 'tetra98',
            'phone' => '09123456789',
            'email' => $user->email,
            'deposit_mode' => 'wallet',
            'tetra98' => [
                'hash_id' => 'tetra98-wallet-access',
                'authority' => $authority,
                'amount_toman' => 200000,
                'state' => 'redirected',
            ],
        ],
    ]);

    Http::fake([
        'https://tetra98.ir/api/verify' => Http::response([
            'status' => '100',
            'authority' => $authority,
        ], 200),
    ]);

    post(Tetra98Config::getCallbackPath(), [
        'status' => 100,
        'authority' => $authority,
    ])->assertOk();

    $reseller->refresh();
    $transaction->refresh();

    expect($reseller->status)->toBe('active');
    expect($transaction->status)->toBe(Transaction::STATUS_COMPLETED);

    DB::table('resellers')->whereKey($reseller->id)->update([
        'primary_panel_id' => $panel->id,
        'panel_id' => $panel->id,
    ]);
    $reseller->refresh();

    actingAs($user);

    $response = get('/reseller');
    $response->assertOk();
});

it('credits wallet but does NOT reactivate reseller when balance is below 150000', function () {
    $user = User::factory()->create(['balance' => 0]);
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => Reseller::TYPE_WALLET,
        'status' => 'suspended_wallet',
        'wallet_balance' => 0,
    ]);

    // Create a disabled config
    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'disabled',
        'panel_id' => null,
        'meta' => [
            'disabled_by_wallet_suspension' => true,
        ],
    ]);

    // Create pending transaction for only 50,000 toman (below 150,000 threshold)
    $authority = 'AUTH_WALLET_50K';
    $transaction = Transaction::create([
        'user_id' => $user->id,
        'order_id' => null,
        'amount' => 50000,
        'type' => Transaction::TYPE_DEPOSIT,
        'status' => Transaction::STATUS_PENDING,
        'description' => 'شارژ کیف پول (درگاه Tetra98) - در انتظار پرداخت',
        'metadata' => [
            'payment_method' => 'tetra98',
            'phone' => '09123456789',
            'email' => $user->email,
            'deposit_mode' => 'wallet',
            'tetra98' => [
                'hash_id' => 'tetra98-wallet-50k',
                'authority' => $authority,
                'amount_toman' => 50000,
                'state' => 'redirected',
            ],
        ],
    ]);

    Http::fake([
        'https://tetra98.ir/api/verify' => Http::response([
            'status' => '100',
            'authority' => $authority,
        ], 200),
    ]);

    // Simulate callback
    post(Tetra98Config::getCallbackPath(), [
        'status' => 100,
        'authority' => $authority,
    ])->assertOk();

    // Refresh models
    $transaction->refresh();
    $reseller->refresh();
    $config->refresh();

    // Assert transaction completed
    expect($transaction->status)->toBe(Transaction::STATUS_COMPLETED);

    // Assert wallet credited
    expect($reseller->wallet_balance)->toBe(50000);

    // Assert reseller STILL SUSPENDED (balance < 150,000)
    expect($reseller->status)->toBe('suspended_wallet');

    // Assert config STILL DISABLED
    expect($config->status)->toBe('disabled');
    expect($config->meta['disabled_by_wallet_suspension'] ?? null)->toBe(true);
});

it('is idempotent - second callback does not double credit or re-enable', function () {
    $user = User::factory()->create(['balance' => 0]);
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => Reseller::TYPE_WALLET,
        'status' => 'suspended_wallet',
        'wallet_balance' => 0,
    ]);

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'disabled',
        'panel_id' => null,
        'meta' => [
            'disabled_by_wallet_suspension' => true,
        ],
    ]);

    $authority = 'AUTH_IDEMPOTENT';
    $transaction = Transaction::create([
        'user_id' => $user->id,
        'order_id' => null,
        'amount' => 150000,
        'type' => Transaction::TYPE_DEPOSIT,
        'status' => Transaction::STATUS_PENDING,
        'description' => 'شارژ کیف پول (درگاه Tetra98) - در انتظار پرداخت',
        'metadata' => [
            'payment_method' => 'tetra98',
            'deposit_mode' => 'wallet',
            'tetra98' => [
                'authority' => $authority,
                'state' => 'redirected',
            ],
        ],
    ]);

    Http::fake([
        'https://tetra98.ir/api/verify' => Http::response([
            'status' => '100',
            'authority' => $authority,
        ], 200),
    ]);

    // First callback
    post(Tetra98Config::getCallbackPath(), [
        'status' => 100,
        'authority' => $authority,
    ])->assertOk();

    $reseller->refresh();
    expect($reseller->wallet_balance)->toBe(150000);
    expect($reseller->status)->toBe('active');

    // Second callback (retry)
    post(Tetra98Config::getCallbackPath(), [
        'status' => 100,
        'authority' => $authority,
    ])->assertOk();

    $reseller->refresh();
    
    // Balance should NOT be doubled
    expect($reseller->wallet_balance)->toBe(150000);
});

it('adds traffic for traffic-mode payment and reactivates suspended_traffic reseller', function () {
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => Reseller::TYPE_TRAFFIC,
        'status' => 'suspended_traffic',
        'traffic_total_bytes' => 0,
        'traffic_used_bytes' => 0,
    ]);

    $trafficGb = 300;
    $authority = 'AUTH_TRAFFIC_300GB';
    $transaction = Transaction::create([
        'user_id' => $user->id,
        'order_id' => null,
        'amount' => $trafficGb * 750, // 300 GB * 750 toman/GB
        'type' => Transaction::TYPE_DEPOSIT,
        'status' => Transaction::STATUS_PENDING,
        'description' => 'خرید ترافیک (درگاه Tetra98) - در انتظار پرداخت',
        'metadata' => [
            'payment_method' => 'tetra98',
            'deposit_mode' => 'traffic',
            'traffic_gb' => $trafficGb,
            'rate_per_gb' => 750,
            'tetra98' => [
                'authority' => $authority,
                'state' => 'redirected',
            ],
        ],
    ]);

    Http::fake([
        'https://tetra98.ir/api/verify' => Http::response([
            'status' => '100',
            'authority' => $authority,
        ], 200),
    ]);

    post(Tetra98Config::getCallbackPath(), [
        'status' => 100,
        'authority' => $authority,
    ])->assertOk();

    $transaction->refresh();
    $reseller->refresh();

    // Assert transaction completed
    expect($transaction->status)->toBe(Transaction::STATUS_COMPLETED);

    // Assert traffic added (300 GB = 300 * 1024^3 bytes)
    $expectedBytes = $trafficGb * 1024 * 1024 * 1024;
    expect($reseller->traffic_total_bytes)->toBe($expectedBytes);

    // Assert reseller reactivated
    expect($reseller->status)->toBe('active');

    // Wallet balance should NOT change for traffic resellers
    expect($reseller->wallet_balance)->toBe(0);
});

it('marks transaction failed when verify returns non-100 status', function () {
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => Reseller::TYPE_WALLET,
        'status' => 'suspended_wallet',
        'wallet_balance' => 0,
    ]);

    $authority = 'AUTH_FAILED_VERIFY';
    $transaction = Transaction::create([
        'user_id' => $user->id,
        'order_id' => null,
        'amount' => 150000,
        'type' => Transaction::TYPE_DEPOSIT,
        'status' => Transaction::STATUS_PENDING,
        'description' => 'شارژ کیف پول (درگاه Tetra98) - در انتظار پرداخت',
        'metadata' => [
            'payment_method' => 'tetra98',
            'deposit_mode' => 'wallet',
            'tetra98' => [
                'authority' => $authority,
                'state' => 'redirected',
            ],
        ],
    ]);

    Http::fake([
        'https://tetra98.ir/api/verify' => Http::response([
            'status' => '0', // Failed verification
            'authority' => $authority,
        ], 200),
    ]);

    post(Tetra98Config::getCallbackPath(), [
        'status' => 100,
        'authority' => $authority,
    ])->assertOk();

    $transaction->refresh();
    $reseller->refresh();

    // Assert transaction failed
    expect($transaction->status)->toBe(Transaction::STATUS_FAILED);
    expect($transaction->metadata['tetra98']['state'])->toBe('failed');

    // Assert wallet NOT credited
    expect($reseller->wallet_balance)->toBe(0);

    // Assert reseller still suspended
    expect($reseller->status)->toBe('suspended_wallet');
});

it('returns 400 when authority is missing from callback', function () {
    post(Tetra98Config::getCallbackPath(), [
        'status' => 100,
        // no authority
    ])->assertStatus(400);
});

it('returns 200 when transaction already completed (idempotency)', function () {
    $user = User::factory()->create();
    $authority = 'AUTH_ALREADY_COMPLETED';
    
    // Create already-completed transaction
    $transaction = Transaction::create([
        'user_id' => $user->id,
        'order_id' => null,
        'amount' => 150000,
        'type' => Transaction::TYPE_DEPOSIT,
        'status' => Transaction::STATUS_COMPLETED, // Already completed
        'description' => 'شارژ کیف پول (درگاه Tetra98)',
        'metadata' => [
            'payment_method' => 'tetra98',
            'tetra98' => [
                'authority' => $authority,
                'state' => 'completed',
            ],
        ],
    ]);

    Http::fake([
        'https://tetra98.ir/api/verify' => Http::response([
            'status' => '100',
            'authority' => $authority,
        ], 200),
    ]);

    // Callback should succeed without error
    post(Tetra98Config::getCallbackPath(), [
        'status' => 100,
        'authority' => $authority,
    ])->assertOk();
});

it('credits wallet for multiple payments and only reactivates when total reaches 150000', function () {
    $user = User::factory()->create(['balance' => 0]);
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => Reseller::TYPE_WALLET,
        'status' => 'suspended_wallet',
        'wallet_balance' => 0,
    ]);

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'disabled',
        'panel_id' => null,
        'meta' => [
            'disabled_by_wallet_suspension' => true,
        ],
    ]);

    // First payment: 80,000 toman
    $authority1 = 'AUTH_PAYMENT_1';
    $transaction1 = Transaction::create([
        'user_id' => $user->id,
        'amount' => 80000,
        'type' => Transaction::TYPE_DEPOSIT,
        'status' => Transaction::STATUS_PENDING,
        'description' => 'شارژ کیف پول (درگاه Tetra98) - در انتظار پرداخت',
        'metadata' => [
            'payment_method' => 'tetra98',
            'deposit_mode' => 'wallet',
            'tetra98' => [
                'authority' => $authority1,
                'state' => 'redirected',
            ],
        ],
    ]);

    Http::fake([
        'https://tetra98.ir/api/verify' => Http::response([
            'status' => '100',
            'authority' => $authority1,
        ], 200),
    ]);

    post(Tetra98Config::getCallbackPath(), [
        'status' => 100,
        'authority' => $authority1,
    ])->assertOk();

    $reseller->refresh();
    $config->refresh();

    expect($reseller->wallet_balance)->toBe(80000);
    expect($reseller->status)->toBe('suspended_wallet'); // Still suspended
    expect($config->status)->toBe('disabled'); // Still disabled

    // Second payment: 70,000 toman (total will be 150,000)
    $authority2 = 'AUTH_PAYMENT_2';
    $transaction2 = Transaction::create([
        'user_id' => $user->id,
        'amount' => 70000,
        'type' => Transaction::TYPE_DEPOSIT,
        'status' => Transaction::STATUS_PENDING,
        'description' => 'شارژ کیف پول (درگاه Tetra98) - در انتظار پرداخت',
        'metadata' => [
            'payment_method' => 'tetra98',
            'deposit_mode' => 'wallet',
            'tetra98' => [
                'authority' => $authority2,
                'state' => 'redirected',
            ],
        ],
    ]);

    Http::fake([
        'https://tetra98.ir/api/verify' => Http::response([
            'status' => '100',
            'authority' => $authority2,
        ], 200),
    ]);

    post(Tetra98Config::getCallbackPath(), [
        'status' => 100,
        'authority' => $authority2,
    ])->assertOk();

    $reseller->refresh();
    $config->refresh();

    expect($reseller->wallet_balance)->toBe(150000);
    expect($reseller->status)->toBe('active'); // Now active!
    expect($config->status)->toBe('active'); // Now enabled!
    expect($config->meta['disabled_by_wallet_suspension'] ?? null)->toBeNull();
});
