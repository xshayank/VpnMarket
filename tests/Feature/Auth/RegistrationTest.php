<?php

use Database\Seeders\RbacSeeder;

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response->assertStatus(200);
});

test('new users can register', function () {
    $this->seed(RbacSeeder::class);

    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'reseller_type' => 'wallet',
    ]);

    $this->assertAuthenticated();
    $this->assertTrue(auth()->user()->hasRole('reseller'));
    $response->assertRedirect(route('wallet.charge.form', absolute: false));
});
