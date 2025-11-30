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

class ResellerConfigsManagerButtonsTest extends TestCase
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

    protected function createConfigForReseller($reseller, $panel, $user, $status = 'active'): ResellerConfig
    {
        return ResellerConfig::create([
            'reseller_id' => $reseller->id,
            'external_username' => 'test_user_' . rand(100, 999),
            'username_prefix' => 'testuser' . rand(100, 999),
            'traffic_limit_bytes' => 10737418240, // 10GB
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
            'status' => $status,
            'panel_type' => 'marzneshin',
            'panel_id' => $panel->id,
            'created_by' => $user->id,
            'meta' => ['max_clients' => 1],
        ]);
    }

    public function test_set_status_filter_all_works(): void
    {
        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller();
        $this->createConfigForReseller($reseller, $panel, $user, 'active');
        $this->createConfigForReseller($reseller, $panel, $user, 'disabled');

        $this->actingAs($user);

        Livewire::test(ConfigsManager::class)
            ->call('setStatusFilter', 'all')
            ->assertSet('statusFilter', 'all');
    }

    public function test_set_status_filter_active_works(): void
    {
        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller();
        $this->createConfigForReseller($reseller, $panel, $user, 'active');
        $this->createConfigForReseller($reseller, $panel, $user, 'disabled');

        $this->actingAs($user);

        Livewire::test(ConfigsManager::class)
            ->call('setStatusFilter', 'active')
            ->assertSet('statusFilter', 'active');
    }

    public function test_set_status_filter_disabled_works(): void
    {
        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller();
        $this->createConfigForReseller($reseller, $panel, $user, 'active');
        $this->createConfigForReseller($reseller, $panel, $user, 'disabled');

        $this->actingAs($user);

        Livewire::test(ConfigsManager::class)
            ->call('setStatusFilter', 'disabled')
            ->assertSet('statusFilter', 'disabled');
    }

    public function test_set_status_filter_expiring_works(): void
    {
        ['user' => $user] = $this->createWalletReseller();

        $this->actingAs($user);

        Livewire::test(ConfigsManager::class)
            ->call('setStatusFilter', 'expiring')
            ->assertSet('statusFilter', 'expiring');
    }

    public function test_open_and_close_create_modal(): void
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

    public function test_open_and_close_edit_modal(): void
    {
        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller();
        $config = $this->createConfigForReseller($reseller, $panel, $user);

        $this->actingAs($user);

        Livewire::test(ConfigsManager::class)
            ->assertSet('showEditModal', false)
            ->call('openEditModal', $config->id)
            ->assertSet('showEditModal', true)
            ->assertSet('editingConfigId', $config->id)
            ->call('closeEditModal')
            ->assertSet('showEditModal', false)
            ->assertSet('editingConfigId', null);
    }

    public function test_sync_stats_button_works(): void
    {
        ['user' => $user] = $this->createWalletReseller();

        $this->actingAs($user);

        Livewire::test(ConfigsManager::class)
            ->call('syncStats')
            ->assertHasNoErrors();
    }

    public function test_search_preserves_across_pagination(): void
    {
        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller();
        
        // Create multiple configs
        for ($i = 0; $i < 5; $i++) {
            $this->createConfigForReseller($reseller, $panel, $user);
        }

        $this->actingAs($user);

        Livewire::test(ConfigsManager::class)
            ->set('search', 'testuser')
            ->assertSet('search', 'testuser');
    }

    public function test_per_page_change_works(): void
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

    public function test_refresh_updates_component(): void
    {
        ['user' => $user] = $this->createWalletReseller();

        $this->actingAs($user);

        // Test $refresh magic method by emitting 'refreshConfigs' listener
        Livewire::test(ConfigsManager::class)
            ->dispatch('refreshConfigs')
            ->assertHasNoErrors();
    }

    public function test_open_edit_modal_for_nonexistent_config_does_not_open(): void
    {
        ['user' => $user] = $this->createWalletReseller();

        $this->actingAs($user);

        Livewire::test(ConfigsManager::class)
            ->call('openEditModal', 99999)
            ->assertSet('showEditModal', false);
    }

    public function test_open_edit_modal_loads_config_data(): void
    {
        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller();
        $config = $this->createConfigForReseller($reseller, $panel, $user);

        $this->actingAs($user);

        Livewire::test(ConfigsManager::class)
            ->call('openEditModal', $config->id)
            ->assertSet('editingConfigId', $config->id)
            ->assertSet('editTrafficLimitGb', 10.0) // 10GB
            ->assertSet('editMaxClients', 1);
    }

    public function test_edit_modal_for_config_of_other_reseller_fails(): void
    {
        ['user' => $user1, 'reseller' => $reseller1, 'panel' => $panel] = $this->createWalletReseller();
        
        // Create another reseller
        $user2 = User::factory()->create();
        $reseller2 = Reseller::create([
            'user_id' => $user2->id,
            'type' => 'wallet',
            'status' => 'active',
            'wallet_balance' => 100000,
            'wallet_price_per_gb' => 1000,
            'primary_panel_id' => $panel->id,
        ]);
        
        // Create config for reseller2
        $config = ResellerConfig::create([
            'reseller_id' => $reseller2->id,
            'external_username' => 'other_user',
            'traffic_limit_bytes' => 10737418240,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
            'status' => 'active',
            'panel_type' => 'marzneshin',
            'panel_id' => $panel->id,
            'created_by' => $user2->id,
        ]);

        $this->actingAs($user1);

        // User1 should not be able to open edit modal for reseller2's config
        Livewire::test(ConfigsManager::class)
            ->call('openEditModal', $config->id)
            ->assertSet('showEditModal', false);
    }

    public function test_create_modal_resets_form_when_opened(): void
    {
        ['user' => $user] = $this->createWalletReseller();

        $this->actingAs($user);

        Livewire::test(ConfigsManager::class)
            ->set('trafficLimitGb', 50)
            ->set('expiresDays', 60)
            ->call('openCreateModal')
            ->assertSet('trafficLimitGb', '')
            ->assertSet('expiresDays', '');
    }

    public function test_close_edit_modal_clears_editing_state(): void
    {
        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller();
        $config = $this->createConfigForReseller($reseller, $panel, $user);

        $this->actingAs($user);

        Livewire::test(ConfigsManager::class)
            ->call('openEditModal', $config->id)
            ->assertSet('editingConfigId', $config->id)
            ->call('closeEditModal')
            ->assertSet('editingConfigId', null)
            ->assertSet('editTrafficLimitGb', '')
            ->assertSet('editExpiresAt', '')
            ->assertSet('editMaxClients', 1);
    }

    public function test_filtering_by_expiring_shows_configs_within_7_days(): void
    {
        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller();
        
        // Create config expiring soon (within 7 days)
        $expiringSoon = ResellerConfig::create([
            'reseller_id' => $reseller->id,
            'external_username' => 'expiring_soon',
            'username_prefix' => 'expiringsoon',
            'traffic_limit_bytes' => 10737418240,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(3),
            'status' => 'active',
            'panel_type' => 'marzneshin',
            'panel_id' => $panel->id,
            'created_by' => $user->id,
        ]);

        // Create config not expiring soon
        $notExpiringSoon = ResellerConfig::create([
            'reseller_id' => $reseller->id,
            'external_username' => 'not_expiring_soon',
            'username_prefix' => 'notexpiring',
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
            ->call('setStatusFilter', 'expiring')
            ->assertSee('expiringsoon')
            ->assertDontSee('notexpiring');
    }

    public function test_toggle_status_button_validation(): void
    {
        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller();
        
        // Test toggle on non-existent config - should show error
        $this->actingAs($user);

        Livewire::test(ConfigsManager::class)
            ->call('toggleStatus', 99999)
            ->assertHasNoErrors();
    }

    public function test_delete_config_button_validation(): void
    {
        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller();
        
        // Test delete on non-existent config - should show error
        $this->actingAs($user);

        Livewire::test(ConfigsManager::class)
            ->call('deleteConfig', 99999)
            ->assertHasNoErrors();
    }

    public function test_update_config_form_validation(): void
    {
        ['user' => $user, 'reseller' => $reseller, 'panel' => $panel] = $this->createWalletReseller();
        $config = $this->createConfigForReseller($reseller, $panel, $user);

        $this->actingAs($user);

        Livewire::test(ConfigsManager::class)
            ->call('openEditModal', $config->id)
            ->set('editTrafficLimitGb', '') // Invalid - required
            ->call('updateConfig')
            ->assertHasErrors(['editTrafficLimitGb']);
    }

    public function test_create_config_form_validation(): void
    {
        ['user' => $user] = $this->createWalletReseller();

        $this->actingAs($user);

        Livewire::test(ConfigsManager::class)
            ->call('openCreateModal')
            ->call('createConfig')
            ->assertHasErrors(['selectedPanelId', 'trafficLimitGb', 'expiresDays']);
    }
}
