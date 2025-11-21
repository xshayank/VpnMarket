<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\ResellerConfigEvent;
use App\Models\ResellerUsageSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class WalletResellerSuspensionOnChargeTest extends TestCase
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

    public function test_reseller_is_suspended_when_charge_pushes_balance_below_threshold(): void
    {
        // Set suspension threshold
        Config::set('billing.wallet.suspension_threshold', -1000);
        Config::set('billing.wallet.price_per_gb', 780);
        Config::set('billing.wallet.minimum_delta_bytes_to_charge', 0);

        // Create a user
        $user = User::factory()->create();

        // Create a panel
        $panel = Panel::create([
            'name' => 'Test Panel',
            'url' => 'https://example.com',
            'panel_type' => 'marzneshin',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
            'extra' => ['node_hostname' => 'https://node.example.com'],
        ]);

        // Create a wallet reseller with balance just above threshold
        // We'll set balance to 500, and usage will cost more than 1500 to push below -1000
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'wallet',
            'status' => 'active',
            'wallet_balance' => 500,  // Starting balance
            'wallet_price_per_gb' => 780,
            'panel_id' => $panel->id,
        ]);

        // Create a config with usage that will cause a charge of ~2000 (2.5 GB * 780 = 1950)
        // This will push balance from 500 to ~-1450 (below -1000 threshold)
        $usageBytes = (int) (2.5 * 1024 * 1024 * 1024);  // 2.5 GB
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_user_id' => 'test-user-123',
            'external_username' => 'test_user_123',
            'status' => 'active',
            'usage_bytes' => $usageBytes,
            'meta' => [],
        ]);

        // Mock HTTP for remote panel disable call
        Http::fake([
            'example.com/*' => Http::response(['success' => true], 200),
        ]);

        // Run the charge command
        $exitCode = Artisan::call('reseller:charge-wallet-hourly');

        // Refresh models from database
        $reseller->refresh();
        $config->refresh();

        // Assert reseller status is suspended_wallet
        $this->assertEquals('suspended_wallet', $reseller->status, 'Reseller should be suspended after balance falls below threshold');

        // Assert balance is below threshold
        $this->assertLessThanOrEqual(-1000, $reseller->wallet_balance, 'Reseller balance should be below suspension threshold');

        // Assert config is disabled
        $this->assertEquals('disabled', $config->status, 'Config should be disabled when reseller is suspended');

        // Assert config meta includes disabled_by_wallet_suspension
        $this->assertNotNull($config->meta, 'Config meta should not be null');
        $this->assertTrue($config->meta['disabled_by_wallet_suspension'] ?? false, 'Config meta should include disabled_by_wallet_suspension = true');
        $this->assertEquals($reseller->id, $config->meta['disabled_by_reseller_id'] ?? null, 'Config meta should include disabled_by_reseller_id');

        // Assert snapshot was created
        $snapshot = ResellerUsageSnapshot::where('reseller_id', $reseller->id)->first();
        $this->assertNotNull($snapshot, 'Usage snapshot should be created');
        $this->assertTrue($snapshot->meta['cycle_charge_applied'] ?? false, 'Snapshot should indicate charge was applied');

        // Assert audit logs were created
        $suspensionAudit = AuditLog::where('action', 'reseller_suspended_wallet')
            ->where('target_type', 'reseller')
            ->where('target_id', $reseller->id)
            ->first();
        $this->assertNotNull($suspensionAudit, 'Audit log for suspension should exist');

        $configDisableAudit = AuditLog::where('action', 'config_auto_disabled')
            ->where('target_type', 'config')
            ->where('target_id', $config->id)
            ->first();
        $this->assertNotNull($configDisableAudit, 'Audit log for config disable should exist');

        // Assert ResellerConfigEvent was created
        $configEvent = ResellerConfigEvent::where('reseller_config_id', $config->id)
            ->where('type', 'auto_disabled')
            ->first();
        $this->assertNotNull($configEvent, 'Config event should be created');
        $this->assertEquals('wallet_balance_exhausted', $configEvent->meta['reason'] ?? null, 'Event should indicate wallet balance exhausted');
    }

    public function test_reseller_stays_active_when_balance_above_threshold(): void
    {
        // Set suspension threshold
        Config::set('billing.wallet.suspension_threshold', -1000);
        Config::set('billing.wallet.price_per_gb', 780);
        Config::set('billing.wallet.minimum_delta_bytes_to_charge', 0);

        // Create a user
        $user = User::factory()->create();

        // Create a panel
        $panel = Panel::create([
            'name' => 'Test Panel',
            'url' => 'https://example.com',
            'panel_type' => 'marzneshin',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
            'extra' => ['node_hostname' => 'https://node.example.com'],
        ]);

        // Create a wallet reseller with high balance
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'wallet',
            'status' => 'active',
            'wallet_balance' => 50000,  // High balance
            'wallet_price_per_gb' => 780,
            'panel_id' => $panel->id,
        ]);

        // Create a config with small usage
        $usageBytes = (int) (0.5 * 1024 * 1024 * 1024);  // 0.5 GB, cost ~390
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_user_id' => 'test-user-456',
            'external_username' => 'test_user_456',
            'status' => 'active',
            'usage_bytes' => $usageBytes,
            'meta' => [],
        ]);

        // Run the charge command
        $exitCode = Artisan::call('reseller:charge-wallet-hourly');

        // Refresh models
        $reseller->refresh();
        $config->refresh();

        // Assert reseller is still active
        $this->assertEquals('active', $reseller->status, 'Reseller should remain active when balance is above threshold');

        // Assert config is still active
        $this->assertEquals('active', $config->status, 'Config should remain active when reseller is not suspended');

        // Assert balance was charged but is still above threshold
        $this->assertGreaterThan(-1000, $reseller->wallet_balance, 'Reseller balance should be above suspension threshold');
        $this->assertLessThan(50000, $reseller->wallet_balance, 'Reseller balance should be reduced by the charge');
    }

    public function test_suspension_is_idempotent_does_not_double_disable_configs(): void
    {
        // Set suspension threshold
        Config::set('billing.wallet.suspension_threshold', -1000);
        Config::set('billing.wallet.price_per_gb', 780);
        Config::set('billing.wallet.minimum_delta_bytes_to_charge', 0);

        // Create a user
        $user = User::factory()->create();

        // Create a panel
        $panel = Panel::create([
            'name' => 'Test Panel',
            'url' => 'https://example.com',
            'panel_type' => 'marzneshin',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
            'extra' => ['node_hostname' => 'https://node.example.com'],
        ]);

        // Create a wallet reseller already suspended
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'wallet',
            'status' => 'suspended_wallet',  // Already suspended
            'wallet_balance' => -2000,  // Already below threshold
            'wallet_price_per_gb' => 780,
            'panel_id' => $panel->id,
        ]);

        // Create a config already disabled
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_user_id' => 'test-user-789',
            'external_username' => 'test_user_789',
            'status' => 'disabled',  // Already disabled
            'usage_bytes' => (int) (1 * 1024 * 1024 * 1024),
            'meta' => [
                'disabled_by_wallet_suspension' => true,
                'disabled_by_reseller_id' => $reseller->id,
            ],
        ]);

        // Mock HTTP
        Http::fake([
            'example.com/*' => Http::response(['success' => true], 200),
        ]);

        // Count initial audit logs
        $initialSuspensionAuditCount = AuditLog::where('action', 'reseller_suspended_wallet')
            ->where('target_id', $reseller->id)
            ->count();

        // Run the charge command (should have minimal usage so small charge)
        $exitCode = Artisan::call('reseller:charge-wallet-hourly');

        // Refresh models
        $reseller->refresh();
        $config->refresh();

        // Assert reseller is still suspended (not changed)
        $this->assertEquals('suspended_wallet', $reseller->status, 'Reseller should remain suspended');

        // Assert config is still disabled
        $this->assertEquals('disabled', $config->status, 'Config should remain disabled');

        // Assert no new suspension audit log was created (idempotency)
        $finalSuspensionAuditCount = AuditLog::where('action', 'reseller_suspended_wallet')
            ->where('target_id', $reseller->id)
            ->count();
        $this->assertEquals($initialSuspensionAuditCount, $finalSuspensionAuditCount, 'No new suspension audit log should be created for already suspended reseller');
    }

    public function test_multiple_configs_all_disabled_on_suspension(): void
    {
        // Set suspension threshold
        Config::set('billing.wallet.suspension_threshold', -1000);
        Config::set('billing.wallet.price_per_gb', 780);
        Config::set('billing.wallet.minimum_delta_bytes_to_charge', 0);

        // Create a user
        $user = User::factory()->create();

        // Create a panel
        $panel = Panel::create([
            'name' => 'Test Panel',
            'url' => 'https://example.com',
            'panel_type' => 'marzneshin',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
            'extra' => ['node_hostname' => 'https://node.example.com'],
        ]);

        // Create a wallet reseller
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'wallet',
            'status' => 'active',
            'wallet_balance' => 500,
            'wallet_price_per_gb' => 780,
            'panel_id' => $panel->id,
        ]);

        // Create multiple configs
        $config1 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_user_id' => 'test-user-1',
            'external_username' => 'test_user_1',
            'status' => 'active',
            'usage_bytes' => (int) (1 * 1024 * 1024 * 1024),  // 1 GB
            'meta' => [],
        ]);

        $config2 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_user_id' => 'test-user-2',
            'external_username' => 'test_user_2',
            'status' => 'active',
            'usage_bytes' => (int) (1 * 1024 * 1024 * 1024),  // 1 GB
            'meta' => [],
        ]);

        $config3 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_user_id' => 'test-user-3',
            'external_username' => 'test_user_3',
            'status' => 'active',
            'usage_bytes' => (int) (0.5 * 1024 * 1024 * 1024),  // 0.5 GB
            'meta' => [],
        ]);

        // Total: 2.5 GB * 780 = ~1950, balance becomes 500 - 1950 = -1450 (below -1000)

        // Mock HTTP
        Http::fake([
            'example.com/*' => Http::response(['success' => true], 200),
        ]);

        // Run the charge command
        $exitCode = Artisan::call('reseller:charge-wallet-hourly');

        // Refresh all models
        $reseller->refresh();
        $config1->refresh();
        $config2->refresh();
        $config3->refresh();

        // Assert reseller is suspended
        $this->assertEquals('suspended_wallet', $reseller->status, 'Reseller should be suspended');

        // Assert all configs are disabled
        $this->assertEquals('disabled', $config1->status, 'Config 1 should be disabled');
        $this->assertEquals('disabled', $config2->status, 'Config 2 should be disabled');
        $this->assertEquals('disabled', $config3->status, 'Config 3 should be disabled');

        // Assert all configs have proper metadata
        $this->assertTrue($config1->meta['disabled_by_wallet_suspension'] ?? false);
        $this->assertTrue($config2->meta['disabled_by_wallet_suspension'] ?? false);
        $this->assertTrue($config3->meta['disabled_by_wallet_suspension'] ?? false);

        // Assert events were created for all configs
        $this->assertEquals(3, ResellerConfigEvent::where('type', 'auto_disabled')->count(), 'Should have 3 config disable events');
    }
}
