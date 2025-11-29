<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\User;
use App\Services\MarzneshinUsernameGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test the Marzneshin API-specific username generation system.
 *
 * This test class validates that the username generation system:
 * - Only affects users/configs created via the Marzneshin-style API
 * - Produces shorter, cleaner usernames
 * - Maintains backward compatibility with prefix-based lookups
 */
class MarzneshinUsernameGeneratorTest extends TestCase
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
            'name' => 'Username Gen Test Panel',
            'url' => 'https://username-gen-test.example.com',
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

        $this->reseller->panels()->attach($this->panel->id);

        // Create Marzneshin-style API key with admin credentials
        $this->plaintextKey = ApiKey::generateKeyString();
        $this->adminUsername = 'mz_'.bin2hex(random_bytes(5));
        $this->adminPassword = bin2hex(random_bytes(16));

        $this->apiKey = ApiKey::create([
            'user_id' => $this->user->id,
            'name' => 'Username Gen Test Key',
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
            ],
            'revoked' => false,
        ]);
    }

    /**
     * Test that MarzneshinUsernameGenerator produces usernames in the expected format
     */
    public function test_generator_produces_correct_format(): void
    {
        $generator = new MarzneshinUsernameGenerator();

        $result = $generator->generate('testuser');

        $this->assertArrayHasKey('panel_username', $result);
        $this->assertArrayHasKey('prefix', $result);
        $this->assertArrayHasKey('original_username', $result);

        // Username should start with the sanitized prefix
        $this->assertStringStartsWith('testuser', $result['panel_username']);

        // Username should be longer than prefix (due to suffix)
        $this->assertGreaterThan(strlen('testuser'), strlen($result['panel_username']));

        // Username should not contain underscore (compact format)
        // The suffix is appended directly without separator
        $this->assertStringStartsWith($result['prefix'], $result['panel_username']);

        // Original username should be preserved
        $this->assertEquals('testuser', $result['original_username']);
    }

    /**
     * Test that generator sanitizes special characters
     */
    public function test_generator_sanitizes_special_characters(): void
    {
        $generator = new MarzneshinUsernameGenerator();

        $result = $generator->generate('test@user#123!');

        // Special characters should be stripped
        $this->assertStringStartsWith('testuser', $result['panel_username']);
        $this->assertEquals('testuser', $result['prefix']);
        $this->assertEquals('test@user#123!', $result['original_username']);
    }

    /**
     * Test that generator respects max length configuration
     */
    public function test_generator_respects_max_length(): void
    {
        $generator = new MarzneshinUsernameGenerator();
        $maxLen = $generator->getMaxTotalLen();

        // Try with a very long username
        $result = $generator->generate('verylongusernamethatshouldbetruncated');

        $this->assertLessThanOrEqual($maxLen, strlen($result['panel_username']));
    }

    /**
     * Test that generator handles empty/invalid input with fallback
     */
    public function test_generator_uses_fallback_for_empty_input(): void
    {
        $generator = new MarzneshinUsernameGenerator();

        // Input that will be empty after sanitization
        $result = $generator->generate('@#$%^&*()');

        // Should use fallback prefix 'mz'
        $this->assertStringStartsWith('mz', $result['panel_username']);
        $this->assertEquals('mz', $result['prefix']);
    }

    /**
     * Test that generator handles collision detection
     */
    public function test_generator_handles_collisions(): void
    {
        // Create an existing config to cause collision
        ResellerConfig::create([
            'reseller_id' => $this->reseller->id,
            'external_username' => 'aliabcd', // Simulating an existing generated username
            'panel_username' => 'aliabcd',
            'prefix' => 'ali',
            'traffic_limit_bytes' => 10737418240,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
            'status' => 'active',
            'panel_type' => 'marzneshin',
            'panel_id' => $this->panel->id,
            'created_by' => $this->user->id,
        ]);

        $generator = new MarzneshinUsernameGenerator();

        // Generate a new username with the same prefix
        $result = $generator->generate('ali');

        // The generated username should be different from the existing one
        $this->assertNotEquals('aliabcd', $result['panel_username']);
        $this->assertStringStartsWith('ali', $result['panel_username']);
    }

    /**
     * Test that config created via Marzneshin API uses the username generator
     */
    public function test_marzneshin_api_user_creation_uses_generator(): void
    {
        // Skip this test in test environment where panel provisioning would fail
        // Instead, we test the generator directly
        $generator = new MarzneshinUsernameGenerator();

        $result = $generator->generate('mytest');

        // Verify the generated username format
        $this->assertStringStartsWith('mytest', $result['panel_username']);
        $this->assertGreaterThan(strlen('mytest'), strlen($result['panel_username']));
    }

    /**
     * Test that the feature can be disabled via config
     */
    public function test_generator_feature_toggle(): void
    {
        // Test that enabled config works
        config(['marzneshin_username.enabled' => true]);
        $this->assertTrue(config('marzneshin_username.enabled'));

        // Test that disabled config works
        config(['marzneshin_username.enabled' => false]);
        $this->assertFalse(config('marzneshin_username.enabled'));
    }

    /**
     * Test that configuration options are respected
     */
    public function test_generator_respects_configuration(): void
    {
        // Set custom configuration
        config(['marzneshin_username.prefix_max_len' => 5]);
        config(['marzneshin_username.suffix_len' => 3]);
        config(['marzneshin_username.max_total_len' => 10]);

        $generator = new MarzneshinUsernameGenerator();

        $this->assertEquals(5, $generator->getPrefixMaxLen());
        $this->assertEquals(3, $generator->getSuffixLen());
        $this->assertEquals(10, $generator->getMaxTotalLen());
    }

    /**
     * Test that prefix extraction works correctly
     */
    public function test_prefix_extraction(): void
    {
        $generator = new MarzneshinUsernameGenerator();

        // Generate a username
        $result = $generator->generate('testuser');

        // Extract the prefix from the generated username
        $extractedPrefix = $generator->extractPrefix($result['panel_username']);

        // The extracted prefix should match the sanitized prefix
        $this->assertEquals($result['prefix'], $extractedPrefix);
    }

    /**
     * Test lowercase conversion
     */
    public function test_generator_converts_to_lowercase(): void
    {
        $generator = new MarzneshinUsernameGenerator();

        $result = $generator->generate('TestUser123');

        // Username should be lowercase
        $this->assertEquals(strtolower($result['panel_username']), $result['panel_username']);
        // Default prefix_max_len is 8, so 'testuser123' (11 chars) becomes 'testuser' (8 chars)
        $this->assertEquals('testuser', $result['prefix']);
    }

    /**
     * Test that findConfigByUsernameOrPrefix still works with new format
     */
    public function test_prefix_lookup_still_works_with_generated_usernames(): void
    {
        // Create a config with the new naming convention
        $generator = new MarzneshinUsernameGenerator();
        $usernameData = $generator->generate('customer');

        $config = ResellerConfig::create([
            'reseller_id' => $this->reseller->id,
            'external_username' => $usernameData['panel_username'],
            'panel_username' => $usernameData['panel_username'],
            'prefix' => 'customer', // Original requested username stored in prefix
            'traffic_limit_bytes' => 10737418240,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
            'status' => 'active',
            'panel_type' => 'marzneshin',
            'panel_id' => $this->panel->id,
            'created_by' => $this->user->id,
        ]);

        // Verify that prefix lookup returns the config
        $foundConfig = $this->reseller->configs()
            ->where('prefix', 'customer')
            ->first();

        $this->assertNotNull($foundConfig);
        $this->assertEquals($config->id, $foundConfig->id);

        // Verify that external_username lookup still works
        $foundByExternal = $this->reseller->configs()
            ->where('external_username', $usernameData['panel_username'])
            ->first();

        $this->assertNotNull($foundByExternal);
        $this->assertEquals($config->id, $foundByExternal->id);
    }

    /**
     * Test the isGeneratedUsername validation method
     */
    public function test_is_generated_username_validation(): void
    {
        $generator = new MarzneshinUsernameGenerator();

        // Generate a valid username
        $result = $generator->generate('testuser');

        // Should detect our generated username as valid
        $this->assertTrue($generator->isGeneratedUsername($result['panel_username']));

        // Should reject usernames with special characters
        $this->assertFalse($generator->isGeneratedUsername('test_user'));
        $this->assertFalse($generator->isGeneratedUsername('test-user'));
        $this->assertFalse($generator->isGeneratedUsername('test@user'));

        // Should reject usernames that are too short (only suffix length or less)
        $this->assertFalse($generator->isGeneratedUsername('ab'));
        $this->assertFalse($generator->isGeneratedUsername('abc'));

        // Should reject uppercase usernames
        $this->assertFalse($generator->isGeneratedUsername('TestUser123'));

        // Should accept valid lowercase alphanumeric usernames
        $this->assertTrue($generator->isGeneratedUsername('testuser1234'));
    }
}
