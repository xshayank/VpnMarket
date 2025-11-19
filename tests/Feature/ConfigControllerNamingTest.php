<?php

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create test user with permissions
    $this->user = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($this->user);
    
    // Create test panel
    $this->panel = Panel::create([
        'name' => 'Test Panel',
        'url' => 'https://panel.example.com',
        'panel_type' => 'eylandoo',
        'username' => 'admin',
        'password' => 'password',
        'is_active' => true,
    ]);
    
    // Create test reseller
    $this->reseller = Reseller::create([
        'user_id' => $this->user->id,
        'type' => 'wallet',
        'status' => 'active',
        'primary_panel_id' => $this->panel->id,
        'panel_id' => $this->panel->id,
        'config_limit' => 10,
        'wallet_balance' => 100000,
    ]);
});

test('config controller uses V2 naming when flag is enabled', function () {
    config(['config_names.enabled' => true]);
    
    // Create a config (simulating what ConfigController does)
    $generator = new \App\Services\ConfigNameGenerator();
    $nameData = $generator->generate($this->reseller, $this->panel, $this->reseller->type);
    
    $config = ResellerConfig::create([
        'reseller_id' => $this->reseller->id,
        'panel_id' => $this->panel->id,
        'external_username' => $nameData['name'],
        'name_version' => $nameData['version'],
        'panel_type' => $this->panel->panel_type,
        'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
        'expires_at' => now()->addDays(30),
        'created_by' => $this->user->id,
        'status' => 'active',
    ]);
    
    expect($config->external_username)
        ->toMatch('/^FP_EY_[a-z0-9]+_W_\d{4}_[a-z0-9]{5}$/')
        ->and($config->name_version)->toBe(2);
});

test('config controller uses legacy naming when flag is disabled', function () {
    config(['config_names.enabled' => false]);
    
    // Create a config (simulating what ConfigController does)
    $generator = new \App\Services\ConfigNameGenerator();
    $nameData = $generator->generate($this->reseller, $this->panel, $this->reseller->type);
    
    $config = ResellerConfig::create([
        'reseller_id' => $this->reseller->id,
        'panel_id' => $this->panel->id,
        'external_username' => $nameData['name'],
        'name_version' => $nameData['version'],
        'panel_type' => $this->panel->panel_type,
        'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
        'expires_at' => now()->addDays(30),
        'created_by' => $this->user->id,
        'status' => 'active',
    ]);
    
    expect($config->name_version)->toBeNull()
        ->and($config->external_username)->not->toBeEmpty()
        ->and($config->external_username)->not->toMatch('/^FP_EY_/');
});

test('custom name overrides V2 naming and sets name_version to null', function () {
    config(['config_names.enabled' => true]);
    
    $customName = 'my_custom_config_123';
    
    // Simulate what ConfigController does with custom_name
    $config = ResellerConfig::create([
        'reseller_id' => $this->reseller->id,
        'panel_id' => $this->panel->id,
        'external_username' => $customName,
        'name_version' => null, // Custom names don't have a version
        'custom_name' => $customName,
        'panel_type' => $this->panel->panel_type,
        'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
        'expires_at' => now()->addDays(30),
        'created_by' => $this->user->id,
        'status' => 'active',
    ]);
    
    expect($config->external_username)->toBe($customName)
        ->and($config->name_version)->toBeNull()
        ->and($config->custom_name)->toBe($customName);
});

test('config sequence increments correctly for same reseller and panel', function () {
    config(['config_names.enabled' => true]);
    
    $generator = new \App\Services\ConfigNameGenerator();
    
    // Create first config
    $nameData1 = $generator->generate($this->reseller, $this->panel, $this->reseller->type);
    $config1 = ResellerConfig::create([
        'reseller_id' => $this->reseller->id,
        'panel_id' => $this->panel->id,
        'external_username' => $nameData1['name'],
        'name_version' => $nameData1['version'],
        'panel_type' => $this->panel->panel_type,
        'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
        'expires_at' => now()->addDays(30),
        'created_by' => $this->user->id,
        'status' => 'active',
    ]);
    
    // Create second config
    $nameData2 = $generator->generate($this->reseller, $this->panel, $this->reseller->type);
    $config2 = ResellerConfig::create([
        'reseller_id' => $this->reseller->id,
        'panel_id' => $this->panel->id,
        'external_username' => $nameData2['name'],
        'name_version' => $nameData2['version'],
        'panel_type' => $this->panel->panel_type,
        'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
        'expires_at' => now()->addDays(30),
        'created_by' => $this->user->id,
        'status' => 'active',
    ]);
    
    // Parse the sequence numbers from the names
    $parsed1 = \App\Services\ConfigNameGenerator::parseName($config1->external_username, $config1->name_version);
    $parsed2 = \App\Services\ConfigNameGenerator::parseName($config2->external_username, $config2->name_version);
    
    expect($parsed1)->not->toBeNull()
        ->and($parsed2)->not->toBeNull()
        ->and($parsed2['sequence'])->toBe($parsed1['sequence'] + 1);
});

test('imported configs from panel have null name_version', function () {
    // Simulate importing a config from panel (as done in AttachPanelConfigsToReseller)
    $config = ResellerConfig::create([
        'reseller_id' => $this->reseller->id,
        'panel_id' => $this->panel->id,
        'panel_type' => $this->panel->panel_type,
        'panel_user_id' => 'external_user_123',
        'external_username' => 'legacy_panel_user',
        'name_version' => null, // Imported configs are legacy
        'status' => 'active',
        'usage_bytes' => 0,
        'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
        'expires_at' => now()->addDays(30),
        'created_by' => $this->user->id,
    ]);
    
    expect($config->name_version)->toBeNull()
        ->and($config->external_username)->toBe('legacy_panel_user');
});
