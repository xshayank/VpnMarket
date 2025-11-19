<?php

namespace Tests\Feature;

use App\Jobs\ReenableResellerConfigsJob;
use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Services\WalletResellerReenableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ReenableMultiPanelConfigsTest extends TestCase
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

    public function test_reenables_configs_across_multiple_panels_after_wallet_topup(): void
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

        // Create a wallet-based reseller
        $reseller = Reseller::factory()->create([
            'type' => 'wallet',
            'status' => 'active',
            'wallet_balance' => 10000, // Has balance now
        ]);

        $reseller->panels()->attach([$panel1->id, $panel2->id]);

        // Create disabled configs on both panels
        $config1 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel1->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'user1',
            'status' => 'disabled',
            'disabled_at' => now(),
            'meta' => [
                'disabled_by_wallet_suspension' => true,
            ],
        ]);

        $config2 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel2->id,
            'panel_type' => 'eylandoo',
            'panel_user_id' => 'user2',
            'status' => 'disabled',
            'disabled_at' => now(),
            'meta' => [
                'disabled_by_wallet_suspension' => true,
            ],
        ]);

        // Mock HTTP - successful enable on both panels
        Http::fake([
            'https://panel1.com/api/admins/token' => Http::response(['access_token' => 'token1'], 200),
            'https://panel1.com/api/users/user1/enable' => Http::response([], 200),
            'https://panel2.com/api/v1/users/user2/enable' => Http::response([], 200),
        ]);

        // Run re-enable service
        $service = new WalletResellerReenableService();
        $result = $service->reenableWalletSuspendedConfigs($reseller);

        // Assert both configs were enabled
        $this->assertEquals(2, $result['enabled']);
        $this->assertEquals(0, $result['failed']);

        $config1->refresh();
        $config2->refresh();

        $this->assertEquals('active', $config1->status);
        $this->assertEquals('active', $config2->status);
        $this->assertNull($config1->disabled_at);
        $this->assertNull($config2->disabled_at);

        // Meta should be cleared
        $this->assertArrayNotHasKey('disabled_by_wallet_suspension', $config1->meta ?? []);
        $this->assertArrayNotHasKey('disabled_by_wallet_suspension', $config2->meta ?? []);
    }

    public function test_reenables_configs_across_multiple_panels_after_traffic_increase(): void
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
            'panel_type' => 'eylandoo',
            'api_token' => 'test-token',
            'is_active' => true,
        ]);

        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 20 * 1024 ** 3, // Increased to 20 GB
            'traffic_used_bytes' => 5 * 1024 ** 3, // Used 5 GB
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        $reseller->panels()->attach([$panel1->id, $panel2->id]);

        $config1 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel1->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'user1',
            'status' => 'disabled',
            'meta' => [
                'disabled_by_traffic_suspension' => true,
            ],
        ]);

        $config2 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel2->id,
            'panel_type' => 'eylandoo',
            'panel_user_id' => 'user2',
            'status' => 'disabled',
            'meta' => [
                'disabled_by_traffic_suspension' => true,
            ],
        ]);

        Http::fake([
            'https://panel1.com/api/admins/token' => Http::response(['access_token' => 'token'], 200),
            'https://panel1.com/api/users/user1/enable' => Http::response([], 200),
            'https://panel2.com/api/v1/users/user2/enable' => Http::response([], 200),
        ]);

        // Run re-enable job
        $job = new ReenableResellerConfigsJob($reseller, 'traffic');
        $job->handle();

        // Assert both configs were enabled
        $config1->refresh();
        $config2->refresh();

        $this->assertEquals('active', $config1->status);
        $this->assertEquals('active', $config2->status);
    }

    public function test_partial_reenable_when_one_panel_fails(): void
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
            'panel_type' => 'eylandoo',
            'api_token' => 'test-token',
            'is_active' => true,
        ]);

        $reseller = Reseller::factory()->create([
            'type' => 'wallet',
            'status' => 'active',
            'wallet_balance' => 10000,
        ]);

        $reseller->panels()->attach([$panel1->id, $panel2->id]);

        $config1 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel1->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'user1',
            'status' => 'disabled',
            'meta' => ['disabled_by_wallet_suspension' => true],
        ]);

        $config2 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel2->id,
            'panel_type' => 'eylandoo',
            'panel_user_id' => 'user2',
            'status' => 'disabled',
            'meta' => ['disabled_by_wallet_suspension' => true],
        ]);

        // Mock: panel1 succeeds, panel2 fails
        Http::fake([
            'https://panel1.com/api/admins/token' => Http::response(['access_token' => 'token'], 200),
            'https://panel1.com/api/users/user1/enable' => Http::response([], 200),
            'https://panel2.com/*' => Http::response([], 500),
        ]);

        $service = new WalletResellerReenableService();
        $result = $service->reenableWalletSuspendedConfigs($reseller);

        // Assert partial success
        $this->assertEquals(1, $result['enabled']);
        $this->assertEquals(1, $result['failed']);

        $config1->refresh();
        $config2->refresh();

        $this->assertEquals('active', $config1->status);
        $this->assertEquals('disabled', $config2->status); // Should remain disabled
    }

    public function test_does_not_reenable_manually_disabled_configs(): void
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
            'type' => 'wallet',
            'status' => 'active',
            'wallet_balance' => 10000,
        ]);

        $reseller->panels()->attach($panel->id);

        // Config disabled manually (no suspension meta)
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'user1',
            'status' => 'disabled',
            'meta' => ['manually_disabled' => true],
        ]);

        Http::fake();

        $service = new WalletResellerReenableService();
        $result = $service->reenableWalletSuspendedConfigs($reseller);

        // Should not re-enable manually disabled configs
        $this->assertEquals(0, $result['enabled']);

        $config->refresh();
        $this->assertEquals('disabled', $config->status);
    }

    public function test_skips_already_active_configs(): void
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
            'type' => 'wallet',
            'status' => 'active',
            'wallet_balance' => 10000,
        ]);

        $reseller->panels()->attach($panel->id);

        // Config already active
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'user1',
            'status' => 'active', // Already active
        ]);

        Http::fake();

        $service = new WalletResellerReenableService();
        $result = $service->reenableWalletSuspendedConfigs($reseller);

        // Should not process already active configs
        $this->assertEquals(0, $result['enabled']);
        $this->assertEquals(0, $result['failed']);
    }
}
