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
}
