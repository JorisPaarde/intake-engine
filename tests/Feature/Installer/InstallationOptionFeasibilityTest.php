<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\CompleteFollowUpRound;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Models\AircoInstallationOption;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\DossierRecord;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\InstallationOptionPreferenceService;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoOptionFeasibility;
use App\Enums\AircoOptionStatus;
use App\Enums\AircoPlacementType;
use App\Enums\ContributionMode;
use App\Enums\ContributionTaskStatus;
use App\Enums\FollowUpItemType;
use App\Enums\FollowUpRoundStatus;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Mail::fake();
    config(['ai.dossier.enabled' => false]);
});

/**
 * @return array{0: Intake, 1: User, 2: AircoSurveyService, 3: AircoInstallationOption, 4: AircoInstallationOption}
 */
function bl103TwoOptions(): array
{
    $user = User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Voorkeur Klant',
        'customer_email' => 'voorkeur-'.uniqid('', true).'@example.com',
        'address_line' => 'Testlaan 10',
        'address_postal_code' => '1000AA',
        'address_house_number' => 10,
        'address_city' => 'Amsterdam',
    ]);
    $survey = app(AircoSurveyService::class);

    $roomA = $survey->createRoom($intake, $user, ['name' => 'Slaapkamer A', 'use_type' => 'bedroom']);
    $roomB = $survey->createRoom($intake, $user, ['name' => 'Slaapkamer B', 'use_type' => 'bedroom']);
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
    $outdoor = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::OutdoorUnit,
        'label' => 'Achtergevel',
    ]);
    $outdoor2 = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::OutdoorUnit,
        'label' => 'Zijgevel',
    ]);

    $multi = $survey->createInstallationOption($intake, $user, [
        'label' => 'Keuze multi',
        'configuration_type' => AircoConfigurationType::MultiSplit,
        'summary' => 'Eén buitenunit voor beide kamers.',
        'placement_ids' => [$indoorA->id, $indoorB->id, $outdoor->id],
    ]);
    $singles = $survey->createInstallationOption($intake, $user, [
        'label' => 'Keuze singles',
        'configuration_type' => AircoConfigurationType::MultipleSingleSplits,
        'summary' => 'Iedere kamer een eigen buitenunit.',
        'placement_ids' => [$indoorA->id, $indoorB->id, $outdoor->id, $outdoor2->id],
    ]);

    return [$intake->fresh(), $user, $survey, $multi, $singles];
}

test('preference action is gated until at least two options are marked feasible', function () {
    $this->withoutVite();
    [$intake, $user, $survey, $multi, $singles] = bl103TwoOptions();
    $preference = app(InstallationOptionPreferenceService::class);

    expect($preference->canRequestPreference($intake))->toBeFalse();

    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertDontSee('Voorkeurstaak versturen')
        ->assertSee('Nog geen klantvoorkeur');

    $this->actingAs($user)
        ->post(route('intakes.workspace.options.preference', $intake))
        ->assertSessionHasErrors('preference');

    $survey->markInstallationOptionFeasible($intake, $user, $multi);
    $intake = $intake->fresh();
    expect($preference->canRequestPreference($intake))->toBeFalse()
        ->and($multi->fresh()->feasibility)->toBe(AircoOptionFeasibility::Feasible);

    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertDontSee('Voorkeurstaak versturen')
        ->assertSee('Eén haalbare keuze');

    $survey->markInstallationOptionFeasible($intake, $user, $singles);
    $intake = $intake->fresh();
    expect($preference->canRequestPreference($intake))->toBeTrue();

    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('Voorkeurstaak versturen')
        ->assertSee('Geen voorkeur')
        ->assertSee('Eén gedeelde buitenunit');
});

test('installer can mark options feasible or infeasible with reason', function () {
    [$intake, $user, $survey, $multi, $singles] = bl103TwoOptions();

    $this->actingAs($user)
        ->post(route('intakes.workspace.options.feasible', [$intake, $multi]))
        ->assertRedirect();

    expect($multi->fresh()->feasibility)->toBe(AircoOptionFeasibility::Feasible)
        ->and($multi->fresh()->infeasibility_reason)->toBeNull();

    $this->actingAs($user)
        ->post(route('intakes.workspace.options.infeasible', [$intake, $singles]), [
            'infeasibility_reason' => 'Geen geschikte tweede buitenplek.',
        ])
        ->assertRedirect();

    expect($singles->fresh()->feasibility)->toBe(AircoOptionFeasibility::Infeasible)
        ->and($singles->fresh()->infeasibility_reason)->toBe('Geen geschikte tweede buitenplek.');

    $this->actingAs($user)
        ->post(route('intakes.workspace.options.infeasible', [$intake, $multi]), [])
        ->assertSessionHasErrors('infeasibility_reason');
});

test('selecting an option requires prior feasibility and does not auto-select from preference', function () {
    [$intake, $user, $survey, $multi, $singles] = bl103TwoOptions();

    expect(fn () => $survey->selectInstallationOption($intake, $user, $multi))
        ->toThrow(ValidationException::class);

    $survey->markInstallationOptionFeasible($intake, $user, $multi);
    $survey->markInstallationOptionFeasible($intake, $user, $singles);

    $this->actingAs($user)
        ->post(route('intakes.workspace.options.preference', $intake))
        ->assertRedirect();

    $task = ContributionTask::query()
        ->where('intake_id', $intake->id)
        ->where('status', ContributionTaskStatus::Open)
        ->firstOrFail();
    expect($task->type)->toBe(FollowUpItemType::Choice)
        ->and($task->meta['kind'] ?? null)->toBe(InstallationOptionPreferenceService::META_KIND)
        ->and($intake->fresh()->status)->toBe(IntakeStatus::AwaitingCustomer);

    $item = $task->followUpItem()->firstOrFail();
    $preferredValue = app(InstallationOptionPreferenceService::class)->optionChoiceValue($singles->id);

    app(CompleteFollowUpRound::class)->handle($intake->fresh(), $item->round, [
        $item->id => $preferredValue,
    ]);

    $intake = $intake->fresh(['aircoInstallationOptions', 'contributionTasks']);
    expect($intake->aircoInstallationOptions->where('status', AircoOptionStatus::Selected))->toHaveCount(0)
        ->and($multi->fresh()->status)->toBe(AircoOptionStatus::Candidate)
        ->and($singles->fresh()->status)->toBe(AircoOptionStatus::Candidate);

    $record = DossierRecord::query()
        ->where('intake_id', $intake->id)
        ->where('key', 'customer_installation_preference')
        ->first();
    expect($record)->not->toBeNull()
        ->and($record?->value['preferred_option_id'] ?? null)->toBe($singles->id)
        ->and($record?->value['auto_selected'] ?? null)->toBeFalse()
        ->and($record?->value['no_preference'] ?? null)->toBeFalse();

    $survey->selectInstallationOption($intake, $user, $multi);
    expect($multi->fresh()->status)->toBe(AircoOptionStatus::Selected)
        ->and($singles->fresh()->status)->toBe(AircoOptionStatus::Candidate);
});

test('customer can choose no preference without selecting an installation option', function () {
    [$intake, $user, $survey, $multi, $singles] = bl103TwoOptions();
    $survey->markInstallationOptionFeasible($intake, $user, $multi);
    $survey->markInstallationOptionFeasible($intake, $user, $singles);

    app(InstallationOptionPreferenceService::class)->requestPreference($intake, $user);

    $task = ContributionTask::query()
        ->where('intake_id', $intake->id)
        ->where('status', ContributionTaskStatus::Open)
        ->firstOrFail();
    $item = $task->followUpItem()->firstOrFail();

    app(CompleteFollowUpRound::class)->handle($intake->fresh(), $item->round, [
        $item->id => InstallationOptionPreferenceService::NO_PREFERENCE_VALUE,
    ]);

    $record = DossierRecord::query()
        ->where('intake_id', $intake->id)
        ->where('key', 'customer_installation_preference')
        ->firstOrFail();

    expect($record->value['no_preference'] ?? null)->toBeTrue()
        ->and(array_key_exists('preferred_option_id', $record->value))->toBeTrue()
        ->and($record->value['preferred_option_id'])->toBeNull()
        ->and($intake->fresh()->aircoInstallationOptions->where('status', AircoOptionStatus::Selected))->toHaveCount(0);
});

test('changing feasible options marks open or old preference answers as stale', function () {
    $this->withoutVite();
    [$intake, $user, $survey, $multi, $singles] = bl103TwoOptions();
    $survey->markInstallationOptionFeasible($intake, $user, $multi);
    $survey->markInstallationOptionFeasible($intake, $user, $singles);

    app(InstallationOptionPreferenceService::class)->requestPreference($intake, $user);
    $openTask = ContributionTask::query()
        ->where('intake_id', $intake->id)
        ->where('status', ContributionTaskStatus::Open)
        ->firstOrFail();

    $survey->markInstallationOptionInfeasible(
        $intake,
        $user,
        $singles,
        'Tweede buitenunit past niet.',
    );

    $openTask = $openTask->fresh();
    expect($openTask->status)->toBe(ContributionTaskStatus::Cancelled)
        ->and($openTask->meta['stale'] ?? null)->toBeTrue()
        ->and($intake->fresh()->status)->not->toBe(IntakeStatus::AwaitingCustomer)
        ->and($intake->followUpRounds()->where('status', FollowUpRoundStatus::Cancelled)->exists())->toBeTrue();

    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('verouderd');

    // Recreate two feasible options and complete a preference, then change set → completed answer stale.
    $survey->markInstallationOptionFeasible($intake->fresh(), $user, $singles->fresh());
    $result = app(InstallationOptionPreferenceService::class)->requestPreference($intake->fresh(), $user);
    $round = $result['round']->fresh(['items']);
    $item = $round->items->firstOrFail();
    $intakeReady = $intake->fresh();

    expect($round->status)->toBe(FollowUpRoundStatus::Open)
        ->and($intakeReady->status)->toBe(IntakeStatus::AwaitingCustomer);

    app(CompleteFollowUpRound::class)->handle($intakeReady, $round, [
        $item->id => InstallationOptionPreferenceService::NO_PREFERENCE_VALUE,
    ]);

    $completedTask = ContributionTask::query()
        ->where('intake_follow_up_item_id', $item->id)
        ->firstOrFail();
    expect($completedTask->status)->toBe(ContributionTaskStatus::Completed);

    $survey->markInstallationOptionInfeasible(
        $intake->fresh(),
        $user,
        $multi->fresh(),
        'Koelroute te lang voor multi-split.',
    );

    expect($completedTask->fresh()->meta['stale'] ?? null)->toBeTrue()
        ->and($completedTask->fresh()->status)->toBe(ContributionTaskStatus::Completed);
});
