<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('theme scripts component contains localStorage initialization', function () {
    $view = $this->view('components.theme-scripts');
    
    $view->assertSee('localStorage.getItem', false);
    $view->assertSee('localStorage.setItem', false);
});

test('theme scripts component contains system preference detection', function () {
    $view = $this->view('components.theme-scripts');
    
    $view->assertSee('prefers-color-scheme: dark', false);
    $view->assertSee('matchMedia', false);
});

test('theme scripts component contains cookie fallback', function () {
    $view = $this->view('components.theme-scripts');
    
    $view->assertSee('document.cookie', false);
});

test('theme scripts component applies dark class to document', function () {
    $view = $this->view('components.theme-scripts');
    
    $view->assertSee('document.documentElement.classList.add(\'dark\')', false);
    $view->assertSee('document.documentElement.classList.remove(\'dark\')', false);
});

test('theme scripts component renders inline script', function () {
    $view = $this->view('components.theme-scripts');
    
    $view->assertSee('<script>', false);
    $view->assertSee('</script>', false);
});

test('guest layout includes theme scripts for default theme', function () {
    // Create the settings variable that the guest layout expects
    $settings = collect(['active_auth_theme' => 'default']);
    
    $html = view('layouts.guest', ['slot' => '', 'settings' => $settings])->render();
    
    // Theme scripts should be included for default theme
    expect($html)->toContain('localStorage.getItem');
});

test('guest layout has dark mode classes for default theme', function () {
    $settings = collect(['active_auth_theme' => 'default']);
    
    $html = view('layouts.guest', ['slot' => '', 'settings' => $settings])->render();
    
    // Guest layout should have dark mode variants for default theme
    expect($html)->toContain('dark:bg-gray-900');
    expect($html)->toContain('dark:bg-gray-800');
});

test('theme toggle component has aria-pressed attribute', function () {
    $view = $this->view('components.theme-toggle');
    
    // Check for aria-pressed binding
    $view->assertSee('aria-pressed', false);
    $view->assertSee('isDark.toString()', false);
});

test('theme toggle component has focus ring for accessibility', function () {
    $view = $this->view('components.theme-toggle');
    
    $view->assertSee('focus:ring-2', false);
    $view->assertSee('focus:outline-none', false);
});

test('theme toggle component uses themeToggle function', function () {
    $view = $this->view('components.theme-toggle');
    
    $view->assertSee('themeToggle()', false);
    $view->assertSee('toggleTheme()', false);
});

test('theme toggle component has proper aria-label', function () {
    $view = $this->view('components.theme-toggle');
    
    $view->assertSee('aria-label', false);
});

test('theme toggle component has sun and moon icons', function () {
    $view = $this->view('components.theme-toggle');
    
    // Check for SVG icons
    $view->assertSee('<svg', false);
    $view->assertSee('viewBox', false);
});

test('reseller header theme toggle has aria-pressed', function () {
    $user = User::factory()->create();
    $panel = \App\Models\Panel::factory()->create([
        'panel_type' => 'marzneshin',
        'is_active' => true,
    ]);
    \App\Models\Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'active',
        'wallet_balance' => 100000,
        'primary_panel_id' => $panel->id,
        'panel_id' => $panel->id,
    ]);

    $view = $this->actingAs($user)->view('components.reseller.header');

    // Should have aria-pressed for accessibility
    $view->assertSee('aria-pressed', false);
});

test('reseller header theme toggle persists to cookie', function () {
    $user = User::factory()->create();
    $panel = \App\Models\Panel::factory()->create([
        'panel_type' => 'marzneshin',
        'is_active' => true,
    ]);
    \App\Models\Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'active',
        'wallet_balance' => 100000,
        'primary_panel_id' => $panel->id,
        'panel_id' => $panel->id,
    ]);

    $view = $this->actingAs($user)->view('components.reseller.header');

    // Should persist theme to cookie
    $view->assertSee('document.cookie', false);
});


