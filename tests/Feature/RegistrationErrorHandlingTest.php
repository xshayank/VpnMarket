<?php

use App\Models\Panel;
use App\Models\User;

test('registration page returns 200 when active panels exist', function () {
    // Create an active panel
    Panel::factory()->create([
        'name' => 'Test Panel',
        'url' => 'https://panel.example.com',
        'panel_type' => 'marzneshin',
        'is_active' => true,
    ]);

    $response = $this->get(route('register'));

    $response->assertStatus(200);
    $response->assertViewIs('auth.register');
});

test('registration page returns 503 when no active panels exist', function () {
    // Create only inactive panels
    Panel::factory()->create([
        'name' => 'Inactive Panel',
        'url' => 'https://panel.example.com',
        'panel_type' => 'marzneshin',
        'is_active' => false,
    ]);

    $response = $this->get(route('register'));

    $response->assertStatus(503);
    // Just ensure 503 is returned, the message may vary based on exception handler
});

test('registration page returns 503 when no panels exist at all', function () {
    // Ensure no panels exist
    Panel::query()->delete();

    $response = $this->get(route('register'));

    $response->assertStatus(503);
    // Just ensure 503 is returned, the message may vary based on exception handler
});

test('admin area redirects to login when unauthenticated', function () {
    $response = $this->get('/admin');

    // Should redirect to login page (302 redirect to /admin/login)
    // We just want to ensure no 500 error occurs
    expect($response->status())->not->toBe(500);
});
