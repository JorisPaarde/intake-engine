<?php

declare(strict_types=1);

use App\Domains\Intake\Models\Intake;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

test('installer can create an intake with a unique customer link', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('intakes.store'), [
        'template_key' => 'airco',
        'customer_name' => 'Jan Demo',
        'customer_email' => 'jan.demo@example.com',
        'customer_phone' => '0611111111',
        'address_line' => 'Testlaan 10',
        'address_postal_code' => '1000AA',
        'address_city' => 'Amsterdam',
        'internal_note' => 'Bel eerst terug.',
    ]);

    $intake = Intake::query()->where('customer_email', 'jan.demo@example.com')->first();

    expect($intake)->not->toBeNull()
        ->and($intake->status)->toBe(IntakeStatus::Sent)
        ->and($intake->access_token)->toHaveLength(64)
        ->and($intake->created_by)->toBe($user->id)
        ->and($intake->templateVersion->template->key)->toBe('airco')
        ->and($intake->token_expires_at)->not->toBeNull();

    $response->assertRedirect(route('intakes.show', $intake));

    $this->actingAs($user)
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee($intake->customerUrl())
        ->assertSee('Jan Demo');
});

test('create intake validation requires customer fields', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('intakes.store'), [
            'template_key' => 'airco',
        ])
        ->assertSessionHasErrors(['customer_name', 'customer_email', 'address_line']);
});

test('guest cannot create an intake', function () {
    $this->post(route('intakes.store'), [
        'template_key' => 'airco',
        'customer_name' => 'X',
        'customer_email' => 'x@example.com',
        'address_line' => 'Y 1',
    ])->assertRedirect(route('login'));
});
