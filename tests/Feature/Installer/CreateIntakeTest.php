<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Validation\ValidationException;

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
        'address_house_number' => 10,
        'address_city' => 'Amsterdam',
        'internal_note' => 'Bel eerst terug.',
    ]);

    $intake = Intake::query()->where('customer_email', 'jan.demo@example.com')->first();

    expect($intake)->not->toBeNull()
        ->and($intake->status)->toBe(IntakeStatus::Sent)
        ->and($intake->uuid)->toHaveLength(36)
        ->and($intake->access_token)->toHaveLength(64)
        ->and($intake->created_by)->toBe($user->id)
        ->and($intake->address_postal_code)->toBe('1000AA')
        ->and($intake->address_house_number)->toBe(10)
        ->and($intake->address_house_number_addition)->toBeNull()
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
        ->assertSessionHasErrors([
            'customer_name',
            'customer_email',
            'address_postal_code',
            'address_house_number',
            'address_line',
            'address_city',
        ]);
});

test('create intake validation rejects malformed postcode lookup fields', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('intakes.store'), [
            'template_key' => 'airco',
            'customer_name' => 'Validatie Klant',
            'customer_email' => 'validatie@example.com',
            'address_postal_code' => '123',
            'address_house_number' => 'nul',
            'address_house_number_addition' => '@',
            'address_line' => 'Teststraat 1',
            'address_city' => 'Testdam',
        ])
        ->assertSessionHasErrors([
            'address_postal_code',
            'address_house_number',
            'address_house_number_addition',
        ]);
});

test('the domain action refuses a new intake without a structured house number', function () {
    $user = User::factory()->create();

    expect(fn () => app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'customer_name' => 'Onvolledig Adres',
        'customer_email' => 'onvolledig@example.com',
        'address_line' => 'Teststraat 10',
        'address_postal_code' => '1000AA',
        'address_city' => 'Amsterdam',
    ]))->toThrow(ValidationException::class, 'Vul een geldig huisnummer in.');
});

test('new intake form shows street and city without toevoeging or manual-address chrome', function () {
    $user = User::factory()->create();

    $html = $this->actingAs($user)
        ->get(route('intakes.create'))
        ->assertOk()
        ->assertDontSee('>Adres zoeken<', false)
        ->assertDontSee('data-address-search', false)
        ->assertDontSee('Toevoeging')
        ->assertDontSee('Handmatig invoeren')
        ->assertDontSee('Handmatig ingevoerd')
        ->assertDontSee('Adres handmatig aangepast')
        ->assertDontSee('Controleer het aangevulde adres')
        ->assertDontSee('data-manual-address', false)
        ->assertSee('Straat en huisnummer')
        ->assertSee('Plaats')
        ->assertSee('id="address_line"', false)
        ->assertSee('id="address_city"', false)
        ->assertSee('type="hidden"', false)
        ->getContent();

    expect(strpos($html, 'id="address_postal_code"'))
        ->toBeLessThan(strpos($html, 'id="address_house_number"'))
        ->and(strpos($html, 'id="address_house_number"'))
        ->toBeLessThan(strpos($html, 'id="address_line"'))
        ->and($html)
        ->toContain('function scheduleAddressSearch()')
        ->toContain("field.addEventListener('input', scheduleAddressSearch)")
        ->toContain('function parseHouseNumber(value)')
        ->toContain('addressLine.value = suggestion.address_line')
        ->toContain("function markAddressAsManuallyEdited() {\n                cancelActiveRequest();")
        ->not->toContain('manualSummary')
        ->not->toContain('Adres handmatig aangepast');
});

test('create form shows only one installer prefill text field', function () {
    $user = User::factory()->create();

    $html = $this->actingAs($user)
        ->get(route('intakes.create'))
        ->assertOk()
        ->assertSee('AI vult de vragen in (optioneel)')
        ->assertSee('Beschrijf wat de klant wil')
        ->assertSee('De AI vult daarmee alles in wat zij zeker genoeg weet')
        ->assertSee('alleen vragen die daarna nog open zijn')
        ->assertSee('name="prefill[request_reason]"', false)
        ->assertDontSee('name="prefill[cooling_heating]"', false)
        ->assertDontSee('name="prefill[indoor_unit_count]"', false)
        ->assertDontSee('name="prefill[crawl_space_present]"', false)
        ->assertDontSee('name="prefill[room_length_m]"', false)
        ->assertDontSee('name="prefill[floor_insulation]"', false)
        ->assertDontSee('data-prefill-block', false)
        ->getContent();

    expect(substr_count($html, 'name="prefill['))->toBe(1);
});

test('house number with addition is parsed from a single field on store', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('intakes.store'), [
        'template_key' => 'airco',
        'customer_name' => 'Toevoeging Klant',
        'customer_email' => 'toevoeging@example.com',
        'address_line' => 'Teststraat 10A',
        'address_postal_code' => '1234AB',
        'address_house_number' => '10A',
        'address_city' => 'Testdam',
    ])->assertRedirect();

    $intake = Intake::query()->where('customer_email', 'toevoeging@example.com')->firstOrFail();

    expect($intake->address_house_number)->toBe(10)
        ->and($intake->address_house_number_addition)->toBe('A')
        ->and($intake->address_line)->toBe('Teststraat 10A');
});

test('installer can pre-fill known request answers at creation', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('intakes.store'), [
        'template_key' => 'airco',
        'customer_name' => 'Prefill Klant',
        'customer_email' => 'prefill@example.com',
        'address_line' => 'Testlaan 10',
        'address_postal_code' => '1000AA',
        'address_house_number' => 10,
        'address_city' => 'Amsterdam',
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

test('installer opening text is applied before the customer receives the intake', function () {
    config(['ai.provider' => 'null', 'ai.text_inference.enabled' => false]);
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('intakes.store'), [
        'template_key' => 'airco',
        'customer_name' => 'Zolder Klant',
        'customer_email' => 'zolder@example.com',
        'address_line' => 'Testlaan 13',
        'address_postal_code' => '1000AA',
        'address_house_number' => 13,
        'address_city' => 'Amsterdam',
        'prefill' => [
            'request_reason' => 'Ik wil twee airco’s om m’n slaapkamers op zolder te koelen.',
        ],
    ]);

    $intake = Intake::query()->where('customer_email', 'zolder@example.com')->firstOrFail();
    $version = $intake->templateVersion()
        ->with(['sections.questions.options', 'sections.questions.rules'])
        ->firstOrFail();
    $steps = collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version))
        ->pluck('question_key');

    expect($intake->answers()->where('question_key', 'cooling_heating')->firstOrFail()->value)
        ->toBe(['value' => 'cooling'])
        ->and($intake->answers()->where('question_key', 'indoor_unit_count')->firstOrFail()->value)
        ->toBe(['number' => 2])
        ->and($intake->answers()->where('question_key', 'room_type')->where('section_instance_key', 'room-1')->firstOrFail()->value)
        ->toBe(['value' => 'bedroom'])
        ->and($intake->answers()->where('question_key', 'room_type')->where('section_instance_key', 'room-2')->firstOrFail()->value)
        ->toBe(['value' => 'bedroom'])
        ->and($intake->answers()->where('question_key', 'floor_level')->where('section_instance_key', 'room-1')->firstOrFail()->value)
        ->toBe(['value' => 'attic'])
        ->and($intake->answers()->where('question_key', 'floor_level')->where('section_instance_key', 'room-2')->firstOrFail()->value)
        ->toBe(['value' => 'attic'])
        ->and($steps)->not->toContain('cooling_heating')
        ->and($steps)->not->toContain('indoor_unit_count')
        ->and($steps)->not->toContain('room_type')
        ->and($steps)->not->toContain('floor_level');
});

test('installer pre-fill ignores questions that are not prefillable', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('intakes.store'), [
        'template_key' => 'airco',
        'customer_name' => 'Whitelist Klant',
        'customer_email' => 'whitelist@example.com',
        'address_line' => 'Testlaan 11',
        'address_postal_code' => '1000AA',
        'address_house_number' => 11,
        'address_city' => 'Amsterdam',
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
        'address_postal_code' => '1000AA',
        'address_house_number' => 12,
        'address_city' => 'Amsterdam',
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
