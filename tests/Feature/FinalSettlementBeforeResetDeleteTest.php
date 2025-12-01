<?php

namespace Tests\Feature;

use App\Livewire\Reseller\ConfigsManager;
use App\Models\BillingLedgerEntry;
use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\ResellerConfigEvent;
use App\Models\User;
use App\Services\Reseller\WalletChargingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Tests\TestCase;

class FinalSettlementBeforeResetDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function createWalletReseller(int $balance = 100000): array
    {
        $user = User::factory()->create();

        $panel = Panel::create([
            'name' => 'Test Panel',
            'panel_type' => 'marzneshin',
            'url' => 'https://test.example.com',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
        ]);

        $reseller = Reseller::create([
            'user_id' => $user->id,
            'type' => 'wallet',
            'status' => 'active',
            'wallet_balance' => $balance,
            'wallet_price_per_gb' => 1000,
            'primary_panel_id' => $panel->id,
        ]);

        $reseller->panels()->attach($panel->id);

        return compact('user', 'reseller', 'panel');
    }

    protected function createConfigWithUsage(Reseller $reseller, Panel $panel, User $user, int $usageBytes, int $chargedBytes = 0): ResellerConfig
    {
        $meta = [];
        if ($chargedBytes > 0) {
            $meta['charged_bytes'] = $chargedBytes;
        }

        return ResellerConfig::create([
            'reseller_id' => $reseller->id,
            'external_username' => 'test_user_' . uniqid(),
            'username_prefix' => 'testuser',
            'traffic_limit_bytes' => 10737418240, // 10GB
            'usage_bytes' => $usageBytes,
            'expires_at' => now()->addDays(30),
            'status' => 'active',
            'panel_type' => 'marzneshin',
            'panel_id' => $panel->id,
            'panel_user_id' => 'test_user_' . uniqid(),
            'created_by' => $user->id,
            'meta' => $meta,
        ]);
    }

    // ==================== Reset Traffic Settlement Tests ====================

    public function test_reset_traffic_settles_outstanding_usage_before_reset(): void
    {
        Config::set('billing.wallet.minimum_delta_bytes_to_charge', 0);

        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller(100000);

        // Create config with 5GB usage but 0 charged bytes (outstanding = 5GB)
        $config = $this->createConfigWithUsage($reseller, $panel, $user, 5 * 1024 * 1024 * 1024, 0);

        $this->actingAs($user);

        $initialBalance = $reseller->wallet_balance;

        Livewire::test(ConfigsManager::class)
            ->call('resetTraffic', $config->id);

        // Verify balance was charged (5GB at 1000 per GB = 5000 Toman)
        $reseller->refresh();
        $this->assertEquals($initialBalance - 5000, $reseller->wallet_balance);

        // Verify config usage was reset
        $config->refresh();
        $this->assertEquals(0, $config->usage_bytes);

        // Verify settled_usage_bytes was updated
        $this->assertEquals(5 * 1024 * 1024 * 1024, data_get($config->meta, 'settled_usage_bytes'));

        // Verify charged_bytes was reset to 0
        $this->assertEquals(0, data_get($config->meta, 'charged_bytes'));

        // Verify billing ledger entry was created
        $this->assertDatabaseHas('billing_ledger_entries', [
            'reseller_id' => $reseller->id,
            'reseller_config_id' => $config->id,
            'action_type' => 'reset_traffic',
        ]);
    }

    public function test_reset_traffic_only_charges_delta_not_already_charged(): void
    {
        Config::set('billing.wallet.minimum_delta_bytes_to_charge', 0);

        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller(100000);

        // Create config with 5GB usage
        $config = $this->createConfigWithUsage($reseller, $panel, $user, 5 * 1024 * 1024 * 1024, 0);

        // Simulate a prior snapshot recording 3GB already charged
        // This means the hourly command previously ran when usage was 3GB
        \App\Models\ResellerUsageSnapshot::create([
            'reseller_id' => $reseller->id,
            'total_bytes' => 3 * 1024 * 1024 * 1024, // 3GB was snapshotted
            'measured_at' => now()->subHour(),
            'meta' => ['cycle_charge_applied' => true],
        ]);

        $this->actingAs($user);

        $initialBalance = $reseller->wallet_balance;

        Livewire::test(ConfigsManager::class)
            ->call('resetTraffic', $config->id);

        // Verify only delta (2GB) was charged (5GB current - 3GB already snapshotted)
        $reseller->refresh();
        $this->assertEquals($initialBalance - 2000, $reseller->wallet_balance); // 2GB at 1000/GB = 2000

        // Verify billing ledger shows correct charged bytes
        $ledgerEntry = BillingLedgerEntry::where('reseller_config_id', $config->id)
            ->where('action_type', 'reset_traffic')
            ->first();

        $this->assertNotNull($ledgerEntry);
        $this->assertEquals(2 * 1024 * 1024 * 1024, $ledgerEntry->charged_bytes);
    }

    public function test_reset_traffic_no_charge_when_no_outstanding_usage(): void
    {
        Config::set('billing.wallet.minimum_delta_bytes_to_charge', 0);

        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller(100000);

        // Create config with 5GB usage
        $config = $this->createConfigWithUsage($reseller, $panel, $user, 5 * 1024 * 1024 * 1024, 0);

        // Simulate a prior snapshot that already recorded all 5GB
        // This means the hourly command already charged for all usage
        \App\Models\ResellerUsageSnapshot::create([
            'reseller_id' => $reseller->id,
            'total_bytes' => 5 * 1024 * 1024 * 1024, // All 5GB already snapshotted
            'measured_at' => now()->subHour(),
            'meta' => ['cycle_charge_applied' => true],
        ]);

        $this->actingAs($user);

        $initialBalance = $reseller->wallet_balance;

        Livewire::test(ConfigsManager::class)
            ->call('resetTraffic', $config->id);

        // Verify balance unchanged (no outstanding usage - already charged via snapshot)
        $reseller->refresh();
        $this->assertEquals($initialBalance, $reseller->wallet_balance);

        // Verify no billing ledger entry created for this action
        $this->assertEquals(0, BillingLedgerEntry::where('reseller_config_id', $config->id)
            ->where('action_type', 'reset_traffic')
            ->count());
    }

    public function test_reset_traffic_audit_event_includes_settlement_info(): void
    {
        Config::set('billing.wallet.minimum_delta_bytes_to_charge', 0);

        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller(100000);

        $config = $this->createConfigWithUsage($reseller, $panel, $user, 2 * 1024 * 1024 * 1024, 0);

        $this->actingAs($user);

        Livewire::test(ConfigsManager::class)
            ->call('resetTraffic', $config->id);

        // Verify audit event contains settlement information
        $event = ResellerConfigEvent::where('reseller_config_id', $config->id)
            ->where('type', 'traffic_reset')
            ->first();

        $this->assertNotNull($event);
        $this->assertEquals('charged', $event->meta['settlement_status']);
        $this->assertEquals(2000, $event->meta['settlement_cost']);
        $this->assertEquals(2 * 1024 * 1024 * 1024, $event->meta['settlement_charged_bytes']);
    }

    // ==================== Delete Config Settlement Tests ====================

    public function test_delete_config_settles_outstanding_usage_before_deletion(): void
    {
        Config::set('billing.wallet.minimum_delta_bytes_to_charge', 0);

        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller(100000);

        $config = $this->createConfigWithUsage($reseller, $panel, $user, 3 * 1024 * 1024 * 1024, 0);

        $this->actingAs($user);

        $initialBalance = $reseller->wallet_balance;

        Livewire::test(ConfigsManager::class)
            ->call('deleteConfig', $config->id);

        // Verify balance was charged
        $reseller->refresh();
        $this->assertEquals($initialBalance - 3000, $reseller->wallet_balance);

        // Verify config is soft-deleted
        $this->assertSoftDeleted('reseller_configs', ['id' => $config->id]);

        // Verify billing ledger entry was created
        $this->assertDatabaseHas('billing_ledger_entries', [
            'reseller_id' => $reseller->id,
            'reseller_config_id' => $config->id,
            'action_type' => 'delete_config',
        ]);
    }

    public function test_deleted_config_excluded_from_future_charges(): void
    {
        Config::set('billing.wallet.minimum_delta_bytes_to_charge', 0);

        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller(100000);

        // Create and delete a config with usage
        $deletedConfig = $this->createConfigWithUsage($reseller, $panel, $user, 2 * 1024 * 1024 * 1024, 0);
        $deletedConfig->update(['status' => 'deleted']);
        $deletedConfig->delete();

        // Create an active config
        $activeConfig = $this->createConfigWithUsage($reseller, $panel, $user, 1 * 1024 * 1024 * 1024, 0);

        // Calculate total usage - should only include active config
        $service = new WalletChargingService();
        $totalUsage = $service->calculateTotalUsageBytes($reseller);

        // Should only be 1GB from active config, not 3GB (2 deleted + 1 active)
        $this->assertEquals(1 * 1024 * 1024 * 1024, $totalUsage);
    }

    public function test_delete_config_preserves_usage_and_settlement_history(): void
    {
        Config::set('billing.wallet.minimum_delta_bytes_to_charge', 0);

        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller(100000);

        $config = $this->createConfigWithUsage($reseller, $panel, $user, 4 * 1024 * 1024 * 1024, 0);

        $this->actingAs($user);

        Livewire::test(ConfigsManager::class)
            ->call('deleteConfig', $config->id);

        // Verify the config (even after soft delete) has the usage preserved
        $config = ResellerConfig::withTrashed()->find($config->id);
        $this->assertNotNull($config);
        // Original usage should be preserved for historical reference
        $this->assertEquals(4 * 1024 * 1024 * 1024, $config->usage_bytes);
        // Settlement info should be in meta
        $this->assertNotNull(data_get($config->meta, 'last_settlement_at'));
        $this->assertEquals('delete_config', data_get($config->meta, 'last_settlement_action'));
    }

    // ==================== Service Tests ====================

    public function test_final_settlement_service_skips_non_wallet_resellers(): void
    {
        $user = User::factory()->create();

        $panel = Panel::create([
            'name' => 'Test Panel',
            'panel_type' => 'marzneshin',
            'url' => 'https://test.example.com',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
        ]);

        // Create traffic-based reseller (not wallet)
        $reseller = Reseller::create([
            'user_id' => $user->id,
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
            'primary_panel_id' => $panel->id,
        ]);

        $config = $this->createConfigWithUsage($reseller, $panel, $user, 5 * 1024 * 1024 * 1024, 0);

        $service = new WalletChargingService();
        $result = $service->finalSettlementForConfig($config, 'reset_traffic');

        $this->assertEquals('skipped', $result['status']);
        $this->assertEquals('not_wallet_type', $result['reason']);
        $this->assertFalse($result['charged']);
    }

    public function test_final_settlement_service_idempotency_guard(): void
    {
        Config::set('billing.wallet.minimum_delta_bytes_to_charge', 0);

        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller(100000);

        $config = $this->createConfigWithUsage($reseller, $panel, $user, 5 * 1024 * 1024 * 1024, 0);

        $service = new WalletChargingService();

        // First settlement should succeed
        $result1 = $service->finalSettlementForConfig($config, 'reset_traffic');
        $this->assertEquals('charged', $result1['status']);

        // Immediate second call should be skipped due to idempotency guard
        $result2 = $service->finalSettlementForConfig($config, 'reset_traffic');
        $this->assertEquals('skipped', $result2['status']);
        $this->assertEquals('idempotency_guard', $result2['reason']);

        // Verify only one charge was applied
        $reseller->refresh();
        $this->assertEquals(100000 - 5000, $reseller->wallet_balance);
    }

    public function test_final_settlement_creates_billing_ledger_entry(): void
    {
        Config::set('billing.wallet.minimum_delta_bytes_to_charge', 0);

        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller(50000);

        $config = $this->createConfigWithUsage($reseller, $panel, $user, 2 * 1024 * 1024 * 1024, 0);

        $service = new WalletChargingService();
        $result = $service->finalSettlementForConfig($config, 'delete_config');

        $this->assertEquals('charged', $result['status']);

        // Verify billing ledger entry details
        $ledgerEntry = BillingLedgerEntry::where('reseller_config_id', $config->id)->first();
        $this->assertNotNull($ledgerEntry);
        $this->assertEquals($reseller->id, $ledgerEntry->reseller_id);
        $this->assertEquals('delete_config', $ledgerEntry->action_type);
        $this->assertEquals(2 * 1024 * 1024 * 1024, $ledgerEntry->charged_bytes);
        $this->assertEquals(2000, $ledgerEntry->amount_charged);
        $this->assertEquals(1000, $ledgerEntry->price_per_gb);
        $this->assertEquals(50000, $ledgerEntry->wallet_balance_before);
        $this->assertEquals(48000, $ledgerEntry->wallet_balance_after);
    }

    // ==================== Hourly Command Independence Tests ====================

    public function test_hourly_command_works_regardless_of_username_pattern(): void
    {
        Config::set('billing.wallet.minimum_delta_bytes_to_charge', 0);

        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller(100000);

        // Create configs with various naming patterns
        ResellerConfig::create([
            'reseller_id' => $reseller->id,
            'external_username' => 'W_old_style_name',
            'username_prefix' => null, // Legacy
            'traffic_limit_bytes' => 10737418240,
            'usage_bytes' => 1 * 1024 * 1024 * 1024,
            'expires_at' => now()->addDays(30),
            'status' => 'active',
            'panel_type' => 'marzneshin',
            'panel_id' => $panel->id,
            'created_by' => $user->id,
        ]);

        ResellerConfig::create([
            'reseller_id' => $reseller->id,
            'external_username' => 'newuser_abc123_xyz',
            'username_prefix' => 'newuser',
            'panel_username' => 'newuser_abc123_xyz',
            'traffic_limit_bytes' => 10737418240,
            'usage_bytes' => 2 * 1024 * 1024 * 1024,
            'expires_at' => now()->addDays(30),
            'status' => 'active',
            'panel_type' => 'marzneshin',
            'panel_id' => $panel->id,
            'created_by' => $user->id,
        ]);

        // Verify total usage includes both config types
        $service = new WalletChargingService();
        $totalUsage = $service->calculateTotalUsageBytes($reseller);

        $this->assertEquals(3 * 1024 * 1024 * 1024, $totalUsage);

        // Verify charging works for both
        $result = $service->chargeForReseller($reseller);
        $this->assertEquals('charged', $result['status']);
        $this->assertEquals(3000, $result['cost']); // 3GB at 1000/GB
    }

    // ==================== Traffic-Based Reseller Tests ====================

    protected function createTrafficReseller(int $totalTrafficBytes = 100 * 1024 * 1024 * 1024): array
    {
        $user = User::factory()->create();

        $panel = Panel::create([
            'name' => 'Test Panel',
            'panel_type' => 'marzneshin',
            'url' => 'https://test.example.com',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
        ]);

        $reseller = Reseller::create([
            'user_id' => $user->id,
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => $totalTrafficBytes,
            'traffic_used_bytes' => 0,
            'primary_panel_id' => $panel->id,
        ]);

        $reseller->panels()->attach($panel->id);

        return compact('user', 'reseller', 'panel');
    }

    public function test_traffic_reseller_reset_preserves_usage_in_settled_bytes(): void
    {
        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createTrafficReseller();

        // Create config with 5GB usage
        $config = $this->createConfigWithUsage($reseller, $panel, $user, 5 * 1024 * 1024 * 1024, 0);

        $this->actingAs($user);

        Livewire::test(ConfigsManager::class)
            ->call('resetTraffic', $config->id);

        // Verify config usage was reset to 0
        $config->refresh();
        $this->assertEquals(0, $config->usage_bytes);

        // Verify settled_usage_bytes was updated
        $this->assertEquals(5 * 1024 * 1024 * 1024, data_get($config->meta, 'settled_usage_bytes'));

        // Verify total usage still includes settled bytes
        $this->assertEquals(5 * 1024 * 1024 * 1024, $config->getTotalUsageBytes());
    }

    public function test_traffic_reseller_total_usage_includes_reset_configs(): void
    {
        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createTrafficReseller();

        // Create config 1: currently has 3GB usage
        $config1 = $this->createConfigWithUsage($reseller, $panel, $user, 3 * 1024 * 1024 * 1024, 0);

        // Create config 2: has 2GB current + 4GB settled (was reset before)
        $config2Meta = ['settled_usage_bytes' => 4 * 1024 * 1024 * 1024];
        $config2 = ResellerConfig::create([
            'reseller_id' => $reseller->id,
            'external_username' => 'test_user_2',
            'username_prefix' => 'testuser2',
            'traffic_limit_bytes' => 10737418240,
            'usage_bytes' => 2 * 1024 * 1024 * 1024,
            'expires_at' => now()->addDays(30),
            'status' => 'active',
            'panel_type' => 'marzneshin',
            'panel_id' => $panel->id,
            'created_by' => $user->id,
            'meta' => $config2Meta,
        ]);

        // Total should be: 3GB (config1) + 2GB (config2 current) + 4GB (config2 settled) = 9GB
        $service = new WalletChargingService();
        $totalUsage = $service->calculateTotalUsageBytes($reseller);

        $this->assertEquals(9 * 1024 * 1024 * 1024, $totalUsage);
    }

    public function test_traffic_reseller_delete_preserves_usage_in_history(): void
    {
        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createTrafficReseller();

        // Create config with 4GB usage
        $config = $this->createConfigWithUsage($reseller, $panel, $user, 4 * 1024 * 1024 * 1024, 0);

        $this->actingAs($user);

        Livewire::test(ConfigsManager::class)
            ->call('deleteConfig', $config->id);

        // Verify config is soft-deleted
        $this->assertSoftDeleted('reseller_configs', ['id' => $config->id]);

        // Verify the soft-deleted config still has the usage recorded
        $deletedConfig = ResellerConfig::withTrashed()->find($config->id);
        $this->assertNotNull($deletedConfig);
        $this->assertEquals(4 * 1024 * 1024 * 1024, $deletedConfig->usage_bytes);
    }

    public function test_traffic_reseller_stats_include_settled_usage(): void
    {
        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createTrafficReseller(50 * 1024 * 1024 * 1024);

        // Create config with some current usage and settled usage
        $config = ResellerConfig::create([
            'reseller_id' => $reseller->id,
            'external_username' => 'test_user',
            'username_prefix' => 'testuser',
            'traffic_limit_bytes' => 10737418240,
            'usage_bytes' => 2 * 1024 * 1024 * 1024, // 2GB current
            'expires_at' => now()->addDays(30),
            'status' => 'active',
            'panel_type' => 'marzneshin',
            'panel_id' => $panel->id,
            'created_by' => $user->id,
            'meta' => ['settled_usage_bytes' => 3 * 1024 * 1024 * 1024], // 3GB settled
        ]);

        $this->actingAs($user);

        // Load the ConfigsManager which calculates stats
        $component = Livewire::test(ConfigsManager::class);

        // Verify the stats calculation includes both current and settled usage
        // Total consumed should be 2GB + 3GB = 5GB
        $stats = $component->get('stats');
        $this->assertEquals(5 * 1024 * 1024 * 1024, $stats['traffic_consumed_bytes']);
        $this->assertEquals(5.0, $stats['traffic_consumed_gb']);
    }

    public function test_traffic_reseller_multiple_resets_accumulate_settled_bytes(): void
    {
        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createTrafficReseller();

        // Create config with 2GB usage
        $config = $this->createConfigWithUsage($reseller, $panel, $user, 2 * 1024 * 1024 * 1024, 0);

        $this->actingAs($user);

        // First reset
        Livewire::test(ConfigsManager::class)
            ->call('resetTraffic', $config->id);

        $config->refresh();
        $this->assertEquals(0, $config->usage_bytes);
        $this->assertEquals(2 * 1024 * 1024 * 1024, data_get($config->meta, 'settled_usage_bytes'));

        // Simulate more usage (normally this would come from panel sync)
        $config->update(['usage_bytes' => 3 * 1024 * 1024 * 1024]);

        // Second reset
        Livewire::test(ConfigsManager::class)
            ->call('resetTraffic', $config->id);

        $config->refresh();
        $this->assertEquals(0, $config->usage_bytes);
        // Settled bytes should now be 2GB + 3GB = 5GB
        $this->assertEquals(5 * 1024 * 1024 * 1024, data_get($config->meta, 'settled_usage_bytes'));
    }

    // ==================== Wallet Balance Lifecycle Tests ====================

    public function test_wallet_balance_correctly_updated_through_usage_lifecycle(): void
    {
        Config::set('billing.wallet.minimum_delta_bytes_to_charge', 0);

        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller(100000);

        // Create config with 5GB usage
        $config = $this->createConfigWithUsage($reseller, $panel, $user, 5 * 1024 * 1024 * 1024, 0);

        $service = new WalletChargingService();

        // Step 1: First hourly charge - should charge 5GB = 5000 Toman
        $result1 = $service->chargeForReseller($reseller);
        $this->assertEquals('charged', $result1['status']);
        $this->assertEquals(5000, $result1['cost']);
        
        $reseller->refresh();
        $this->assertEquals(95000, $reseller->wallet_balance);

        // Step 2: Reset the config (should NOT charge again since already charged via snapshot)
        $this->actingAs($user);
        Livewire::test(ConfigsManager::class)
            ->call('resetTraffic', $config->id);

        $reseller->refresh();
        // Balance should still be 95000 (no new charge because usage was already charged via snapshot)
        $this->assertEquals(95000, $reseller->wallet_balance);

        // Step 3: Add new usage after reset (2GB)
        $config->refresh();
        $config->update(['usage_bytes' => 2 * 1024 * 1024 * 1024]);

        // Step 4: Hourly charge should now charge for the 2GB new usage
        $result2 = $service->chargeForReseller($reseller);
        $this->assertEquals('charged', $result2['status']);
        $this->assertEquals(2000, $result2['cost']); // 2GB at 1000/GB

        $reseller->refresh();
        $this->assertEquals(93000, $reseller->wallet_balance);
    }

    public function test_wallet_balance_charged_on_reset_when_no_prior_hourly_charge(): void
    {
        Config::set('billing.wallet.minimum_delta_bytes_to_charge', 0);

        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller(100000);

        // Create config with 5GB usage (no prior hourly charge)
        $config = $this->createConfigWithUsage($reseller, $panel, $user, 5 * 1024 * 1024 * 1024, 0);

        $this->actingAs($user);

        // Reset WITHOUT any prior hourly charge - should charge the 5GB
        Livewire::test(ConfigsManager::class)
            ->call('resetTraffic', $config->id);

        $reseller->refresh();
        // Should have charged 5GB = 5000 Toman
        $this->assertEquals(95000, $reseller->wallet_balance);

        // Verify billing ledger entry was created
        $this->assertDatabaseHas('billing_ledger_entries', [
            'reseller_id' => $reseller->id,
            'reseller_config_id' => $config->id,
            'action_type' => 'reset_traffic',
            'charged_bytes' => 5 * 1024 * 1024 * 1024,
            'amount_charged' => 5000,
        ]);
    }

    public function test_wallet_balance_no_double_charge_after_reset(): void
    {
        Config::set('billing.wallet.minimum_delta_bytes_to_charge', 0);

        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller(100000);

        // Create config with 5GB usage
        $config = $this->createConfigWithUsage($reseller, $panel, $user, 5 * 1024 * 1024 * 1024, 0);

        $this->actingAs($user);

        // Reset first (this will charge the 5GB)
        Livewire::test(ConfigsManager::class)
            ->call('resetTraffic', $config->id);

        $reseller->refresh();
        $this->assertEquals(95000, $reseller->wallet_balance);

        // Now run hourly charge - should NOT charge again (no new usage)
        $service = new WalletChargingService();
        $result = $service->chargeForReseller($reseller);

        // Should be skipped because total usage hasn't changed
        // (usage_bytes=0 + settled_usage_bytes=5GB = same total as before)
        $this->assertEquals('skipped', $result['status']);
        $this->assertEquals('no_usage_delta', $result['reason']);

        $reseller->refresh();
        $this->assertEquals(95000, $reseller->wallet_balance); // Unchanged
    }

    public function test_wallet_hourly_charge_after_settlement_charges_only_new_usage(): void
    {
        Config::set('billing.wallet.minimum_delta_bytes_to_charge', 0);

        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller(100000);

        // Create config with 3GB usage
        $config = $this->createConfigWithUsage($reseller, $panel, $user, 3 * 1024 * 1024 * 1024, 0);

        $this->actingAs($user);

        // Reset first (this will charge and settle 3GB)
        Livewire::test(ConfigsManager::class)
            ->call('resetTraffic', $config->id);

        $reseller->refresh();
        $this->assertEquals(97000, $reseller->wallet_balance); // 100000 - 3000

        // Simulate new usage (2GB)
        $config->refresh();
        $config->update(['usage_bytes' => 2 * 1024 * 1024 * 1024]);

        // Hourly charge should only charge for the 2GB new usage
        $service = new WalletChargingService();
        $result = $service->chargeForReseller($reseller);

        $this->assertEquals('charged', $result['status']);
        $this->assertEquals(2000, $result['cost']); // Only 2GB
        $this->assertEquals(2 * 1024 * 1024 * 1024, $result['delta_bytes']);

        $reseller->refresh();
        $this->assertEquals(95000, $reseller->wallet_balance); // 97000 - 2000
    }
}
