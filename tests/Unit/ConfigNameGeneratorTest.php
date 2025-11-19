<?php

use App\Models\ConfigNameSequence;
use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\User;
use App\Services\ConfigNameGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // Create test user
    $this->user = User::factory()->create();
    
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
    
    $this->generator = new ConfigNameGenerator();
});

test('generates legacy name when feature is disabled', function () {
    config(['config_names.enabled' => false]);
    
    $result = $this->generator->generate($this->reseller, $this->panel, 'wallet');
    
    expect($result)->toHaveKey('name')
        ->and($result)->toHaveKey('version')
        ->and($result['version'])->toBeNull()
        ->and($result['name'])->toBeString()
        ->and($result['name'])->not->toBeEmpty();
});

test('generates new pattern name when feature is enabled', function () {
    config(['config_names.enabled' => true]);
    
    $result = $this->generator->generate($this->reseller, $this->panel, 'wallet');
    
    expect($result)->toHaveKey('name')
        ->and($result)->toHaveKey('version')
        ->and($result['version'])->toBe(2)
        ->and($result['name'])->toMatch('/^FP-EY-[a-z0-9]+-W-\d{4}-[a-z0-9]{5}$/');
});

test('generates correct panel type codes', function () {
    config(['config_names.enabled' => true]);
    
    $panels = [
        'eylandoo' => 'EY',
        'marzneshin' => 'MN',
        'marzban' => 'MB',
        'xui' => 'XU',
    ];
    
    foreach ($panels as $panelType => $expectedCode) {
        $panel = Panel::create([
            'name' => "Test Panel {$panelType}",
            'url' => 'https://panel.example.com',
            'panel_type' => $panelType,
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
        ]);
        
        $result = $this->generator->generate($this->reseller, $panel, 'wallet');
        
        expect($result['name'])->toContain("-{$expectedCode}-");
    }
});

test('generates correct mode codes', function () {
    config(['config_names.enabled' => true]);
    
    $modes = [
        'wallet' => 'W',
        'traffic' => 'T',
    ];
    
    foreach ($modes as $mode => $expectedCode) {
        $user = User::factory()->create();
        $reseller = Reseller::create([
            'user_id' => $user->id,
            'type' => $mode,
            'status' => 'active',
            'primary_panel_id' => $this->panel->id,
            'config_limit' => 10,
        ]);
        
        $result = $this->generator->generate($reseller, $this->panel, $mode);
        
        expect($result['name'])->toContain("-{$expectedCode}-");
    }
});

test('sequence increments correctly', function () {
    config(['config_names.enabled' => true]);
    
    // Generate first config name
    $result1 = $this->generator->generate($this->reseller, $this->panel, 'wallet');
    expect($result1['name'])->toContain('-0001-');
    
    // Generate second config name
    $result2 = $this->generator->generate($this->reseller, $this->panel, 'wallet');
    expect($result2['name'])->toContain('-0002-');
    
    // Generate third config name
    $result3 = $this->generator->generate($this->reseller, $this->panel, 'wallet');
    expect($result3['name'])->toContain('-0003-');
    
    // Verify sequence record exists
    $sequence = ConfigNameSequence::where('reseller_id', $this->reseller->id)
        ->where('panel_id', $this->panel->id)
        ->first();
    
    expect($sequence)->not->toBeNull()
        ->and($sequence->next_seq)->toBe(4);
});

test('generates unique names for different resellers', function () {
    config(['config_names.enabled' => true]);
    
    $user2 = User::factory()->create();
    
    $reseller2 = Reseller::create([
        'user_id' => $user2->id,
        'type' => 'wallet',
        'status' => 'active',
        'primary_panel_id' => $this->panel->id,
        'config_limit' => 10,
    ]);
    
    $result1 = $this->generator->generate($this->reseller, $this->panel, 'wallet');
    $result2 = $this->generator->generate($reseller2, $this->panel, 'wallet');
    
    expect($result1['name'])->not->toBe($result2['name']);
});

test('generates unique names for different panels', function () {
    config(['config_names.enabled' => true]);
    
    $panel2 = Panel::create([
        'name' => 'Test Panel 2',
        'url' => 'https://panel2.example.com',
        'panel_type' => 'marzban',
        'username' => 'admin',
        'password' => 'password',
        'is_active' => true,
    ]);
    
    $result1 = $this->generator->generate($this->reseller, $this->panel, 'wallet');
    $result2 = $this->generator->generate($this->reseller, $panel2, 'wallet');
    
    expect($result1['name'])->not->toBe($result2['name'])
        ->and($result1['name'])->toContain('-EY-')
        ->and($result2['name'])->toContain('-MB-');
});

test('auto-generates short_code for reseller if missing', function () {
    config(['config_names.enabled' => true]);
    
    // Ensure reseller doesn't have short_code
    $this->reseller->update(['short_code' => null]);
    $this->reseller->refresh();
    
    expect($this->reseller->short_code)->toBeNull();
    
    // Generate config name
    $result = $this->generator->generate($this->reseller, $this->panel, 'wallet');
    
    // Refresh reseller and check short_code was generated
    $this->reseller->refresh();
    
    expect($this->reseller->short_code)->not->toBeNull()
        ->and($this->reseller->short_code)->toBeString()
        ->and(strlen($this->reseller->short_code))->toBeGreaterThanOrEqual(3);
});

test('short_code generation uses base36 encoding', function () {
    config(['config_names.enabled' => true]);
    
    $user2 = User::factory()->create();
    
    // Create reseller with specific ID
    $reseller = Reseller::create([
        'user_id' => $user2->id,
        'type' => 'wallet',
        'status' => 'active',
        'primary_panel_id' => $this->panel->id,
        'config_limit' => 10,
    ]);
    
    // Clear short_code
    $reseller->update(['short_code' => null]);
    
    // Generate config name to trigger short_code generation
    $this->generator->generate($reseller, $this->panel, 'wallet');
    
    $reseller->refresh();
    
    // Expected short_code should be base36 of reseller ID, padded to 3 chars
    $expectedBase36 = strtolower(base_convert((string)$reseller->id, 10, 36));
    $expectedShortCode = str_pad($expectedBase36, 3, '0', STR_PAD_LEFT);
    
    expect($reseller->short_code)->toBe($expectedShortCode);
});

test('parseName returns correct components for v2 name', function () {
    $name = 'FP-EY-00f-W-0042-abc12';
    $parsed = ConfigNameGenerator::parseName($name, 2);
    
    expect($parsed)->not->toBeNull()
        ->and($parsed['prefix'])->toBe('FP')
        ->and($parsed['panel_type'])->toBe('EY')
        ->and($parsed['reseller_code'])->toBe('00f')
        ->and($parsed['mode'])->toBe('W')
        ->and($parsed['sequence'])->toBe(42)
        ->and($parsed['hash'])->toBe('abc12');
});

test('parseName returns null for legacy name', function () {
    $name = 'R1_20251119_abc123';
    $parsed = ConfigNameGenerator::parseName($name, null);
    
    expect($parsed)->toBeNull();
});

test('parseName returns null for invalid v2 name format', function () {
    $name = 'INVALID-NAME';
    $parsed = ConfigNameGenerator::parseName($name, 2);
    
    expect($parsed)->toBeNull();
});

test('creates sequence record on first generation', function () {
    config(['config_names.enabled' => true]);
    
    // Ensure no sequence exists
    expect(ConfigNameSequence::count())->toBe(0);
    
    // Generate config name
    $this->generator->generate($this->reseller, $this->panel, 'wallet');
    
    // Verify sequence was created
    $sequence = ConfigNameSequence::where('reseller_id', $this->reseller->id)
        ->where('panel_id', $this->panel->id)
        ->first();
    
    expect($sequence)->not->toBeNull()
        ->and($sequence->reseller_id)->toBe($this->reseller->id)
        ->and($sequence->panel_id)->toBe($this->panel->id)
        ->and($sequence->next_seq)->toBe(2); // Should be 2 after first generation
});

test('handles concurrent generation with transaction locking', function () {
    config(['config_names.enabled' => true]);
    
    // Generate multiple names rapidly
    $names = [];
    for ($i = 0; $i < 5; $i++) {
        $result = $this->generator->generate($this->reseller, $this->panel, 'wallet');
        $names[] = $result['name'];
    }
    
    // All names should be unique
    expect(count($names))->toBe(count(array_unique($names)));
    
    // Sequence should be at 6
    $sequence = ConfigNameSequence::where('reseller_id', $this->reseller->id)
        ->where('panel_id', $this->panel->id)
        ->first();
    
    expect($sequence->next_seq)->toBe(6);
});

test('uses custom prefix from config', function () {
    config([
        'config_names.enabled' => true,
        'config_names.prefix' => 'CUSTOM',
    ]);
    
    $result = $this->generator->generate($this->reseller, $this->panel, 'wallet');
    
    expect($result['name'])->toStartWith('CUSTOM-');
});
