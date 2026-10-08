<?php

declare(strict_types=1);

use App\Domains\AI\Actions\DeriveIntentFromRequest;
use App\Domains\AI\Actions\SynthesizeSurveyDossier;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\DTOs\AiCompletionRequest;
use App\Domains\AI\Jobs\DeriveIntentFromRequestJob;
use App\Domains\AI\Jobs\SynthesizeSurveyDossierJob;
use App\Domains\AI\Models\AiRun;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoPlacementType;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\ContributionMode;
use App\Enums\ContributionTaskStatus;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    FakeAiClient::reset();
    Mail::fake();
});

afterEach(function () {
    FakeAiClient::reset();
});

function reviewRound2Intake(User $user, string $email, string $reason = 'Woonkamer koelen op de begane grond.'): Intake
{
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Review Round 2',
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

/** @return array<string, mixed> */
function reviewRound2SynthesisOutput(AiCompletionRequest $request): array
{
    $placements = collect($request->input['placements'])->keyBy('type');
    $inside = $placements->get('indoor_unit')['reference'];
    $outside = $placements->get('outdoor_unit')['reference'];
    $power = $placements->get('power_source')['reference'] ?? $outside;
    $drain = $placements->get('drain_point')['reference'] ?? $outside;
    $roomSubject = collect($request->input['rooms'])->first()['subject_reference'] ?? null;

    return [
        'summary' => 'Review round 2 voorstel.',
        'placement_proposals' => [],
        'option_proposals' => [[
            'key' => 'option-a',
            'label' => 'Single-split voorstel',
            'configuration_type' => AircoConfigurationType::SingleSplit->value,
            'summary' => 'Eén binnen- en buitenunit.',
            'cost_impact' => 'medium',
            'confidence' => 0.8,
            'placement_references' => array_values(array_filter([$inside, $outside, $power, $drain])),
            'connections' => [
                [
                    'type' => 'refrigerant',
                    'label' => 'Koelleiding',
                    'from_placement_reference' => $inside,
                    'to_placement_reference' => $outside,
                    'status' => 'proposed',
                    'length_class' => 'short',
                    'segments' => [],
                    'obstacles' => [],
                    'uncertainties' => [],
                    'cost_impact' => 'low',
                    'confidence' => 0.7,
                    'evidence_references' => [$inside],
                ],
                [
                    'type' => 'condensate',
                    'label' => 'Condens',
                    'from_placement_reference' => $inside,
                    'to_placement_reference' => $drain,
                    'status' => 'proposed',
                    'length_class' => 'short',
                    'segments' => [],
                    'obstacles' => [],
                    'uncertainties' => [],
                    'cost_impact' => 'low',
                    'confidence' => 0.7,
                    'evidence_references' => [$drain],
                ],
                [
                    'type' => 'power',
                    'label' => 'Stroom',
                    'from_placement_reference' => $outside,
                    'to_placement_reference' => $power,
                    'status' => 'proposed',
                    'length_class' => 'short',
                    'segments' => [],
                    'obstacles' => [],
                    'uncertainties' => [],
                    'cost_impact' => 'low',
                    'confidence' => 0.7,
                    'evidence_references' => [$power],
                ],
            ],
        ]],
        'exceptions' => [],
        'customer_tasks' => [[
            'type' => 'photo',
            'prompt' => 'Maak een foto van de buitenmuur bij de woonkamer.',
            'decision_area_key' => 'placement',
            'subject_reference' => $roomSubject,
            'reason' => 'Buitenplaatsing nog onzeker.',
            'evidence_references' => [$outside],
        ]],
    ];
}

function reviewRound2ReadyForSynthesis(Intake $intake, User $user): Intake
{
    $survey = app(AircoSurveyService::class);
    $room = $survey->createRoom($intake, $user, [
        'name' => 'Woonkamer',
        'use_type' => 'living_room',
        'length_m' => 5,
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

function reviewRound2FloorCatalog(): array
{
    return [
        'sections' => [[
            'key' => 'rooms',
            'is_repeatable' => true,
            'questions' => [
                [
                    'key' => 'room_type',
                    'type' => 'single_choice',
                    'label' => 'Type',
                    'options' => [
                        ['value' => 'living_room'],
                        ['value' => 'bedroom'],
                    ],
                ],
                [
                    'key' => 'floor_level',
                    'type' => 'single_choice',
                    'label' => 'Verdieping',
                    'options' => [
                        ['value' => 'ground'],
                        ['value' => '1'],
                        ['value' => 'attic'],
                    ],
                ],
            ],
        ]],
    ];
}

/** @return list<string> */
function reviewRound2StepKeys(Intake $intake): array
{
    $version = $intake->templateVersion()
        ->with(['sections.questions.options', 'sections.questions.rules'])
        ->firstOrFail();

    return collect(app(IntakeStepBuilder::class)->build($intake->fresh() ?? $intake, $version))
        ->pluck('question_key')
        ->all();
}

test('preserve-modus: twee debounced runs geven geen dubbele Proposed taken', function () {
    config(['ai.dossier.enabled' => true, 'ai.provider' => 'fake']);

    $user = User::factory()->create();
    $intake = reviewRound2ReadyForSynthesis(reviewRound2Intake($user, 'no-dup-tasks@example.com'), $user);

    FakeAiClient::respondUsing(static fn (AiCompletionRequest $request): array => reviewRound2SynthesisOutput($request));

    $first = app(SynthesizeSurveyDossier::class)->handle($intake->fresh() ?? $intake, preserveProposedCustomerTasks: true);
    expect($first?->status)->toBeIn([AiRunStatus::Succeeded, AiRunStatus::Partial], $first?->error_message ?? '');

    $task = ContributionTask::query()
        ->where('intake_id', $intake->id)
        ->where('status', ContributionTaskStatus::Proposed)
        ->sole();
    $taskId = $task->id;

    $second = app(SynthesizeSurveyDossier::class)->handle($intake->fresh() ?? $intake, preserveProposedCustomerTasks: true);
    expect($second?->status)->toBeIn([AiRunStatus::Succeeded, AiRunStatus::Partial], $second?->error_message ?? '');

    $third = app(SynthesizeSurveyDossier::class)->handle($intake->fresh() ?? $intake, preserveProposedCustomerTasks: true);
    expect($third?->status)->toBeIn([AiRunStatus::Succeeded, AiRunStatus::Partial], $third?->error_message ?? '');

    $proposedAi = ContributionTask::query()
        ->where('intake_id', $intake->id)
        ->where('status', ContributionTaskStatus::Proposed)
        ->get()
        ->filter(static fn (ContributionTask $task): bool => ($task->meta['source_type'] ?? null) === 'ai');

    expect($proposedAi)->toHaveCount(1)
        ->and(ContributionTask::query()->find($taskId)?->status)->toBe(ContributionTaskStatus::Proposed)
        ->and(ContributionTask::query()->find($taskId)?->prompt)->toBe('Maak een foto van de buitenmuur bij de woonkamer.');
});

test('debounced job slaat Reviewed/AwaitingCustomer/Cancelled over; Completed mag', function () {
    config(['ai.dossier.enabled' => true, 'ai.provider' => 'fake']);

    $user = User::factory()->create();
    $intake = reviewRound2ReadyForSynthesis(reviewRound2Intake($user, 'skip-terminal@example.com'), $user);

    FakeAiClient::respondUsing(static fn (AiCompletionRequest $request): array => reviewRound2SynthesisOutput($request));

    app(SynthesizeSurveyDossier::class)->handle($intake->fresh() ?? $intake);
    $before = ContributionTask::query()
        ->where('intake_id', $intake->id)
        ->where('status', ContributionTaskStatus::Proposed)
        ->count();
    expect($before)->toBeGreaterThan(0);
    $taskId = ContributionTask::query()
        ->where('intake_id', $intake->id)
        ->where('status', ContributionTaskStatus::Proposed)
        ->value('id');

    foreach ([IntakeStatus::Reviewed, IntakeStatus::AwaitingCustomer, IntakeStatus::Cancelled] as $status) {
        $intake->forceFill(['status' => $status])->save();
        (new SynthesizeSurveyDossierJob($intake->id, preserveProposedCustomerTasks: true))
            ->handle(app(SynthesizeSurveyDossier::class));

        expect(ContributionTask::query()
            ->where('intake_id', $intake->id)
            ->where('status', ContributionTaskStatus::Proposed)
            ->count())->toBe($before, 'status '.$status->value);
    }

    // Completed: synthese mag draaien; preserve upsert houdt zelfde Proposed-id.
    $intake->forceFill(['status' => IntakeStatus::Completed])->save();
    (new SynthesizeSurveyDossierJob($intake->id, preserveProposedCustomerTasks: true))
        ->handle(app(SynthesizeSurveyDossier::class));

    expect(ContributionTask::query()
        ->where('intake_id', $intake->id)
        ->where('status', ContributionTaskStatus::Proposed)
        ->count())->toBe($before)
        ->and(ContributionTask::query()->find($taskId)?->status)->toBe(ContributionTaskStatus::Proposed);
});

test('late prefill na klantstart: geen fills en geen prune', function () {
    config([
        'ai.request_prefill.sync_on_create' => false,
        'ai.request_prefill.wizard_wait_seconds' => 20,
        'ai.text_inference.enabled' => true,
        'ai.provider' => 'fake',
    ]);

    $user = User::factory()->create();
    Queue::fake();

    $this->actingAs($user)->post(route('intakes.store'), [
        'template_key' => 'airco',
        'customer_name' => 'Late Prefill Skip',
        'customer_email' => 'late-prefill-skip@example.com',
        'address_line' => 'Testlaan 10',
        'address_postal_code' => '1000AA',
        'address_house_number' => 10,
        'address_city' => 'Amsterdam',
        'prefill' => [
            'request_reason' => 'Woonkamer en slaapkamer koelen op de begane grond.',
        ],
    ])->assertRedirect();

    $intake = Intake::query()->where('customer_email', 'late-prefill-skip@example.com')->firstOrFail();
    Queue::assertPushed(DeriveIntentFromRequestJob::class);

    // Klant is begonnen vóór de late job.
    app(SaveIntakeAnswer::class)->handle($intake, 'ownership', null, ['value' => 'owner']);
    $intake->forceFill([
        'status' => IntakeStatus::InProgress,
        'current_question_key' => 'ownership',
    ])->save();

    $answersBefore = $intake->fresh()->answers()->orderBy('id')->get()
        ->map(static fn ($a): array => [
            'key' => $a->question_key,
            'section' => $a->section_instance_key,
            'value' => $a->value,
            'prefill' => $a->prefill_source,
        ])
        ->all();
    $stepsBefore = reviewRound2StepKeys($intake);

    FakeAiClient::alwaysReturn([
        'evidence' => 'Woonkamer en slaapkamer',
        'fills' => [
            [
                'question_key' => 'indoor_unit_count',
                'section_instance_key' => null,
                'confidence' => 'high',
                'value' => ['number' => 2],
                'evidence' => 'Woonkamer en slaapkamer',
                'provenance' => 'stated',
            ],
            [
                'question_key' => 'room_type',
                'section_instance_key' => 'room-1',
                'confidence' => 'high',
                'value' => ['value' => 'living_room'],
                'evidence' => 'Woonkamer',
                'provenance' => 'stated',
            ],
            [
                'question_key' => 'room_type',
                'section_instance_key' => 'room-2',
                'confidence' => 'high',
                'value' => ['value' => 'bedroom'],
                'evidence' => 'slaapkamer',
                'provenance' => 'stated',
            ],
        ],
    ]);

    Event::fake([MessageLogged::class]);
    (new DeriveIntentFromRequestJob($intake->id, allowExternal: true, skipIfCustomerStarted: true))
        ->handle(app(DeriveIntentFromRequest::class));

    Event::assertDispatched(MessageLogged::class, static function (MessageLogged $event) use ($intake): bool {
        return $event->message === 'request_prefill.skipped'
            && ($event->context['reason'] ?? null) === 'customer_started'
            && ($event->context['intake_id'] ?? null) === $intake->id;
    });

    $answersAfter = $intake->fresh()->answers()->orderBy('id')->get()
        ->map(static fn ($a): array => [
            'key' => $a->question_key,
            'section' => $a->section_instance_key,
            'value' => $a->value,
            'prefill' => $a->prefill_source,
        ])
        ->all();

    expect($answersAfter)->toBe($answersBefore)
        ->and(reviewRound2StepKeys($intake))->toBe($stepsBefore)
        ->and(config('ai.request_prefill.wizard_wait_seconds'))->toBe(20);
});

test('prefill: klantstart tijdens model-call → geen fills/prune na lock', function () {
    config([
        'ai.text_inference.enabled' => true,
        'ai.provider' => 'fake',
        'ai.request_prefill.sync_on_create' => false,
    ]);

    $user = User::factory()->create();
    $intake = reviewRound2Intake(
        $user,
        'race-during-model@example.com',
        'Woonkamer en slaapkamer koelen op de begane grond.',
    );

    expect(app(DeriveIntentFromRequest::class)->customerHasStarted($intake))->toBeFalse();

    $callbackHit = false;

    FakeAiClient::respondUsing(function () use ($intake, &$callbackHit): array {
        $callbackHit = true;
        // Alleen cursor zetten: genoeg voor customerHasStarted; Draft staat geen null-prefill toe.
        $intake->forceFill([
            'status' => IntakeStatus::InProgress,
            'current_question_key' => 'ownership',
        ])->save();

        return [
            'evidence' => 'Woonkamer en slaapkamer',
            'fills' => [
                [
                    'question_key' => 'indoor_unit_count',
                    'section_instance_key' => null,
                    'confidence' => 'high',
                    'value' => ['number' => 2],
                    'evidence' => 'Woonkamer en slaapkamer',
                    'provenance' => 'stated',
                ],
                [
                    'question_key' => 'room_type',
                    'section_instance_key' => 'room-99',
                    'confidence' => 'high',
                    'value' => ['value' => 'office'],
                    'evidence' => 'Woonkamer',
                    'provenance' => 'stated',
                ],
            ],
        ];
    });

    Event::fake([MessageLogged::class]);

    $run = app(DeriveIntentFromRequest::class)->handle(
        $intake->fresh() ?? $intake,
        allowExternal: true,
        skipIfCustomerStarted: true,
    );

    expect($callbackHit)->toBeTrue('catalogus-AI callback moet draaien')
        ->and($run)->not->toBeNull()
        ->and($run->type)->toBe(AiRunType::RequestIntent)
        ->and($run->status)->toBe(AiRunStatus::Skipped, $run->error_message ?? 'no error')
        ->and(data_get($run->output, 'skipped'))->toBe('customer_started');

    Event::assertDispatched(MessageLogged::class, static function (MessageLogged $event) use ($intake): bool {
        return $event->message === 'request_prefill.skipped'
            && ($event->context['reason'] ?? null) === 'customer_started'
            && ($event->context['intake_id'] ?? null) === $intake->id;
    });

    // room-99 komt alleen uit de catalogus-callback — lokale parse vult die niet.
    expect(Intake::query()->findOrFail($intake->id)->answers()
        ->where('section_instance_key', 'room-99')
        ->exists())->toBeFalse()
        ->and(data_get($run->output, 'applied_question_keys'))->toBe([]);
});

test('workspace-tekst volgt auto_after_notes A/B; zonder pending run neutrale AI-tekst', function () {
    config(['ai.dossier.enabled' => true]);

    $user = User::factory()->create();
    $intake = reviewRound2ReadyForSynthesis(reviewRound2Intake($user, 'workspace-copy@example.com'), $user);
    $survey = app(AircoSurveyService::class);
    $indoor = $intake->aircoPlacements()->where('type', AircoPlacementType::IndoorUnit)->firstOrFail();
    $outdoor = $intake->aircoPlacements()->where('type', AircoPlacementType::OutdoorUnit)->firstOrFail();
    $survey->createInstallationOption($intake, $user, [
        'label' => 'Single-split',
        'configuration_type' => AircoConfigurationType::SingleSplit,
        'placement_ids' => [$indoor->id, $outdoor->id],
    ]);

    config(['ai.dossier_synthesis.auto_after_notes' => false]);
    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('automatisch bijgewerkt na een installatiekeuze', false)
        ->assertDontSee('en wanneer je notities of klantaanvullingen vastlegt', false)
        ->assertSee('Er is nog geen AI-voorstel.', false)
        ->assertDontSee('Het AI-voorstel wordt zo opgesteld.', false)
        ->assertDontSee('Dat komt automatisch na een installatiekeuze', false);

    config(['ai.dossier_synthesis.auto_after_notes' => true]);
    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('na een installatiekeuze en wanneer je notities of klantaanvullingen vastlegt', false)
        ->assertSee('Er is nog geen AI-voorstel.', false);
});

test('placeholder AiRun wordt Failed bij intake_unavailable en via failed-hook', function () {
    $user = User::factory()->create();
    $intake = reviewRound2Intake($user, 'placeholder-fail@example.com', 'Woonkamer koelen op zolder graag.');

    DeriveIntentFromRequestJob::markPending($intake);
    expect(DeriveIntentFromRequestJob::hasRecentPending($intake->id))->toBeTrue();

    // Cancelled intake → finalizePlaceholders(failed: true), geen Succeeded.
    $intake->forceFill(['status' => IntakeStatus::Cancelled])->save();
    $job = new DeriveIntentFromRequestJob($intake->id, allowExternal: true);
    $job->handle(app(DeriveIntentFromRequest::class));

    $placeholder = AiRun::query()
        ->where('intake_id', $intake->id)
        ->where('type', AiRunType::RequestIntent)
        ->where('provider', DeriveIntentFromRequestJob::PLACEHOLDER_PROVIDER)
        ->where('model', DeriveIntentFromRequestJob::PLACEHOLDER_MODEL)
        ->latest('id')
        ->first();

    expect($placeholder)->not->toBeNull()
        ->and($placeholder->status)->toBe(AiRunStatus::Failed)
        ->and($placeholder->error_message)->toBe('intake_unavailable');

    // failed()-hook: opnieuw Pending → Failed (queue-exhausted).
    DeriveIntentFromRequestJob::markPending($intake);
    $job->failed(new RuntimeException('queue-exhausted'));

    $afterFailed = AiRun::query()
        ->where('intake_id', $intake->id)
        ->where('type', AiRunType::RequestIntent)
        ->where('provider', DeriveIntentFromRequestJob::PLACEHOLDER_PROVIDER)
        ->where('status', AiRunStatus::Failed)
        ->latest('id')
        ->first();

    expect($afterFailed)->not->toBeNull()
        ->and($afterFailed->error_message)->toContain('queue-exhausted');
});
