<?php

test('register page renders successfully', function () {
    $response = $this->get('/register');

    $response->assertOk();
});

test('register page uses the login stylesheet', function () {
    $response = $this->get('/register');

    $response->assertSee(asset('themes/auth/modern/style.css'), escape: false);
});
