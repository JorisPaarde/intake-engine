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

test('installer can pre-fill known request answers at creation', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('intakes.store'), [
        'template_key' => 'airco',
        'customer_name' => 'Prefill Klant',
        'customer_email' => 'prefill@example.com',
        'address_line' => 'Testlaan 10',
        'prefill' => [
            'request_reason' => 'Slaapkamer te warm',
            'cooling_heating' => 'cooling',
            'brand_preference' => '', // empty is skipped
        ],
    ]);

    $intake = Intake::query()->where('customer_email', 'prefill@example.com')->firstOrFail();

    // Pre-filling must not "start" the intake for the customer.
    expect($intake->status)->toBe(IntakeStatus::Sent);

    $reason = $intake->answers()->where('question_key', 'request_reason')->first();
    $cooling = $intake->answers()->where('question_key', 'cooling_heating')->first();

    expect($reason->value)->toBe(['text' => 'Slaapkamer te warm'])
        ->and($reason->prefill_source)->toBe('installer')
        ->and($cooling->value)->toBe(['value' => 'cooling'])
        ->and($cooling->prefill_source)->toBe('installer')
        ->and($intake->answers()->where('question_key', 'brand_preference')->exists())->toBeFalse();
});

test('installer pre-fill ignores questions that are not prefillable', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('intakes.store'), [
        'template_key' => 'airco',
        'customer_name' => 'Whitelist Klant',
        'customer_email' => 'whitelist@example.com',
        'address_line' => 'Testlaan 11',
        'prefill' => [
            'noise_sensitive' => '1', // valid question, but not installer_prefillable
        ],
    ]);

    $intake = Intake::query()->where('customer_email', 'whitelist@example.com')->firstOrFail();

    expect($intake->answers()->where('question_key', 'noise_sensitive')->exists())->toBeFalse();
});

test('creating without prefill stores no answers', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('intakes.store'), [
        'template_key' => 'airco',
        'customer_name' => 'Plain Klant',
        'customer_email' => 'plain@example.com',
        'address_line' => 'Testlaan 12',
    ]);

    $intake = Intake::query()->where('customer_email', 'plain@example.com')->firstOrFail();

    expect($intake->answers()->count())->toBe(0)
        ->and($intake->status)->toBe(IntakeStatus::Sent);
});

test('guest cannot create an intake', function () {
    $this->post(route('intakes.store'), [
        'template_key' => 'airco',
        'customer_name' => 'X',
        'customer_email' => 'x@example.com',
        'address_line' => 'Y 1',
    ])->assertRedirect(route('login'));
});
