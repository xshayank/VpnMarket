<?php

use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;

test('reenable wallet disabled command finds and queues eligible resellers', function () {
    Queue::fake();
    
    Config::set('billing.wallet.suspension_threshold', -1000);
    Config::set('billing.wallet.auto_reenable_enabled', true);

    $user = User::factory()->create();
    
    // Create an eligible reseller: active, balance > threshold, has wallet-disabled configs
    $eligibleReseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'active',
        'wallet_balance' => 5000, // Above threshold
    ]);

    ResellerConfig::factory()->create([
        'reseller_id' => $eligibleReseller->id,
        'status' => 'disabled',
        'meta' => [
            'disabled_by_wallet_suspension' => true,
        ],
    ]);

    // Run the command
    $exitCode = Artisan::call('reseller:reenable-wallet-disabled');

    expect($exitCode)->toBe(0);

    // Verify job was queued
    Queue::assertPushed(\App\Jobs\ReenableResellerConfigsJob::class, function ($job) use ($eligibleReseller) {
        return $job->reseller->id === $eligibleReseller->id;
    });
});

test('reenable wallet disabled command skips resellers with low balance', function () {
    Queue::fake();
    
    Config::set('billing.wallet.suspension_threshold', -1000);
    Config::set('billing.wallet.auto_reenable_enabled', true);

    $user = User::factory()->create();
    
    // Create ineligible reseller: active but balance <= threshold
    $ineligibleReseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'active',
        'wallet_balance' => -1500, // Below threshold
    ]);

    ResellerConfig::factory()->create([
        'reseller_id' => $ineligibleReseller->id,
        'status' => 'disabled',
        'meta' => [
            'disabled_by_wallet_suspension' => true,
        ],
    ]);

    // Run the command
    $exitCode = Artisan::call('reseller:reenable-wallet-disabled');

    expect($exitCode)->toBe(0);

    // Verify no jobs were queued
    Queue::assertNothingPushed();
});

test('reenable wallet disabled command skips suspended resellers', function () {
    Queue::fake();
    
    Config::set('billing.wallet.suspension_threshold', -1000);
    Config::set('billing.wallet.auto_reenable_enabled', true);

    $user = User::factory()->create();
    
    // Create ineligible reseller: good balance but suspended
    $ineligibleReseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'suspended_wallet', // Not active
        'wallet_balance' => 5000,
    ]);

    ResellerConfig::factory()->create([
        'reseller_id' => $ineligibleReseller->id,
        'status' => 'disabled',
        'meta' => [
            'disabled_by_wallet_suspension' => true,
        ],
    ]);

    // Run the command
    $exitCode = Artisan::call('reseller:reenable-wallet-disabled');

    expect($exitCode)->toBe(0);

    // Verify no jobs were queued
    Queue::assertNothingPushed();
});

test('reenable wallet disabled command skips when auto_reenable is disabled', function () {
    Queue::fake();
    
    Config::set('billing.wallet.auto_reenable_enabled', false);
    Config::set('billing.wallet.suspension_threshold', -1000);

    $user = User::factory()->create();
    
    // Create otherwise eligible reseller
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'active',
        'wallet_balance' => 5000,
    ]);

    ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'disabled',
        'meta' => [
            'disabled_by_wallet_suspension' => true,
        ],
    ]);

    // Run the command
    $exitCode = Artisan::call('reseller:reenable-wallet-disabled');

    expect($exitCode)->toBe(0);

    // Verify no jobs were queued because flag is disabled
    Queue::assertNothingPushed();
});

test('reenable wallet disabled command only processes resellers with wallet-disabled configs', function () {
    Queue::fake();
    
    Config::set('billing.wallet.suspension_threshold', -1000);
    Config::set('billing.wallet.auto_reenable_enabled', true);

    $user = User::factory()->create();
    
    // Create reseller without any wallet-disabled configs
    $resellerNoDisabledConfigs = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'active',
        'wallet_balance' => 5000,
    ]);

    // Config exists but not disabled by wallet
    ResellerConfig::factory()->create([
        'reseller_id' => $resellerNoDisabledConfigs->id,
        'status' => 'active',
    ]);

    // Run the command
    $exitCode = Artisan::call('reseller:reenable-wallet-disabled');

    expect($exitCode)->toBe(0);

    // Verify no jobs were queued
    Queue::assertNothingPushed();
});

test('reenable wallet disabled command queues multiple eligible resellers', function () {
    Queue::fake();
    
    Config::set('billing.wallet.suspension_threshold', -1000);
    Config::set('billing.wallet.auto_reenable_enabled', true);

    // Create multiple eligible resellers
    $resellers = [];
    for ($i = 0; $i < 3; $i++) {
        $user = User::factory()->create();
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'wallet',
            'status' => 'active',
            'wallet_balance' => 5000,
        ]);

        ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'status' => 'disabled',
            'meta' => [
                'disabled_by_wallet_suspension' => true,
            ],
        ]);

        $resellers[] = $reseller;
    }

    // Run the command
    $exitCode = Artisan::call('reseller:reenable-wallet-disabled');

    expect($exitCode)->toBe(0);

    // Verify jobs were queued for all 3 resellers
    Queue::assertPushed(\App\Jobs\ReenableResellerConfigsJob::class, 3);
});
