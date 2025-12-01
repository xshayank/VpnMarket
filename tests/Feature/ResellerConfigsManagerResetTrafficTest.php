<?php

namespace Tests\Feature;

use App\Livewire\Reseller\ConfigsManager;
use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\ResellerConfigEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ResellerConfigsManagerResetTrafficTest extends TestCase
{
    use RefreshDatabase;

    protected function createWalletReseller(): array
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
            'wallet_balance' => 100000,
            'wallet_price_per_gb' => 1000,
            'primary_panel_id' => $panel->id,
        ]);

        // Attach panel to reseller
        $reseller->panels()->attach($panel->id);

        return compact('user', 'reseller', 'panel');
    }

    public function test_reset_traffic_zeros_out_usage(): void
    {
        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller();

        // Create config with some usage
        $config = ResellerConfig::create([
            'reseller_id' => $reseller->id,
            'external_username' => 'test_user',
            'username_prefix' => 'testuser',
            'traffic_limit_bytes' => 10737418240, // 10GB
            'usage_bytes' => 5368709120, // 5GB used
            'expires_at' => now()->addDays(30),
            'status' => 'active',
            'panel_type' => 'marzneshin',
            'panel_id' => $panel->id,
            'panel_user_id' => 'test_user',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user);

        // Verify usage before reset
        $this->assertEquals(5368709120, $config->usage_bytes);

        // Call reset traffic
        Livewire::test(ConfigsManager::class)
            ->call('resetTraffic', $config->id);

        // Verify usage is zeroed
        $config->refresh();
        $this->assertEquals(0, $config->usage_bytes);
    }

    public function test_reset_traffic_creates_audit_event(): void
    {
        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller();

        $config = ResellerConfig::create([
            'reseller_id' => $reseller->id,
            'external_username' => 'test_user',
            'username_prefix' => 'testuser',
            'traffic_limit_bytes' => 10737418240,
            'usage_bytes' => 5368709120,
            'expires_at' => now()->addDays(30),
            'status' => 'active',
            'panel_type' => 'marzneshin',
            'panel_id' => $panel->id,
            'panel_user_id' => 'test_user',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user);

        Livewire::test(ConfigsManager::class)
            ->call('resetTraffic', $config->id);

        // Verify audit event was created
        $this->assertDatabaseHas('reseller_config_events', [
            'reseller_config_id' => $config->id,
            'type' => 'traffic_reset',
        ]);

        // Check the meta contains the old usage
        $event = ResellerConfigEvent::where('reseller_config_id', $config->id)
            ->where('type', 'traffic_reset')
            ->first();

        $this->assertNotNull($event);
        $this->assertEquals(5368709120, $event->meta['old_usage_bytes']);
        $this->assertEquals($user->id, $event->meta['user_id']);
    }

    public function test_reset_traffic_requires_config_ownership(): void
    {
        ['user' => $user1, 'reseller' => $reseller1, 'panel' => $panel] = $this->createWalletReseller();

        // Create another user with their own reseller
        $user2 = User::factory()->create();
        $reseller2 = Reseller::create([
            'user_id' => $user2->id,
            'type' => 'wallet',
            'status' => 'active',
            'wallet_balance' => 50000,
            'wallet_price_per_gb' => 1000,
            'primary_panel_id' => $panel->id,
        ]);
        $reseller2->panels()->attach($panel->id);

        // Create config for reseller2
        $config = ResellerConfig::create([
            'reseller_id' => $reseller2->id,
            'external_username' => 'other_user',
            'username_prefix' => 'otheruser',
            'traffic_limit_bytes' => 10737418240,
            'usage_bytes' => 5368709120,
            'expires_at' => now()->addDays(30),
            'status' => 'active',
            'panel_type' => 'marzneshin',
            'panel_id' => $panel->id,
            'panel_user_id' => 'other_user',
            'created_by' => $user2->id,
        ]);

        // Try to reset traffic as user1 (not the owner)
        $this->actingAs($user1);

        Livewire::test(ConfigsManager::class)
            ->call('resetTraffic', $config->id)
            ->assertSee('کانفیگ یافت نشد');

        // Verify usage was NOT reset
        $config->refresh();
        $this->assertEquals(5368709120, $config->usage_bytes);
    }

    public function test_reset_traffic_shows_success_message(): void
    {
        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller();

        $config = ResellerConfig::create([
            'reseller_id' => $reseller->id,
            'external_username' => 'test_user',
            'username_prefix' => 'testuser',
            'traffic_limit_bytes' => 10737418240,
            'usage_bytes' => 5368709120,
            'expires_at' => now()->addDays(30),
            'status' => 'active',
            'panel_type' => 'marzneshin',
            'panel_id' => $panel->id,
            'panel_user_id' => 'test_user',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user);

        Livewire::test(ConfigsManager::class)
            ->call('resetTraffic', $config->id)
            ->assertSee('ترافیک کاربر با موفقیت صفر شد');
    }

    public function test_reset_traffic_for_nonexistent_config(): void
    {
        ['user' => $user] = $this->createWalletReseller();

        $this->actingAs($user);

        Livewire::test(ConfigsManager::class)
            ->call('resetTraffic', 99999)
            ->assertSee('کانفیگ یافت نشد');
    }

    public function test_reset_traffic_preserves_other_config_fields(): void
    {
        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller();

        $expiresAt = now()->addDays(30);
        $config = ResellerConfig::create([
            'reseller_id' => $reseller->id,
            'external_username' => 'test_user',
            'username_prefix' => 'testuser',
            'traffic_limit_bytes' => 10737418240,
            'usage_bytes' => 5368709120,
            'expires_at' => $expiresAt,
            'status' => 'active',
            'panel_type' => 'marzneshin',
            'panel_id' => $panel->id,
            'panel_user_id' => 'test_user',
            'subscription_url' => 'https://example.com/sub/test',
            'comment' => 'Test comment',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user);

        Livewire::test(ConfigsManager::class)
            ->call('resetTraffic', $config->id);

        $config->refresh();

        // Verify usage is zeroed
        $this->assertEquals(0, $config->usage_bytes);

        // Verify other fields are preserved
        $this->assertEquals(10737418240, $config->traffic_limit_bytes);
        $this->assertEquals('active', $config->status);
        $this->assertEquals('test_user', $config->external_username);
        $this->assertEquals('https://example.com/sub/test', $config->subscription_url);
        $this->assertEquals('Test comment', $config->comment);
    }
}
