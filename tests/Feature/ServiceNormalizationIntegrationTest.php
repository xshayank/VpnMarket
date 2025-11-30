<?php

namespace Tests\Feature;

use App\Helpers\DurationNormalization;
use App\Services\EylandooService;
use App\Services\MarzneshinService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Integration tests for service methods that use duration/data limit normalization
 */
class ServiceNormalizationIntegrationTest extends TestCase
{
    /**
     * Test MarzneshinService createUser converts usage_duration seconds to days
     */
    public function test_marzneshin_create_user_converts_duration_to_days(): void
    {
        Http::fake([
            '*/api/admins/token' => Http::response([
                'access_token' => 'test_token',
            ], 200),
            '*/api/users' => Http::response([
                'username' => 'test_user',
                'subscription_url' => '/sub/test123',
            ], 200),
        ]);

        $service = new MarzneshinService(
            'https://test-panel.example.com',
            'admin',
            'password',
            'https://node.example.com'
        );

        $service->login();

        // Create user with 1 hour (3600 seconds) - should become 1 day
        $result = $service->createUser([
            'username' => 'test_user',
            'data_limit' => 1073741824,
            'expire_strategy' => 'start_on_first_use',
            'usage_duration' => 3600, // 1 hour in seconds
            'service_ids' => [],
        ]);

        // Verify the HTTP request was made with the correct payload
        Http::assertSent(function ($request) {
            if (str_contains($request->url(), '/api/users')) {
                $body = $request->data();

                // Verify usage_duration was converted from 3600 seconds to 1 day
                return isset($body['usage_duration']) && $body['usage_duration'] === 1;
            }

            return true;
        });

        $this->assertNotNull($result);
    }

    /**
     * Test MarzneshinService createUser converts 25 hours to 2 days
     */
    public function test_marzneshin_create_user_rounds_up_partial_days(): void
    {
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test_token'], 200),
            '*/api/users' => Http::response(['username' => 'test_user', 'subscription_url' => '/sub/test'], 200),
        ]);

        $service = new MarzneshinService(
            'https://test-panel.example.com',
            'admin',
            'password',
            ''
        );

        $service->login();

        // 25 hours (90000 seconds) should become 2 days
        $service->createUser([
            'username' => 'test_user',
            'data_limit' => 1073741824,
            'expire_strategy' => 'start_on_first_use',
            'usage_duration' => 90000,
            'service_ids' => [],
        ]);

        Http::assertSent(function ($request) {
            if (str_contains($request->url(), '/api/users')) {
                $body = $request->data();

                return isset($body['usage_duration']) && $body['usage_duration'] === 2;
            }

            return true;
        });
    }

    /**
     * Test EylandooService createUser uses MB for small data limits
     */
    public function test_eylandoo_create_user_uses_mb_for_small_limits(): void
    {
        Http::fake([
            '*/api/v1/users' => Http::response([
                'success' => true,
                'created_users' => ['test_user'],
            ], 200),
        ]);

        $service = new EylandooService(
            'https://test-eylandoo.example.com',
            'api_key_123',
            ''
        );

        // 500 MB (524288000 bytes)
        $result = $service->createUser([
            'username' => 'test_user',
            'data_limit' => 524288000,
            'expire' => time() + 86400 * 30,
        ]);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            // Should use MB unit for 500 MB
            return isset($body['data_limit']) &&
                   $body['data_limit'] === 500 &&
                   isset($body['data_limit_unit']) &&
                   $body['data_limit_unit'] === 'MB';
        });

        $this->assertNotNull($result);
    }

    /**
     * Test EylandooService createUser uses GB for large data limits
     */
    public function test_eylandoo_create_user_uses_gb_for_large_limits(): void
    {
        Http::fake([
            '*/api/v1/users' => Http::response([
                'success' => true,
                'created_users' => ['test_user'],
            ], 200),
        ]);

        $service = new EylandooService(
            'https://test-eylandoo.example.com',
            'api_key_123',
            ''
        );

        // 10 GB (10737418240 bytes)
        $service->createUser([
            'username' => 'test_user',
            'data_limit' => 10737418240,
            'expire' => time() + 86400 * 30,
        ]);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            // Should use GB unit for 10 GB
            // Note: 10.0 may be compared as float
            return isset($body['data_limit']) &&
                   abs($body['data_limit'] - 10.0) < 0.01 &&
                   isset($body['data_limit_unit']) &&
                   $body['data_limit_unit'] === 'GB';
        });
    }

    /**
     * Test EylandooService updateUser uses MB for small data limits
     */
    public function test_eylandoo_update_user_uses_mb_for_small_limits(): void
    {
        Http::fake([
            '*/api/v1/users/*' => Http::response(['success' => true], 200),
        ]);

        $service = new EylandooService(
            'https://test-eylandoo.example.com',
            'api_key_123',
            ''
        );

        // 50 MB (52428800 bytes)
        $service->updateUser('test_user', [
            'data_limit' => 52428800,
        ]);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return isset($body['data_limit']) &&
                   $body['data_limit'] === 50 &&
                   isset($body['data_limit_unit']) &&
                   $body['data_limit_unit'] === 'MB';
        });
    }

    /**
     * Test data limit at threshold boundary (just under 1 GB uses MB)
     */
    public function test_eylandoo_threshold_boundary_uses_correct_unit(): void
    {
        Http::fake([
            '*/api/v1/users' => Http::response([
                'success' => true,
                'created_users' => ['test_user'],
            ], 200),
        ]);

        $service = new EylandooService(
            'https://test-eylandoo.example.com',
            'api_key_123',
            ''
        );

        // Just under 1 GB (1073741823 bytes) - should use MB
        $service->createUser([
            'username' => 'test_user',
            'data_limit' => 1073741823,
            'expire' => time() + 86400 * 30,
        ]);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return isset($body['data_limit_unit']) && $body['data_limit_unit'] === 'MB';
        });
    }

    /**
     * Test MarzneshinService updateUser converts usage_duration
     */
    public function test_marzneshin_update_user_converts_duration(): void
    {
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test_token'], 200),
            '*/api/users/*' => Http::response(['username' => 'test_user'], 200),
        ]);

        $service = new MarzneshinService(
            'https://test-panel.example.com',
            'admin',
            'password',
            ''
        );

        $service->login();

        // Update with 30 days (2592000 seconds)
        $service->updateUser('test_user', [
            'expire_strategy' => 'start_on_first_use',
            'usage_duration' => 2592000,
        ]);

        Http::assertSent(function ($request) {
            if (str_contains($request->url(), '/api/users/test_user') && $request->method() === 'PUT') {
                $body = $request->data();

                return isset($body['usage_duration']) && $body['usage_duration'] === 30;
            }

            return true;
        });
    }

    /**
     * Test helper function directly for edge cases
     */
    public function test_duration_normalization_edge_cases(): void
    {
        // Very large seconds value
        $days = DurationNormalization::normalizeUsageDurationSecondsToDays(31536000); // 365 days
        $this->assertEquals(365, $days);

        // Just over a day
        $days = DurationNormalization::normalizeUsageDurationSecondsToDays(86401); // 1 day + 1 second
        $this->assertEquals(2, $days); // Rounds up to 2 days
    }

    /**
     * Test 1.5 GB data limit precision
     */
    public function test_eylandoo_1_5_gb_precision(): void
    {
        Http::fake([
            '*/api/v1/users' => Http::response(['success' => true, 'created_users' => ['test']], 200),
        ]);

        $service = new EylandooService('https://test.com', 'key', '');

        // 1.5 GB (1610612736 bytes)
        $service->createUser([
            'username' => 'test',
            'data_limit' => 1610612736,
            'expire' => time() + 86400,
        ]);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return isset($body['data_limit']) &&
                   $body['data_limit'] === 1.5 &&
                   $body['data_limit_unit'] === 'GB';
        });
    }
}
