<?php

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('reseller model has panel_id accessor that returns primary_panel_id', function () {
    $panel = Panel::factory()->create(['panel_type' => 'marzban']);
    $user = User::factory()->create();
    
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'primary_panel_id' => $panel->id,
    ]);

    // Accessing panel_id should return primary_panel_id value
    expect($reseller->panel_id)->toBe($panel->id);
    expect($reseller->primary_panel_id)->toBe($panel->id);
});

test('setting panel_id updates both panel_id and primary_panel_id', function () {
    $panel = Panel::factory()->create(['panel_type' => 'marzban']);
    $user = User::factory()->create();
    
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
    ]);

    // Setting panel_id should update both fields
    $reseller->panel_id = $panel->id;
    $reseller->save();
    
    $reseller->refresh();
    
    expect($reseller->panel_id)->toBe($panel->id);
    expect($reseller->primary_panel_id)->toBe($panel->id);
    expect($reseller->getRawOriginal('panel_id'))->toBe($panel->id);
});

test('panel relationship uses primary_panel_id', function () {
    $panel = Panel::factory()->create(['panel_type' => 'marzban', 'name' => 'Test Panel']);
    $user = User::factory()->create();
    
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'primary_panel_id' => $panel->id,
    ]);

    // The panel() relationship should work
    expect($reseller->panel)->not->toBeNull();
    expect($reseller->panel->id)->toBe($panel->id);
    expect($reseller->panel->name)->toBe('Test Panel');
});

test('primaryPanel relationship works correctly', function () {
    $panel = Panel::factory()->create(['panel_type' => 'marzban', 'name' => 'Primary Panel']);
    $user = User::factory()->create();
    
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'primary_panel_id' => $panel->id,
    ]);

    // The primaryPanel() relationship should work
    expect($reseller->primaryPanel)->not->toBeNull();
    expect($reseller->primaryPanel->id)->toBe($panel->id);
    expect($reseller->primaryPanel->name)->toBe('Primary Panel');
});

test('hasPrimaryPanel returns true when primary_panel_id is set', function () {
    $panel = Panel::factory()->create(['panel_type' => 'marzban']);
    $user = User::factory()->create();
    
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'primary_panel_id' => $panel->id,
    ]);

    expect($reseller->hasPrimaryPanel())->toBeTrue();
});

test('hasPrimaryPanel returns false when primary_panel_id is null', function () {
    $user = User::factory()->create();
    
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'plan',
        'primary_panel_id' => null,
    ]);

    expect($reseller->hasPrimaryPanel())->toBeFalse();
});

test('backfill command can restore panel from configs', function () {
    $panel = Panel::factory()->create(['panel_type' => 'marzban']);
    $user = User::factory()->create();
    
    // Create reseller without primary_panel_id
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'primary_panel_id' => null,
    ]);

    // Create configs with panel_id
    ResellerConfig::factory()->count(3)->create([
        'reseller_id' => $reseller->id,
        'panel_id' => $panel->id,
    ]);

    // Run the backfill command
    $this->artisan('resellers:backfill-primary-panel')
        ->assertSuccessful();

    // Verify primary_panel_id was set
    $reseller->refresh();
    expect($reseller->primary_panel_id)->toBe($panel->id);
    expect($reseller->panel_id)->toBe($panel->id);
});

test('backfill command dry run does not modify data', function () {
    $panel = Panel::factory()->create(['panel_type' => 'marzban']);
    $user = User::factory()->create();
    
    // Create reseller without primary_panel_id
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'primary_panel_id' => null,
    ]);

    // Create configs with panel_id
    ResellerConfig::factory()->count(2)->create([
        'reseller_id' => $reseller->id,
        'panel_id' => $panel->id,
    ]);

    // Run the backfill command with --dry flag
    $this->artisan('resellers:backfill-primary-panel', ['--dry' => true])
        ->assertSuccessful();

    // Verify primary_panel_id was NOT set
    $reseller->refresh();
    expect($reseller->primary_panel_id)->toBeNull();
});

test('backfill command skips plan-based resellers without panel', function () {
    $user = User::factory()->create();
    
    // Create plan-based reseller without primary_panel_id
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'plan',
        'primary_panel_id' => null,
    ]);

    // Run the backfill command
    $this->artisan('resellers:backfill-primary-panel')
        ->assertSuccessful();

    // Verify primary_panel_id remains null (plan-based doesn't need panel)
    $reseller->refresh();
    expect($reseller->primary_panel_id)->toBeNull();
});

test('reseller can be created with primary_panel_id', function () {
    $panel = Panel::factory()->create(['panel_type' => 'marzban']);
    $user = User::factory()->create();
    
    $reseller = Reseller::create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'active',
        'primary_panel_id' => $panel->id,
        'config_limit' => 10,
    ]);

    expect($reseller->primary_panel_id)->toBe($panel->id);
    expect($reseller->hasPrimaryPanel())->toBeTrue();
    expect($reseller->panel)->not->toBeNull();
});

test('reseller can be updated with primary_panel_id', function () {
    $panel1 = Panel::factory()->create(['panel_type' => 'marzban']);
    $panel2 = Panel::factory()->create(['panel_type' => 'marzneshin']);
    $user = User::factory()->create();
    
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'primary_panel_id' => $panel1->id,
    ]);

    expect($reseller->primary_panel_id)->toBe($panel1->id);

    // Update to different panel
    $reseller->update(['primary_panel_id' => $panel2->id]);
    
    $reseller->refresh();
    expect($reseller->primary_panel_id)->toBe($panel2->id);
    expect($reseller->panel->id)->toBe($panel2->id);
});
