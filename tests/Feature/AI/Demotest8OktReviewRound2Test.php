<?php

declare(strict_types=1);

use App\Domains\AI\Actions\DeriveIntentFromRequest;
use App\Domains\AI\Actions\RecordExistingAircoFromRequest;
use App\Domains\AI\Actions\SynthesizeSurveyDossier;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\DTOs\AiCompletionRequest;
use App\Domains\AI\DTOs\RequestPrefillCandidate;
use App\Domains\AI\Jobs\DebouncedSynthesizeSurveyDossierJob;
use App\Domains\AI\Jobs\DeriveIntentFromRequestJob;
use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\RequestPrefillOutcomeClassifier;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\DossierRecord;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoPlacementType;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\ContributionMode;
use App\Enums\ContributionTaskStatus;
use App\Enums\DossierRecordKind;
use App\Enums\DossierRecordStatus;
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

test('debounced job na Completed/AwaitingCustomer maakt geen extra Proposed set', function () {
    config(['ai.dossier.enabled' => true, 'ai.provider' => 'fake']);

    $user = User::factory()->create();
    $intake = reviewRound2ReadyForSynthesis(reviewRound2Intake($user, 'skip-completed@example.com'), $user);

    FakeAiClient::respondUsing(static fn (AiCompletionRequest $request): array => reviewRound2SynthesisOutput($request));

    // CompleteIntake-keten (zonder preserve) → eerste Proposed set.
    app(SynthesizeSurveyDossier::class)->handle($intake->fresh() ?? $intake);
    $before = ContributionTask::query()
        ->where('intake_id', $intake->id)
        ->where('status', ContributionTaskStatus::Proposed)
        ->count();
    expect($before)->toBeGreaterThan(0);

    foreach ([IntakeStatus::Completed, IntakeStatus::Reviewed, IntakeStatus::AwaitingCustomer, IntakeStatus::Cancelled] as $status) {
        $intake->forceFill(['status' => $status])->save();
        (new DebouncedSynthesizeSurveyDossierJob($intake->id))
            ->handle(app(SynthesizeSurveyDossier::class));

        expect(ContributionTask::query()
            ->where('intake_id', $intake->id)
            ->where('status', ContributionTaskStatus::Proposed)
            ->count())->toBe($before, 'status '.$status->value);
    }
});

test('auto_after_notes B: debounced na Completed maakt geen extra Proposed set', function () {
    config([
        'ai.dossier.enabled' => true,
        'ai.provider' => 'fake',
        'ai.dossier_synthesis.auto_after_notes' => true,
    ]);

    $user = User::factory()->create();
    $intake = reviewRound2ReadyForSynthesis(reviewRound2Intake($user, 'auto-notes-skip@example.com'), $user);

    FakeAiClient::respondUsing(static fn (AiCompletionRequest $request): array => reviewRound2SynthesisOutput($request));
    app(SynthesizeSurveyDossier::class)->handle($intake->fresh() ?? $intake);

    $before = ContributionTask::query()
        ->where('intake_id', $intake->id)
        ->where('status', ContributionTaskStatus::Proposed)
        ->count();

    $intake->forceFill(['status' => IntakeStatus::Completed])->save();
    (new DebouncedSynthesizeSurveyDossierJob($intake->id))
        ->handle(app(SynthesizeSurveyDossier::class));

    expect(ContributionTask::query()
        ->where('intake_id', $intake->id)
        ->where('status', ContributionTaskStatus::Proposed)
        ->count())->toBe($before);
});

test('RecordExistingAirco is idempotent bij herhaalde handle en raakt installer-record niet', function () {
    config(['ai.text_inference.enabled' => false]);

    $user = User::factory()->create();
    $reason = 'Er hangt al een oude airco in de woonkamer die vervangen moet worden.';
    $intake = reviewRound2Intake($user, 'airco-idempotent@example.com', $reason);

    $action = app(RecordExistingAircoFromRequest::class);
    $action->handle($intake->fresh() ?? $intake, $reason);
    $action->handle($intake->fresh() ?? $intake, $reason);
    $action->handle($intake->fresh() ?? $intake, $reason);

    $records = DossierRecord::query()
        ->where('intake_id', $intake->id)
        ->where('key', RecordExistingAircoFromRequest::RECORD_KEY)
        ->get();

    expect($records)->toHaveCount(1)
        ->and($records->first()->actor_type)->toBe('system')
        ->and($records->first()->superseded_by_id)->toBeNull();

    // Installateur-record nooit overschrijven.
    $installerIntake = reviewRound2Intake($user, 'airco-installer-lock@example.com', $reason);
    app(DossierManager::class)->initialize($installerIntake);
    $root = app(DossierManager::class)->root($installerIntake->fresh() ?? $installerIntake);
    app(DossierManager::class)->record(
        intake: $installerIntake,
        subject: $root,
        kind: DossierRecordKind::Observation,
        key: RecordExistingAircoFromRequest::RECORD_KEY,
        value: [
            'text' => 'Handmatig: bestaande airco blijft',
            'present' => true,
            'replacement' => false,
            'room_type' => 'living_room',
        ],
        actorType: 'installer',
        actorId: $user->id,
        sourceType: 'installer',
        sourceId: $user->id,
        method: 'installer_observation',
        confidence: 1.0,
        status: DossierRecordStatus::Established,
    );

    $action->handle($installerIntake->fresh() ?? $installerIntake, $reason);

    $open = DossierRecord::query()
        ->where('intake_id', $installerIntake->id)
        ->where('key', RecordExistingAircoFromRequest::RECORD_KEY)
        ->whereNull('superseded_by_id')
        ->get();

    expect($open)->toHaveCount(1)
        ->and($open->first()->actor_type)->toBe('installer')
        ->and($open->first()->value['replacement'] ?? null)->toBeFalse();
});

test('bare boven/beneden in plaatsing is geen floor-cue', function () {
    $text = 'Airco boven de bank, ca. 25 m²';
    $result = app(RequestPrefillOutcomeClassifier::class)->classifyCatalogOutput([
        'evidence' => $text,
        'fills' => [
            [
                'question_key' => 'room_type',
                'section_instance_key' => 'room-1',
                'confidence' => 'high',
                'value' => ['value' => 'living_room'],
                'evidence' => 'Airco',
                'provenance' => 'inferred',
            ],
            [
                'question_key' => 'floor_level',
                'section_instance_key' => 'room-1',
                'confidence' => 'high',
                'value' => ['value' => 'ground'],
                'evidence' => 'boven',
                'provenance' => 'inferred',
            ],
        ],
    ], reviewRound2FloorCatalog(), [], $text);

    $floor = collect($result['candidates'])->first(
        static fn (RequestPrefillCandidate $c): bool => $c->questionKey === 'floor_level',
    );

    expect($floor)->not->toBeNull()
        ->and($floor->disposition)->toBe(RequestPrefillCandidate::DISPOSITION_REJECTED)
        ->and($floor->evidence)->not->toBe('boven')
        ->and($floor->evidence === null || ! preg_match('/^(?:boven|beneden)$/iu', trim((string) $floor->evidence)))->toBeTrue()
        ->and(collect($result['candidates'])
            ->where('questionKey', 'floor_level')
            ->where('disposition', RequestPrefillCandidate::DISPOSITION_FILL)
            ->count())->toBe(0);
});

test('wissen van verdieping houdt installer-marker; prefill zet niet terug', function () {
    config(['ai.text_inference.enabled' => true, 'ai.provider' => 'fake']);

    $user = User::factory()->create();
    $intake = reviewRound2Intake(
        $user,
        'floor-clear-marker@example.com',
        'Woonkamer koelen op de begane grond, ca. 25 m².',
    );

    app(DeriveIntentFromRequest::class)->handle($intake->fresh() ?? $intake, allowExternal: false);
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    $room = $intake->fresh()->aircoRooms()->where('key', 'room-1')->firstOrFail();

    $this->actingAs($user)
        ->post(route('intakes.workspace.rooms.update', [$intake, $room]), [
            'name' => $room->name,
            'use_type' => 'living_room',
            'floor_level' => 'ground',
            'length_m' => 5,
            'width_m' => 4,
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->post(route('intakes.workspace.rooms.update', [$intake, $room]), [
            'name' => $room->name,
            'use_type' => 'living_room',
            'floor_level' => '',
            'length_m' => 5,
            'width_m' => 4,
        ])
        ->assertRedirect();

    $cleared = $room->fresh();
    $clearedDims = is_array($cleared->dimensions) ? $cleared->dimensions : [];
    expect(array_key_exists('floor_level', $clearedDims))->toBeTrue()
        ->and($clearedDims['floor_level'])->toBeNull()
        ->and($clearedDims['floor_level_source'] ?? null)->toBe('installer');

    FakeAiClient::alwaysReturn([
        'evidence' => 'Woonkamer koelen op de begane grond',
        'fills' => [
            [
                'question_key' => 'floor_level',
                'section_instance_key' => 'room-1',
                'confidence' => 'high',
                'value' => ['value' => 'ground'],
                'evidence' => 'begane grond',
                'provenance' => 'stated',
            ],
        ],
    ]);

    app(DeriveIntentFromRequest::class)->handle($intake->fresh() ?? $intake, allowExternal: true);
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    $after = $room->fresh();
    $afterDims = is_array($after->dimensions) ? $after->dimensions : [];
    expect(array_key_exists('floor_level', $afterDims))->toBeTrue()
        ->and($afterDims['floor_level'])->toBeNull()
        ->and($afterDims['floor_level_source'] ?? null)->toBe('installer')
        ->and($intake->fresh()->answers()
            ->where('question_key', 'floor_level')
            ->where('section_instance_key', 'room-1')
            ->exists())->toBeFalse();
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
    (new DeriveIntentFromRequestJob($intake->id, allowExternal: true))
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

test('workspace-tekst volgt auto_after_notes A/B en toont zo-opgesteld bij opties zonder synthese', function () {
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
        ->assertSee('Het AI-voorstel wordt zo opgesteld.', false)
        ->assertDontSee('Dat komt automatisch na een installatiekeuze', false);

    config(['ai.dossier_synthesis.auto_after_notes' => true]);
    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('na een installatiekeuze en wanneer je notities of klantaanvullingen vastlegt', false)
        ->assertSee('Het AI-voorstel wordt zo opgesteld.', false);
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
