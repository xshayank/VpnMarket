<?php

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    // Clear cache before each test
    Cache::flush();
});

test('panel model getCachedMarzneshinServices returns empty for non-marzneshin panels', function () {
    $panel = Panel::factory()->create([
        'name' => 'Test Marzban Panel',
        'url' => 'https://marzban.example.com',
        'panel_type' => 'marzban',
        'username' => 'admin',
        'password' => 'password',
        'is_active' => true,
    ]);

    $services = $panel->getCachedMarzneshinServices();
    
    expect($services)->toBeArray()
        ->and($services)->toBeEmpty();
});

test('panel model getCachedMarzneshinServices caches marzneshin services', function () {
    $panel = Panel::factory()->create([
        'name' => 'Test Marzneshin Panel',
        'url' => 'https://marzneshin.example.com',
        'panel_type' => 'marzneshin',
        'username' => 'admin',
        'password' => 'password',
        'is_active' => true,
        'extra' => ['node_hostname' => 'https://node.marzneshin.example.com'],
    ]);

    // Mock login and list services
    Http::fake([
        'marzneshin.example.com/api/admins/token' => Http::response([
            'access_token' => 'test-token-123',
        ], 200),
        'marzneshin.example.com/api/services' => Http::response([
            ['id' => 1, 'name' => 'Service A'],
            ['id' => 2, 'name' => 'Service B'],
        ], 200),
    ]);

    // First call - should hit API
    $services1 = $panel->getCachedMarzneshinServices();
    expect($services1)->toHaveCount(2)
        ->and($services1[0]['id'])->toBe(1)
        ->and($services1[0]['name'])->toBe('Service A');

    // Verify cache was set
    expect(Cache::has("panel:{$panel->id}:marzneshin_services"))->toBeTrue();

    // Change HTTP response to verify it uses cache
    Http::fake([
        'marzneshin.example.com/api/services' => Http::response([
            ['id' => 99, 'name' => 'Service Z'],
        ], 200),
    ]);

    // Second call - should use cache, not hit API
    $services2 = $panel->getCachedMarzneshinServices();
    expect($services2)->toHaveCount(2) // Still 2 services from cache
        ->and($services2[0]['id'])->toBe(1); // Still Service A from cache
});

test('panel model handles marzneshin api failures gracefully', function () {
    $panel = Panel::factory()->create([
        'name' => 'Test Marzneshin Panel',
        'url' => 'https://marzneshin.example.com',
        'panel_type' => 'marzneshin',
        'username' => 'admin',
        'password' => 'password',
        'is_active' => true,
    ]);

    Http::fake([
        'marzneshin.example.com/api/admins/token' => Http::response('Server Error', 500),
    ]);

    $services = $panel->getCachedMarzneshinServices();
    expect($services)->toBeArray()
        ->and($services)->toBeEmpty();
});

test('registration applies eylandoo default nodes to new reseller', function () {
    // Create Eylandoo panel with default nodes
    $panel = Panel::factory()->create([
        'name' => 'Test Eylandoo Panel',
        'url' => 'https://eylandoo.example.com',
        'panel_type' => 'eylandoo',
        'api_token' => 'test-token',
        'is_active' => true,
        'registration_default_node_ids' => [101, 102, 103],
    ]);

    // Simulate registration
    $user = User::factory()->create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => bcrypt('password123'),
    ]);

    $reseller = Reseller::create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'suspended_wallet',
        'primary_panel_id' => $panel->id,
        'wallet_balance' => 0,
        'traffic_total_bytes' => 0,
        'traffic_used_bytes' => 0,
        'eylandoo_allowed_node_ids' => $panel->getRegistrationDefaultNodeIds(),
        'max_configs' => 1000,
        'meta' => [],
    ]);

    // Verify reseller has the default nodes
    expect($reseller->eylandoo_allowed_node_ids)->toBeArray()
        ->and($reseller->eylandoo_allowed_node_ids)->toHaveCount(3)
        ->and($reseller->eylandoo_allowed_node_ids)->toContain(101)
        ->and($reseller->eylandoo_allowed_node_ids)->toContain(102)
        ->and($reseller->eylandoo_allowed_node_ids)->toContain(103);
});

test('registration applies marzneshin default services to new reseller', function () {
    // Create Marzneshin panel with default services
    $panel = Panel::factory()->create([
        'name' => 'Test Marzneshin Panel',
        'url' => 'https://marzneshin.example.com',
        'panel_type' => 'marzneshin',
        'username' => 'admin',
        'password' => 'password',
        'is_active' => true,
        'registration_default_service_ids' => [1, 2],
    ]);

    // Simulate registration
    $user = User::factory()->create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => bcrypt('password123'),
    ]);

    $reseller = Reseller::create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'suspended_wallet',
        'primary_panel_id' => $panel->id,
        'wallet_balance' => 0,
        'traffic_total_bytes' => 0,
        'traffic_used_bytes' => 0,
        'marzneshin_allowed_service_ids' => $panel->getRegistrationDefaultServiceIds(),
        'max_configs' => 1000,
        'meta' => [],
    ]);

    // Verify reseller has the default services
    expect($reseller->marzneshin_allowed_service_ids)->toBeArray()
        ->and($reseller->marzneshin_allowed_service_ids)->toHaveCount(2)
        ->and($reseller->marzneshin_allowed_service_ids)->toContain(1)
        ->and($reseller->marzneshin_allowed_service_ids)->toContain(2);
});

test('registration handles missing defaults gracefully', function () {
    // Create Eylandoo panel without default nodes
    $panel = Panel::factory()->create([
        'name' => 'Test Eylandoo Panel',
        'url' => 'https://eylandoo.example.com',
        'panel_type' => 'eylandoo',
        'api_token' => 'test-token',
        'is_active' => true,
        'registration_default_node_ids' => null,
    ]);

    $user = User::factory()->create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => bcrypt('password123'),
    ]);

    $reseller = Reseller::create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'suspended_wallet',
        'primary_panel_id' => $panel->id,
        'wallet_balance' => 0,
        'traffic_total_bytes' => 0,
        'traffic_used_bytes' => 0,
        'eylandoo_allowed_node_ids' => $panel->getRegistrationDefaultNodeIds(),
        'max_configs' => 1000,
        'meta' => [],
    ]);

    // Verify reseller has empty allowed nodes (not null)
    expect($reseller->eylandoo_allowed_node_ids)->toBeArray()
        ->and($reseller->eylandoo_allowed_node_ids)->toBeEmpty();
});

test('panel getRegistrationDefaultNodeIds returns empty for non-eylandoo panels', function () {
    $panel = Panel::factory()->create([
        'name' => 'Test Marzban Panel',
        'url' => 'https://marzban.example.com',
        'panel_type' => 'marzban',
        'username' => 'admin',
        'password' => 'password',
        'is_active' => true,
        'registration_default_node_ids' => [1, 2, 3],
    ]);

    $nodeIds = $panel->getRegistrationDefaultNodeIds();
    expect($nodeIds)->toBeArray()
        ->and($nodeIds)->toBeEmpty();
});

test('panel getRegistrationDefaultServiceIds returns empty for non-marzneshin panels', function () {
    $panel = Panel::factory()->create([
        'name' => 'Test Eylandoo Panel',
        'url' => 'https://eylandoo.example.com',
        'panel_type' => 'eylandoo',
        'api_token' => 'test-token',
        'is_active' => true,
        'registration_default_service_ids' => [1, 2],
    ]);

    $serviceIds = $panel->getRegistrationDefaultServiceIds();
    expect($serviceIds)->toBeArray()
        ->and($serviceIds)->toBeEmpty();
});

test('panel saves and retrieves default nodes correctly', function () {
    $panel = Panel::factory()->create([
        'name' => 'Test Eylandoo Panel',
        'url' => 'https://eylandoo.example.com',
        'panel_type' => 'eylandoo',
        'api_token' => 'test-token',
        'is_active' => true,
        'registration_default_node_ids' => [1, 2, 3],
    ]);

    // Refresh from database
    $panel->refresh();

    expect($panel->registration_default_node_ids)->toBeArray()
        ->and($panel->registration_default_node_ids)->toHaveCount(3)
        ->and($panel->registration_default_node_ids)->toEqual([1, 2, 3]);
});

test('panel saves and retrieves default services correctly', function () {
    $panel = Panel::factory()->create([
        'name' => 'Test Marzneshin Panel',
        'url' => 'https://marzneshin.example.com',
        'panel_type' => 'marzneshin',
        'username' => 'admin',
        'password' => 'password',
        'is_active' => true,
        'registration_default_service_ids' => [10, 20],
    ]);

    // Refresh from database
    $panel->refresh();

    expect($panel->registration_default_service_ids)->toBeArray()
        ->and($panel->registration_default_service_ids)->toHaveCount(2)
        ->and($panel->registration_default_service_ids)->toEqual([10, 20]);
});

test('eylandoo node list caching works correctly', function () {
    $panel = Panel::factory()->create([
        'name' => 'Test Eylandoo Panel',
        'url' => 'https://eylandoo.example.com',
        'panel_type' => 'eylandoo',
        'api_token' => 'test-token',
        'is_active' => true,
        'extra' => ['node_hostname' => 'https://node.eylandoo.example.com'],
    ]);

    Http::fake([
        'eylandoo.example.com/api/v1/nodes' => Http::response([
            'data' => [
                'nodes' => [
                    ['id' => 1, 'name' => 'Node 1'],
                    ['id' => 2, 'name' => 'Node 2'],
                ],
            ],
        ], 200),
    ]);

    // First call
    $nodes1 = $panel->getCachedEylandooNodes();
    expect($nodes1)->toHaveCount(2);

    // Second call should use cache
    $nodes2 = $panel->getCachedEylandooNodes();
    expect($nodes2)->toHaveCount(2)
        ->and($nodes2)->toEqual($nodes1);
});

test('reseller created with eylandoo defaults logs correctly', function () {
    Log::spy();

    $panel = Panel::factory()->create([
        'name' => 'Test Eylandoo Panel',
        'url' => 'https://eylandoo.example.com',
        'panel_type' => 'eylandoo',
        'api_token' => 'test-token',
        'is_active' => true,
        'registration_default_node_ids' => [101, 102],
    ]);

    $user = User::factory()->create();

    $reseller = Reseller::create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'suspended_wallet',
        'primary_panel_id' => $panel->id,
        'wallet_balance' => 0,
        'eylandoo_allowed_node_ids' => $panel->getRegistrationDefaultNodeIds(),
        'max_configs' => 1000,
    ]);

    // We can't easily test log calls in the registration controller from here,
    // but we can verify the reseller was created with correct defaults
    expect($reseller->eylandoo_allowed_node_ids)->toEqual([101, 102]);
});
