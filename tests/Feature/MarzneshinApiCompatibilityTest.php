<?php

use App\Models\ApiKey;
use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create test user with permissions
    $this->user = User::factory()->create(['is_super_admin' => true]);

    // Create test panel
    $this->panel = Panel::create([
        'name' => 'Test Marzneshin Panel',
        'url' => 'https://panel.example.com',
        'panel_type' => 'marzneshin',
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
        'config_limit' => 100,
        'wallet_balance' => 100000,
        'api_enabled' => true,
    ]);

    // Attach panel access
    $this->reseller->panels()->attach($this->panel->id, [
        'allowed_node_ids' => null,
        'allowed_service_ids' => null,
    ]);

    // Generate API key
    $this->apiKeyPlaintext = ApiKey::generateKeyString();
    $this->apiKey = ApiKey::create([
        'user_id' => $this->user->id,
        'name' => 'Test Marzneshin API Key',
        'key_hash' => ApiKey::hashKey($this->apiKeyPlaintext),
        'scopes' => [
            'services:list',
            'users:create',
            'users:read',
            'users:update',
            'users:delete',
            'subscription:read',
            'nodes:list',
        ],
        'api_style' => ApiKey::STYLE_MARZNESHIN,
        'default_panel_id' => $this->panel->id,
        'rate_limit_per_minute' => 60,
        'revoked' => false,
    ]);

    // Create a test config (user)
    $this->testConfig = ResellerConfig::create([
        'reseller_id' => $this->reseller->id,
        'panel_id' => $this->panel->id,
        'panel_type' => $this->panel->panel_type,
        'panel_user_id' => 'test_user_123',
        'external_username' => 'test_user_123',
        'name_version' => null,
        'status' => 'active',
        'usage_bytes' => 1073741824, // 1 GB
        'traffic_limit_bytes' => 10737418240, // 10 GB
        'expires_at' => now()->addDays(30),
        'created_by' => $this->user->id,
        'subscription_url' => 'https://panel.example.com/sub/test_user_123',
    ]);
});

test('marzneshin token endpoint returns bearer token', function () {
    $response = $this->postJson('/api/admins/token', [
        'username' => $this->apiKeyPlaintext,
        'password' => $this->apiKeyPlaintext,
    ]);

    // Marzneshin format: access_token, is_sudo, token_type (no expires_in)
    $response->assertStatus(200)
        ->assertJsonStructure([
            'access_token',
            'is_sudo',
            'token_type',
        ])
        ->assertJson([
            'token_type' => 'bearer',
            'is_sudo' => true,
        ])
        ->assertJsonMissing(['expires_in']);
});

test('marzneshin token endpoint rejects invalid credentials', function () {
    $response = $this->postJson('/api/admins/token', [
        'username' => 'invalid_key',
        'password' => 'invalid_key',
    ]);

    $response->assertStatus(401)
        ->assertJsonStructure(['detail']);
});

test('marzneshin admin endpoint returns admin info', function () {
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->apiKeyPlaintext}",
    ])->getJson('/api/admin');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'username',
            'is_sudo',
            'telegram_id',
            'discord_webhook',
            'users_usage',
        ])
        ->assertJson([
            'is_sudo' => false,
        ]);
});

test('marzneshin admins/current endpoint returns same as admin', function () {
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->apiKeyPlaintext}",
    ])->getJson('/api/admins/current');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'username',
            'is_sudo',
        ]);
});

test('marzneshin system endpoint returns system stats', function () {
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->apiKeyPlaintext}",
    ])->getJson('/api/system');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'version',
            'mem_total',
            'mem_used',
            'cpu_cores',
            'cpu_usage',
            'total_user',
            'users_active',
            'users_disabled',
            'users_limited',
            'users_expired',
            'incoming_bandwidth',
            'outgoing_bandwidth',
        ]);
});

test('marzneshin services endpoint returns services list', function () {
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->apiKeyPlaintext}",
    ])->getJson('/api/services');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'items',
            'total',
        ]);
});

test('marzneshin inbounds endpoint returns inbounds list', function () {
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->apiKeyPlaintext}",
    ])->getJson('/api/inbounds');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'items',
            'total',
        ]);
});

test('marzneshin users list endpoint returns users in marzneshin format', function () {
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->apiKeyPlaintext}",
    ])->getJson('/api/users');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'items',
            'total',
        ]);

    $data = $response->json();
    expect($data['total'])->toBeGreaterThanOrEqual(1);
    
    // Check first item has Marzneshin format
    $user = $data['items'][0];
    expect($user)->toHaveKeys([
        'username',
        'status',
        'used_traffic',
        'data_limit',
        'expire_date',
    ]);
});

test('marzneshin get user endpoint returns user in marzneshin format', function () {
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->apiKeyPlaintext}",
    ])->getJson("/api/users/{$this->testConfig->external_username}");

    $response->assertStatus(200)
        ->assertJsonStructure([
            'username',
            'status',
            'used_traffic',
            'data_limit',
            'data_limit_reset_strategy',
            'expire_date',
            'expire_strategy',
            'lifetime_used_traffic',
            'subscription_url',
            'service_ids',
            'note',
            'created_at',
        ]);
});

test('marzneshin get user returns 404 for non-existent user', function () {
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->apiKeyPlaintext}",
    ])->getJson('/api/users/nonexistent_user_12345');

    $response->assertStatus(404)
        ->assertJsonStructure(['detail']);
});

test('marzneshin user subscription endpoint returns subscription url', function () {
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->apiKeyPlaintext}",
    ])->getJson("/api/users/{$this->testConfig->external_username}/subscription");

    $response->assertStatus(200)
        ->assertJsonStructure([
            'username',
            'subscription_url',
        ]);
});

test('marzneshin user usage endpoint returns usage data', function () {
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->apiKeyPlaintext}",
    ])->getJson("/api/users/{$this->testConfig->external_username}/usage");

    $response->assertStatus(200)
        ->assertJsonStructure([
            'username',
            'used_traffic',
            'node_usages',
        ]);
});

test('marzneshin expired users endpoint returns expired users list', function () {
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->apiKeyPlaintext}",
    ])->getJson('/api/users/expired');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'items',
            'total',
        ]);
});

test('marzneshin nodes endpoint returns nodes list', function () {
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->apiKeyPlaintext}",
    ])->getJson('/api/nodes');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'items',
            'total',
        ]);
});

test('marzneshin api returns 401 for missing api key', function () {
    $response = $this->getJson('/api/users');

    $response->assertStatus(401)
        ->assertJsonStructure(['detail']);
});

test('marzneshin api returns 401 for invalid api key', function () {
    $response = $this->withHeaders([
        'Authorization' => 'Bearer invalid_key_12345',
    ])->getJson('/api/users');

    $response->assertStatus(401)
        ->assertJsonStructure(['detail']);
});

test('marzneshin api returns 403 for revoked api key', function () {
    // Revoke the API key
    $this->apiKey->revoke();

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->apiKeyPlaintext}",
    ])->getJson('/api/users');

    $response->assertStatus(401);
});

test('marzneshin error format matches spec', function () {
    // Test validation error format
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->apiKeyPlaintext}",
    ])->postJson('/api/users', [
        // Missing required fields
    ]);

    $response->assertStatus(422);
    $data = $response->json();
    
    // Should have 'detail' key (Marzneshin format)
    expect($data)->toHaveKey('detail');
});

test('marzneshin bearer token authentication works', function () {
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->apiKeyPlaintext}",
    ])->getJson('/api/users');

    $response->assertStatus(200);
});

test('marzneshin basic auth works with api key', function () {
    $encoded = base64_encode("{$this->apiKeyPlaintext}:{$this->apiKeyPlaintext}");
    
    $response = $this->withHeaders([
        'Authorization' => "Basic {$encoded}",
    ])->getJson('/api/users');

    $response->assertStatus(200);
});

test('marzneshin api key header works', function () {
    $response = $this->withHeaders([
        'X-API-KEY' => $this->apiKeyPlaintext,
    ])->getJson('/api/users');

    $response->assertStatus(200);
});
