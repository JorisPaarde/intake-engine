<?php

declare(strict_types=1);

use App\Models\User;

it('shows the product homepage with login navigation', function () {
    $response = $this->get('/');

    $response->assertOk()
        ->assertSee('Digitale Opname', false)
        ->assertSee('Beoordeel installatieaanvragen op afstand', false)
        ->assertSee('Inloggen', false)
        ->assertSee(route('login'), false);
});

it('shows dashboard link for authenticated users on the homepage', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertSee('Naar opnames', false)
        ->assertSee(route('dashboard'), false);
});
