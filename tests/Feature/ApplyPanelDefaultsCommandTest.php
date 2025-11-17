<?php

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    // Create test data
    $this->eylandooPanel = Panel::factory()->create([
        'name' => 'Eylandoo Panel',
        'url' => 'https://eylandoo.example.com',
        'panel_type' => 'eylandoo',
        'api_token' => 'test-token',
        'is_active' => true,
        'registration_default_node_ids' => [10, 20, 30],
    ]);

    $this->marzneshinPanel = Panel::factory()->create([
        'name' => 'Marzneshin Panel',
        'url' => 'https://marzneshin.example.com',
        'panel_type' => 'marzneshin',
        'username' => 'admin',
        'password' => 'password',
        'is_active' => true,
        'registration_default_service_ids' => [5, 6],
    ]);

    $this->emptyEylandooPanel = Panel::factory()->create([
        'name' => 'Empty Eylandoo Panel',
        'url' => 'https://eylandoo2.example.com',
        'panel_type' => 'eylandoo',
        'api_token' => 'test-token',
        'is_active' => true,
        'registration_default_node_ids' => null,
    ]);
});

test('backfill command applies eylandoo defaults to resellers without node ids', function () {
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'active',
        'primary_panel_id' => $this->eylandooPanel->id,
        'eylandoo_allowed_node_ids' => null,
    ]);

    // Run command
    Artisan::call('reseller:apply-panel-defaults', ['--force' => true]);

    // Refresh reseller
    $reseller->refresh();

    // Verify defaults were applied
    expect($reseller->eylandoo_allowed_node_ids)->toBeArray()
        ->and($reseller->eylandoo_allowed_node_ids)->toHaveCount(3)
        ->and($reseller->eylandoo_allowed_node_ids)->toEqual([10, 20, 30]);
});

test('backfill command applies marzneshin defaults to resellers without service ids', function () {
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'active',
        'primary_panel_id' => $this->marzneshinPanel->id,
        'marzneshin_allowed_service_ids' => null,
    ]);

    // Run command
    Artisan::call('reseller:apply-panel-defaults', ['--force' => true]);

    // Refresh reseller
    $reseller->refresh();

    // Verify defaults were applied
    expect($reseller->marzneshin_allowed_service_ids)->toBeArray()
        ->and($reseller->marzneshin_allowed_service_ids)->toHaveCount(2)
        ->and($reseller->marzneshin_allowed_service_ids)->toEqual([5, 6]);
});

test('backfill command skips resellers that already have allowed ids', function () {
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'active',
        'primary_panel_id' => $this->eylandooPanel->id,
        'eylandoo_allowed_node_ids' => [99], // Already has IDs
    ]);

    // Run command
    Artisan::call('reseller:apply-panel-defaults', ['--force' => true]);

    // Refresh reseller
    $reseller->refresh();

    // Verify original IDs were not changed
    expect($reseller->eylandoo_allowed_node_ids)->toBeArray()
        ->and($reseller->eylandoo_allowed_node_ids)->toHaveCount(1)
        ->and($reseller->eylandoo_allowed_node_ids)->toEqual([99]);
});

test('backfill command skips resellers when panel has no defaults', function () {
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'active',
        'primary_panel_id' => $this->emptyEylandooPanel->id,
        'eylandoo_allowed_node_ids' => null,
    ]);

    // Run command
    Artisan::call('reseller:apply-panel-defaults', ['--force' => true]);

    // Refresh reseller
    $reseller->refresh();

    // Verify no IDs were applied (still null)
    expect($reseller->eylandoo_allowed_node_ids)->toBeNull();
});

test('backfill command dry run does not apply changes', function () {
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'active',
        'primary_panel_id' => $this->eylandooPanel->id,
        'eylandoo_allowed_node_ids' => null,
    ]);

    // Run command in dry-run mode
    Artisan::call('reseller:apply-panel-defaults', ['--dry' => true]);

    // Refresh reseller
    $reseller->refresh();

    // Verify no changes were made
    expect($reseller->eylandoo_allowed_node_ids)->toBeNull();
});

test('backfill command processes multiple resellers correctly', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $user3 = User::factory()->create();

    // Reseller 1: needs eylandoo defaults
    $reseller1 = Reseller::factory()->create([
        'user_id' => $user1->id,
        'type' => 'wallet',
        'status' => 'active',
        'primary_panel_id' => $this->eylandooPanel->id,
        'eylandoo_allowed_node_ids' => null,
    ]);

    // Reseller 2: needs marzneshin defaults
    $reseller2 = Reseller::factory()->create([
        'user_id' => $user2->id,
        'type' => 'wallet',
        'status' => 'active',
        'primary_panel_id' => $this->marzneshinPanel->id,
        'marzneshin_allowed_service_ids' => null,
    ]);

    // Reseller 3: already has IDs (should be skipped)
    $reseller3 = Reseller::factory()->create([
        'user_id' => $user3->id,
        'type' => 'wallet',
        'status' => 'active',
        'primary_panel_id' => $this->eylandooPanel->id,
        'eylandoo_allowed_node_ids' => [88],
    ]);

    // Run command
    Artisan::call('reseller:apply-panel-defaults', ['--force' => true]);

    // Refresh all resellers
    $reseller1->refresh();
    $reseller2->refresh();
    $reseller3->refresh();

    // Verify results
    expect($reseller1->eylandoo_allowed_node_ids)->toEqual([10, 20, 30])
        ->and($reseller2->marzneshin_allowed_service_ids)->toEqual([5, 6])
        ->and($reseller3->eylandoo_allowed_node_ids)->toEqual([88]); // Unchanged
});

test('backfill command returns success exit code', function () {
    $exitCode = Artisan::call('reseller:apply-panel-defaults', ['--force' => true]);
    expect($exitCode)->toBe(0);
});
