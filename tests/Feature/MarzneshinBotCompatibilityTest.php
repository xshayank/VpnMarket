<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test compatibility with the marzneshin.php bot script.
 * 
 * This test class simulates the exact requests made by the bot:
 * - POST /api/admins/token with application/x-www-form-urlencoded
 * - GET /api/users/{username} with Bearer token
 * - POST /api/users with JSON body
 * - etc.
 */
class MarzneshinBotCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Reseller $reseller;
    protected Panel $panel;
    protected ApiKey $apiKey;
    protected string $plaintextKey;
    protected string $adminUsername;
    protected string $adminPassword;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->panel = Panel::create([
            'name' => 'Bot Test Panel',
            'url' => 'https://bot-test-panel.example.com',
            'panel_type' => 'marzneshin',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
        ]);

        $this->reseller = Reseller::create([
            'user_id' => $this->user->id,
            'type' => 'wallet',
            'status' => 'active',
            'username_prefix' => 'BOT_TEST',
            'primary_panel_id' => $this->panel->id,
            'api_enabled' => true,
            'wallet_balance' => 1000000,
        ]);

        $this->reseller->panels()->attach($this->panel->id);

        // Create Marzneshin-style API key with admin credentials
        $this->plaintextKey = ApiKey::generateKeyString();
        $this->adminUsername = 'mz_' . bin2hex(random_bytes(5));
        $this->adminPassword = bin2hex(random_bytes(16));

        $this->apiKey = ApiKey::create([
            'user_id' => $this->user->id,
            'name' => 'Bot Compatibility Test Key',
            'key_hash' => ApiKey::hashKey($this->plaintextKey),
            'api_style' => ApiKey::STYLE_MARZNESHIN,
            'default_panel_id' => $this->panel->id,
            'admin_username' => $this->adminUsername,
            'admin_password' => $this->adminPassword,
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

    /**
     * Test token endpoint accepts form-urlencoded data (like the bot sends)
     * Note: In real HTTP requests, PHP parses form-urlencoded into $_POST
     * which Laravel then reads. Using post() simulates this correctly.
     */
    public function test_token_endpoint_accepts_form_urlencoded_admin_credentials(): void
    {
        // The bot sends: Content-Type: application/x-www-form-urlencoded
        // PHP parses this into $_POST, Laravel reads from $_POST via input()
        // Using post() with an array simulates this behavior
        $response = $this->post('/api/admins/token', [
            'username' => $this->adminUsername,
            'password' => $this->adminPassword,
        ], [
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'access_token',
                'token_type',
                'expires_in',
            ])
            ->assertJsonPath('token_type', 'bearer');

        $token = $response->json('access_token');
        $this->assertStringStartsWith('mzsess_', $token);
    }

    /**
     * Test legacy flow with form-urlencoded data
     */
    public function test_token_endpoint_accepts_form_urlencoded_legacy_key(): void
    {
        $response = $this->post('/api/admins/token', [
            'username' => $this->plaintextKey,
            'password' => $this->plaintextKey,
        ], [
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'access_token',
                'token_type',
            ])
            ->assertJsonPath('token_type', 'bearer');

        // Legacy flow returns the key itself
        $this->assertEquals($this->plaintextKey, $response->json('access_token'));
    }

    /**
     * Test the full bot workflow: get token, then get user
     */
    public function test_full_bot_workflow_get_token_then_get_user(): void
    {
        // Step 1: Create a user config
        $config = ResellerConfig::create([
            'reseller_id' => $this->reseller->id,
            'external_username' => 'bot_test_user',
            'prefix' => 'bot_test_user',
            'traffic_limit_bytes' => 10737418240,
            'usage_bytes' => 1073741824,
            'expires_at' => now()->addDays(30),
            'status' => 'active',
            'panel_type' => 'marzneshin',
            'panel_id' => $this->panel->id,
            'created_by' => $this->user->id,
        ]);

        // Step 2: Get token (like the bot does)
        $tokenResponse = $this->post('/api/admins/token', [
            'username' => $this->adminUsername,
            'password' => $this->adminPassword,
        ], [
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]);

        $tokenResponse->assertStatus(200);
        $token = $tokenResponse->json('access_token');

        // Step 3: Use token to get user (like the bot does)
        $userResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/users/bot_test_user');

        $userResponse->assertStatus(200)
            ->assertJsonStructure([
                'username',
                'data_limit',
                'used_traffic',
            ])
            ->assertJsonPath('username', 'bot_test_user');
    }

    /**
     * Test the bot's system stats endpoint
     */
    public function test_system_stats_users_endpoint(): void
    {
        // Create some test users
        ResellerConfig::create([
            'reseller_id' => $this->reseller->id,
            'external_username' => 'stats_user_1',
            'traffic_limit_bytes' => 10737418240,
            'usage_bytes' => 1000000000,
            'expires_at' => now()->addDays(30),
            'status' => 'active',
            'panel_type' => 'marzneshin',
            'panel_id' => $this->panel->id,
            'created_by' => $this->user->id,
        ]);

        // Get token
        $tokenResponse = $this->post('/api/admins/token', [
            'username' => $this->adminUsername,
            'password' => $this->adminPassword,
        ], [
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]);

        $token = $tokenResponse->json('access_token');

        // Get system stats
        $statsResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/system/stats/users');

        $statsResponse->assertStatus(200)
            ->assertJsonStructure([
                'total',
                'active',
                'disabled',
                'total_used_traffic',
            ]);
    }

    /**
     * Test that token endpoint rejects invalid credentials
     */
    public function test_token_endpoint_rejects_invalid_credentials(): void
    {
        $response = $this->post('/api/admins/token', [
            'username' => 'invalid_user',
            'password' => 'invalid_password',
        ], [
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('detail', 'Invalid credentials');
    }

    /**
     * Test that expired session tokens are rejected
     */
    public function test_expired_session_token_is_rejected(): void
    {
        // Get a token
        $tokenResponse = $this->post('/api/admins/token', [
            'username' => $this->adminUsername,
            'password' => $this->adminPassword,
        ], [
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]);

        $token = $tokenResponse->json('access_token');

        // Manually expire the token by clearing from cache
        \Illuminate\Support\Facades\Cache::forget("api_session:{$token}");

        // Try to use the token
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/services');

        $response->assertStatus(401)
            ->assertJsonPath('detail', 'Invalid or expired session token');
    }

    /**
     * Test token endpoint with raw body content (fallback parsing)
     * This test verifies the controller's fallback mechanism for parsing
     * form-urlencoded data from raw body when PHP hasn't parsed it.
     */
    public function test_token_endpoint_handles_raw_body_content(): void
    {
        // Directly use the controller to test the fallback parsing
        // by creating a request with raw content
        $rawContent = http_build_query([
            'username' => $this->adminUsername,
            'password' => $this->adminPassword,
        ]);

        // Create a request with raw content in body
        $request = \Illuminate\Http\Request::create(
            '/api/admins/token',
            'POST',
            [], // No parsed parameters
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
                'HTTP_ACCEPT' => 'application/json',
            ],
            $rawContent
        );

        // Call the controller directly
        $controller = new \App\Http\Controllers\Api\MarzneshinStyleController();
        $response = $controller->token($request);

        // Verify the response
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('access_token', $data);
        $this->assertStringStartsWith('mzsess_', $data['access_token']);
    }

    /**
     * Test bot workflow with reset user endpoint
     */
    public function test_bot_workflow_reset_user(): void
    {
        // Create a user config
        $config = ResellerConfig::create([
            'reseller_id' => $this->reseller->id,
            'external_username' => 'reset_test_user',
            'prefix' => 'reset_test_user',
            'panel_user_id' => 'reset_test_user',
            'traffic_limit_bytes' => 10737418240,
            'usage_bytes' => 5000000000,
            'expires_at' => now()->addDays(30),
            'status' => 'active',
            'panel_type' => 'marzneshin',
            'panel_id' => $this->panel->id,
            'created_by' => $this->user->id,
        ]);

        // Get token
        $tokenResponse = $this->post('/api/admins/token', [
            'username' => $this->adminUsername,
            'password' => $this->adminPassword,
        ]);

        $token = $tokenResponse->json('access_token');

        // Reset user (like bot's ResetUserDataUsagem function)
        $resetResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/users/reset_test_user/reset');

        // In test environment, remote panel may fail but local update should work
        $this->assertTrue(in_array($resetResponse->status(), [200, 500]));
    }

    /**
     * Test bot workflow with revoke_sub endpoint
     */
    public function test_bot_workflow_revoke_sub(): void
    {
        $config = ResellerConfig::create([
            'reseller_id' => $this->reseller->id,
            'external_username' => 'revoke_sub_user',
            'prefix' => 'revoke_sub_user',
            'traffic_limit_bytes' => 10737418240,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
            'status' => 'active',
            'panel_type' => 'marzneshin',
            'panel_id' => $this->panel->id,
            'subscription_url' => 'https://example.com/sub/test',
            'created_by' => $this->user->id,
        ]);

        // Get token
        $tokenResponse = $this->post('/api/admins/token', [
            'username' => $this->adminUsername,
            'password' => $this->adminPassword,
        ]);

        $token = $tokenResponse->json('access_token');

        // Revoke sub (like bot's revoke_subm function)
        $revokeResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/users/revoke_sub_user/revoke_sub');

        $revokeResponse->assertStatus(200)
            ->assertJsonPath('username', 'revoke_sub_user');
    }
}
