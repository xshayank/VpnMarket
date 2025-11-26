<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarzneshinStyleApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Reseller $reseller;
    protected Panel $panel;
    protected ApiKey $apiKey;
    protected string $plaintextKey;

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

        // Create Marzneshin-style API key
        $this->plaintextKey = ApiKey::generateKeyString();
        $this->apiKey = ApiKey::create([
            'user_id' => $this->user->id,
            'name' => 'Marzneshin Test Key',
            'key_hash' => ApiKey::hashKey($this->plaintextKey),
            'api_style' => ApiKey::STYLE_MARZNESHIN,
            'default_panel_id' => $this->panel->id,
            'scopes' => [
                'services:list',
                'users:create',
                'users:read',
                'users:update',
                'users:delete',
                'subscription:read',
                'nodes:list',
            ],
            'revoked' => false,
        ]);
    }

    public function test_token_endpoint_returns_access_token(): void
    {
        $response = $this->postJson('/api/admins/token', [
            'username' => $this->plaintextKey,
            'password' => $this->plaintextKey,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'access_token',
                'token_type',
            ])
            ->assertJsonPath('token_type', 'bearer');
    }

    public function test_token_endpoint_rejects_invalid_credentials(): void
    {
        $response = $this->postJson('/api/admins/token', [
            'username' => 'invalid_key',
            'password' => 'invalid_key',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('detail', 'Invalid credentials');
    }

    public function test_services_endpoint_requires_auth(): void
    {
        $response = $this->getJson('/api/services');

        $response->assertStatus(401);
    }

    public function test_services_endpoint_returns_marzneshin_format(): void
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->plaintextKey}",
        ])->getJson('/api/services');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'items',
                'total',
            ]);
    }

    public function test_users_list_endpoint_returns_marzneshin_format(): void
    {
        // Create some configs
        $config = ResellerConfig::create([
            'reseller_id' => $this->reseller->id,
            'external_username' => 'test_user_1',
            'traffic_limit_bytes' => 10737418240, // 10GB
            'usage_bytes' => 1073741824, // 1GB
            'expires_at' => now()->addDays(30),
            'status' => 'active',
            'panel_type' => 'marzneshin',
            'panel_id' => $this->panel->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->plaintextKey}",
        ])->getJson('/api/users');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'items',
                'total',
            ]);

        // Verify Marzneshin-style field names
        $items = $response->json('items');
        $this->assertNotEmpty($items);
        
        $user = $items[0];
        $this->assertArrayHasKey('username', $user);
        $this->assertArrayHasKey('status', $user);
        $this->assertArrayHasKey('used_traffic', $user);
        $this->assertArrayHasKey('data_limit', $user);
        $this->assertArrayHasKey('expire_date', $user);
    }

    public function test_get_user_endpoint_returns_marzneshin_format(): void
    {
        $config = ResellerConfig::create([
            'reseller_id' => $this->reseller->id,
            'external_username' => 'specific_user',
            'traffic_limit_bytes' => 10737418240,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
            'status' => 'active',
            'panel_type' => 'marzneshin',
            'panel_id' => $this->panel->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->plaintextKey}",
        ])->getJson('/api/users/specific_user');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'username',
                'status',
                'used_traffic',
                'data_limit',
                'expire_date',
                'subscription_url',
            ]);
    }

    public function test_get_user_returns_404_for_nonexistent_user(): void
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->plaintextKey}",
        ])->getJson('/api/users/nonexistent_user');

        $response->assertStatus(404)
            ->assertJsonPath('detail', 'User not found');
    }

    public function test_nodes_endpoint_returns_marzneshin_format(): void
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->plaintextKey}",
        ])->getJson('/api/nodes');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'items',
                'total',
            ]);
    }

    public function test_error_responses_use_marzneshin_format(): void
    {
        // Create a Falco-style key to compare
        $falcoKey = ApiKey::generateKeyString();
        ApiKey::create([
            'user_id' => $this->user->id,
            'name' => 'Falco Test Key',
            'key_hash' => ApiKey::hashKey($falcoKey),
            'api_style' => ApiKey::STYLE_FALCO,
            'scopes' => ['configs:read'],
            'revoked' => false,
        ]);

        // Marzneshin-style key should get Marzneshin-format errors
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->plaintextKey}",
        ])->getJson('/api/users/nonexistent');

        $response->assertJsonStructure(['detail']);

        // Falco-style key should get Falco-format errors  
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$falcoKey}",
        ])->getJson('/api/v1/configs/nonexistent');

        $response->assertJsonStructure(['error', 'message']);
    }

    public function test_rate_limiting_applies_to_requests(): void
    {
        // Create a fresh API key with a very low rate limit for this test
        $newKey = ApiKey::generateKeyString();
        $rateLimitedApiKey = ApiKey::create([
            'user_id' => $this->user->id,
            'name' => 'Rate Limited Test Key',
            'key_hash' => ApiKey::hashKey($newKey),
            'api_style' => ApiKey::STYLE_MARZNESHIN,
            'default_panel_id' => $this->panel->id,
            'scopes' => ['services:list'],
            'revoked' => false,
            'rate_limit_per_minute' => 2,
        ]);

        // Verify the key was created with correct rate limit
        $this->assertEquals(2, $rateLimitedApiKey->rate_limit_per_minute);
        $this->assertEquals(0, $rateLimitedApiKey->requests_this_minute);
        $this->assertNull($rateLimitedApiKey->rate_limit_reset_at);

        // First request should succeed
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$newKey}",
        ])->getJson('/api/services');
        $response->assertStatus(200);

        // Refresh the key and check counter
        $rateLimitedApiKey->refresh();
        $this->assertEquals(1, $rateLimitedApiKey->requests_this_minute, 'After 1st request, counter should be 1');

        // Second request should succeed
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$newKey}",
        ])->getJson('/api/services');
        $response->assertStatus(200);

        // Refresh and check counter
        $rateLimitedApiKey->refresh();
        $this->assertEquals(2, $rateLimitedApiKey->requests_this_minute, 'After 2nd request, counter should be 2');

        // Third request should be rate limited (2 >= 2)
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$newKey}",
        ])->getJson('/api/services');
        $response->assertStatus(429);
    }

    public function test_api_key_style_is_validated(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson('/api/keys', [
            'name' => 'Invalid Style Key',
            'api_style' => 'invalid_style',
            'scopes' => ['configs:read'],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.api_style', fn($errors) => count($errors) > 0);
    }

    public function test_subscription_endpoint_returns_url(): void
    {
        $config = ResellerConfig::create([
            'reseller_id' => $this->reseller->id,
            'external_username' => 'sub_test_user',
            'traffic_limit_bytes' => 10737418240,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
            'status' => 'active',
            'panel_type' => 'marzneshin',
            'panel_id' => $this->panel->id,
            'subscription_url' => 'https://example.com/sub/abc123',
            'created_by' => $this->user->id,
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->plaintextKey}",
        ])->getJson('/api/users/sub_test_user/subscription');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'username',
                'subscription_url',
            ])
            ->assertJsonPath('subscription_url', 'https://example.com/sub/abc123');
    }

    public function test_user_status_mapping(): void
    {
        // Create configs with different statuses
        ResellerConfig::create([
            'reseller_id' => $this->reseller->id,
            'external_username' => 'active_user',
            'traffic_limit_bytes' => 10737418240,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
            'status' => 'active',
            'panel_type' => 'marzneshin',
            'panel_id' => $this->panel->id,
            'created_by' => $this->user->id,
        ]);

        ResellerConfig::create([
            'reseller_id' => $this->reseller->id,
            'external_username' => 'limited_user',
            'traffic_limit_bytes' => 1000,
            'usage_bytes' => 2000, // Over limit
            'expires_at' => now()->addDays(30),
            'status' => 'active',
            'panel_type' => 'marzneshin',
            'panel_id' => $this->panel->id,
            'created_by' => $this->user->id,
        ]);

        ResellerConfig::create([
            'reseller_id' => $this->reseller->id,
            'external_username' => 'expired_user',
            'traffic_limit_bytes' => 10737418240,
            'usage_bytes' => 0,
            'expires_at' => now()->subDays(1), // Expired
            'status' => 'active',
            'panel_type' => 'marzneshin',
            'panel_id' => $this->panel->id,
            'created_by' => $this->user->id,
        ]);

        // Check active user
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->plaintextKey}",
        ])->getJson('/api/users/active_user');
        $response->assertJsonPath('status', 'active');

        // Check limited user (over traffic limit)
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->plaintextKey}",
        ])->getJson('/api/users/limited_user');
        $response->assertJsonPath('status', 'limited');

        // Check expired user
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->plaintextKey}",
        ])->getJson('/api/users/expired_user');
        $response->assertJsonPath('status', 'expired');
    }
}
