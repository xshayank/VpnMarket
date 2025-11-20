<?php

use App\Models\Reseller;
use App\Models\User;
use Illuminate\Support\Facades\Config;

test('new wallet reseller with insufficient balance starts as suspended_wallet', function () {
    Config::set('billing.reseller.first_topup.wallet_min', 150000);

    $user = User::factory()->create();
    $reseller = Reseller::factory()
        ->walletBased()
        ->create([
            'user_id' => $user->id,
            'wallet_balance' => 50000, // Below threshold
        ]);

    expect($reseller->status)->toBe('suspended_wallet')
        ->and($reseller->type)->toBe('wallet')
        ->and($reseller->wallet_balance)->toBe(50000);
});

test('new wallet reseller with sufficient balance starts as active', function () {
    Config::set('billing.reseller.first_topup.wallet_min', 150000);

    $user = User::factory()->create();
    $reseller = Reseller::factory()
        ->walletBased()
        ->create([
            'user_id' => $user->id,
            'wallet_balance' => 200000, // Above threshold
        ]);

    expect($reseller->status)->toBe('active')
        ->and($reseller->type)->toBe('wallet')
        ->and($reseller->wallet_balance)->toBe(200000);
});

test('wallet factory defaults to suspended_wallet when balance is low', function () {
    Config::set('billing.reseller.first_topup.wallet_min', 150000);

    $user = User::factory()->create();
    
    // Factory default balance is 10000, which is below 150000
    $reseller = Reseller::factory()
        ->walletBased()
        ->create([
            'user_id' => $user->id,
        ]);

    expect($reseller->status)->toBe('suspended_wallet')
        ->and($reseller->wallet_balance)->toBe(10000);
});

test('wallet reseller cannot be reactivated without sufficient balance', function () {
    Config::set('billing.wallet.suspension_threshold', -1000);
    Config::set('billing.wallet.auto_reenable_enabled', true);

    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'suspended_wallet',
        'wallet_balance' => -500, // Above suspension threshold but still negative
    ]);

    // Create a disabled config with wallet suspension flag
    $config = \App\Models\ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'disabled',
        'meta' => [
            'disabled_by_wallet_suspension' => true,
        ],
    ]);

    // Manually change status to active (simulating unauthorized activation)
    $reseller->update(['status' => 'active']);

    // Try to re-enable - should be blocked by balance check
    $job = new \App\Jobs\ReenableResellerConfigsJob($reseller, 'wallet');
    $job->handle();

    $config->refresh();

    // Config should remain disabled because balance is below threshold
    expect($config->status)->toBe('disabled')
        ->and($config->meta['disabled_by_wallet_suspension'])->toBeTrue();
});

test('re-enable job skips when auto_reenable is disabled', function () {
    Config::set('billing.wallet.auto_reenable_enabled', false);
    Config::set('billing.wallet.suspension_threshold', -1000);

    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'active',
        'wallet_balance' => 10000, // Well above threshold
    ]);

    $config = \App\Models\ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'disabled',
        'meta' => [
            'disabled_by_wallet_suspension' => true,
        ],
    ]);

    // Try to re-enable - should skip due to flag
    $job = new \App\Jobs\ReenableResellerConfigsJob($reseller, 'wallet');
    $job->handle();

    $config->refresh();

    // Config should remain disabled because auto-reenable is disabled
    expect($config->status)->toBe('disabled');
});

test('re-enable job skips when reseller is not active', function () {
    Config::set('billing.wallet.auto_reenable_enabled', true);
    Config::set('billing.wallet.suspension_threshold', -1000);

    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'suspended_wallet', // Not active
        'wallet_balance' => 10000, // Above threshold
    ]);

    $config = \App\Models\ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'disabled',
        'meta' => [
            'disabled_by_wallet_suspension' => true,
        ],
    ]);

    // Try to re-enable - should skip because status is not active
    $job = new \App\Jobs\ReenableResellerConfigsJob($reseller, 'wallet');
    $job->handle();

    $config->refresh();

    // Config should remain disabled because reseller is not active
    expect($config->status)->toBe('disabled');
});
