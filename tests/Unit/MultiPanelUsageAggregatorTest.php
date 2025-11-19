<?php

namespace Tests\Unit;

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\ResellerPanelUsageSnapshot;
use App\Services\MultiPanelUsageAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class MultiPanelUsageAggregatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock Log to avoid output during tests
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('notice')->andReturnNull();
        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();
        Log::shouldReceive('debug')->andReturnNull();
    }

    public function test_aggregates_usage_across_multiple_panels(): void
    {
        // Create two panels
        $panel1 = Panel::create([
            'name' => 'Panel 1',
            'url' => 'https://panel1.com',
            'panel_type' => 'marzneshin',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
            'extra' => ['node_hostname' => 'https://node1.com'],
        ]);

        $panel2 = Panel::create([
            'name' => 'Panel 2',
            'url' => 'https://panel2.com',
            'panel_type' => 'eylandoo',
            'api_token' => 'test-token',
            'is_active' => true,
            'extra' => ['node_hostname' => 'https://node2.com'],
        ]);

        // Create a reseller with access to both panels
        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 10 * 1024 ** 3, // 10 GB
            'traffic_used_bytes' => 0,
        ]);

        $reseller->panels()->attach([$panel1->id, $panel2->id]);

        // Create configs on both panels
        $config1 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel1->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'user1',
            'status' => 'active',
            'usage_bytes' => 0,
        ]);

        $config2 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel2->id,
            'panel_type' => 'eylandoo',
            'panel_user_id' => 'user2',
            'status' => 'active',
            'usage_bytes' => 0,
        ]);

        // Mock HTTP responses
        Http::fake([
            'https://panel1.com/api/admins/token' => Http::response(['access_token' => 'token1'], 200),
            'https://panel1.com/api/users/user1' => Http::response([
                'username' => 'user1',
                'used_traffic' => 1 * 1024 ** 3, // 1 GB
            ], 200),
            'https://panel2.com/api/v1/users/user2' => Http::response([
                'userInfo' => [
                    'username' => 'user2',
                    'total_traffic_bytes' => 2 * 1024 ** 3, // 2 GB
                ],
            ], 200),
        ]);

        // Run aggregator
        $aggregator = new MultiPanelUsageAggregator();
        $result = $aggregator->aggregateUsage($reseller);

        // Assert total usage is sum of both panels
        $this->assertEquals(3 * 1024 ** 3, $result['total_usage_bytes']);
        $this->assertEquals(7 * 1024 ** 3, $result['remaining_bytes']);
        $this->assertEquals(2, $result['panels_processed']);

        // Assert configs were updated
        $config1->refresh();
        $config2->refresh();
        $this->assertEquals(1 * 1024 ** 3, $config1->usage_bytes);
        $this->assertEquals(2 * 1024 ** 3, $config2->usage_bytes);

        // Assert snapshots were created
        $this->assertDatabaseHas('reseller_panel_usage_snapshots', [
            'reseller_id' => $reseller->id,
            'panel_id' => $panel1->id,
            'total_usage_bytes' => 1 * 1024 ** 3,
        ]);

        $this->assertDatabaseHas('reseller_panel_usage_snapshots', [
            'reseller_id' => $reseller->id,
            'panel_id' => $panel2->id,
            'total_usage_bytes' => 2 * 1024 ** 3,
        ]);
    }

    public function test_uses_legacy_mode_when_feature_disabled(): void
    {
        Config::set('multi_panel.usage_enabled', false);

        $panel = Panel::create([
            'name' => 'Test Panel',
            'url' => 'https://panel.com',
            'panel_type' => 'marzneshin',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
        ]);

        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 10 * 1024 ** 3,
            'traffic_used_bytes' => 0,
            'primary_panel_id' => $panel->id,
        ]);

        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'usage_bytes' => 1 * 1024 ** 3,
            'status' => 'active',
        ]);

        $aggregator = new MultiPanelUsageAggregator();
        $result = $aggregator->aggregateUsage($reseller);

        // Should use legacy calculation (sum of config usage_bytes)
        $this->assertEquals(1 * 1024 ** 3, $result['total_usage_bytes']);
        $this->assertEquals(9 * 1024 ** 3, $result['remaining_bytes']);
    }

    public function test_handles_panel_failure_gracefully(): void
    {
        $panel1 = Panel::create([
            'name' => 'Panel 1',
            'url' => 'https://panel1.com',
            'panel_type' => 'marzneshin',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
        ]);

        $panel2 = Panel::create([
            'name' => 'Panel 2',
            'url' => 'https://panel2.com',
            'panel_type' => 'marzneshin',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
        ]);

        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 10 * 1024 ** 3,
        ]);

        $reseller->panels()->attach([$panel1->id, $panel2->id]);

        ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel1->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'user1',
            'status' => 'active',
        ]);

        ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel2->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'user2',
            'status' => 'active',
        ]);

        // Mock: panel1 fails, panel2 succeeds
        Http::fake([
            'https://panel1.com/*' => Http::response([], 500),
            'https://panel2.com/api/admins/token' => Http::response(['access_token' => 'token'], 200),
            'https://panel2.com/api/users/user2' => Http::response([
                'username' => 'user2',
                'used_traffic' => 1 * 1024 ** 3,
            ], 200),
        ]);

        $aggregator = new MultiPanelUsageAggregator();
        $result = $aggregator->aggregateUsage($reseller);

        // Should still process panel2 successfully and get its usage
        // Panel1 is processed but returns 0 bytes due to failures
        $this->assertEquals(1 * 1024 ** 3, $result['total_usage_bytes']);
        $this->assertEquals(2, $result['panels_processed']); // Both panels processed, panel1 just returned 0
    }

    public function test_updates_reseller_total_usage_with_admin_forgiven_bytes(): void
    {
        $panel = Panel::create([
            'name' => 'Test Panel',
            'url' => 'https://panel.com',
            'panel_type' => 'marzneshin',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
        ]);

        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 10 * 1024 ** 3,
            'traffic_used_bytes' => 0,
            'admin_forgiven_bytes' => 1 * 1024 ** 3, // Admin forgave 1 GB
        ]);

        $reseller->panels()->attach($panel->id);

        ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'user1',
            'status' => 'active',
            'usage_bytes' => 0,
        ]);

        Http::fake([
            'https://panel.com/api/admins/token' => Http::response(['access_token' => 'token'], 200),
            'https://panel.com/api/users/user1' => Http::response([
                'used_traffic' => 3 * 1024 ** 3, // 3 GB
            ], 200),
        ]);

        $aggregator = new MultiPanelUsageAggregator();
        $result = $aggregator->aggregateUsage($reseller);
        $aggregator->updateResellerTotalUsage($reseller, $result['total_usage_bytes']);

        $reseller->refresh();

        // Effective usage should be 3 GB - 1 GB = 2 GB
        $this->assertEquals(2 * 1024 ** 3, $reseller->traffic_used_bytes);
    }

    public function test_backward_compatibility_with_primary_panel_only(): void
    {
        $panel = Panel::create([
            'name' => 'Primary Panel',
            'url' => 'https://panel.com',
            'panel_type' => 'marzneshin',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
        ]);

        // Reseller with only primary_panel_id, no panels relationship
        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 10 * 1024 ** 3,
            'primary_panel_id' => $panel->id,
        ]);

        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'user1',
            'status' => 'active',
        ]);

        Http::fake([
            'https://panel.com/api/admins/token' => Http::response(['access_token' => 'token'], 200),
            'https://panel.com/api/users/user1' => Http::response([
                'used_traffic' => 1 * 1024 ** 3,
            ], 200),
        ]);

        $aggregator = new MultiPanelUsageAggregator();
        $result = $aggregator->aggregateUsage($reseller);

        // Should fallback to primary panel
        $this->assertEquals(1 * 1024 ** 3, $result['total_usage_bytes']);
        $this->assertEquals(1, $result['panels_processed']);
    }
}
