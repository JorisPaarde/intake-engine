<?php

declare(strict_types=1);

use App\Domains\AI\Jobs\DeriveIntentFromRequestJob;
use App\Domains\AI\Jobs\SynthesizeSurveyDossierJob;
use App\Domains\AI\Models\AiRun;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoPlacementType;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\ContributionMode;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Mail::fake();
});

function reviewRound5Intake(User $user, string $email, string $reason = 'Woonkamer koelen op de begane grond.'): Intake
{
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Review Round 5',
        'customer_email' => $email,
        'address_line' => 'Testlaan 10',
        'address_postal_code' => '1000AA',
        'address_house_number' => 10,
        'address_city' => 'Amsterdam',
        'prefill' => [
            'request_reason' => $reason,
        ],
    ]);

    return $intake->fresh() ?? $intake;
}

function reviewRound5Ready(Intake $intake, User $user): Intake
{
    $survey = app(AircoSurveyService::class);
    $room = $survey->createRoom($intake, $user, [
        'name' => 'Woonkamer',
        'use_type' => 'living_room',
        'length_m' => 6,
        'width_m' => 4,
    ]);
    $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::IndoorUnit,
        'label' => 'Binnenunit',
        'airco_room_id' => $room->id,
    ]);
    $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::OutdoorUnit,
        'label' => 'Buitenunit',
    ]);
    $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::PowerSource,
        'label' => 'Meterkast',
    ]);
    $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::DrainPoint,
        'label' => 'Afvoer',
    ]);

    return $intake->fresh() ?? $intake;
}

test('SynthesizeSurveyDossierJob: overlap-release brandt maxExceptions niet op via tries', function () {
    $job = new SynthesizeSurveyDossierJob(1, preserveProposedCustomerTasks: true);

    // Geen harde tries=2: overlap-releases mogen tot retryUntil doorgaan; alleen exceptions tellen.
    expect($job->maxExceptions)->toBe(2)
        ->and($job->retryUntil()->getTimestamp())->toBeGreaterThan(now()->addMinutes(4)->getTimestamp())
        ->and(property_exists($job, 'tries'))->toBeFalse();
});

test('notitie-dispatch gebruikt geen skipIfCustomerStarted; create wel', function () {
    Queue::fake();
    config(['ai.text_inference.enabled' => true, 'ai.provider' => 'fake']);

    $user = User::factory()->create();
    $intake = reviewRound5Intake($user, 'note-no-skip@example.com');
    $intake->forceFill([
        'status' => IntakeStatus::Sent,
        'current_question_key' => 'ownership',
        'customer_access_enabled' => true,
        'access_token' => str_repeat('n', 64),
        'token_expires_at' => now()->addDay(),
    ])->save();

    $room = app(AircoSurveyService::class)->createRoom($intake->fresh() ?? $intake, $user, [
        'name' => 'Woonkamer',
        'use_type' => 'living_room',
    ]);

    $this->actingAs($user)
        ->post(route('intakes.workspace.notes.store', [$intake, $room->subject]), [
            'text' => 'Massieve buitenmuur, vanaf de grond bereikbaar.',
        ])
        ->assertRedirect();

    Queue::assertPushed(DeriveIntentFromRequestJob::class, static function (DeriveIntentFromRequestJob $job) use ($intake): bool {
        return $job->intakeId === $intake->id && $job->skipIfCustomerStarted === false;
    });

    // Create-pad: skip-vlag aan.
    Queue::fake();
    config(['ai.request_prefill.sync_on_create' => false]);
    $this->actingAs($user)
        ->post(route('intakes.store'), [
            'template_key' => 'airco',
            'workflow_mode' => ContributionMode::Installer->value,
            'customer_name' => 'Create Skip',
            'customer_email' => 'create-skip@example.com',
            'address_line' => 'Testlaan 11',
            'address_postal_code' => '1000AB',
            'address_house_number' => 11,
            'address_city' => 'Amsterdam',
            'request_reason' => 'Woonkamer koelen op de begane grond.',
        ]);

    Queue::assertPushed(DeriveIntentFromRequestJob::class, static function (DeriveIntentFromRequestJob $job): bool {
        return $job->skipIfCustomerStarted === true;
    });
});

test('mislukte dossiersynthese toont neutrale workspace-tekst, geen zo-opgesteld', function () {
    config(['ai.dossier.enabled' => true]);

    $user = User::factory()->create();
    $intake = reviewRound5Ready(reviewRound5Intake($user, 'failed-synth-copy@example.com'), $user);
    $survey = app(AircoSurveyService::class);
    $indoor = $intake->aircoPlacements()->where('type', AircoPlacementType::IndoorUnit)->firstOrFail();
    $outdoor = $intake->aircoPlacements()->where('type', AircoPlacementType::OutdoorUnit)->firstOrFail();
    $survey->createInstallationOption($intake, $user, [
        'label' => 'Single-split',
        'configuration_type' => AircoConfigurationType::SingleSplit,
        'placement_ids' => [$indoor->id, $outdoor->id],
    ]);

    AiRun::query()->create([
        'intake_id' => $intake->id,
        'type' => AiRunType::DossierSynthesis,
        'provider' => 'fake',
        'model' => 'test',
        'prompt_version' => 'test',
        'input_hash' => 'failed-synth',
        'output' => null,
        'status' => AiRunStatus::Failed,
        'error_message' => 'provider_error',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('Er is nog geen AI-voorstel.', false)
        ->assertDontSee('Het AI-voorstel wordt zo opgesteld.', false);
});

test('pending dossiersynthese toont zo-opgesteld; RequestIntent-pending niet', function () {
    config(['ai.dossier.enabled' => true]);

    $user = User::factory()->create();
    $intake = reviewRound5Ready(reviewRound5Intake($user, 'pending-synth-copy@example.com'), $user);
    $survey = app(AircoSurveyService::class);
    $indoor = $intake->aircoPlacements()->where('type', AircoPlacementType::IndoorUnit)->firstOrFail();
    $outdoor = $intake->aircoPlacements()->where('type', AircoPlacementType::OutdoorUnit)->firstOrFail();
    $survey->createInstallationOption($intake, $user, [
        'label' => 'Single-split',
        'configuration_type' => AircoConfigurationType::SingleSplit,
        'placement_ids' => [$indoor->id, $outdoor->id],
    ]);

    AiRun::query()->create([
        'intake_id' => $intake->id,
        'type' => AiRunType::RequestIntent,
        'provider' => 'fake',
        'model' => 'test',
        'prompt_version' => 'test',
        'input_hash' => 'pending-intent',
        'output' => null,
        'status' => AiRunStatus::Pending,
        'started_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('Er is nog geen AI-voorstel.', false)
        ->assertDontSee('Het AI-voorstel wordt zo opgesteld.', false);

    AiRun::query()->create([
        'intake_id' => $intake->id,
        'type' => AiRunType::DossierSynthesis,
        'provider' => 'fake',
        'model' => 'test',
        'prompt_version' => 'test',
        'input_hash' => 'pending-synth',
        'output' => null,
        'status' => AiRunStatus::Pending,
        'started_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('Het AI-voorstel wordt zo opgesteld.', false)
        ->assertDontSee('Er is nog geen AI-voorstel.', false);
});
