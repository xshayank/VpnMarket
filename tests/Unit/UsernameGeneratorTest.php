<?php

namespace Tests\Unit;

use App\Services\UsernameGenerator;
use Mockery;
use Tests\TestCase;

class UsernameGeneratorTest extends TestCase
{
    protected UsernameGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new UsernameGenerator();
    }

    /**
     * Test sanitization removes invalid characters
     */
    public function test_sanitize_prefix_removes_invalid_characters(): void
    {
        $this->assertEquals('ali', $this->generator->sanitizePrefix('ali'));
        $this->assertEquals('ali123', $this->generator->sanitizePrefix('ali@123'));
        $this->assertEquals('username', $this->generator->sanitizePrefix('user.name!'));
        $this->assertEquals('test', $this->generator->sanitizePrefix('test#$%^'));
        $this->assertEquals('helloworld', $this->generator->sanitizePrefix('hello_world')); // underscore is non-alphanumeric so it's stripped
        $this->assertEquals('abc123xyz', $this->generator->sanitizePrefix('abc-123-xyz'));
    }

    /**
     * Test sanitization handles empty result with fallback
     */
    public function test_sanitize_prefix_uses_fallback_for_empty_result(): void
    {
        $this->assertEquals('user', $this->generator->sanitizePrefix('!!!'));
        $this->assertEquals('user', $this->generator->sanitizePrefix('@#$%'));
        $this->assertEquals('user', $this->generator->sanitizePrefix('---'));
        $this->assertEquals('user', $this->generator->sanitizePrefix(''));
    }

    /**
     * Test sanitization truncates to max length
     */
    public function test_sanitize_prefix_truncates_to_max_length(): void
    {
        $longUsername = 'verylongusernamethatexceedsmaxlength';
        $sanitized = $this->generator->sanitizePrefix($longUsername);
        
        // Default max is 12
        $this->assertLessThanOrEqual(12, strlen($sanitized));
        $this->assertEquals('verylonguser', $sanitized);
    }

    /**
     * Test sanitization converts to lowercase
     */
    public function test_sanitize_prefix_converts_to_lowercase(): void
    {
        $this->assertEquals('testuser', $this->generator->sanitizePrefix('TestUser'));
        $this->assertEquals('username', $this->generator->sanitizePrefix('USERNAME'));
        $this->assertEquals('mixedcase', $this->generator->sanitizePrefix('MixedCASE'));
    }

    /**
     * Test panel username generation format
     */
    public function test_generate_panel_username_format(): void
    {
        $result = $this->generator->generatePanelUsername('ali');
        
        $this->assertArrayHasKey('panel_username', $result);
        $this->assertArrayHasKey('username_prefix', $result);
        $this->assertArrayHasKey('original_username', $result);
        
        // Panel username should be prefix_suffix format
        $this->assertStringContainsString('_', $result['panel_username']);
        $this->assertEquals('ali', $result['username_prefix']);
        $this->assertEquals('ali', $result['original_username']);
        
        // Total length should be within limit
        $this->assertLessThanOrEqual(20, strlen($result['panel_username']));
    }

    /**
     * Test panel username generation with long input
     */
    public function test_generate_panel_username_with_long_input(): void
    {
        $longUsername = 'this_is_a_very_long_username_from_telegram_bot';
        $result = $this->generator->generatePanelUsername($longUsername);
        
        // Total length should be within limit
        $this->assertLessThanOrEqual(20, strlen($result['panel_username']));
        
        // Original should be preserved
        $this->assertEquals($longUsername, $result['original_username']);
    }

    /**
     * Test collision handling with exists checker
     */
    public function test_generate_panel_username_handles_collisions(): void
    {
        $existingUsernames = [];
        $callCount = 0;
        
        // Simulate collision on first 3 attempts, succeed on 4th
        $existsChecker = function (string $username) use (&$existingUsernames, &$callCount): bool {
            $callCount++;
            if ($callCount <= 3) {
                $existingUsernames[] = $username;
                return true; // Simulate exists
            }
            return false; // Fourth attempt succeeds
        };
        
        $result = $this->generator->generatePanelUsername('test', $existsChecker);
        
        // Should eventually succeed
        $this->assertNotEmpty($result['panel_username']);
        $this->assertEquals('test', $result['username_prefix']);
    }

    /**
     * Test extract prefix from panel username
     */
    public function test_extract_prefix_from_panel_username(): void
    {
        $this->assertEquals('ali', $this->generator->extractPrefix('ali_abc123'));
        $this->assertEquals('user_123_cfg', $this->generator->extractPrefix('user_123_cfg_456'));
        $this->assertEquals('simple', $this->generator->extractPrefix('simple_x'));
        $this->assertEquals('nounderscore', $this->generator->extractPrefix('nounderscore'));
    }

    /**
     * Test username validation
     */
    public function test_validate_username(): void
    {
        // Valid username
        $result = $this->generator->validateUsername('ali_abc123');
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
        
        // Too long username
        $result = $this->generator->validateUsername('this_is_a_very_long_username_that_exceeds_limit');
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        
        // Empty username
        $result = $this->generator->validateUsername('');
        $this->assertFalse($result['valid']);
    }

    /**
     * Test configuration getters
     */
    public function test_configuration_getters(): void
    {
        // Default values
        $this->assertEquals(12, $this->generator->getPrefixMaxLen());
        $this->assertEquals(6, $this->generator->getSuffixLen());
        $this->assertEquals(20, $this->generator->getMaxTotalLen());
    }

    /**
     * Test generation with special characters in username
     */
    public function test_generate_with_special_telegram_username(): void
    {
        // Telegram usernames can have various formats
        $result = $this->generator->generatePanelUsername('@telegram_user');
        
        $this->assertStringNotContainsString('@', $result['panel_username']);
        $this->assertEquals('telegramuser', $result['username_prefix']);
    }

    /**
     * Test generation with unicode characters
     */
    public function test_generate_with_unicode_characters(): void
    {
        $result = $this->generator->generatePanelUsername('علی'); // Persian characters
        
        // Should fall back to 'user' as default
        $this->assertEquals('user', $result['username_prefix']);
    }

    /**
     * Test that suffix is properly generated
     */
    public function test_suffix_has_correct_length(): void
    {
        $result = $this->generator->generatePanelUsername('test');
        
        // Extract suffix (everything after last underscore)
        $parts = explode('_', $result['panel_username']);
        $suffix = end($parts);
        
        // Default suffix length is 6
        $this->assertEquals(6, strlen($suffix));
    }
}
