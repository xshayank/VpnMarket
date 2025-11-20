<?php

use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\ResellerUsageSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

test('idempotency: consecutive runs do not double-charge', function () {
    Config::set('billing.wallet.price_per_gb', 1000);
    Config::set('billing.wallet.charge_idempotency_seconds', 50);

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

    // First charge - should succeed
    Artisan::call('reseller:charge-wallet-hourly');
    $reseller->refresh();
    $balanceAfterFirst = $reseller->wallet_balance;
    
    // Should charge 1 GB * 1000 = 1000 تومان
    expect($balanceAfterFirst)->toBe(9000);
    expect($reseller->usageSnapshots()->count())->toBe(1);

    // Second charge immediately - should skip due to idempotency
    Artisan::call('reseller:charge-wallet-hourly');
    $reseller->refresh();
    
    // Balance should NOT change
    expect($reseller->wallet_balance)->toBe($balanceAfterFirst);
    // Should still have only one snapshot
    expect($reseller->usageSnapshots()->count())->toBe(1);
});

test('idempotency: forced charge bypasses idempotency window', function () {
    Config::set('billing.wallet.price_per_gb', 1000);
    Config::set('billing.wallet.charge_idempotency_seconds', 50);

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

    // First charge
    Artisan::call('reseller:charge-wallet-hourly');
    $reseller->refresh();
    expect($reseller->wallet_balance)->toBe(9000);

    // Add more usage
    ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'usage_bytes' => 0.5 * 1024 * 1024 * 1024, // 0.5 GB
    ]);

    // Second charge with force flag - should bypass idempotency
    Artisan::call('reseller:charge-wallet-once', [
        '--reseller' => $reseller->id,
        '--force' => true,
    ]);
    
    $reseller->refresh();
    
    // Should have charged for additional 0.5 GB (delta from last snapshot)
    // Total usage now: 1.5 GB, last snapshot: 1 GB, delta: 0.5 GB
    // Cost: 0.5 * 1000 = 500 (ceiling)
    expect($reseller->wallet_balance)->toBeLessThan(9000);
    expect($reseller->usageSnapshots()->count())->toBe(2);
});

test('idempotency: snapshot stores cycle metadata', function () {
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
        'usage_bytes' => 2 * 1024 * 1024 * 1024, // 2 GB
    ]);

    Artisan::call('reseller:charge-wallet-hourly');

    $snapshot = $reseller->usageSnapshots()->latest('measured_at')->first();
    
    expect($snapshot)->not->toBeNull();
    expect($snapshot->meta)->toBeArray();
    expect($snapshot->meta['cycle_charge_applied'])->toBeTrue();
    expect($snapshot->meta['delta_bytes'])->toBe(2 * 1024 * 1024 * 1024);
    expect($snapshot->meta['delta_gb'])->toBeGreaterThan(1.9);
    expect($snapshot->meta['cost'])->toBe(2000);
    expect($snapshot->meta['cycle_started_at'])->toBeString();
});

test('idempotency: feature flag disables charging', function () {
    Config::set('billing.wallet.charge_enabled', false);
    Config::set('billing.wallet.price_per_gb', 1000);

    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'wallet_balance' => 10000,
    ]);

    ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'usage_bytes' => 1 * 1024 * 1024 * 1024,
    ]);

    Artisan::call('reseller:charge-wallet-hourly');
    $reseller->refresh();

    // Balance should not change when feature is disabled
    expect($reseller->wallet_balance)->toBe(10000);
    expect($reseller->usageSnapshots()->count())->toBe(0);
});

test('idempotency: only charges wallet-based resellers', function () {
    Config::set('billing.wallet.price_per_gb', 1000);

    $user = User::factory()->create();
    
    // Create traffic-based reseller
    $trafficReseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'wallet_balance' => 0,
    ]);

    ResellerConfig::factory()->create([
        'reseller_id' => $trafficReseller->id,
        'usage_bytes' => 1 * 1024 * 1024 * 1024,
    ]);

    Artisan::call('reseller:charge-wallet-hourly');
    $trafficReseller->refresh();

    // Traffic reseller should not be charged
    expect($trafficReseller->wallet_balance)->toBe(0);
    expect($trafficReseller->usageSnapshots()->count())->toBe(0);
});

test('locking: concurrent execution protection', function () {
    Config::set('billing.wallet.price_per_gb', 1000);

    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'wallet_balance' => 10000,
    ]);

    ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'usage_bytes' => 1 * 1024 * 1024 * 1024,
    ]);

    // Acquire lock manually to simulate another process
    $lockKey = config('billing.wallet.charge_lock_key_prefix', 'wallet_charge') . ":reseller:{$reseller->id}";
    $lock = Cache::lock($lockKey, 20);
    $lock->get();

    try {
        // Try to charge - should fail to acquire lock
        Artisan::call('reseller:charge-wallet-hourly');
        $reseller->refresh();

        // Balance should not change due to lock
        expect($reseller->wallet_balance)->toBe(10000);
    } finally {
        $lock->release();
    }
});

test('minimum delta: skips charges below configured threshold without snapshots', function () {
    Config::set('billing.wallet.price_per_gb', 1000);
    Config::set('billing.wallet.minimum_delta_bytes_to_charge', 5 * 1024 * 1024); // 5 MB

    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'wallet_balance' => 10000,
    ]);

    // Usage below the minimum threshold (1 MB)
    ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'usage_bytes' => 1 * 1024 * 1024,
    ]);

    Artisan::call('reseller:charge-wallet-hourly');
    $reseller->refresh();

    expect($reseller->wallet_balance)->toBe(10000);
    expect($reseller->usageSnapshots()->count())->toBe(0);
});

test('suspension: configs disabled once per cycle', function () {
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

    // First charge - should suspend and disable configs
    Artisan::call('reseller:charge-wallet-hourly');
    
    $config->refresh();
    $reseller->refresh();
    
    expect($reseller->status)->toBe('suspended_wallet');
    expect($config->status)->toBe('disabled');
    expect($config->meta['disabled_by_wallet_suspension'])->toBeTrue();
    expect($config->meta['disabled_by_wallet_suspension_cycle_at'])->toBeString();
    
    $cycleHour = $config->meta['disabled_by_wallet_suspension_cycle_at'];

    // Try to force another charge in the same cycle
    // Even if forced, the config should not be disabled again
    $initialDisabledAt = $config->disabled_at;
    
    Artisan::call('reseller:charge-wallet-once', [
        '--reseller' => $reseller->id,
        '--force' => true,
    ]);
    
    $config->refresh();
    
    // disabled_at should not change if already disabled in this cycle
    expect($config->meta['disabled_by_wallet_suspension_cycle_at'])->toBe($cycleHour);
});
