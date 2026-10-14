<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\DecisionReadinessService;
use App\Domains\Intake\Services\IntakeDossierSummaryBuilder;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoPlacementType;
use App\Enums\ContributionMode;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

/** @return array{0: User, 1: Intake, 2: AircoRoom} */
function n68IntakeWithSelectedBedroomsOnly(?User $user = null): array
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
    $inA = $survey->createPlacement($intake, $user, ['airco_room_id' => $a->id, 'type' => AircoPlacementType::IndoorUnit, 'label' => 'Unit 1']);
    $inB = $survey->createPlacement($intake, $user, ['airco_room_id' => $b->id, 'type' => AircoPlacementType::IndoorUnit, 'label' => 'Unit 2']);
    $out = $survey->createPlacement($intake, $user, ['type' => AircoPlacementType::OutdoorUnit, 'label' => 'Buiten']);
    $option = $survey->createInstallationOption($intake, $user, [
        'label' => 'Multi-split twee slaapkamers',
        'configuration_type' => AircoConfigurationType::MultiSplit,
        'placement_ids' => [$inA->id, $inB->id, $out->id],
        'refrigerant_links' => [
            ['from_placement_id' => $inA->id, 'to_placement_id' => $out->id],
            ['from_placement_id' => $inB->id, 'to_placement_id' => $out->id],
        ],
    ]);
    $survey->markInstallationOptionFeasible($intake, $user, $option);
    $survey->selectInstallationOption($intake, $user, $option);

    return [$user, $intake->fresh(), $living];
}

function n68CoverageBlockers(Intake $intake): array
{
    $assessment = app(DecisionReadinessService::class)->bulkApprovalAssessment($intake->fresh());

    return array_values(array_filter(
        $assessment['blockers'],
        static fn (string $b): bool => str_contains($b, 'bedient niet alle aangevraagde ruimtes'),
    ));
}

test('coverage blocks before and not after the installer confirms the existing installation', function () {
    [$user, $intake, $living] = n68IntakeWithSelectedBedroomsOnly();

    expect(n68CoverageBlockers($intake))->toHaveCount(1)
        ->and(n68CoverageBlockers($intake)[0])->toContain('Woonkamer');

    $this->actingAs($user)
        ->post(route('intakes.workspace.rooms.scope.confirm', [$intake, $living]))
        ->assertRedirect();

    $living->refresh();
    expect($living->scope_status)->toBe(AircoRoom::SCOPE_EXISTING_REMAINS)
        ->and($living->scope_confirmed_by)->toBe($user->id)
        ->and($living->scope_confirmed_at)->not->toBeNull()
        ->and(n68CoverageBlockers($intake))->toBe([])
        ->and(IntakeActivityEvent::query()->where('intake_id', $intake->id)->where('event', 'airco_room_scope_confirmed')->exists())->toBeTrue();
});

test('confirmation can be undone and coverage blocks again', function () {
    [$user, $intake, $living] = n68IntakeWithSelectedBedroomsOnly();

    $this->actingAs($user)->post(route('intakes.workspace.rooms.scope.confirm', [$intake, $living]));
    $this->actingAs($user)->post(route('intakes.workspace.rooms.scope.clear', [$intake, $living]))->assertRedirect();

    $living->refresh();
    expect($living->scope_status)->toBeNull()
        ->and($living->scope_confirmed_by)->toBeNull()
        ->and(n68CoverageBlockers($intake))->toHaveCount(1);
});

test('report summary mentions the confirmed existing installation', function () {
    [$user, $intake, $living] = n68IntakeWithSelectedBedroomsOnly();

    $summary = fn (): string => app(IntakeDossierSummaryBuilder::class)->build(
        $intake->fresh(),
        $intake->templateVersion()->firstOrFail(),
    );
    $before = $summary();
    expect($before)->not->toContain('bestaande installatie blijft');

    app(AircoSurveyService::class)->confirmRoomExistingInstallation($intake, $user, $living);

    $after = $summary();
    expect($after)->toContain('Woonkamer')->toContain('bestaande installatie blijft (bevestigd door installateur)');
});

test('room of another intake or company cannot be confirmed', function () {
    [$user, $intake, $living] = n68IntakeWithSelectedBedroomsOnly();
    [, $otherIntake] = n68IntakeWithSelectedBedroomsOnly($user);

    $this->actingAs($user)
        ->post(route('intakes.workspace.rooms.scope.confirm', [$otherIntake, $living]))
        ->assertStatus(404);

    $stranger = User::factory()->create(['company_id' => Company::factory()->create()->id]);
    $this->actingAs($stranger)
        ->post(route('intakes.workspace.rooms.scope.confirm', [$intake, $living]))
        ->assertForbidden();

    expect($living->fresh()->scope_status)->toBeNull();
});
