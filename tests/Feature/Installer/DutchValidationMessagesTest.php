<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoPlacementType;
use App\Enums\ContributionMode;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

function createIntakeForDutchValidation(User $user, string $email = 'nl-validatie@example.com'): Intake
{
    return app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'NL Validatie',
        'customer_email' => $email,
        'address_line' => 'Testlaan 10',
        'address_postal_code' => '1000AA',
        'address_house_number' => 10,
        'address_city' => 'Amsterdam',
    ]);
}

function assertDutchValidationMessage(string $message): void
{
    expect($message)
        ->not->toContain('The ')
        ->not->toContain(' field')
        ->not->toContain('airco room id')
        ->and(mb_strtolower($message))->not->toStartWith('the ');
}

test('validation.required translation is Dutch for foto attribute', function () {
    $message = __('validation.required', ['attribute' => 'foto']);

    expect($message)->toBe('Foto is verplicht.')
        ->and($message)->not->toContain('The ')
        ->and($message)->not->toContain('field');
});

test('create intake returns Dutch validation messages without English field wording', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->from(route('intakes.create'))
        ->post(route('intakes.store'), [
            'template_key' => 'airco',
            'customer_name' => '',
            'customer_email' => 'geen-email',
            'customer_phone' => '06',
            'address_postal_code' => '123',
            'address_house_number' => 0,
            'address_line' => '',
            'address_city' => '',
            'workflow_mode' => ContributionMode::Customer->value,
        ]);

    $response->assertSessionHasErrors([
        'customer_name',
        'customer_email',
        'customer_phone',
        'address_postal_code',
        'address_house_number',
        'address_line',
        'address_city',
    ]);

    $errors = session('errors');
    expect($errors)->not->toBeNull();

    foreach ([
        'customer_name',
        'customer_email',
        'customer_phone',
        'address_postal_code',
        'address_house_number',
        'address_line',
        'address_city',
    ] as $field) {
        $message = $errors->first($field);
        expect($message)->toBeString();
        assertDutchValidationMessage((string) $message);
    }

    expect($errors->first('customer_phone'))
        ->toBe('Vul een geldig telefoonnummer in, bijvoorbeeld 06 12345678.');
    expect(mb_strtolower((string) $errors->first('customer_name')))->toContain('naam klant');
    expect(mb_strtolower((string) $errors->first('address_postal_code')))->toContain('postcode');
});

test('create intake accepts empty phone and valid spaced phone numbers', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('intakes.store'), [
            'template_key' => 'airco',
            'customer_name' => 'Telefoon OK',
            'customer_email' => 'telefoon-ok@example.com',
            'customer_phone' => '',
            'address_line' => 'Testlaan 10',
            'address_postal_code' => '1000AA',
            'address_house_number' => 10,
            'address_city' => 'Amsterdam',
            'workflow_mode' => ContributionMode::Installer->value,
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->post(route('intakes.store'), [
            'template_key' => 'airco',
            'customer_name' => 'Telefoon Spatie',
            'customer_email' => 'telefoon-spatie@example.com',
            'customer_phone' => '06 12345678',
            'address_line' => 'Testlaan 11',
            'address_postal_code' => '1000AB',
            'address_house_number' => 11,
            'address_city' => 'Amsterdam',
            'workflow_mode' => ContributionMode::Installer->value,
        ])
        ->assertRedirect();
});

test('room dimension validation messages are Dutch', function () {
    $user = User::factory()->create();
    $intake = createIntakeForDutchValidation($user, 'ruimte-maten@example.com');

    $response = $this->actingAs($user)
        ->from(route('intakes.workspace', $intake))
        ->post(route('intakes.workspace.rooms.store', $intake), [
            'name' => 'Woonkamer',
            'use_type' => 'living_room',
            'length_m' => 0.1,
            'width_m' => 200,
            'height_m' => 0.5,
        ]);

    $response->assertSessionHasErrors(['length_m', 'width_m', 'height_m']);

    $errors = session('errors');
    foreach (['length_m', 'width_m', 'height_m'] as $field) {
        $message = (string) $errors->first($field);
        assertDutchValidationMessage($message);
    }

    expect(mb_strtolower((string) $errors->first('length_m')))->toContain('lengte')
        ->and(mb_strtolower((string) $errors->first('width_m')))->toContain('breedte')
        ->and(mb_strtolower((string) $errors->first('height_m')))->toContain('hoogte');
});

test('indoor unit without room returns Dutch custom message and keeps old input', function () {
    $user = User::factory()->create();
    $intake = createIntakeForDutchValidation($user, 'unit-ruimte@example.com');
    app(AircoSurveyService::class)->createRoom($intake, $user, [
        'name' => 'Slaapkamer',
        'use_type' => 'bedroom',
    ]);

    $response = $this->actingAs($user)
        ->from(route('intakes.workspace', $intake))
        ->post(route('intakes.workspace.placements.store', $intake), [
            'form_key' => 'placement-new',
            'type' => AircoPlacementType::IndoorUnit->value,
            'airco_room_id' => '',
            'label' => 'Unit slaapkamer 1',
            'description' => 'Boven de deur',
        ]);

    $response->assertSessionHasErrors(['airco_room_id'])
        ->assertSessionHasInput('form_key', 'placement-new')
        ->assertSessionHasInput('label', 'Unit slaapkamer 1')
        ->assertSessionHasInput('description', 'Boven de deur');

    $message = (string) session('errors')->first('airco_room_id');
    expect($message)->toBe('Kies bij welke ruimte deze binnenunit hoort.');
    assertDutchValidationMessage($message);
});

test('placement without name returns Vul een naam in', function () {
    $user = User::factory()->create();
    $intake = createIntakeForDutchValidation($user, 'unit-naam@example.com');
    $room = app(AircoSurveyService::class)->createRoom($intake, $user, [
        'name' => 'Werkkamer',
        'use_type' => 'office',
    ]);

    $response = $this->actingAs($user)
        ->from(route('intakes.workspace', $intake))
        ->post(route('intakes.workspace.placements.store', $intake), [
            'form_key' => 'placement-new',
            'type' => AircoPlacementType::IndoorUnit->value,
            'airco_room_id' => $room->id,
            'label' => '',
        ]);

    $response->assertSessionHasErrors(['label']);
    expect((string) session('errors')->first('label'))->toBe('Vul een naam in.');
});

test('failed placement edit keeps old input scoped to that form only', function () {
    $this->withoutVite();
    $user = User::factory()->create();
    $intake = createIntakeForDutchValidation($user, 'form-key-scope@example.com');
    $survey = app(AircoSurveyService::class);
    $room = $survey->createRoom($intake, $user, [
        'name' => 'Slaapkamer',
        'use_type' => 'bedroom',
    ]);
    $unitA = $survey->createPlacement($intake, $user, [
        'airco_room_id' => $room->id,
        'type' => AircoPlacementType::IndoorUnit,
        'label' => 'Unit A origineel',
    ]);
    $unitB = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::OutdoorUnit,
        'label' => 'Unit B buiten',
    ]);

    $html = $this->actingAs($user)
        ->from(route('intakes.workspace', $intake))
        ->followingRedirects()
        ->post(route('intakes.workspace.placements.update', [$intake, $unitA]), [
            'form_key' => 'placement-'.$unitA->id,
            'type' => AircoPlacementType::IndoorUnit->value,
            'airco_room_id' => '',
            'label' => 'Unit A foutpoging',
            'description' => 'Alleen voor A',
        ])
        ->assertOk()
        ->assertSee('Unit A foutpoging')
        ->assertSee('Kies bij welke ruimte deze binnenunit hoort.')
        ->assertDontSee('data-testid="workspace-top-errors"', false)
        ->assertDontSee('Dit onderdeel kon nog niet worden opgeslagen.')
        ->getContent();

    expect($html)
        ->toMatch('/<details[^>]*\bopen\b[^>]*data-form-key="placement-'.$unitA->id.'"|<details[^>]*data-form-key="placement-'.$unitA->id.'"[^>]*\bopen\b/')
        ->toMatch('/x-init="\$el\.scrollIntoView\(\{block:\'center\'\}\)"/')
        ->not->toMatch('/<details[^>]*\bopen\b[^>]*data-form-key="placement-new"|<details[^>]*data-form-key="placement-new"[^>]*\bopen\b/')
        ->and(preg_match('/id="placement_label"[^>]*value="Unit A foutpoging"/', $html))->toBe(0)
        ->and(preg_match('/id="placement-'.$unitA->id.'-label"[^>]*value="Unit A foutpoging"/', $html))->toBe(1)
        ->and(preg_match('/id="placement-'.$unitB->id.'-label"[^>]*value="Unit A foutpoging"/', $html))->toBe(0)
        ->and(preg_match('/id="placement-'.$unitB->id.'-label"[^>]*value="Unit B buiten"/', $html))->toBe(1);

    expect(preg_match(
        '/<details[^>]*data-form-key="placement-'.$unitA->id.'"[^>]*x-init="\$el\.scrollIntoView\(\{block:\'center\'\}\)"|<details[^>]*x-init="\$el\.scrollIntoView\(\{block:\'center\'\}\)"[^>]*data-form-key="placement-'.$unitA->id.'"/',
        $html,
    ))->toBe(1);
});

test('rejected coupling for room A does not leak old input into room B form', function () {
    $this->withoutVite();
    $user = User::factory()->create();
    $intake = createIntakeForDutchValidation($user, 'coupling-form-key@example.com');
    $survey = app(AircoSurveyService::class);

    $living = $survey->createRoom($intake, $user, ['name' => 'Woonkamer', 'use_type' => 'living_room']);
    $office = $survey->createRoom($intake, $user, ['name' => 'Werkkamer', 'use_type' => 'office']);

    $option = $survey->syncRoomUnitCoupling($intake, $user, $living, [
        'indoor_label' => 'Binnenunit woonkamer',
        'outdoor_label' => 'Buitenunit tuin',
        'configuration_type' => AircoConfigurationType::SingleSplit,
    ]);

    $outdoor = $intake->fresh()?->aircoPlacements()->where('type', AircoPlacementType::OutdoorUnit)->firstOrFail();
    $couplingKey = 'coupling-'.$office->id;

    $html = $this->actingAs($user)
        ->from(route('intakes.workspace', $intake))
        ->followingRedirects()
        ->post(route('intakes.workspace.rooms.unit-coupling', [$intake, $office]), [
            'form_key' => $couplingKey,
            'indoor_label' => 'Lekkende naam voor werkkamer',
            'outdoor_placement_id' => $outdoor->id,
            'configuration_type' => AircoConfigurationType::SingleSplit->value,
            'installation_option_id' => $option->id,
            'outdoor_label' => 'Lekkende buitennaam',
        ])
        ->assertOk()
        ->assertSee('Lekkende naam voor werkkamer')
        ->assertDontSee('data-testid="workspace-top-errors"', false)
        ->getContent();

    expect($html)
        ->toMatch('/<details[^>]*\bopen\b[^>]*data-form-key="'.preg_quote($couplingKey, '/').'"|<details[^>]*data-form-key="'.preg_quote($couplingKey, '/').'"[^>]*\bopen\b/')
        ->and(preg_match(
            '/<details[^>]*data-form-key="'.preg_quote($couplingKey, '/').'"[^>]*x-init="\$el\.scrollIntoView\(\{block:\'center\'\}\)"|<details[^>]*x-init="\$el\.scrollIntoView\(\{block:\'center\'\}\)"[^>]*data-form-key="'.preg_quote($couplingKey, '/').'"/',
            $html,
        ))->toBe(1)
        ->and(preg_match('/id="room-'.$office->id.'-indoor-label"[^>]*value="Lekkende naam voor werkkamer"/', $html))->toBe(1)
        ->and(preg_match('/id="room-'.$living->id.'-indoor-label"[^>]*value="Lekkende naam voor werkkamer"/', $html))->toBe(0)
        ->and(preg_match('/id="room-'.$living->id.'-indoor-label"[^>]*value="Binnenunit woonkamer"/', $html))->toBe(1)
        ->and(preg_match('/id="room-'.$office->id.'-outdoor-label"[^>]*value="Lekkende buitennaam"/', $html))->toBe(1)
        ->and(preg_match('/id="room-'.$living->id.'-outdoor-label"[^>]*value="Lekkende buitennaam"/', $html))->toBe(0)
        ->and(preg_match('/id="room-'.$office->id.'-outdoor"[^>]*>[\s\S]*?<option[^>]*value="'.$outdoor->id.'"[^>]*selected/', $html))->toBe(1)
        ->and(preg_match('/name="form_key" value="coupling-'.$office->id.'"/', $html))->toBe(1)
        ->and(preg_match('/name="form_key" value="coupling-'.$living->id.'"/', $html))->toBe(1);
});

test('new placement without room opens details with scrollIntoView so the error is visible', function () {
    $this->withoutVite();
    $user = User::factory()->create();
    $intake = createIntakeForDutchValidation($user, 'new-placement-scroll@example.com');
    app(AircoSurveyService::class)->createRoom($intake, $user, [
        'name' => 'Slaapkamer',
        'use_type' => 'bedroom',
    ]);

    $html = $this->actingAs($user)
        ->from(route('intakes.workspace', $intake))
        ->followingRedirects()
        ->post(route('intakes.workspace.placements.store', $intake), [
            'form_key' => 'placement-new',
            'type' => AircoPlacementType::IndoorUnit->value,
            'airco_room_id' => '',
            'label' => 'Unit zonder ruimte',
            'description' => 'Moet zichtbaar blijven',
        ])
        ->assertOk()
        ->assertDontSee('data-testid="workspace-top-errors"', false)
        ->getContent();

    expect($html)
        ->toMatch('/<details[^>]*\bopen\b[^>]*data-form-key="placement-new"|<details[^>]*data-form-key="placement-new"[^>]*\bopen\b/')
        ->and(preg_match(
            '/<details[^>]*data-form-key="placement-new"[^>]*x-init="\$el\.scrollIntoView\(\{block:\'center\'\}\)"|<details[^>]*x-init="\$el\.scrollIntoView\(\{block:\'center\'\}\)"[^>]*data-form-key="placement-new"/',
            $html,
        ))->toBe(1)
        ->and($html)->toContain('Kies bij welke ruimte deze binnenunit hoort.');
});

test('stale installation_option_id on coupling stays visible in the top alert', function () {
    $this->withoutVite();
    $user = User::factory()->create();
    $intake = createIntakeForDutchValidation($user, 'stale-option@example.com');
    $survey = app(AircoSurveyService::class);
    $room = $survey->createRoom($intake, $user, [
        'name' => 'Woonkamer',
        'use_type' => 'living_room',
    ]);

    $html = $this->actingAs($user)
        ->from(route('intakes.workspace', $intake))
        ->followingRedirects()
        ->post(route('intakes.workspace.rooms.unit-coupling', [$intake, $room]), [
            'form_key' => 'coupling-'.$room->id,
            'indoor_label' => 'Binnenunit woonkamer',
            'outdoor_label' => 'Buitenunit tuin',
            'configuration_type' => AircoConfigurationType::SingleSplit->value,
            'installation_option_id' => 999999,
        ])
        ->assertOk()
        ->assertSee('data-testid="workspace-top-errors"', false)
        ->assertSee('Dit onderdeel kon nog niet worden opgeslagen.')
        ->getContent();

    expect($html)->toContain('workspace-top-errors')
        ->and($html)->toMatch('/data-testid="workspace-top-errors"[\s\S]*?<li>/');
});

test('non-placement form errors still appear in the top workspace alert', function () {
    $this->withoutVite();
    $user = User::factory()->create();
    $intake = createIntakeForDutchValidation($user, 'top-errors@example.com');

    $html = $this->actingAs($user)
        ->from(route('intakes.workspace', $intake))
        ->followingRedirects()
        ->post(route('intakes.workspace.rooms.store', $intake), [
            'name' => '',
            'use_type' => 'living_room',
        ])
        ->assertOk()
        ->assertSee('data-testid="workspace-top-errors"', false)
        ->assertSee('Dit onderdeel kon nog niet worden opgeslagen.')
        ->getContent();

    expect($html)->toContain('workspace-top-errors');
});

test('unit coupling validation messages are Dutch', function () {
    $user = User::factory()->create();
    $intake = createIntakeForDutchValidation($user, 'koppel-nl@example.com');
    $room = app(AircoSurveyService::class)->createRoom($intake, $user, [
        'name' => 'Kamer',
        'use_type' => 'other',
    ]);

    $response = $this->actingAs($user)
        ->from(route('intakes.workspace', $intake))
        ->post(route('intakes.workspace.rooms.unit-coupling', [$intake, $room]), [
            'indoor_label' => '',
            'configuration_type' => 'single_split',
            'outdoor_label' => '',
        ]);

    $response->assertSessionHasErrors(['indoor_label']);
    $message = (string) session('errors')->first('indoor_label');
    assertDutchValidationMessage($message);
});
