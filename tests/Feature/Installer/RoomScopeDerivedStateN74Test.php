<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\AircoUnitCouplingValidator;
use App\Domains\Intake\Services\CompletenessChecker;
use App\Domains\Intake\Services\RefreshStoredIntakeReport;
use App\Domains\Intake\Services\WorkspacePrimaryActionResolver;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoConnectionType;
use App\Enums\AircoPlacementType;
use App\Enums\AttentionPointSource;
use App\Enums\ContributionMode;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

/** @return array{0: User, 1: Intake, 2: AircoRoom} */
function n74Survey(?User $user = null): array
{
    $user ??= User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Scopetest',
        'customer_email' => 'scope@example.com',
        'address_line' => 'Testlaan 10',
        'address_postal_code' => '1000AA',
        'address_house_number' => 10,
        'address_city' => 'Amsterdam',
    ]);
    $survey = app(AircoSurveyService::class);

    $living = $survey->createRoom($intake, $user, ['name' => 'Woonkamer', 'use_type' => 'living_room']);
    $a = $survey->createRoom($intake, $user, ['name' => 'Slaapkamer 1', 'use_type' => 'bedroom']);
    $b = $survey->createRoom($intake, $user, ['name' => 'Slaapkamer 2', 'use_type' => 'bedroom']);
    $inLiving = $survey->createPlacement($intake, $user, ['airco_room_id' => $living->id, 'type' => AircoPlacementType::IndoorUnit, 'label' => 'Binnenunit woonkamer']);
    $inA = $survey->createPlacement($intake, $user, ['airco_room_id' => $a->id, 'type' => AircoPlacementType::IndoorUnit, 'label' => 'Unit 1']);
    $inB = $survey->createPlacement($intake, $user, ['airco_room_id' => $b->id, 'type' => AircoPlacementType::IndoorUnit, 'label' => 'Unit 2']);
    $out = $survey->createPlacement($intake, $user, ['type' => AircoPlacementType::OutdoorUnit, 'label' => 'Buiten']);
    $option = $survey->createInstallationOption($intake, $user, [
        'label' => 'Multi-split twee slaapkamers',
        'configuration_type' => AircoConfigurationType::MultiSplit,
        'placement_ids' => [$inLiving->id, $inA->id, $inB->id, $out->id],
        'refrigerant_links' => [
            ['from_placement_id' => $inLiving->id, 'to_placement_id' => $out->id],
            ['from_placement_id' => $inA->id, 'to_placement_id' => $out->id],
            ['from_placement_id' => $inB->id, 'to_placement_id' => $out->id],
        ],
    ]);
    $survey->createConnection($intake, $user, $option, [
        'type' => AircoConnectionType::Condensate,
        'label' => 'Afvoer woonkamer',
        'from_placement_id' => $inLiving->id,
    ]);
    $intake->answers()->create([
        'question_key' => 'indoor_unit_count',
        'value' => ['number' => 3],
        'answered_at' => now(),
    ]);
    $survey->markInstallationOptionFeasible($intake, $user, $option);
    $survey->selectInstallationOption($intake, $user, $option);

    return [$user, $intake->fresh(), $living];
}

test('N74 scope confirmation excludes existing indoor and routes without deleting their data and undo restores them', function () {
    [$user, $intake, $living] = n74Survey();
    $survey = app(AircoSurveyService::class);
    $option = $intake->aircoInstallationOptions()->firstOrFail();
    $point = app(CompletenessChecker::class);
    $intake->attentionPoints()->create([
        'source' => AttentionPointSource::System,
        'code' => 'review_split_configuration',
        'label' => $point->splitConfigurationPoint($intake)['label'],
        'is_resolved' => false,
    ]);
    $placementCount = $option->placements()->count();
    $connectionCount = $option->connections()->count();
    expect($option->newInstallationPlacements())->toHaveCount(4)
        ->and($option->newInstallationConnections())->toHaveCount(4);

    $this->actingAs($user)->post(route('intakes.workspace.rooms.scope.confirm', [$intake, $living]))->assertRedirect();
    $option->refresh();
    expect($option->newInstallationPlacements())->toHaveCount(3)
        ->and($option->newInstallationConnections())->toHaveCount(2)
        ->and($option->placements()->count())->toBe($placementCount)
        ->and($option->connections()->count())->toBe($connectionCount)
        ->and($point->splitConfigurationPoint($intake->fresh())['label'])->toContain('2 ruimtes')
        ->and($intake->attentionPoints()->first()->label)->toContain('2 ruimtes')
        ->and(app(AircoUnitCouplingValidator::class)->optionProblems($option))->toBe([]);

    $response = $this->actingAs($user)->get(route('intakes.workspace', $intake));
    $response->assertOk()
        ->assertSee('Bestaande installatie blijft — telt niet mee als nieuwe unit.')
        ->assertDontSee('Afvoer woonkamer')
        ->assertDontSee('data-form-key="coupling-'.$living->id.'"', false);
    $indoor = $option->placements->firstWhere('label', 'Binnenunit woonkamer');
    $response->assertDontSee('name="placement_ids[]" value="'.$indoor->id.'"', false);

    $survey->clearRoomScopeConfirmation($intake, $user, $living->fresh());
    $option->refresh();
    expect($option->newInstallationPlacements())->toHaveCount(4)
        ->and($option->newInstallationConnections())->toHaveCount(4)
        ->and($intake->attentionPoints()->first()->label)->toContain('3 ruimtes');
});

test('N74 a confirmed existing unit cannot be submitted as a new choice or coupled again', function () {
    [$user, $intake, $living] = n74Survey();
    $survey = app(AircoSurveyService::class);
    $survey->confirmRoomExistingInstallation($intake, $user, $living);
    $option = $intake->aircoInstallationOptions()->firstOrFail();
    expect(fn () => $survey->createInstallationOption($intake, $user, [
        'label' => 'Nieuwe keuze',
        'configuration_type' => AircoConfigurationType::MultiSplit,
        'placement_ids' => $option->placements->pluck('id')->all(),
    ]))->toThrow(ValidationException::class);
    expect(fn () => $survey->syncRoomUnitCoupling($intake, $user, $living->fresh(), [
        'indoor_label' => 'Nieuw',
        'configuration_type' => AircoConfigurationType::MultiSplit,
    ]))->toThrow(ValidationException::class);
});

test('N74 split attention disappears below two new rooms and returns on undo', function () {
    [$user, $intake, $living] = n74Survey();
    $survey = app(AircoSurveyService::class);
    $checker = app(CompletenessChecker::class);
    $survey->confirmRoomExistingInstallation($intake, $user, $living);
    $bedroom = $intake->aircoRooms()->where('name', 'Slaapkamer 1')->firstOrFail();
    $survey->confirmRoomExistingInstallation($intake, $user, $bedroom);
    expect($checker->splitConfigurationPoint($intake->fresh()))->toBeNull();
    $survey->clearRoomScopeConfirmation($intake, $user, $bedroom->fresh());
    expect($checker->splitConfigurationPoint($intake->fresh())['label'])->toContain('2 ruimtes');
});

test('N74 dedicated singles exclude only the existing rooms outdoor and its routes and undo restores all', function () {
    [$user, $intake, $living] = n74Survey();
    $survey = app(AircoSurveyService::class);
    $indoors = $intake->aircoPlacements()->where('type', AircoPlacementType::IndoorUnit)->get();
    $ids = $indoors->pluck('id')->all();
    $links = [];
    foreach ($indoors as $indoor) {
        $outdoor = $survey->createPlacement($intake, $user, ['type' => AircoPlacementType::OutdoorUnit, 'label' => 'Buiten '.$indoor->id]);
        $ids[] = $outdoor->id;
        // Exercise both refrigerant directions.
        $links[] = ['from_placement_id' => $outdoor->id, 'to_placement_id' => $indoor->id];
    }
    $option = $survey->createInstallationOption($intake, $user, [
        'label' => 'Drie singles',
        'configuration_type' => AircoConfigurationType::MultipleSingleSplits,
        'placement_ids' => $ids,
        'refrigerant_links' => $links,
    ]);
    $power = $survey->createConnection($intake, $user, $option, [
        'type' => AircoConnectionType::Power,
        'label' => 'Voeding bestaande buitenunit',
        'from_placement_id' => $links[0]['from_placement_id'],
    ]);
    expect(app(AircoUnitCouplingValidator::class)->optionProblems($option->fresh()))->toBe([]);
    $survey->confirmRoomExistingInstallation($intake, $user, $living);
    $option->refresh();
    expect($option->newInstallationPlacements())->toHaveCount(4)
        ->and($option->newInstallationConnections())->toHaveCount(2)
        ->and($option->newInstallationConnections()->contains('id', $power->id))->toBeFalse()
        ->and(app(AircoUnitCouplingValidator::class)->optionProblems($option))->toBe([])
        ->and($option->placements()->count())->toBe(6)
        ->and($option->connections()->count())->toBe(4);
    $survey->clearRoomScopeConfirmation($intake, $user, $living->fresh());
    $option->refresh();
    expect($option->newInstallationPlacements())->toHaveCount(6)
        ->and($option->newInstallationConnections())->toHaveCount(4)
        ->and(app(AircoUnitCouplingValidator::class)->optionProblems($option))->toBe([]);
});

test('N74 primary route action skips hidden routes and falls back when none remain', function () {
    [$user, $intake, $living] = n74Survey();
    app(AircoSurveyService::class)->confirmRoomExistingInstallation($intake, $user, $living);
    $intake = $intake->fresh();
    $option = $intake->aircoInstallationOptions->first();
    $visible = $option->newInstallationConnections()->firstWhere('type', AircoConnectionType::Refrigerant);
    $resolver = app(WorkspacePrimaryActionResolver::class);
    expect($resolver->targetForArea($intake, 'refrigerant')['href'])->toBe('#connection-'.$visible->id)
        ->and($resolver->targetForArea($intake, 'condensate')['href'])->toBe('#demo-proposal');
});

test('N74 confirmation and undo refresh the report once each after attention point synchronization', function () {
    [$user, $intake, $living] = n74Survey();
    $intake->attentionPoints()->create([
        'source' => AttentionPointSource::System,
        'code' => 'review_split_configuration',
        'label' => 'Oude telling',
        'is_resolved' => false,
    ]);
    $refresh = Mockery::mock(RefreshStoredIntakeReport::class);
    $refresh->shouldReceive('handle')->once()->ordered()->withArgs(function (Intake $current) {
        return str_contains($current->attentionPoints()->where('code', 'review_split_configuration')->firstOrFail()->label, '2 ruimtes');
    });
    $refresh->shouldReceive('handle')->once()->ordered()->withArgs(function (Intake $current) {
        return str_contains($current->attentionPoints()->where('code', 'review_split_configuration')->firstOrFail()->label, '3 ruimtes');
    });
    $this->app->instance(RefreshStoredIntakeReport::class, $refresh);
    $survey = app(AircoSurveyService::class);
    $survey->confirmRoomExistingInstallation($intake, $user, $living);
    $survey->clearRoomScopeConfirmation($intake, $user, $living->fresh());
});
