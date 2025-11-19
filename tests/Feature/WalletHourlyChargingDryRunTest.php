<?php

use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;

test('dry-run: does not modify balance or create snapshot', function () {
    Config::set('billing.wallet.price_per_gb', 1000);

    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'wallet_balance' => 10000,
        'wallet_price_per_gb' => 1000,
    ]);

    // Create config with 1 GB usage
    ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'usage_bytes' => 1 * 1024 * 1024 * 1024,
    ]);

    $initialBalance = $reseller->wallet_balance;
    $initialSnapshotCount = $reseller->usageSnapshots()->count();

    // Run dry-run charge
    Artisan::call('reseller:charge-wallet-once', [
        '--reseller' => $reseller->id,
        '--dry-run' => true,
    ]);

    $reseller->refresh();

    // Balance should not change
    expect($reseller->wallet_balance)->toBe($initialBalance);
    
    // No new snapshot should be created
    expect($reseller->usageSnapshots()->count())->toBe($initialSnapshotCount);
});

test('dry-run: shows cost estimate correctly', function () {
    Config::set('billing.wallet.price_per_gb', 2000);

    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'wallet_balance' => 10000,
        'wallet_price_per_gb' => 2000,
    ]);

    // Create config with 2.5 GB usage
    ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'usage_bytes' => (int)(2.5 * 1024 * 1024 * 1024),
    ]);

    // Run dry-run charge
    $exitCode = Artisan::call('reseller:charge-wallet-once', [
        '--reseller' => $reseller->id,
        '--dry-run' => true,
    ]);

    // Command should succeed
    expect($exitCode)->toBe(0);
    
    // Check output contains dry-run indication
    $output = Artisan::output();
    expect($output)->toContain('DRY RUN');
    expect($output)->toContain('Cost');
});

test('dry-run: can be combined with force flag', function () {
    Config::set('billing.wallet.price_per_gb', 1000);

    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'wallet_balance' => 10000,
        'wallet_price_per_gb' => 1000,
    ]);

    ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'usage_bytes' => 1 * 1024 * 1024 * 1024,
    ]);

    // First regular charge to create a snapshot
    Artisan::call('reseller:charge-wallet-hourly');
    $reseller->refresh();
    $balanceAfterCharge = $reseller->wallet_balance;

    // Dry-run with force should not charge even though idempotency would be bypassed
    Artisan::call('reseller:charge-wallet-once', [
        '--reseller' => $reseller->id,
        '--dry-run' => true,
        '--force' => true,
    ]);

    $reseller->refresh();

    // Balance should still not change in dry-run mode
    expect($reseller->wallet_balance)->toBe($balanceAfterCharge);
});

test('single reseller command: requires reseller option', function () {
    $exitCode = Artisan::call('reseller:charge-wallet-once');
    
    // Command should fail without reseller option
    expect($exitCode)->toBe(1);
    
    // Output should show usage instructions
    $output = Artisan::output();
    expect($output)->toContain('--reseller option is required');
});

test('single reseller command: rejects non-existent reseller', function () {
    $exitCode = Artisan::call('reseller:charge-wallet-once', [
        '--reseller' => 99999,
    ]);
    
    // Command should fail
    expect($exitCode)->toBe(1);
    
    $output = Artisan::output();
    expect($output)->toContain('not found');
});

test('single reseller command: rejects traffic-based reseller', function () {
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
    ]);

    $exitCode = Artisan::call('reseller:charge-wallet-once', [
        '--reseller' => $reseller->id,
    ]);
    
    // Command should fail
    expect($exitCode)->toBe(1);
    
    $output = Artisan::output();
    expect($output)->toContain('not a wallet-based reseller');
});
