<?php

use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Modules\Reseller\Jobs\ReenableResellerConfigsJob;

test('wallet reseller auto re-enables when balance recovered', function () {
    Config::set('billing.wallet.suspension_threshold', -1000);
    Config::set('billing.wallet.price_per_gb', 5000);

    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'wallet_balance' => 500,
        'wallet_price_per_gb' => 5000,
        'status' => 'active',
    ]);

    // Create config with 1 GB usage - this will cost 5000, bringing balance to -4500
    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'usage_bytes' => 1 * 1024 * 1024 * 1024,
        'status' => 'active',
    ]);

    // First charge - should suspend
    Artisan::call('reseller:charge-wallet-hourly');
    
    $reseller->refresh();
    $config->refresh();
    
    expect($reseller->status)->toBe('suspended_wallet');
    expect($config->status)->toBe('disabled');
    expect($config->meta['disabled_by_wallet_suspension'])->toBeTrue();

    // Top up wallet to above threshold
    $reseller->update(['wallet_balance' => 5000]);

    // Run re-enable job
    ReenableResellerConfigsJob::dispatchSync();

    $reseller->refresh();
    $config->refresh();

    // Reseller should be reactivated
    expect($reseller->status)->toBe('active');
    
    // Config should be re-enabled
    expect($config->status)->toBe('active');
    expect($config->meta['disabled_by_wallet_suspension'] ?? null)->toBeNull();
});

test('wallet reseller does not re-enable if balance still below threshold', function () {
    Config::set('billing.wallet.suspension_threshold', -1000);
    Config::set('billing.wallet.price_per_gb', 5000);

    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'wallet_balance' => 500,
        'wallet_price_per_gb' => 5000,
        'status' => 'active',
    ]);

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'usage_bytes' => 1 * 1024 * 1024 * 1024,
        'status' => 'active',
    ]);

    // First charge - should suspend (balance goes to -4500)
    Artisan::call('reseller:charge-wallet-hourly');
    
    $reseller->refresh();
    $config->refresh();
    
    expect($reseller->status)->toBe('suspended_wallet');
    expect($config->status)->toBe('disabled');

    // Top up wallet but still below threshold (-1000)
    $reseller->update(['wallet_balance' => -2000]);

    // Run re-enable job
    ReenableResellerConfigsJob::dispatchSync();

    $reseller->refresh();
    $config->refresh();

    // Reseller should still be suspended
    expect($reseller->status)->toBe('suspended_wallet');
    
    // Config should still be disabled
    expect($config->status)->toBe('disabled');
});

test('wallet reseller re-enable only affects wallet-suspended configs', function () {
    Config::set('billing.wallet.suspension_threshold', -1000);
    Config::set('billing.wallet.price_per_gb', 5000);

    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'wallet_balance' => 500,
        'wallet_price_per_gb' => 5000,
        'status' => 'active',
    ]);

    // Config disabled by wallet suspension
    $walletSuspendedConfig = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'usage_bytes' => 1 * 1024 * 1024 * 1024,
        'status' => 'active',
    ]);

    // Config disabled manually (not by wallet suspension)
    $manuallyDisabledConfig = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'usage_bytes' => 0,
        'status' => 'disabled',
        'meta' => [
            'manually_disabled' => true,
        ],
    ]);

    // Suspend via wallet charge
    Artisan::call('reseller:charge-wallet-hourly');
    
    $reseller->refresh();
    $walletSuspendedConfig->refresh();
    
    expect($reseller->status)->toBe('suspended_wallet');
    expect($walletSuspendedConfig->status)->toBe('disabled');

    // Top up wallet
    $reseller->update(['wallet_balance' => 5000]);

    // Run re-enable job
    ReenableResellerConfigsJob::dispatchSync();

    $walletSuspendedConfig->refresh();
    $manuallyDisabledConfig->refresh();

    // Wallet-suspended config should be re-enabled
    expect($walletSuspendedConfig->status)->toBe('active');
    
    // Manually disabled config should remain disabled
    expect($manuallyDisabledConfig->status)->toBe('disabled');
    expect($manuallyDisabledConfig->meta['manually_disabled'])->toBeTrue();
});
