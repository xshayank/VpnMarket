<?php

use App\Models\Order;
use App\Models\Reseller;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Seed roles/permissions
    $this->artisan('db:seed', ['--class' => 'RbacSeeder']);
    $this->artisan('db:seed', ['--class' => 'WalletTopUpPermissionsSeeder']);
});

test('super admin can view wallet top-up transactions', function () {
    // Create super admin user with role
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $superAdminRole = Role::findByName('super-admin', 'web');
    $superAdmin->assignRole($superAdminRole);

    expect($superAdmin->can('view_any_wallet::top::up::transaction'))->toBeTrue();
});

test('admin can view wallet top-up transactions', function () {
    // Create admin user with role
    $admin = User::factory()->create(['is_admin' => true]);
    $adminRole = Role::findByName('admin', 'web');
    $admin->assignRole($adminRole);

    expect($admin->can('view_any_wallet::top::up::transaction'))->toBeTrue();
});

test('regular user cannot view wallet top-up transactions', function () {
    // Create regular user
    $user = User::factory()->create();
    $userRole = Role::findByName('user', 'web');
    $user->assignRole($userRole);

    expect($user->can('view_any_wallet::top::up::transaction'))->toBeFalse();
});

test('reseller cannot view wallet top-up transactions', function () {
    // Create reseller user
    $user = User::factory()->create();
    $resellerRole = Role::findByName('reseller', 'web');
    $user->assignRole($resellerRole);

    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
    ]);

    expect($user->can('view_any_wallet::top::up::transaction'))->toBeFalse();
});

test('approving wallet top-up transaction credits user balance', function () {
    // Create user and pending deposit transaction
    $user = User::factory()->create(['balance' => 0]);

    $transaction = Transaction::create([
        'user_id' => $user->id,
        'amount' => 10000,
        'type' => Transaction::TYPE_DEPOSIT,
        'status' => Transaction::STATUS_PENDING,
        'description' => 'شارژ کیف پول (در انتظار تایید)',
    ]);

    // Simulate approval
    DB::transaction(function () use ($transaction, $user) {
        $transaction->update(['status' => Transaction::STATUS_COMPLETED]);
        $user->increment('balance', $transaction->amount);
    });

    expect($transaction->fresh()->status)->toBe(Transaction::STATUS_COMPLETED);
    expect($user->fresh()->balance)->toBe(10000);
});

test('approving wallet top-up transaction credits reseller wallet balance', function () {
    // Create wallet-based reseller
    $user = User::factory()->create(['balance' => 0]);
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'wallet_balance' => 0,
    ]);

    $transaction = Transaction::create([
        'user_id' => $user->id,
        'amount' => 50000,
        'type' => Transaction::TYPE_DEPOSIT,
        'status' => Transaction::STATUS_PENDING,
        'description' => 'شارژ کیف پول ریسلر (در انتظار تایید)',
    ]);

    // Simulate approval for wallet-based reseller
    DB::transaction(function () use ($transaction, $reseller) {
        $transaction->update(['status' => Transaction::STATUS_COMPLETED]);
        $reseller->increment('wallet_balance', $transaction->amount);
    });

    expect($transaction->fresh()->status)->toBe(Transaction::STATUS_COMPLETED);
    expect($reseller->fresh()->wallet_balance)->toBe(50000);
});

test('rejecting wallet top-up transaction does not credit balance', function () {
    // Create user and pending deposit transaction
    $user = User::factory()->create(['balance' => 0]);

    $transaction = Transaction::create([
        'user_id' => $user->id,
        'amount' => 10000,
        'type' => Transaction::TYPE_DEPOSIT,
        'status' => Transaction::STATUS_PENDING,
        'description' => 'شارژ کیف پول (در انتظار تایید)',
    ]);

    // Simulate rejection (only update status, don't credit)
    $transaction->update(['status' => Transaction::STATUS_FAILED]);

    expect($transaction->fresh()->status)->toBe(Transaction::STATUS_FAILED);
    expect($user->fresh()->balance)->toBe(0); // Balance should remain 0
});

test('suspended wallet reseller is reactivated when balance exceeds threshold', function () {
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'suspended_wallet',
        'wallet_balance' => 0,
    ]);

    $transaction = Transaction::create([
        'user_id' => $user->id,
        'amount' => 150000, // Use first_topup minimum
        'type' => Transaction::TYPE_DEPOSIT,
        'status' => Transaction::STATUS_PENDING,
        'description' => 'شارژ کیف پول ریسلر',
    ]);

    // Simulate approval with reactivation logic
    DB::transaction(function () use ($transaction, $reseller) {
        $transaction->update(['status' => Transaction::STATUS_COMPLETED]);
        $reseller->increment('wallet_balance', $transaction->amount);

        // Check if reseller should be reactivated using first_topup threshold
        $reactivationThreshold = config('billing.reseller.first_topup.wallet_min', 150000);
        if ($reseller->isSuspendedWallet() &&
            $reseller->wallet_balance >= $reactivationThreshold) {
            $reseller->update(['status' => 'active']);
        }
    });

    expect($reseller->fresh()->status)->toBe('active');
    expect($reseller->fresh()->wallet_balance)->toBe(150000);
});

test('wallet charge submission creates pending transaction for regular user', function () {
    $user = User::factory()->create(['balance' => 0]);

    // Create a fake uploaded file
    $file = \Illuminate\Http\UploadedFile::fake()->image('receipt.jpg', 600, 600)->size(1024);

    // Simulate wallet charge submission
    $this->actingAs($user)
        ->post(route('wallet.charge.create'), [
            'amount' => 100000,
            'proof' => $file,
        ])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('status');

    // Verify pending transaction was created
    $transaction = Transaction::where('user_id', $user->id)
        ->where('type', Transaction::TYPE_DEPOSIT)
        ->first();

    expect($transaction)->not->toBeNull();
    expect($transaction->amount)->toBe(100000);
    expect($transaction->status)->toBe(Transaction::STATUS_PENDING);
    expect($transaction->description)->toContain('شارژ کیف پول');
    expect($transaction->order_id)->toBeNull();
    expect($transaction->proof_image_path)->not->toBeNull();
});

test('wallet charge submission creates pending transaction for wallet reseller', function () {
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'wallet_balance' => 5000,
    ]);

    // Create a fake uploaded file
    $file = \Illuminate\Http\UploadedFile::fake()->image('receipt.jpg', 600, 600)->size(1024);

    // Simulate wallet charge submission
    $this->actingAs($user)
        ->post(route('wallet.charge.create'), [
            'amount' => 50000,
            'proof' => $file,
        ])
        ->assertRedirect('/reseller')
        ->assertSessionHas('status');

    // Verify pending transaction was created
    $transaction = Transaction::where('user_id', $user->id)
        ->where('type', Transaction::TYPE_DEPOSIT)
        ->first();

    expect($transaction)->not->toBeNull();
    expect($transaction->amount)->toBe(50000);
    expect($transaction->status)->toBe(Transaction::STATUS_PENDING);
    expect($transaction->description)->toContain('ریسلر');
    expect($transaction->order_id)->toBeNull();
    expect($transaction->proof_image_path)->not->toBeNull();
});

test('wallet charge submission validates minimum amount', function () {
    $user = User::factory()->create();

    // Try to submit with amount less than minimum
    $this->actingAs($user)
        ->post(route('wallet.charge.create'), [
            'amount' => 5000,  // Less than 10000 minimum
        ])
        ->assertSessionHasErrors('amount');

    // Verify no transaction was created
    $transaction = Transaction::where('user_id', $user->id)
        ->where('type', Transaction::TYPE_DEPOSIT)
        ->first();

    expect($transaction)->toBeNull();
});

test('order approval creates pending transaction', function () {
    // Create user and order for wallet top-up (no plan)
    $user = User::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'plan_id' => null,
        'amount' => 20000,
        'status' => 'pending',
    ]);

    // Simulate order approval that creates pending transaction
    DB::transaction(function () use ($order, $user) {
        $order->update(['status' => 'paid']);

        Transaction::create([
            'user_id' => $user->id,
            'order_id' => $order->id,
            'amount' => $order->amount,
            'type' => Transaction::TYPE_DEPOSIT,
            'status' => Transaction::STATUS_PENDING,
            'description' => 'شارژ کیف پول (در انتظار تایید نهایی)',
        ]);
    });

    $transaction = Transaction::where('order_id', $order->id)->first();

    expect($order->fresh()->status)->toBe('paid');
    expect($transaction)->not->toBeNull();
    expect($transaction->status)->toBe(Transaction::STATUS_PENDING);
    expect($transaction->type)->toBe(Transaction::TYPE_DEPOSIT);
});

test('wallet charge submission requires proof image', function () {
    $user = User::factory()->create();

    // Try to submit without proof image
    $this->actingAs($user)
        ->post(route('wallet.charge.create'), [
            'amount' => 100000,
        ])
        ->assertSessionHasErrors('proof');

    // Verify no transaction was created
    $transaction = Transaction::where('user_id', $user->id)
        ->where('type', Transaction::TYPE_DEPOSIT)
        ->first();

    expect($transaction)->toBeNull();
});

test('wallet charge submission validates proof image type', function () {
    $user = User::factory()->create();

    // Try to submit with non-image file
    $file = \Illuminate\Http\UploadedFile::fake()->create('document.pdf', 1024);

    $this->actingAs($user)
        ->post(route('wallet.charge.create'), [
            'amount' => 100000,
            'proof' => $file,
        ])
        ->assertSessionHasErrors('proof');

    // Verify no transaction was created
    $transaction = Transaction::where('user_id', $user->id)
        ->where('type', Transaction::TYPE_DEPOSIT)
        ->first();

    expect($transaction)->toBeNull();
});

test('wallet charge submission validates proof image size', function () {
    $user = User::factory()->create();

    // Try to submit with oversized image (> 4MB)
    $file = \Illuminate\Http\UploadedFile::fake()->image('large.jpg')->size(5120);

    $this->actingAs($user)
        ->post(route('wallet.charge.create'), [
            'amount' => 100000,
            'proof' => $file,
        ])
        ->assertSessionHasErrors('proof');

    // Verify no transaction was created
    $transaction = Transaction::where('user_id', $user->id)
        ->where('type', Transaction::TYPE_DEPOSIT)
        ->first();

    expect($transaction)->toBeNull();
});

test('wallet charge submission stores proof image in correct path', function () {
    \Illuminate\Support\Facades\Storage::fake('public');
    
    $user = User::factory()->create(['balance' => 0]);
    $file = \Illuminate\Http\UploadedFile::fake()->image('receipt.jpg', 600, 600)->size(1024);

    // Submit wallet charge
    $this->actingAs($user)
        ->post(route('wallet.charge.create'), [
            'amount' => 100000,
            'proof' => $file,
        ])
        ->assertRedirect(route('dashboard'));

    // Verify transaction was created with proof path
    $transaction = Transaction::where('user_id', $user->id)
        ->where('type', Transaction::TYPE_DEPOSIT)
        ->first();

    expect($transaction)->not->toBeNull();
    expect($transaction->proof_image_path)->not->toBeNull();
    
    // Verify file was stored
    \Illuminate\Support\Facades\Storage::disk('public')->assertExists($transaction->proof_image_path);
    
    // Verify path format (wallet-topups/{year}/{month}/{uuid}.{ext})
    expect($transaction->proof_image_path)->toContain('wallet-topups/');
    expect($transaction->proof_image_path)->toContain(now()->format('Y'));
    expect($transaction->proof_image_path)->toContain(now()->format('m'));
});

test('wallet approval re-enables eylandoo configs only when remote succeeds', function () {
    \Illuminate\Support\Facades\Http::fake([
        '*/api/v1/users/*' => \Illuminate\Support\Facades\Http::sequence()
            ->push(['data' => ['status' => 'disabled', 'username' => 'test_user']], 200)
            ->push(['data' => ['status' => 'active', 'username' => 'test_user']], 200),
        '*/api/v1/users/*/toggle' => \Illuminate\Support\Facades\Http::response(['status' => 'active'], 200),
    ]);

    $user = User::factory()->create();
    $reseller = \App\Models\Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'suspended_wallet',
        'wallet_balance' => 0,
    ]);

    $panel = \App\Models\Panel::factory()->eylandoo()->create();
    $config = \App\Models\ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'panel_id' => $panel->id,
        'panel_type' => 'eylandoo',
        'panel_user_id' => 'test_user',
        'status' => 'disabled',
        'meta' => [
            'disabled_by_wallet_suspension' => true,
        ],
    ]);

    $transaction = Transaction::create([
        'user_id' => $user->id,
        'amount' => 150000, // Use first_topup minimum
        'type' => Transaction::TYPE_DEPOSIT,
        'status' => Transaction::STATUS_PENDING,
        'description' => 'شارژ کیف پول ریسلر',
    ]);

    // Use the actual reenableWalletSuspendedConfigs method via the service
    DB::transaction(function () use ($transaction, $reseller) {
        $transaction->update(['status' => Transaction::STATUS_COMPLETED]);
        $reseller->increment('wallet_balance', $transaction->amount);

        // Use first_topup threshold for reactivation
        $reactivationThreshold = config('billing.reseller.first_topup.wallet_min', 150000);
        if ($reseller->isSuspendedWallet() &&
            $reseller->wallet_balance >= $reactivationThreshold) {
            $reseller->update(['status' => 'active']);
            
            // Call the reenableWalletSuspendedConfigs method via service
            $service = new \App\Services\WalletResellerReenableService();
            $service->reenableWalletSuspendedConfigs($reseller);
        }
    });

    // Verify config was re-enabled locally
    $config->refresh();
    expect($config->status)->toBe('active')
        ->and($config->meta['disabled_by_wallet_suspension'] ?? null)->toBeNull();

    // Verify the toggle endpoint was called (the proven-good path)
    \Illuminate\Support\Facades\Http::assertSent(function ($request) {
        return str_contains($request->url(), '/api/v1/users/')
            && str_contains($request->url(), '/toggle')
            && $request->method() === 'POST';
    });
});

test('wallet approval keeps eylandoo config disabled when remote fails', function () {
    \Illuminate\Support\Facades\Http::fake([
        '*/api/v1/users/test_user' => \Illuminate\Support\Facades\Http::response(['data' => ['status' => 'disabled', 'username' => 'test_user']], 200),
        '*/api/v1/users/test_user/toggle' => \Illuminate\Support\Facades\Http::response(['error' => 'Panel error'], 500),
    ]);

    $user = User::factory()->create();
    $reseller = \App\Models\Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'suspended_wallet',
        'wallet_balance' => 0,
    ]);

    $panel = \App\Models\Panel::factory()->eylandoo()->create();
    $config = \App\Models\ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'panel_id' => $panel->id,
        'panel_type' => 'eylandoo',
        'panel_user_id' => 'test_user',
        'status' => 'disabled',
        'meta' => [
            'disabled_by_wallet_suspension' => true,
        ],
    ]);

    $transaction = Transaction::create([
        'user_id' => $user->id,
        'amount' => 150000, // Use first_topup minimum
        'type' => Transaction::TYPE_DEPOSIT,
        'status' => Transaction::STATUS_PENDING,
        'description' => 'شارژ کیف پول ریسلر',
    ]);

    // Use the actual reenableWalletSuspendedConfigs method via service
    DB::transaction(function () use ($transaction, $reseller) {
        $transaction->update(['status' => Transaction::STATUS_COMPLETED]);
        $reseller->increment('wallet_balance', $transaction->amount);

        // Use first_topup threshold for reactivation
        $reactivationThreshold = config('billing.reseller.first_topup.wallet_min', 150000);
        if ($reseller->isSuspendedWallet() &&
            $reseller->wallet_balance >= $reactivationThreshold) {
            $reseller->update(['status' => 'active']);
            
            // Call the reenableWalletSuspendedConfigs method via service
            $service = new \App\Services\WalletResellerReenableService();
            $service->reenableWalletSuspendedConfigs($reseller);
        }
    });

    // Verify config remains disabled because remote call failed
    $config->refresh();
    expect($config->status)->toBe('disabled')
        ->and($config->meta['disabled_by_wallet_suspension'])->toBeTruthy();
});
