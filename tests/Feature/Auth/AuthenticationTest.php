<?php

declare(strict_types=1);

use App\Models\User;

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response->assertStatus(200)
        ->assertDontSee('Je sessie is verlopen');
});

test('guest hitting a protected page sees an expired-session message on login', function () {
    config(['intake.demo.enabled' => true]);

    $this->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('session_expired', true);

    $this->followingRedirects()
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Je sessie is verlopen')
        ->assertSee('Log opnieuw in om verder te gaan.')
        ->assertSee('Was je in de demo? Start een nieuwe demo.')
        ->assertSee('Nieuwe demo starten')
        ->assertSee(route('demo.start'), false);
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertRedirect('/');
});
