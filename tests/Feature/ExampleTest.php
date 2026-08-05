<?php

declare(strict_types=1);

use App\Models\User;

it('shows the product homepage with login navigation', function () {
    $response = $this->get('/');

    $response->assertOk()
        ->assertSee('Digitale Opname', false)
        ->assertSee('Een complete opname vóór je de bus instapt.', false)
        ->assertSee('Wat al bekend is, staat klaar', false)
        ->assertSee('Van woninggegevens tot installatievoorstel', false)
        ->assertSee('Inloggen', false)
        ->assertSee(route('login'), false);
});

it('shows my intakes link for authenticated users on the homepage', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertSee('Mijn opnames', false)
        ->assertDontSee('Probeer de demo', false)
        ->assertDontSee('Open dashboard', false)
        ->assertSee(route('dashboard'), false);
});
