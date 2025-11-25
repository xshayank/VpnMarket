<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Panel;
use App\Models\Reseller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerApiKeyTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Reseller $reseller;
    protected Panel $panel;

    protected function setUp(): void
    {
        parent::setUp();

        // Create user with reseller
        $this->user = User::factory()->create();
        
        $this->panel = Panel::create([
            'name' => 'Test Panel',
            'url' => 'https://test-panel.example.com',
            'panel_type' => 'marzneshin',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
        ]);

        $this->reseller = Reseller::create([
            'user_id' => $this->user->id,
            'type' => 'wallet',
            'status' => 'active',
            'username_prefix' => 'TEST',
            'primary_panel_id' => $this->panel->id,
            'api_enabled' => true,
            'wallet_balance' => 1000000,
        ]);

        // Attach panel to reseller
        $this->reseller->panels()->attach($this->panel->id);
    }

    public function test_can_create_api_key(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson('/api/keys', [
            'name' => 'Test API Key',
            'scopes' => ['configs:create', 'configs:read'],
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'api_key',
                    'name',
                    'scopes',
                    'created_at',
                ],
                'message',
            ]);

        $this->assertDatabaseHas('api_keys', [
            'user_id' => $this->user->id,
            'name' => 'Test API Key',
        ]);
    }

    public function test_cannot_create_api_key_without_api_enabled(): void
    {
        $this->reseller->update(['api_enabled' => false]);
        $this->actingAs($this->user);

        $response = $this->postJson('/api/keys', [
            'name' => 'Test API Key',
            'scopes' => ['configs:create'],
        ]);

        $response->assertStatus(403);
    }

    public function test_can_list_api_keys(): void
    {
        $this->actingAs($this->user);

        // Create a key first
        $plaintextKey = ApiKey::generateKeyString();
        ApiKey::create([
            'user_id' => $this->user->id,
            'name' => 'Test Key',
            'key_hash' => ApiKey::hashKey($plaintextKey),
            'scopes' => ['configs:read'],
            'revoked' => false,
        ]);

        $response = $this->getJson('/api/keys');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'scopes',
                        'revoked',
                        'created_at',
                    ],
                ],
            ]);
    }

    public function test_can_revoke_api_key(): void
    {
        $this->actingAs($this->user);

        $plaintextKey = ApiKey::generateKeyString();
        $apiKey = ApiKey::create([
            'user_id' => $this->user->id,
            'name' => 'Test Key',
            'key_hash' => ApiKey::hashKey($plaintextKey),
            'scopes' => ['configs:read'],
            'revoked' => false,
        ]);

        $response = $this->postJson("/api/keys/{$apiKey->id}/revoke");

        $response->assertStatus(200);
        $this->assertDatabaseHas('api_keys', [
            'id' => $apiKey->id,
            'revoked' => true,
        ]);
    }

    public function test_api_key_authentication(): void
    {
        $plaintextKey = ApiKey::generateKeyString();
        $apiKey = ApiKey::create([
            'user_id' => $this->user->id,
            'name' => 'Test Key',
            'key_hash' => ApiKey::hashKey($plaintextKey),
            'scopes' => ['panels:list'],
            'revoked' => false,
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$plaintextKey}",
        ])->getJson('/api/v1/panels');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
            ]);
    }

    public function test_api_key_requires_correct_scope(): void
    {
        $plaintextKey = ApiKey::generateKeyString();
        ApiKey::create([
            'user_id' => $this->user->id,
            'name' => 'Test Key',
            'key_hash' => ApiKey::hashKey($plaintextKey),
            'scopes' => ['configs:read'], // Only has read scope
            'revoked' => false,
        ]);

        // Try to create a config (requires configs:create scope)
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$plaintextKey}",
        ])->postJson('/api/v1/configs', [
            'panel_id' => $this->panel->id,
            'traffic_limit_gb' => 10,
            'expires_days' => 30,
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'error' => true,
                'message' => 'Missing required scope: configs:create',
            ]);
    }

    public function test_revoked_api_key_is_rejected(): void
    {
        $plaintextKey = ApiKey::generateKeyString();
        ApiKey::create([
            'user_id' => $this->user->id,
            'name' => 'Test Key',
            'key_hash' => ApiKey::hashKey($plaintextKey),
            'scopes' => ['panels:list'],
            'revoked' => true, // Key is revoked
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$plaintextKey}",
        ])->getJson('/api/v1/panels');

        $response->assertStatus(401)
            ->assertJson([
                'error' => true,
                'message' => 'API key has been revoked',
            ]);
    }

    public function test_expired_api_key_is_rejected(): void
    {
        $plaintextKey = ApiKey::generateKeyString();
        ApiKey::create([
            'user_id' => $this->user->id,
            'name' => 'Test Key',
            'key_hash' => ApiKey::hashKey($plaintextKey),
            'scopes' => ['panels:list'],
            'revoked' => false,
            'expires_at' => now()->subDay(), // Expired yesterday
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$plaintextKey}",
        ])->getJson('/api/v1/panels');

        $response->assertStatus(401)
            ->assertJson([
                'error' => true,
                'message' => 'API key has expired',
            ]);
    }

    public function test_api_disabled_reseller_cannot_use_api(): void
    {
        $this->reseller->update(['api_enabled' => false]);

        $plaintextKey = ApiKey::generateKeyString();
        ApiKey::create([
            'user_id' => $this->user->id,
            'name' => 'Test Key',
            'key_hash' => ApiKey::hashKey($plaintextKey),
            'scopes' => ['panels:list'],
            'revoked' => false,
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$plaintextKey}",
        ])->getJson('/api/v1/panels');

        $response->assertStatus(403)
            ->assertJson([
                'error' => true,
                'message' => 'API access is not enabled for this account',
            ]);
    }

    public function test_invalid_api_key_is_rejected(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer invalid_key_here',
        ])->getJson('/api/v1/panels');

        $response->assertStatus(401)
            ->assertJson([
                'error' => true,
                'message' => 'Invalid API key',
            ]);
    }

    public function test_missing_api_key_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/panels');

        $response->assertStatus(401)
            ->assertJson([
                'error' => true,
                'message' => 'API key is required',
            ]);
    }

    public function test_ip_whitelist_enforcement(): void
    {
        $plaintextKey = ApiKey::generateKeyString();
        ApiKey::create([
            'user_id' => $this->user->id,
            'name' => 'Test Key',
            'key_hash' => ApiKey::hashKey($plaintextKey),
            'scopes' => ['panels:list'],
            'revoked' => false,
            'ip_whitelist' => ['192.168.1.1'], // Only allow this IP
        ]);

        // Request from a different IP (127.0.0.1 in tests)
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$plaintextKey}",
        ])->getJson('/api/v1/panels');

        $response->assertStatus(403)
            ->assertJson([
                'error' => true,
                'message' => 'IP address not allowed',
            ]);
    }

    public function test_api_key_hash_verification(): void
    {
        $key1 = 'vpnm_test_key_1';
        $key2 = 'vpnm_test_key_2';

        $hash1 = ApiKey::hashKey($key1);
        $hash2 = ApiKey::hashKey($key2);

        // Different keys should produce different hashes
        $this->assertNotEquals($hash1, $hash2);

        // Same key should produce same hash
        $this->assertEquals($hash1, ApiKey::hashKey($key1));
    }

    public function test_api_key_scopes_check(): void
    {
        $apiKey = new ApiKey([
            'scopes' => ['configs:read', 'configs:create'],
        ]);

        $this->assertTrue($apiKey->hasScope('configs:read'));
        $this->assertTrue($apiKey->hasScope('configs:create'));
        $this->assertFalse($apiKey->hasScope('configs:delete'));
        $this->assertTrue($apiKey->hasAnyScope(['configs:delete', 'configs:read']));
        $this->assertFalse($apiKey->hasAnyScope(['configs:delete', 'panels:list']));
    }

    public function test_api_key_validity_check(): void
    {
        // Valid key
        $validKey = new ApiKey([
            'revoked' => false,
            'expires_at' => now()->addDays(30),
        ]);
        $this->assertTrue($validKey->isValid());

        // Revoked key
        $revokedKey = new ApiKey([
            'revoked' => true,
            'expires_at' => now()->addDays(30),
        ]);
        $this->assertFalse($revokedKey->isValid());

        // Expired key
        $expiredKey = new ApiKey([
            'revoked' => false,
            'expires_at' => now()->subDay(),
        ]);
        $this->assertFalse($expiredKey->isValid());
    }
}
