<?php

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\User;
use App\Services\ConfigNameGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create test user
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
        'config_limit' => 10,
        'wallet_balance' => 100000,
    ]);
});

test('config created with new naming system when flag enabled', function () {
    config(['config_names.enabled' => true]);
    
    $generator = new ConfigNameGenerator();
    $nameData = $generator->generate($this->reseller, $this->panel, 'wallet');
    
    $config = ResellerConfig::create([
        'reseller_id' => $this->reseller->id,
        'panel_id' => $this->panel->id,
        'external_username' => $nameData['name'],
        'name_version' => $nameData['version'],
        'panel_type' => $this->panel->panel_type,
        'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024, // 10GB
        'expires_at' => now()->addDays(30),
        'created_by' => $this->user->id,
        'status' => 'active',
    ]);
    
    expect($config)->not->toBeNull()
        ->and($config->external_username)->toMatch('/^FP-EY-[a-z0-9]+-W-\d{4}-[a-z0-9]{5}$/')
        ->and($config->name_version)->toBe(2);
});

test('config created with legacy naming when flag disabled', function () {
    config(['config_names.enabled' => false]);
    
    $generator = new ConfigNameGenerator();
    $nameData = $generator->generate($this->reseller, $this->panel, 'wallet');
    
    $config = ResellerConfig::create([
        'reseller_id' => $this->reseller->id,
        'panel_id' => $this->panel->id,
        'external_username' => $nameData['name'],
        'name_version' => $nameData['version'],
        'panel_type' => $this->panel->panel_type,
        'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024, // 10GB
        'expires_at' => now()->addDays(30),
        'created_by' => $this->user->id,
        'status' => 'active',
    ]);
    
    expect($config)->not->toBeNull()
        ->and($config->name_version)->toBeNull();
});

test('multiple configs created sequentially have incremental sequences', function () {
    config(['config_names.enabled' => true]);
    
    $generator = new ConfigNameGenerator();
    $configs = [];
    
    for ($i = 0; $i < 3; $i++) {
        $nameData = $generator->generate($this->reseller, $this->panel, 'wallet');
        
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
        
        $configs[] = $config;
    }
    
    expect($configs[0]->external_username)->toContain('-0001-')
        ->and($configs[1]->external_username)->toContain('-0002-')
        ->and($configs[2]->external_username)->toContain('-0003-');
});

test('configs for different resellers have different reseller codes', function () {
    config(['config_names.enabled' => true]);
    
    $reseller2 = Reseller::create([
        'user_id' => $this->user->id,
        'type' => 'traffic',
        'status' => 'active',
        'primary_panel_id' => $this->panel->id,
        'config_limit' => 10,
    ]);
    
    $generator = new ConfigNameGenerator();
    
    $nameData1 = $generator->generate($this->reseller, $this->panel, 'wallet');
    $nameData2 = $generator->generate($reseller2, $this->panel, 'traffic');
    
    // Parse the names to extract reseller codes
    $parsed1 = ConfigNameGenerator::parseName($nameData1['name'], 2);
    $parsed2 = ConfigNameGenerator::parseName($nameData2['name'], 2);
    
    expect($parsed1['reseller_code'])->not->toBe($parsed2['reseller_code']);
});

test('configs for different modes have different mode codes', function () {
    config(['config_names.enabled' => true]);
    
    $walletReseller = Reseller::create([
        'user_id' => $this->user->id,
        'type' => 'wallet',
        'status' => 'active',
        'primary_panel_id' => $this->panel->id,
        'config_limit' => 10,
        'wallet_balance' => 100000,
    ]);
    
    $trafficReseller = Reseller::create([
        'user_id' => $this->user->id,
        'type' => 'traffic',
        'status' => 'active',
        'primary_panel_id' => $this->panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024, // 100GB
    ]);
    
    $generator = new ConfigNameGenerator();
    
    $walletNameData = $generator->generate($walletReseller, $this->panel, 'wallet');
    $trafficNameData = $generator->generate($trafficReseller, $this->panel, 'traffic');
    
    expect($walletNameData['name'])->toContain('-W-')
        ->and($trafficNameData['name'])->toContain('-T-');
});

test('backfill command generates short codes for all resellers', function () {
    // Create multiple resellers without short_code
    $resellers = [];
    for ($i = 0; $i < 5; $i++) {
        $resellers[] = Reseller::create([
            'user_id' => $this->user->id,
            'type' => 'wallet',
            'status' => 'active',
            'primary_panel_id' => $this->panel->id,
            'config_limit' => 10,
            'short_code' => null,
        ]);
    }
    
    // Run backfill command
    $this->artisan('configs:backfill-short-codes')
        ->assertSuccessful();
    
    // Verify all resellers have short_code
    foreach ($resellers as $reseller) {
        $reseller->refresh();
        expect($reseller->short_code)->not->toBeNull()
            ->and($reseller->short_code)->toBeString()
            ->and(strlen($reseller->short_code))->toBeGreaterThanOrEqual(3);
    }
});

test('config name is unique across all configs', function () {
    config(['config_names.enabled' => true]);
    
    $generator = new ConfigNameGenerator();
    
    // Generate many configs
    $names = [];
    for ($i = 0; $i < 20; $i++) {
        $nameData = $generator->generate($this->reseller, $this->panel, 'wallet');
        $names[] = $nameData['name'];
        
        // Create the config to ensure uniqueness constraint is tested
        ResellerConfig::create([
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
    }
    
    // All names should be unique
    expect(count($names))->toBe(count(array_unique($names)));
});
