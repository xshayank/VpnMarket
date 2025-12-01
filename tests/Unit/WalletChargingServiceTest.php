<?php

namespace Tests\Unit;

use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\ResellerUsageSnapshot;
use App\Models\User;
use App\Services\Reseller\WalletChargingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class WalletChargingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected WalletChargingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WalletChargingService;
    }

    public function test_service_charges_correct_amount_for_traffic_delta(): void
    {
        Config::set('billing.wallet.price_per_gb', 1000);
        Config::set('billing.wallet.minimum_delta_bytes_to_charge', 0);

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

        $result = $this->service->chargeForReseller($reseller);

        $this->assertEquals('charged', $result['status']);
        $this->assertTrue($result['charged']);
        $this->assertEquals(1000, $result['cost']); // 1 GB * 1000 تومان
        $this->assertEquals(9000, $result['new_balance']);

        $reseller->refresh();
        $this->assertEquals(9000, $reseller->wallet_balance);
    }

    public function test_service_creates_snapshot_with_metadata(): void
    {
        Config::set('billing.wallet.price_per_gb', 1000);
        Config::set('billing.wallet.minimum_delta_bytes_to_charge', 0);

        $user = User::factory()->create();
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'wallet',
            'wallet_balance' => 10000,
            'wallet_price_per_gb' => 1000,
        ]);

        ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'usage_bytes' => 2 * 1024 * 1024 * 1024, // 2 GB
        ]);

        $result = $this->service->chargeForReseller($reseller, null, false, 'test');

        $this->assertArrayHasKey('snapshot_id', $result);

        $snapshot = ResellerUsageSnapshot::find($result['snapshot_id']);
        $this->assertNotNull($snapshot);
        $this->assertTrue($snapshot->meta['cycle_charge_applied']);
        $this->assertEquals(2 * 1024 * 1024 * 1024, $snapshot->meta['delta_bytes']);
        $this->assertEquals('test', $snapshot->meta['source']);
    }

    public function test_service_skips_non_wallet_resellers(): void
    {
        $user = User::factory()->create();
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'traffic',
            'wallet_balance' => 10000,
        ]);

        ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'usage_bytes' => 1 * 1024 * 1024 * 1024,
        ]);

        $result = $this->service->chargeForReseller($reseller);

        $this->assertEquals('skipped', $result['status']);
        $this->assertEquals('not_wallet_type', $result['reason']);
        $this->assertFalse($result['charged']);
    }

    public function test_service_skips_when_no_usage_delta(): void
    {
        Config::set('billing.wallet.price_per_gb', 1000);
        Config::set('billing.wallet.minimum_delta_bytes_to_charge', 0);

        $user = User::factory()->create();
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'wallet',
            'wallet_balance' => 10000,
        ]);

        // Create config with 1 GB usage
        ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'usage_bytes' => 1 * 1024 * 1024 * 1024,
        ]);

        // First charge
        $result1 = $this->service->chargeForReseller($reseller);
        $this->assertEquals('charged', $result1['status']);

        // Second charge (no new usage)
        $result2 = $this->service->chargeForReseller($reseller);
        $this->assertEquals('skipped', $result2['status']);
        $this->assertEquals('no_usage_delta', $result2['reason']);
    }

    public function test_service_handles_first_charge_correctly(): void
    {
        Config::set('billing.wallet.price_per_gb', 1000);
        Config::set('billing.wallet.minimum_delta_bytes_to_charge', 0);

        $user = User::factory()->create();
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'wallet',
            'wallet_balance' => 10000,
            'wallet_price_per_gb' => 1000,
        ]);

        // No prior snapshots - first charge should use all current usage
        ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'usage_bytes' => 3 * 1024 * 1024 * 1024, // 3 GB
        ]);

        $result = $this->service->chargeForReseller($reseller);

        $this->assertEquals('charged', $result['status']);
        $this->assertEquals(3 * 1024 * 1024 * 1024, $result['delta_bytes']);
        $this->assertEquals(3000, $result['cost']); // 3 GB * 1000 = 3000 تومان

        // Verify snapshot was created
        $this->assertEquals(1, $reseller->usageSnapshots()->count());
    }

    public function test_service_includes_settled_usage_bytes(): void
    {
        Config::set('billing.wallet.price_per_gb', 1000);
        Config::set('billing.wallet.minimum_delta_bytes_to_charge', 0);

        $user = User::factory()->create();
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'wallet',
            'wallet_balance' => 10000,
            'wallet_price_per_gb' => 1000,
        ]);

        // Create config with current usage + settled usage from a previous reset
        ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'usage_bytes' => 1 * 1024 * 1024 * 1024, // 1 GB current
            'meta' => [
                'settled_usage_bytes' => 2 * 1024 * 1024 * 1024, // 2 GB settled
            ],
        ]);

        $result = $this->service->chargeForReseller($reseller);

        $this->assertEquals('charged', $result['status']);
        // Total should be 3 GB (1 current + 2 settled)
        $this->assertEquals(3 * 1024 * 1024 * 1024, $result['delta_bytes']);
        $this->assertEquals(3000, $result['cost']);
    }

    public function test_service_dry_run_does_not_charge(): void
    {
        Config::set('billing.wallet.price_per_gb', 1000);
        Config::set('billing.wallet.minimum_delta_bytes_to_charge', 0);

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

        $result = $this->service->chargeForReseller($reseller, null, true);

        $this->assertEquals('dry_run', $result['status']);
        $this->assertFalse($result['charged']);

        $reseller->refresh();
        $this->assertEquals(10000, $reseller->wallet_balance); // Unchanged
        $this->assertEquals(0, $reseller->usageSnapshots()->count()); // No snapshot
    }

    public function test_charge_from_panel_logs_source_correctly(): void
    {
        Config::set('billing.wallet.price_per_gb', 1000);
        Config::set('billing.wallet.minimum_delta_bytes_to_charge', 0);

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

        $result = $this->service->chargeFromPanel($reseller, 'edit_config');

        $this->assertEquals('charged', $result['status']);

        $snapshot = $reseller->usageSnapshots()->first();
        $this->assertNotNull($snapshot);
        $this->assertEquals('panel:edit_config', $snapshot->meta['source']);
    }

    public function test_service_calculates_total_usage_correctly(): void
    {
        $user = User::factory()->create();
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'wallet',
        ]);

        // Create multiple configs
        ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'usage_bytes' => 1 * 1024 * 1024 * 1024, // 1 GB
        ]);

        ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'usage_bytes' => 2 * 1024 * 1024 * 1024, // 2 GB
            'meta' => ['settled_usage_bytes' => 500 * 1024 * 1024], // 0.5 GB
        ]);

        $totalBytes = $this->service->calculateTotalUsageBytes($reseller);

        // Total: 1 GB + 2 GB + 0.5 GB = 3.5 GB
        $expected = (1 * 1024 * 1024 * 1024) + (2 * 1024 * 1024 * 1024) + (500 * 1024 * 1024);
        $this->assertEquals($expected, $totalBytes);
    }

    public function test_service_skips_when_charging_disabled(): void
    {
        Config::set('billing.wallet.charge_enabled', false);

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

        $result = $this->service->chargeFromPanel($reseller, 'test_action');

        $this->assertEquals('skipped', $result['status']);
        $this->assertEquals('charging_disabled', $result['reason']);
    }
}
