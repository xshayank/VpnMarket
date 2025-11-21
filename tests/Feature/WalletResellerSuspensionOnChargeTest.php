<?php

use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;

it('suspends reseller and disables configs when charge crosses suspension threshold', function () {
    Config::set('billing.wallet.price_per_gb', 1000);
    Config::set('billing.wallet.suspension_threshold', -1000);

    $user = User::factory()->create();

    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'wallet_balance' => -900,
        'wallet_price_per_gb' => 1000,
        'status' => 'active',
    ]);

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'active',
        'usage_bytes' => 0.2 * 1024 * 1024 * 1024,
    ]);

    Artisan::call('reseller:charge-wallet-hourly');

    $reseller->refresh();
    $config->refresh();

    expect($reseller->status)->toBe('suspended_wallet');
    expect($config->status)->toBe('disabled');
    expect(data_get($config->meta, 'disabled_by_wallet_suspension'))->toBeTrue();
    expect(data_get($config->meta, 'disabled_by_reseller_id'))->toBe($reseller->id);
    expect(data_get($config->meta, 'disabled_by_wallet_suspension_cycle_at'))->toBeString();
});

it('suspends reseller already below threshold even when there is no new usage to charge', function () {
    Config::set('billing.wallet.price_per_gb', 1000);
    Config::set('billing.wallet.suspension_threshold', -1000);

    $user = User::factory()->create();

    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'wallet_balance' => -3000,
        'wallet_price_per_gb' => 1000,
        'status' => 'active',
    ]);

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'active',
        'usage_bytes' => 0,
    ]);

    // Ensure a snapshot exists with matching usage to keep delta at zero
    $reseller->usageSnapshots()->create([
        'total_bytes' => 0,
        'measured_at' => now()->subMinute(),
    ]);

    Artisan::call('reseller:charge-wallet-hourly');

    $reseller->refresh();
    $config->refresh();

    expect($reseller->status)->toBe('suspended_wallet');
    expect($config->status)->toBe('disabled');
    expect(data_get($config->meta, 'disabled_by_wallet_suspension'))->toBeTrue();
    expect(data_get($config->meta, 'disabled_by_reseller_id'))->toBe($reseller->id);
});
