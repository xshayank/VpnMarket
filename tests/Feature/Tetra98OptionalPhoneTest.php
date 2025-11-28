<?php

use App\Models\Reseller;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\PaymentMethodConfig;
use App\Support\Tetra98Config;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

beforeEach(function () {
    PaymentMethodConfig::clearCache();
    Tetra98Config::clearCache();
    enableTetra98ForPhoneTests();
});

function enableTetra98ForPhoneTests(): void
{
    Setting::updateOrCreate(['key' => 'payment.tetra98.enabled'], ['value' => '1']);
    Setting::updateOrCreate(['key' => 'payment.tetra98.api_key'], ['value' => 'test-api-key']);
    Setting::updateOrCreate(['key' => 'payment.tetra98.base_url'], ['value' => 'https://tetra98.ir']);
    Setting::updateOrCreate(['key' => 'payment.tetra98.callback_path'], ['value' => '/webhooks/tetra98/callback']);
    Setting::updateOrCreate(['key' => 'payment.tetra98.min_amount'], ['value' => '10000']);
    PaymentMethodConfig::clearCache();
    Tetra98Config::clearCache();
}

function setDefaultPhone(?string $phone): void
{
    if ($phone === null) {
        Setting::where('key', 'payment.tetra98.default_phone')->delete();
    } else {
        Setting::updateOrCreate(['key' => 'payment.tetra98.default_phone'], ['value' => $phone]);
    }
    Tetra98Config::clearCache();
}

it('initiates payment with user-supplied phone when default phone not set', function () {
    $user = User::factory()->create(['balance' => 0]);
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => Reseller::TYPE_WALLET,
        'status' => 'active',
        'wallet_balance' => 100000,
    ]);

    setDefaultPhone(null);

    Http::fake([
        'https://tetra98.ir/api/create-order' => Http::response([
            'status' => '100',
            'Authority' => 'AUTH_USER_PHONE',
            'payment_url_web' => 'https://tetra98.ir/pay/AUTH_USER_PHONE',
        ], 200),
    ]);

    actingAs($user);

    $response = post(route('wallet.charge.tetra98.initiate'), [
        'amount' => 50000,
        'phone' => '09123456789',
    ]);

    $response->assertRedirect();

    $transaction = Transaction::where('user_id', $user->id)->first();
    expect($transaction)->not->toBeNull();
    expect($transaction->metadata['phone'])->toBe('09123456789');
    expect($transaction->metadata['phone_source'])->toBe('user');
});

it('initiates payment with user-supplied phone when default phone is set', function () {
    $user = User::factory()->create(['balance' => 0]);
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => Reseller::TYPE_WALLET,
        'status' => 'active',
        'wallet_balance' => 100000,
    ]);

    setDefaultPhone('09000000000');

    Http::fake([
        'https://tetra98.ir/api/create-order' => Http::response([
            'status' => '100',
            'Authority' => 'AUTH_USER_PHONE_OVERRIDE',
            'payment_url_web' => 'https://tetra98.ir/pay/AUTH_USER_PHONE_OVERRIDE',
        ], 200),
    ]);

    actingAs($user);

    $response = post(route('wallet.charge.tetra98.initiate'), [
        'amount' => 50000,
        'phone' => '09123456789',
    ]);

    $response->assertRedirect();

    $transaction = Transaction::where('user_id', $user->id)->first();
    expect($transaction)->not->toBeNull();
    expect($transaction->metadata['phone'])->toBe('09123456789');
    expect($transaction->metadata['phone_source'])->toBe('user');
});

it('falls back to default phone when user phone is empty', function () {
    $user = User::factory()->create(['balance' => 0]);
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => Reseller::TYPE_WALLET,
        'status' => 'active',
        'wallet_balance' => 100000,
    ]);

    setDefaultPhone('09000000000');

    Http::fake([
        'https://tetra98.ir/api/create-order' => Http::response([
            'status' => '100',
            'Authority' => 'AUTH_DEFAULT_PHONE',
            'payment_url_web' => 'https://tetra98.ir/pay/AUTH_DEFAULT_PHONE',
        ], 200),
    ]);

    actingAs($user);

    $response = post(route('wallet.charge.tetra98.initiate'), [
        'amount' => 50000,
        'phone' => '',
    ]);

    $response->assertRedirect();

    $transaction = Transaction::where('user_id', $user->id)->first();
    expect($transaction)->not->toBeNull();
    expect($transaction->metadata['phone'])->toBe('09000000000');
    expect($transaction->metadata['phone_source'])->toBe('default');
});

it('fails when both user phone and default phone are missing', function () {
    $user = User::factory()->create(['balance' => 0]);
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => Reseller::TYPE_WALLET,
        'status' => 'active',
        'wallet_balance' => 100000,
    ]);

    setDefaultPhone(null);

    actingAs($user);

    $response = post(route('wallet.charge.tetra98.initiate'), [
        'amount' => 50000,
        'phone' => '',
    ]);

    $response->assertSessionHasErrors('phone');
    expect(Transaction::where('user_id', $user->id)->count())->toBe(0);
});

it('fails when user provides invalid phone and no default phone is set', function () {
    $user = User::factory()->create(['balance' => 0]);
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => Reseller::TYPE_WALLET,
        'status' => 'active',
        'wallet_balance' => 100000,
    ]);

    setDefaultPhone(null);

    actingAs($user);

    $response = post(route('wallet.charge.tetra98.initiate'), [
        'amount' => 50000,
        'phone' => '12345', // Invalid phone
    ]);

    $response->assertSessionHasErrors('phone');
    expect(Transaction::where('user_id', $user->id)->count())->toBe(0);
});

it('shows optional phone indicator when default phone is configured', function () {
    $user = User::factory()->create();
    Reseller::factory()->walletBased()->for($user)->create();

    setDefaultPhone('09000000000');
    PaymentMethodConfig::clearCache();

    actingAs($user);

    $response = $this->get(route('wallet.charge.form'));

    $response->assertOk();
    $response->assertSee('(اختیاری برای Tetra98)', false);
    $response->assertSee('در صورت خالی گذاشتن، از شماره پیش‌فرض تنظیم شده استفاده می‌شود', false);
});

it('does not show optional phone indicator when default phone is not configured', function () {
    $user = User::factory()->create();
    Reseller::factory()->walletBased()->for($user)->create();

    setDefaultPhone(null);
    PaymentMethodConfig::clearCache();

    actingAs($user);

    $response = $this->get(route('wallet.charge.form'));

    $response->assertOk();
    $response->assertDontSee('(اختیاری برای Tetra98)', false);
});

it('stores default phone setting in admin theme settings', function () {
    setDefaultPhone('09111111111');

    expect(Tetra98Config::getDefaultPhone())->toBe('09111111111');
});

it('returns null when default phone is not set', function () {
    setDefaultPhone(null);

    expect(Tetra98Config::getDefaultPhone())->toBeNull();
});
