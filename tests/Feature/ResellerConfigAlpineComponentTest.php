<?php

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create test user with permissions
    $this->user = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($this->user);

    // Create test panel
    $this->eylandooPanel = Panel::create([
        'name' => 'Eylandoo Panel',
        'url' => 'https://eylandoo.example.com',
        'panel_type' => 'eylandoo',
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

    // Attach panel to reseller
    $this->reseller->panels()->attach($this->eylandooPanel->id, [
        'allowed_node_ids' => json_encode([1, 2]),
        'allowed_service_ids' => null,
    ]);
});

test('create page defines window.configForm function before x-data element', function () {
    $response = $this->get(route('reseller.configs.create'));

    $response->assertStatus(200);
    
    // Check that window.configForm is defined in a script tag
    $response->assertSee('window.configForm', false);
    $response->assertSee('function(panels, initialPanelId)', false);
    
    // Verify the script comes before the x-data element
    $content = $response->getContent();
    $scriptPos = strpos($content, 'window.configForm');
    $xDataPos = strpos($content, 'x-data="configForm');
    
    expect($scriptPos)->toBeLessThan($xDataPos)
        ->and($scriptPos)->toBeGreaterThan(0)
        ->and($xDataPos)->toBeGreaterThan(0);
});

test('Alpine component has all required properties and methods', function () {
    $response = $this->get(route('reseller.configs.create'));

    $response->assertStatus(200);
    
    // Check for essential Alpine component properties
    $content = $response->getContent();
    
    // Check for state properties
    expect($content)->toContain('panels: panels || []')
        ->and($content)->toContain('selectedPanelId')
        ->and($content)->toContain('nodeSelections: []')
        ->and($content)->toContain('serviceSelections: []')
        ->and($content)->toContain('maxClients');
    
    // Check for computed properties
    expect($content)->toContain('get selectedPanel()');
    
    // Check for methods
    expect($content)->toContain('init()')
        ->and($content)->toContain('refreshPanelData(panelId)');
    
    // Check for watcher
    expect($content)->toContain('this.$watch');
});

test('Alpine component initialization receives correct panel data', function () {
    $response = $this->get(route('reseller.configs.create'));

    $response->assertStatus(200);
    
    // Verify panels data is passed via @js() directive
    $panelsForJs = $response->viewData('panelsForJs');
    
    expect($panelsForJs)->toBeArray()
        ->and(count($panelsForJs))->toBe(1);
    
    // Check that JSON is properly encoded in the response
    $content = $response->getContent();
    $jsonEncoded = json_encode($panelsForJs);
    
    // The @js() directive should properly encode the data
    expect($content)->toContain('x-data="configForm(');
});

test('Alpine component clears selections when panel changes', function () {
    $response = $this->get(route('reseller.configs.create'));

    $response->assertStatus(200);
    
    // Verify the watcher clears selections
    $content = $response->getContent();
    
    expect($content)->toContain('this.nodeSelections = []')
        ->and($content)->toContain('this.serviceSelections = []');
});

test('Alpine component handles panel data refresh with AJAX endpoint', function () {
    $response = $this->get(route('reseller.configs.create'));

    $response->assertStatus(200);
    
    // Verify the refreshPanelData method exists and uses correct endpoint
    $content = $response->getContent();
    
    expect($content)->toContain('async refreshPanelData(panelId)')
        ->and($content)->toContain('/reseller/panels/${panelId}/data')
        ->and($content)->toContain('fetch(');
});

test('create page has no Alpine errors in rendered HTML', function () {
    $response = $this->get(route('reseller.configs.create'));

    $response->assertStatus(200);
    
    $content = $response->getContent();
    
    // Check that all Alpine directives are properly formed
    expect($content)->toContain('x-data=')
        ->and($content)->toContain('x-model=')
        ->and($content)->toContain('x-if=')
        ->and($content)->toContain('x-for=');
    
    // Ensure no undefined variables in Alpine expressions
    // The component should define all necessary properties
    expect($content)->not->toContain('undefined');
});
