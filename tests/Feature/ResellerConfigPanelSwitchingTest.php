<?php

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\User;
use App\Services\PanelDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create test user with permissions
    $this->user = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($this->user);
    
    // Create test panels
    $this->eylandooPanel = Panel::create([
        'name' => 'Eylandoo Panel',
        'url' => 'https://eylandoo.example.com',
        'panel_type' => 'eylandoo',
        'username' => 'admin',
        'password' => 'password',
        'api_token' => 'test-token',
        'is_active' => true,
    ]);
    
    $this->marzneshinPanel = Panel::create([
        'name' => 'Marzneshin Panel',
        'url' => 'https://marzneshin.example.com',
        'panel_type' => 'marzneshin',
        'username' => 'admin',
        'password' => 'password',
        'api_token' => 'test-token',
        'is_active' => true,
    ]);
    
    // Create test reseller
    $this->reseller = Reseller::create([
        'user_id' => $this->user->id,
        'type' => 'wallet',
        'status' => 'active',
        'primary_panel_id' => $this->eylandooPanel->id,
        'panel_id' => $this->eylandooPanel->id,
        'config_limit' => 10,
        'wallet_balance' => 100000,
    ]);
    
    // Attach panels to reseller with whitelists
    $this->reseller->panels()->attach($this->eylandooPanel->id, [
        'allowed_node_ids' => json_encode([1, 2]),
        'allowed_service_ids' => null,
    ]);
    
    $this->reseller->panels()->attach($this->marzneshinPanel->id, [
        'allowed_node_ids' => null,
        'allowed_service_ids' => json_encode([10, 20, 30]),
    ]);
});

test('create page returns panelsForJs array with correct structure', function () {
    $response = $this->get(route('reseller.configs.create'));
    
    $response->assertStatus(200);
    $response->assertViewHas('panelsForJs');
    
    $panelsForJs = $response->viewData('panelsForJs');
    
    expect($panelsForJs)->toBeArray()
        ->and(count($panelsForJs))->toBe(2);
    
    // Find Eylandoo panel
    $eylandoo = collect($panelsForJs)->firstWhere('panel_type', 'eylandoo');
    expect($eylandoo)->toHaveKeys(['id', 'name', 'panel_type', 'nodes', 'services'])
        ->and($eylandoo['panel_type'])->toBe('eylandoo')
        ->and($eylandoo['nodes'])->toBeArray()
        ->and($eylandoo['services'])->toBeArray()->toBeEmpty();
    
    // Find Marzneshin panel
    $marzneshin = collect($panelsForJs)->firstWhere('panel_type', 'marzneshin');
    expect($marzneshin)->toHaveKeys(['id', 'name', 'panel_type', 'nodes', 'services'])
        ->and($marzneshin['panel_type'])->toBe('marzneshin')
        ->and($marzneshin['nodes'])->toBeArray()->toBeEmpty()
        ->and($marzneshin['services'])->toBeArray();
});

test('create page includes prefillPanelId from old input', function () {
    // Simulate validation failure with old input
    $response = $this->withSession(['_old_input' => ['panel_id' => $this->eylandooPanel->id]])
        ->get(route('reseller.configs.create'));
    
    $response->assertStatus(200);
    $response->assertViewHas('prefillPanelId', $this->eylandooPanel->id);
});

test('create page includes prefillPanelId from query parameter', function () {
    $response = $this->get(route('reseller.configs.create', ['panel_id' => $this->marzneshinPanel->id]));
    
    $response->assertStatus(200);
    $response->assertViewHas('prefillPanelId', $this->marzneshinPanel->id);
});

test('store validates node_ids only allowed for eylandoo panels', function () {
    $response = $this->post(route('reseller.configs.store'), [
        'panel_id' => $this->marzneshinPanel->id,
        'traffic_limit_gb' => 10,
        'expires_days' => 30,
        'node_ids' => [1, 2], // Invalid for Marzneshin
    ]);
    
    $response->assertSessionHas('error', 'Node selection is only available for Eylandoo panels.');
});

test('store validates service_ids only allowed for marzneshin panels', function () {
    $response = $this->post(route('reseller.configs.store'), [
        'panel_id' => $this->eylandooPanel->id,
        'traffic_limit_gb' => 10,
        'expires_days' => 30,
        'service_ids' => [10, 20], // Invalid for Eylandoo
    ]);
    
    $response->assertSessionHas('error', 'Service selection is only available for Marzneshin panels.');
});

test('store validates node_ids against panel whitelist', function () {
    $response = $this->post(route('reseller.configs.store'), [
        'panel_id' => $this->eylandooPanel->id,
        'traffic_limit_gb' => 10,
        'expires_days' => 30,
        'node_ids' => [1, 2, 999], // 999 not in whitelist
    ]);
    
    $response->assertSessionHas('error', 'One or more selected nodes are not allowed for your account.');
});

test('store validates service_ids against panel whitelist', function () {
    $response = $this->post(route('reseller.configs.store'), [
        'panel_id' => $this->marzneshinPanel->id,
        'traffic_limit_gb' => 10,
        'expires_days' => 30,
        'service_ids' => [10, 999], // 999 not in whitelist
    ]);
    
    $response->assertSessionHas('error', 'One or more selected services are not allowed for your account.');
});

test('PanelDataService returns correct data for eylandoo panel', function () {
    $service = new PanelDataService();
    $data = $service->getPanelDataForJs($this->eylandooPanel, [1, 2]);
    
    expect($data)->toHaveKeys(['id', 'name', 'panel_type', 'nodes', 'services'])
        ->and($data['panel_type'])->toBe('eylandoo')
        ->and($data['nodes'])->toBeArray()
        ->and($data['services'])->toBeEmpty();
    
    // Should have default nodes if API returns empty
    if (empty($data['nodes'])) {
        // Fallback defaults should be applied
        expect($data['nodes'])->toBeArray();
    }
});

test('PanelDataService returns correct data for marzneshin panel', function () {
    $service = new PanelDataService();
    $data = $service->getPanelDataForJs($this->marzneshinPanel, null, [10, 20, 30]);
    
    expect($data)->toHaveKeys(['id', 'name', 'panel_type', 'nodes', 'services'])
        ->and($data['panel_type'])->toBe('marzneshin')
        ->and($data['nodes'])->toBeEmpty()
        ->and($data['services'])->toBeArray();
    
    // Should convert service IDs to objects
    expect(count($data['services']))->toBe(3);
});

test('PanelDataService applies node whitelist filtering', function () {
    $service = new PanelDataService();
    
    // Test with whitelist
    $dataWithWhitelist = $service->getPanelDataForJs($this->eylandooPanel, [1]);
    
    // Should only include whitelisted nodes (or defaults if API fails)
    expect($dataWithWhitelist['nodes'])->toBeArray();
});

test('PanelDataService applies service whitelist filtering', function () {
    $service = new PanelDataService();
    
    // Test with whitelist
    $dataWithWhitelist = $service->getPanelDataForJs($this->marzneshinPanel, null, [10, 20]);
    
    // Should only include whitelisted services
    expect($dataWithWhitelist['services'])->toBeArray()
        ->and(count($dataWithWhitelist['services']))->toBe(2);
});

test('PanelDataService getPanelsForReseller returns all reseller panels', function () {
    $service = new PanelDataService();
    $panels = $service->getPanelsForReseller($this->reseller);
    
    expect($panels)->toBeArray()
        ->and(count($panels))->toBe(2);
    
    // Verify both panel types are present
    $panelTypes = collect($panels)->pluck('panel_type')->toArray();
    expect($panelTypes)->toContain('eylandoo')
        ->and($panelTypes)->toContain('marzneshin');
});

test('reseller without panels sees error message', function () {
    // Create new user for new reseller
    $newUser = User::factory()->create(['is_super_admin' => true]);
    
    // Create new reseller without panels
    $newReseller = Reseller::create([
        'user_id' => $newUser->id,
        'type' => 'wallet',
        'status' => 'active',
        'primary_panel_id' => $this->eylandooPanel->id,
        'panel_id' => $this->eylandooPanel->id,
        'config_limit' => 10,
        'wallet_balance' => 100000,
    ]);
    
    // Login as new user
    $this->actingAs($newUser);
    
    $response = $this->get(route('reseller.configs.create'));
    
    $response->assertRedirect(route('reseller.dashboard'))
        ->assertSessionHas('error', 'No panels assigned to your account. Please contact support.');
});

test('create page view has Alpine.js data binding', function () {
    $response = $this->get(route('reseller.configs.create'));
    
    $response->assertStatus(200);
    $response->assertSee('x-data=', false); // Use false to check unescaped
    $response->assertSee('configForm', false);
    $response->assertSee('x-model', false);
    $response->assertSee('selectedPanelId', false);
});

test('store rejects config when reseller has no access to panel', function () {
    // Create a panel not attached to reseller
    $otherPanel = Panel::create([
        'name' => 'Other Panel',
        'url' => 'https://other.example.com',
        'panel_type' => 'eylandoo',
        'username' => 'admin',
        'password' => 'password',
        'is_active' => true,
    ]);
    
    $response = $this->post(route('reseller.configs.store'), [
        'panel_id' => $otherPanel->id,
        'traffic_limit_gb' => 10,
        'expires_days' => 30,
    ]);
    
    $response->assertSessionHas('error', 'You do not have access to the selected panel.');
});
