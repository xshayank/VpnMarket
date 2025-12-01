<?php

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create test panel
    $this->panel = Panel::factory()->create([
        'panel_type' => 'marzneshin',
        'is_active' => true,
    ]);
});

test('header component renders for wallet based reseller', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'active',
        'wallet_balance' => 100000,
        'primary_panel_id' => $this->panel->id,
        'panel_id' => $this->panel->id,
    ]);

    // Test header component directly by rendering view
    $view = $this->actingAs($user)->view('components.reseller.header', [
        'title' => 'کاربران',
        'subtitle' => null,
    ]);

    $view->assertSee('کاربران', false);
    $view->assertSee('داشبورد', false); // Dashboard link text
});

test('header component renders with correct page title from route', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'active',
        'wallet_balance' => 100000,
        'primary_panel_id' => $this->panel->id,
        'panel_id' => $this->panel->id,
    ]);

    // When no title provided, should auto-detect from route
    $view = $this->actingAs($user)->view('components.reseller.header', [
        'title' => null,
        'subtitle' => null,
    ]);

    // Should fall back to default title
    $view->assertSee('داشبورد ریسلر', false);
});

test('header component shows api keys link when api enabled', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'active',
        'wallet_balance' => 100000,
        'api_enabled' => true,
        'primary_panel_id' => $this->panel->id,
        'panel_id' => $this->panel->id,
    ]);

    $view = $this->actingAs($user)->view('components.reseller.header');

    // Should show API keys link when api_enabled is true
    $view->assertSee('کلیدهای API', false);
});

test('header component hides api keys link when api disabled', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'active',
        'wallet_balance' => 100000,
        'api_enabled' => false,
        'primary_panel_id' => $this->panel->id,
        'panel_id' => $this->panel->id,
    ]);

    $view = $this->actingAs($user)->view('components.reseller.header');

    // API keys link should not appear in desktop actions (aria-label="مدیریت کلیدهای API")
    $view->assertDontSee('مدیریت کلیدهای API', false);
});

test('header component has mobile menu toggle', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'active',
        'wallet_balance' => 100000,
        'primary_panel_id' => $this->panel->id,
        'panel_id' => $this->panel->id,
    ]);

    $view = $this->actingAs($user)->view('components.reseller.header');

    // Should have mobile menu toggle with Alpine.js
    $view->assertSee('mobileMenuOpen', false);
    $view->assertSee('منوی موبایل', false);
});

test('header component includes theme toggle', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'active',
        'wallet_balance' => 100000,
        'primary_panel_id' => $this->panel->id,
        'panel_id' => $this->panel->id,
    ]);

    $view = $this->actingAs($user)->view('components.reseller.header');

    // Should have theme toggle functionality
    $view->assertSee('تغییر تم', false);
    $view->assertSee('darkMode', false);
    $view->assertSee('localStorage.setItem', false);
});

test('header component includes logout button', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'active',
        'wallet_balance' => 100000,
        'primary_panel_id' => $this->panel->id,
        'panel_id' => $this->panel->id,
    ]);

    $view = $this->actingAs($user)->view('components.reseller.header');

    // Should have logout form
    $view->assertSee('خروج از حساب', false);
    $view->assertSee('logout', false);
});

test('header component includes settings link', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'active',
        'wallet_balance' => 100000,
        'primary_panel_id' => $this->panel->id,
        'panel_id' => $this->panel->id,
    ]);

    $view = $this->actingAs($user)->view('components.reseller.header');

    // Should have settings link
    $view->assertSee('تنظیمات', false);
});

test('header component has proper aria labels for accessibility', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'active',
        'wallet_balance' => 100000,
        'primary_panel_id' => $this->panel->id,
        'panel_id' => $this->panel->id,
    ]);

    $view = $this->actingAs($user)->view('components.reseller.header');

    // Should have aria labels
    $view->assertSee('aria-label', false);
    $view->assertSee('aria-hidden', false);
});

test('header component does not render for non-reseller', function () {
    $user = User::factory()->create();
    // No reseller record created

    $view = $this->actingAs($user)->view('components.reseller.header');

    // Component should render empty when no reseller
    $view->assertDontSee('بروزرسانی صفحه', false);
});

test('header component does not render for guest', function () {
    // Not logged in
    $view = $this->view('components.reseller.header');

    // Component should render empty when not authenticated
    $view->assertDontSee('بروزرسانی صفحه', false);
});

test('header component has correct sticky positioning classes', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'active',
        'wallet_balance' => 100000,
        'primary_panel_id' => $this->panel->id,
        'panel_id' => $this->panel->id,
    ]);

    $view = $this->actingAs($user)->view('components.reseller.header');

    // Should have sticky positioning
    $view->assertSee('sticky top-0 z-40', false);
});

test('header component has light background with shadow (marzban style)', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'active',
        'wallet_balance' => 100000,
        'primary_panel_id' => $this->panel->id,
        'panel_id' => $this->panel->id,
    ]);

    $view = $this->actingAs($user)->view('components.reseller.header');

    // Should have light background with shadow (Marzban style)
    $view->assertSee('bg-white dark:bg-gray-800', false);
    $view->assertSee('shadow-sm', false);
});

test('header shows reseller name in subtitle', function () {
    $user = User::factory()->create(['name' => 'تست ریسلر']);
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'active',
        'wallet_balance' => 100000,
        'primary_panel_id' => $this->panel->id,
        'panel_id' => $this->panel->id,
    ]);

    $view = $this->actingAs($user)->view('components.reseller.header');

    // Should show user name
    $view->assertSee('تست ریسلر', false);
});
