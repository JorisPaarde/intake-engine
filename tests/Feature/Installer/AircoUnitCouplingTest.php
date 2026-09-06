<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Models\DossierDecisionArea;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\DecisionReadinessService;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoConnectionType;
use App\Enums\AircoPlacementType;
use App\Enums\ContributionMode;
use App\Enums\DecisionAreaStatus;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

function createIntakeForUnitCoupling(User $user, string $email = 'koppeling@example.com'): Intake
{
    return app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Koppelingstest',
        'customer_email' => $email,
        'address_line' => 'Testlaan 10',
        'address_postal_code' => '1000AA',
        'address_house_number' => 10,
        'address_city' => 'Amsterdam',
    ]);
}

test('single-split validates exactly one indoor to one outdoor refrigerant link', function () {
    $user = User::factory()->create();
    $intake = createIntakeForUnitCoupling($user, 'single@example.com');
    $survey = app(AircoSurveyService::class);

    $room = $survey->createRoom($intake, $user, ['name' => 'Woonkamer', 'use_type' => 'living_room']);
    $indoor = $survey->createPlacement($intake, $user, [
        'airco_room_id' => $room->id,
        'type' => AircoPlacementType::IndoorUnit,
        'label' => 'Boven de bank',
    ]);
    $outdoor = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::OutdoorUnit,
        'label' => 'Achtergevel',
    ]);

    $option = $survey->createInstallationOption($intake, $user, [
        'label' => 'Single-split woonkamer',
        'configuration_type' => AircoConfigurationType::SingleSplit,
        'placement_ids' => [$indoor->id, $outdoor->id],
        'refrigerant_links' => [[
            'from_placement_id' => $indoor->id,
            'to_placement_id' => $outdoor->id,
        ]],
    ]);

    expect($option->connections)->toHaveCount(1)
        ->and($option->connections->first()?->type)->toBe(AircoConnectionType::Refrigerant)
        ->and($option->connections->first()?->from_placement_id)->toBe($indoor->id)
        ->and($option->connections->first()?->to_placement_id)->toBe($outdoor->id)
        ->and($outdoor->fresh()?->airco_room_id)->toBeNull();
});

test('multi-split validates at least two indoors linked to the same outdoor', function () {
    $user = User::factory()->create();
    $intake = createIntakeForUnitCoupling($user, 'multi@example.com');
    $survey = app(AircoSurveyService::class);

    $roomA = $survey->createRoom($intake, $user, ['name' => 'Slaapkamer 1', 'use_type' => 'bedroom']);
    $roomB = $survey->createRoom($intake, $user, ['name' => 'Slaapkamer 2', 'use_type' => 'bedroom']);
    $indoorA = $survey->createPlacement($intake, $user, [
        'airco_room_id' => $roomA->id,
        'type' => AircoPlacementType::IndoorUnit,
        'label' => 'Slaapkamer 1 unit',
    ]);
    $indoorB = $survey->createPlacement($intake, $user, [
        'airco_room_id' => $roomB->id,
        'type' => AircoPlacementType::IndoorUnit,
        'label' => 'Slaapkamer 2 unit',
    ]);
    $outdoor = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::OutdoorUnit,
        'label' => 'Plat dak',
    ]);

    $option = $survey->createInstallationOption($intake, $user, [
        'label' => 'Multi-split',
        'configuration_type' => AircoConfigurationType::MultiSplit,
        'placement_ids' => [$indoorA->id, $indoorB->id, $outdoor->id],
        'refrigerant_links' => [
            ['from_placement_id' => $indoorA->id, 'to_placement_id' => $outdoor->id],
            ['from_placement_id' => $indoorB->id, 'to_placement_id' => $outdoor->id],
        ],
    ]);

    expect($option->connections->where('type', AircoConnectionType::Refrigerant))->toHaveCount(2);

    expect(fn () => $survey->createConnection($intake, $user, $option, [
        'type' => AircoConnectionType::Refrigerant,
        'label' => 'Dubbele link',
        'from_placement_id' => $indoorA->id,
        'to_placement_id' => $outdoor->id,
        'status' => AircoConnectionStatus::Unknown,
    ]))->toThrow(ValidationException::class);
});

test('multiple single-splits require unique one-to-one indoor outdoor pairs', function () {
    $user = User::factory()->create();
    $intake = createIntakeForUnitCoupling($user, 'singles@example.com');
    $survey = app(AircoSurveyService::class);

    $roomA = $survey->createRoom($intake, $user, ['name' => 'Kamer A', 'use_type' => 'bedroom']);
    $roomB = $survey->createRoom($intake, $user, ['name' => 'Kamer B', 'use_type' => 'bedroom']);
    $indoorA = $survey->createPlacement($intake, $user, [
        'airco_room_id' => $roomA->id,
        'type' => AircoPlacementType::IndoorUnit,
        'label' => 'Unit A',
    ]);
    $indoorB = $survey->createPlacement($intake, $user, [
        'airco_room_id' => $roomB->id,
        'type' => AircoPlacementType::IndoorUnit,
        'label' => 'Unit B',
    ]);
    $outdoorA = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::OutdoorUnit,
        'label' => 'Buiten A',
    ]);
    $outdoorB = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::OutdoorUnit,
        'label' => 'Buiten B',
    ]);

    $option = $survey->createInstallationOption($intake, $user, [
        'label' => 'Twee singles',
        'configuration_type' => AircoConfigurationType::MultipleSingleSplits,
        'placement_ids' => [$indoorA->id, $indoorB->id, $outdoorA->id, $outdoorB->id],
        'refrigerant_links' => [
            ['from_placement_id' => $indoorA->id, 'to_placement_id' => $outdoorA->id],
            ['from_placement_id' => $indoorB->id, 'to_placement_id' => $outdoorB->id],
        ],
    ]);

    expect($option->connections)->toHaveCount(2);

    expect(fn () => $survey->createInstallationOption($intake, $user, [
        'label' => 'Gedeelde buitenunit fout',
        'configuration_type' => AircoConfigurationType::MultipleSingleSplits,
        'placement_ids' => [$indoorA->id, $indoorB->id, $outdoorA->id, $outdoorB->id],
        'refrigerant_links' => [
            ['from_placement_id' => $indoorA->id, 'to_placement_id' => $outdoorA->id],
            ['from_placement_id' => $indoorB->id, 'to_placement_id' => $outdoorA->id],
        ],
    ]))->toThrow(ValidationException::class);
});

test('incomplete refrigerant links become open points after readiness recalculation', function () {
    $user = User::factory()->create();
    $intake = createIntakeForUnitCoupling($user, 'openpunt@example.com');
    $survey = app(AircoSurveyService::class);
    $readiness = app(DecisionReadinessService::class);

    $room = $survey->createRoom($intake, $user, ['name' => 'Zolder', 'use_type' => 'attic']);
    $indoor = $survey->createPlacement($intake, $user, [
        'airco_room_id' => $room->id,
        'type' => AircoPlacementType::IndoorUnit,
        'label' => 'Zolderunit',
    ]);
    $outdoor = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::OutdoorUnit,
        'label' => 'Dak',
    ]);
    $option = $survey->createInstallationOption($intake, $user, [
        'label' => 'Nog zonder koelleiding',
        'configuration_type' => AircoConfigurationType::SingleSplit,
        'placement_ids' => [$indoor->id, $outdoor->id],
    ]);
    $survey->selectInstallationOption($intake, $user, $option);

    $areas = $readiness->recalculate($intake->fresh() ?? $intake);
    $refrigerant = $areas->first(
        static fn (DossierDecisionArea $area): bool => $area->key === 'refrigerant',
    );

    expect($refrigerant)->not->toBeNull()
        ->and($refrigerant?->status)->toBe(DecisionAreaStatus::Blocked)
        ->and($refrigerant?->blocker)->not->toBeNull();
});

test('room-centric coupling syncs indoor outdoor and configuration without a global hunt', function () {
    $this->withoutVite();
    $user = User::factory()->create();
    $intake = createIntakeForUnitCoupling($user, 'ruimtekaart@example.com');
    $survey = app(AircoSurveyService::class);

    $room = $survey->createRoom($intake, $user, ['name' => 'Werkkamer', 'use_type' => 'office']);
    $option = $survey->syncRoomUnitCoupling($intake, $user, $room, [
        'indoor_label' => 'Hoge wand',
        'outdoor_label' => 'Balkon',
        'configuration_type' => AircoConfigurationType::SingleSplit,
    ]);

    $indoor = $room->fresh()?->placements()->where('type', AircoPlacementType::IndoorUnit)->first();
    $outdoor = $intake->fresh()?->aircoPlacements()->where('type', AircoPlacementType::OutdoorUnit)->first();

    expect($option->configuration_type)->toBe(AircoConfigurationType::SingleSplit)
        ->and($indoor?->airco_room_id)->toBe($room->id)
        ->and($outdoor?->airco_room_id)->toBeNull()
        ->and($option->connections)->toHaveCount(1);

    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('Koppel de binnenunit van deze ruimte')
        ->assertSee('Hoge wand')
        ->assertSee('Balkon')
        ->assertSee('name="configuration_type"', false);

    $this->actingAs($user)
        ->post(route('intakes.workspace.rooms.unit-coupling', [$intake, $room]), [
            'indoor_label' => 'Hoge wand bij raam',
            'outdoor_placement_id' => $outdoor?->id,
            'configuration_type' => AircoConfigurationType::SingleSplit->value,
            'installation_option_id' => $option->id,
        ])
        ->assertRedirect(route('intakes.workspace', $intake));

    expect($indoor?->fresh()?->label)->toBe('Hoge wand bij raam');
});

test('tenant boundary blocks coupling on another company intake', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create([
        'company_id' => Company::factory()->create()->id,
    ]);
    $intake = createIntakeForUnitCoupling($owner, 'tenant@example.com');
    $survey = app(AircoSurveyService::class);
    $room = $survey->createRoom($intake, $owner, ['name' => 'Kamer', 'use_type' => 'other']);

    expect(fn () => $survey->syncRoomUnitCoupling($intake, $intruder, $room, [
        'indoor_label' => 'Unit',
        'outdoor_label' => 'Gevel',
        'configuration_type' => AircoConfigurationType::SingleSplit,
    ]))->toThrow(ValidationException::class);
});

test('indoor placement without room is rejected and outdoor never owns a room', function () {
    $user = User::factory()->create();
    $intake = createIntakeForUnitCoupling($user, 'eigendom@example.com');
    $survey = app(AircoSurveyService::class);
    $room = $survey->createRoom($intake, $user, ['name' => 'Kamer', 'use_type' => 'bedroom']);

    expect(fn () => $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::IndoorUnit,
        'label' => 'Zonder ruimte',
    ]))->toThrow(ValidationException::class);

    $outdoor = $survey->createPlacement($intake, $user, [
        'airco_room_id' => $room->id,
        'type' => AircoPlacementType::OutdoorUnit,
        'label' => 'Toch geen ruimteeigendom',
    ]);

    expect($outdoor->airco_room_id)->toBeNull();
});

test('cross links for multi-split to different outdoors are rejected', function () {
    $user = User::factory()->create();
    $intake = createIntakeForUnitCoupling($user, 'kruis@example.com');
    $survey = app(AircoSurveyService::class);

    $roomA = $survey->createRoom($intake, $user, ['name' => 'A', 'use_type' => 'bedroom']);
    $roomB = $survey->createRoom($intake, $user, ['name' => 'B', 'use_type' => 'bedroom']);
    $indoorA = $survey->createPlacement($intake, $user, [
        'airco_room_id' => $roomA->id,
        'type' => AircoPlacementType::IndoorUnit,
        'label' => 'In A',
    ]);
    $indoorB = $survey->createPlacement($intake, $user, [
        'airco_room_id' => $roomB->id,
        'type' => AircoPlacementType::IndoorUnit,
        'label' => 'In B',
    ]);
    $outdoorA = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::OutdoorUnit,
        'label' => 'Uit A',
    ]);
    $outdoorB = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::OutdoorUnit,
        'label' => 'Uit B',
    ]);

    $option = $survey->createInstallationOption($intake, $user, [
        'label' => 'Multi',
        'configuration_type' => AircoConfigurationType::MultiSplit,
        'placement_ids' => [$indoorA->id, $indoorB->id, $outdoorA->id],
    ]);

    $survey->createConnection($intake, $user, $option, [
        'type' => AircoConnectionType::Refrigerant,
        'label' => 'Link A',
        'from_placement_id' => $indoorA->id,
        'to_placement_id' => $outdoorA->id,
        'status' => AircoConnectionStatus::Unknown,
    ]);

    $option->placements()->attach($outdoorB->id, [
        'role' => AircoPlacementType::OutdoorUnit->value,
        'sort_order' => 4,
    ]);
    $option->load(['placements', 'connections']);

    expect(fn () => $survey->createConnection($intake, $user, $option, [
        'type' => AircoConnectionType::Refrigerant,
        'label' => 'Kruiskoppeling',
        'from_placement_id' => $indoorB->id,
        'to_placement_id' => $outdoorB->id,
        'status' => AircoConnectionStatus::Unknown,
    ]))->toThrow(ValidationException::class);
});
