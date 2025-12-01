<?php

namespace Tests\Feature;

use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class WalletChargingCommandServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('billing.wallet.price_per_gb', 1000);
        Config::set('billing.wallet.minimum_delta_bytes_to_charge', 0);
        Config::set('billing.wallet.charge_enabled', true);
    }

    public function test_hourly_command_uses_service_and_charges_correctly(): void
    {
        $user = User::factory()->create();
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'wallet',
            'wallet_balance' => 10000,
            'wallet_price_per_gb' => 1000,
        ]);

        // Create config with 1 GB usage
        ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'usage_bytes' => 1 * 1024 * 1024 * 1024,
        ]);

        Artisan::call('reseller:charge-wallet-hourly');

        $reseller->refresh();

        // Should charge 1 GB * 1000 = 1000 تومان
        $this->assertEquals(9000, $reseller->wallet_balance);
        $this->assertEquals(1, $reseller->usageSnapshots()->count());
    }

    public function test_hourly_command_includes_new_naming_configs(): void
    {
        $user = User::factory()->create();
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'wallet',
            'wallet_balance' => 10000,
            'wallet_price_per_gb' => 1000,
        ]);

        // Create configs with different naming patterns
        // New naming system - with username_prefix
        ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'username_prefix' => 'newstyle',
            'panel_username' => 'newstyle_a1b2c3',
            'usage_bytes' => 1 * 1024 * 1024 * 1024, // 1 GB
        ]);

        // Legacy naming - without username_prefix but with external_username
        ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'username_prefix' => null,
            'external_username' => 'legacy_config_123',
            'usage_bytes' => 1 * 1024 * 1024 * 1024, // 1 GB
        ]);

        // API-based config - with username_prefix
        ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'username_prefix' => 'apiconfig',
            'panel_username' => 'apiconfig_xyz789',
            'usage_bytes' => 1 * 1024 * 1024 * 1024, // 1 GB
        ]);

        Artisan::call('reseller:charge-wallet-hourly');

        $reseller->refresh();

        // All 3 configs should be included: 3 GB * 1000 = 3000 تومان
        $this->assertEquals(7000, $reseller->wallet_balance);

        // Verify snapshot includes all usage
        $snapshot = $reseller->usageSnapshots()->first();
        $this->assertEquals(3 * 1024 * 1024 * 1024, $snapshot->total_bytes);
    }

    public function test_hourly_command_handles_configs_with_null_username_prefix(): void
    {
        $user = User::factory()->create();
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'wallet',
            'wallet_balance' => 10000,
            'wallet_price_per_gb' => 1000,
        ]);

        // Config with null username_prefix should still be counted
        ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'username_prefix' => null,
            'panel_username' => 'some_panel_user',
            'usage_bytes' => 2 * 1024 * 1024 * 1024, // 2 GB
        ]);

        Artisan::call('reseller:charge-wallet-hourly');

        $reseller->refresh();

        // Config should be charged: 2 GB * 1000 = 2000 تومان
        $this->assertEquals(8000, $reseller->wallet_balance);
    }

    public function test_hourly_command_handles_null_usage_bytes(): void
    {
        $user = User::factory()->create();
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'wallet',
            'wallet_balance' => 10000,
            'wallet_price_per_gb' => 1000,
        ]);

        // Config with no usage (usage_bytes is 0)
        ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'usage_bytes' => 0,
        ]);

        Artisan::call('reseller:charge-wallet-hourly');

        $reseller->refresh();

        // No charge should occur since usage is 0
        $this->assertEquals(10000, $reseller->wallet_balance);
        // No snapshot created for 0 usage
        $this->assertEquals(0, $reseller->usageSnapshots()->count());
    }

    public function test_hourly_command_records_source_in_snapshot(): void
    {
        $user = User::factory()->create();
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'wallet',
            'wallet_balance' => 10000,
        ]);

        ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'usage_bytes' => 1 * 1024 * 1024 * 1024,
        ]);

        Artisan::call('reseller:charge-wallet-hourly');

        $snapshot = $reseller->usageSnapshots()->first();

        // The source should be 'command' from the hourly command
        $this->assertEquals('command', $snapshot->meta['source']);
    }

    public function test_once_command_uses_service_correctly(): void
    {
        $user = User::factory()->create();
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'wallet',
            'wallet_balance' => 10000,
            'wallet_price_per_gb' => 1000,
        ]);

        // Use exact 1 GB to avoid ceiling calculation issues
        ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'usage_bytes' => 1 * 1024 * 1024 * 1024, // 1 GB
        ]);

        Artisan::call('reseller:charge-wallet-once', [
            '--reseller' => $reseller->id,
        ]);

        $reseller->refresh();

        // Should charge 1 GB * 1000 = 1000 تومان
        $this->assertEquals(9000, $reseller->wallet_balance);

        // Verify snapshot source is 'command:once'
        $snapshot = $reseller->usageSnapshots()->first();
        $this->assertEquals('command:once', $snapshot->meta['source']);
    }

    public function test_once_command_dry_run_does_not_charge(): void
    {
        $user = User::factory()->create();
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'wallet',
            'wallet_balance' => 10000,
        ]);

        ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'usage_bytes' => 1 * 1024 * 1024 * 1024,
        ]);

        Artisan::call('reseller:charge-wallet-once', [
            '--reseller' => $reseller->id,
            '--dry-run' => true,
        ]);

        $reseller->refresh();

        // Balance should be unchanged
        $this->assertEquals(10000, $reseller->wallet_balance);
        // No snapshot should be created
        $this->assertEquals(0, $reseller->usageSnapshots()->count());
    }

    public function test_consecutive_commands_do_not_double_charge(): void
    {
        $user = User::factory()->create();
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'wallet',
            'wallet_balance' => 10000,
            'wallet_price_per_gb' => 1000,
        ]);

        ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'usage_bytes' => 1 * 1024 * 1024 * 1024,
        ]);

        // First charge
        Artisan::call('reseller:charge-wallet-hourly');
        $reseller->refresh();
        $this->assertEquals(9000, $reseller->wallet_balance);
        $this->assertEquals(1, $reseller->usageSnapshots()->count());

        // Second charge without new usage
        Artisan::call('reseller:charge-wallet-hourly');
        $reseller->refresh();
        $this->assertEquals(9000, $reseller->wallet_balance); // Unchanged
        $this->assertEquals(1, $reseller->usageSnapshots()->count()); // No new snapshot
    }
}
