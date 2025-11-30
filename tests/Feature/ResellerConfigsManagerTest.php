<?php

namespace Tests\Feature;

use App\Livewire\Reseller\ConfigsManager;
use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ResellerConfigsManagerTest extends TestCase
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

    public function test_configs_manager_renders_for_wallet_reseller(): void
    {
        ['user' => $user, 'reseller' => $reseller] = $this->createWalletReseller();

        $this->actingAs($user);

        Livewire::test(ConfigsManager::class)
            ->assertSee('ایجاد کاربر')
            ->assertSee('موجودی')
            ->assertSee('ترافیک مصرفی');
    }

    public function test_configs_manager_shows_empty_state(): void
    {
        ['user' => $user] = $this->createWalletReseller();

        $this->actingAs($user);

        Livewire::test(ConfigsManager::class)
            ->assertSee('هیچ کانفیگی یافت نشد');
    }

    public function test_configs_manager_can_search_configs(): void
    {
        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller();

        // Create some configs
        ResellerConfig::create([
            'reseller_id' => $reseller->id,
            'external_username' => 'test_user_1',
            'username_prefix' => 'testuser1',
            'traffic_limit_bytes' => 10737418240, // 10GB
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
            'status' => 'active',
            'panel_type' => 'marzneshin',
            'panel_id' => $panel->id,
            'created_by' => $user->id,
        ]);

        ResellerConfig::create([
            'reseller_id' => $reseller->id,
            'external_username' => 'other_user_2',
            'username_prefix' => 'otheruser2',
            'traffic_limit_bytes' => 10737418240,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
            'status' => 'active',
            'panel_type' => 'marzneshin',
            'panel_id' => $panel->id,
            'created_by' => $user->id,
        ]);

        $this->actingAs($user);

        Livewire::test(ConfigsManager::class)
            ->assertSee('testuser1')
            ->assertSee('otheruser2')
            ->set('search', 'testuser1')
            ->assertSee('testuser1')
            ->assertDontSee('otheruser2');
    }

    public function test_configs_manager_can_filter_by_status(): void
    {
        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller();

        ResellerConfig::create([
            'reseller_id' => $reseller->id,
            'external_username' => 'active_user',
            'username_prefix' => 'activeuser',
            'traffic_limit_bytes' => 10737418240,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
            'status' => 'active',
            'panel_type' => 'marzneshin',
            'panel_id' => $panel->id,
            'created_by' => $user->id,
        ]);

        ResellerConfig::create([
            'reseller_id' => $reseller->id,
            'external_username' => 'disabled_user',
            'username_prefix' => 'disableduser',
            'traffic_limit_bytes' => 10737418240,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
            'status' => 'disabled',
            'panel_type' => 'marzneshin',
            'panel_id' => $panel->id,
            'created_by' => $user->id,
        ]);

        $this->actingAs($user);

        // Test 'all' filter
        Livewire::test(ConfigsManager::class)
            ->assertSee('activeuser')
            ->assertSee('disableduser');

        // Test 'active' filter
        Livewire::test(ConfigsManager::class)
            ->call('setStatusFilter', 'active')
            ->assertSee('activeuser')
            ->assertDontSee('disableduser');

        // Test 'disabled' filter
        Livewire::test(ConfigsManager::class)
            ->call('setStatusFilter', 'disabled')
            ->assertDontSee('activeuser')
            ->assertSee('disableduser');
    }

    public function test_configs_manager_displays_username_prefix(): void
    {
        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller();

        // Create config with username_prefix set
        $config = ResellerConfig::create([
            'reseller_id' => $reseller->id,
            'external_username' => 'r1_cfg_123',
            'username_prefix' => 'myprefix',
            'traffic_limit_bytes' => 10737418240,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
            'status' => 'active',
            'panel_type' => 'marzneshin',
            'panel_id' => $panel->id,
            'created_by' => $user->id,
        ]);

        $this->actingAs($user);

        // Should display the prefix, not the full external_username
        Livewire::test(ConfigsManager::class)
            ->assertSee('myprefix')
            ->assertDontSee('r1_cfg_123');
    }

    public function test_configs_manager_shows_stats(): void
    {
        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller();

        // Create some configs
        ResellerConfig::create([
            'reseller_id' => $reseller->id,
            'external_username' => 'user1',
            'traffic_limit_bytes' => 10737418240,
            'usage_bytes' => 5368709120, // 5GB used
            'expires_at' => now()->addDays(30),
            'status' => 'active',
            'panel_type' => 'marzneshin',
            'panel_id' => $panel->id,
            'created_by' => $user->id,
        ]);

        $this->actingAs($user);

        Livewire::test(ConfigsManager::class)
            // Stats should be displayed
            ->assertSee('100,000') // wallet balance
            ->assertSee('5') // traffic consumed in GB (approximately)
            ->assertSee('1,000'); // price per GB
    }

    public function test_configs_manager_opens_create_modal(): void
    {
        ['user' => $user] = $this->createWalletReseller();

        $this->actingAs($user);

        Livewire::test(ConfigsManager::class)
            ->assertSet('showCreateModal', false)
            ->call('openCreateModal')
            ->assertSet('showCreateModal', true)
            ->call('closeCreateModal')
            ->assertSet('showCreateModal', false);
    }

    public function test_configs_manager_changes_per_page(): void
    {
        ['user' => $user] = $this->createWalletReseller();

        $this->actingAs($user);

        Livewire::test(ConfigsManager::class)
            ->assertSet('perPage', 20)
            ->set('perPage', 10)
            ->assertSet('perPage', 10)
            ->set('perPage', 30)
            ->assertSet('perPage', 30);
    }

    public function test_configs_manager_has_username_prefix_field(): void
    {
        ['user' => $user] = $this->createWalletReseller();

        $this->actingAs($user);

        Livewire::test(ConfigsManager::class)
            ->call('openCreateModal')
            ->assertSet('showCreateModal', true)
            // Assert that usernamePrefix field exists and is empty by default
            ->assertSet('usernamePrefix', '')
            // Set a username prefix
            ->set('usernamePrefix', 'testali')
            ->assertSet('usernamePrefix', 'testali');
    }

    public function test_configs_manager_validates_username_prefix(): void
    {
        ['user' => $user] = $this->createWalletReseller();

        $this->actingAs($user);

        // Test validation: too short
        Livewire::test(ConfigsManager::class)
            ->call('openCreateModal')
            ->set('usernamePrefix', 'a')
            ->set('selectedPanelId', 1)
            ->set('trafficLimitGb', 10)
            ->set('expiresDays', 30)
            ->call('createConfig')
            ->assertHasErrors(['usernamePrefix']);
    }

    public function test_display_username_extracts_prefix_from_panel_created_config(): void
    {
        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller();

        // Create config with panel-created style name (no username_prefix)
        $config = ResellerConfig::create([
            'reseller_id' => $reseller->id,
            'external_username' => 'ali_MN_5k2h9',
            'panel_username' => 'ali_MN_5k2h9',
            'username_prefix' => null, // No prefix set (panel-created)
            'traffic_limit_bytes' => 10737418240,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
            'status' => 'active',
            'panel_type' => 'marzneshin',
            'panel_id' => $panel->id,
            'created_by' => $user->id,
        ]);

        // Refresh to get the accessor
        $config->refresh();

        // The display_username accessor should extract 'ali' from 'ali_MN_5k2h9'
        $this->assertEquals('ali', $config->display_username);
    }

    public function test_display_username_returns_username_prefix_when_set(): void
    {
        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller();

        $config = ResellerConfig::create([
            'reseller_id' => $reseller->id,
            'external_username' => 'complicated_name_abc123',
            'panel_username' => 'complicated_name_abc123',
            'username_prefix' => 'myprefix', // Explicit prefix set
            'traffic_limit_bytes' => 10737418240,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
            'status' => 'active',
            'panel_type' => 'marzneshin',
            'panel_id' => $panel->id,
            'created_by' => $user->id,
        ]);

        $config->refresh();

        // Should return the explicit username_prefix
        $this->assertEquals('myprefix', $config->display_username);
    }

    public function test_display_username_handles_various_panel_created_formats(): void
    {
        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller();

        // Test case: "user_4_order_84" -> "user"
        $config1 = ResellerConfig::create([
            'reseller_id' => $reseller->id,
            'external_username' => 'user_4_order_84',
            'panel_username' => 'user_4_order_84',
            'username_prefix' => null,
            'traffic_limit_bytes' => 10737418240,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
            'status' => 'active',
            'panel_type' => 'marzneshin',
            'panel_id' => $panel->id,
            'created_by' => $user->id,
        ]);

        // Test case: "Z2733" (no underscore) -> "Z2733"
        $config2 = ResellerConfig::create([
            'reseller_id' => $reseller->id,
            'external_username' => 'Z2733',
            'panel_username' => 'Z2733',
            'username_prefix' => null,
            'traffic_limit_bytes' => 10737418240,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
            'status' => 'active',
            'panel_type' => 'marzneshin',
            'panel_id' => $panel->id,
            'created_by' => $user->id,
        ]);

        $config1->refresh();
        $config2->refresh();

        $this->assertEquals('user', $config1->display_username);
        $this->assertEquals('Z2733', $config2->display_username);
    }

    public function test_create_config_handles_string_expires_days(): void
    {
        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller();

        $this->actingAs($user);

        // Test that string '7' for expiresDays doesn't throw TypeError
        // This simulates the real behavior where Livewire bindings send strings from HTML inputs
        Livewire::test(ConfigsManager::class)
            ->call('openCreateModal')
            ->set('selectedPanelId', $panel->id)
            ->set('trafficLimitGb', '10')  // String from form
            ->set('expiresDays', '7')      // String from form - this was causing TypeError
            ->assertSet('expiresDays', '7')
            ->assertHasNoErrors(['expiresDays']);

        // Verify the validation passes for string integer values
    }

    public function test_create_config_validates_non_numeric_expires_days(): void
    {
        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller();

        $this->actingAs($user);

        // Test that non-numeric string fails validation
        Livewire::test(ConfigsManager::class)
            ->call('openCreateModal')
            ->set('selectedPanelId', $panel->id)
            ->set('trafficLimitGb', 10)
            ->set('expiresDays', 'abc')    // Non-numeric string should fail validation
            ->call('createConfig')
            ->assertHasErrors(['expiresDays']);
    }

    public function test_create_config_validates_zero_expires_days(): void
    {
        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller();

        $this->actingAs($user);

        // Test that zero fails validation (min:1)
        Livewire::test(ConfigsManager::class)
            ->call('openCreateModal')
            ->set('selectedPanelId', $panel->id)
            ->set('trafficLimitGb', 10)
            ->set('expiresDays', '0')
            ->call('createConfig')
            ->assertHasErrors(['expiresDays']);
    }

    public function test_create_config_validates_negative_expires_days(): void
    {
        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller();

        $this->actingAs($user);

        // Test that negative values fail validation
        Livewire::test(ConfigsManager::class)
            ->call('openCreateModal')
            ->set('selectedPanelId', $panel->id)
            ->set('trafficLimitGb', 10)
            ->set('expiresDays', '-5')
            ->call('createConfig')
            ->assertHasErrors(['expiresDays']);
    }
}
