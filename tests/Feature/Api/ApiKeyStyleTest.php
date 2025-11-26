<?php

use App\Models\ApiKey;
use App\Models\Panel;
use App\Models\Reseller;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\RbacSeeder']);
});

test('marzneshin style requires a default panel', function () {
    $reseller = Reseller::factory()->create(['api_enabled' => true]);
    $user = $reseller->user;

    // Missing default_panel_id should fail
    $response = $this->actingAs($user)->postJson('/api/keys', [
        'name' => 'Missing panel',
        'scopes' => [ApiKey::SCOPE_CONFIGS_READ],
        'api_style' => ApiKey::STYLE_MARZNESHIN,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['default_panel_id']);

    $panel = Panel::factory()->marzneshin()->create();

    $response = $this->actingAs($user)->postJson('/api/keys', [
        'name' => 'Marzneshin key',
        'scopes' => [ApiKey::SCOPE_CONFIGS_READ],
        'api_style' => ApiKey::STYLE_MARZNESHIN,
        'default_panel_id' => $panel->id,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.default_panel_id', $panel->id)
        ->assertJsonPath('data.api_style', ApiKey::STYLE_MARZNESHIN);
});

test('api keys can be rotated to issue a new secret', function () {
    $reseller = Reseller::factory()->create(['api_enabled' => true]);
    $user = $reseller->user;

    $originalPlaintext = ApiKey::generateKeyString();
    $apiKey = ApiKey::create([
        'user_id' => $user->id,
        'name' => 'Rotate me',
        'key_hash' => ApiKey::hashKey($originalPlaintext),
        'scopes' => [ApiKey::SCOPE_CONFIGS_READ],
        'api_style' => ApiKey::STYLE_FALCO,
        'revoked' => false,
    ]);

    $response = $this->actingAs($user)->postJson("/api/keys/{$apiKey->id}/rotate");

    $response->assertStatus(200)
        ->assertJsonPath('data.id', $apiKey->id)
        ->assertJsonPath('data.api_style', ApiKey::STYLE_FALCO)
        ->assertJsonStructure(['data' => ['api_key']]);

    $apiKey->refresh();
    expect($apiKey->verifyKey($originalPlaintext))->toBeFalse();
});
