<?php

declare(strict_types=1);

use App\Domains\AI\Actions\DeriveIntentFromRequest;
use App\Domains\AI\Actions\SynthesizeSurveyDossier;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\DTOs\AiCompletionRequest;
use App\Domains\AI\DTOs\RequestPrefillCandidate;
use App\Domains\AI\Jobs\SynthesizeSurveyDossierJob;
use App\Domains\AI\Services\RequestPrefillOutcomeClassifier;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Support\FactAcceptance;
use App\Domains\Intake\Support\FactProvenance;
use App\Domains\Intake\Support\FactSource;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoPlacementType;
use App\Enums\AiRunStatus;
use App\Enums\ContributionAudience;
use App\Enums\ContributionMode;
use App\Enums\ContributionTaskStatus;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    FakeAiClient::reset();
    Mail::fake();
});

afterEach(function () {
    FakeAiClient::reset();
});

function reviewRound3Intake(User $user, string $email, string $reason = 'Woonkamer koelen op de begane grond.'): Intake
{
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Review Round 3',
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
function reviewRound3SynthesisOutput(AiCompletionRequest $request, string $taskPrompt = 'Maak een foto van de buitenmuur.'): array
{
    $placements = collect($request->input['placements'])->keyBy('type');
    $inside = $placements->get('indoor_unit')['reference'];
    $outside = $placements->get('outdoor_unit')['reference'];
    $power = $placements->get('power_source')['reference'] ?? $outside;
    $drain = $placements->get('drain_point')['reference'] ?? $outside;
    $roomSubject = collect($request->input['rooms'])->first()['subject_reference'] ?? null;

    return [
        'summary' => 'Review round 3 voorstel.',
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
            'prompt' => $taskPrompt,
            'decision_area_key' => 'placement',
            'subject_reference' => $roomSubject,
            'reason' => 'Buitenplaatsing nog onzeker.',
            'evidence_references' => [$outside],
        ]],
    ];
}

function reviewRound3Ready(Intake $intake, User $user): Intake
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

function reviewRound3FloorCatalog(): array
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
                    ],
                ],
            ],
        ]],
    ];
}

test('klant mag request_reason wijzigen na cursor; parse draait opnieuw', function () {
    config(['ai.text_inference.enabled' => false]);

    $user = User::factory()->create();
    $intake = reviewRound3Intake(
        $user,
        'wizard-edit-reason@example.com',
        'Slaapkamer koelen op zolder.',
    );
    $intake->forceFill([
        'customer_access_enabled' => true,
        'access_token' => str_repeat('c', 64),
        'token_expires_at' => now()->addDay(),
        'status' => IntakeStatus::Sent,
        'current_question_key' => 'ownership',
    ])->save();

    app(DeriveIntentFromRequest::class)->handle($intake->fresh() ?? $intake, allowExternal: false);
    expect($intake->fresh()->answers()->where('question_key', 'room_type')->count())->toBeGreaterThan(0);

    // Zonder skipIfCustomerStarted (wizard persistComposite-pad): nieuwe openingszin → opnieuw parsen.
    app(SaveIntakeAnswer::class)->handle(
        $intake->fresh() ?? $intake,
        'request_reason',
        null,
        ['text' => 'Woonkamer en slaapkamer koelen op de begane grond.'],
    );
    $run = app(DeriveIntentFromRequest::class)->handle($intake->fresh() ?? $intake, allowExternal: false);

    expect($run?->status)->toBe(AiRunStatus::Succeeded)
        ->and($intake->fresh()->answers()
            ->where('question_key', 'room_type')
            ->where('section_instance_key', 'room-1')
            ->value('value'))->toBe(['value' => 'living_room']);

    // Late pad (job/mount) blijft geblokkeerd zodra klant is begonnen.
    $skipped = app(DeriveIntentFromRequest::class)->handle(
        $intake->fresh() ?? $intake,
        allowExternal: false,
        skipIfCustomerStarted: true,
    );
    expect($skipped)->toBeNull();
});

test('Completed intake: installatiekeuze-synthese draait met preserve', function () {
    config(['ai.dossier.enabled' => true, 'ai.provider' => 'fake']);

    $user = User::factory()->create();
    $intake = reviewRound3Ready(reviewRound3Intake($user, 'completed-synth@example.com'), $user);
    $intake->forceFill(['status' => IntakeStatus::Completed])->save();

    FakeAiClient::respondUsing(static fn (AiCompletionRequest $r): array => reviewRound3SynthesisOutput($r));

    (new SynthesizeSurveyDossierJob($intake->id, preserveProposedCustomerTasks: true))
        ->handle(app(SynthesizeSurveyDossier::class));

    expect(ContributionTask::query()
        ->where('intake_id', $intake->id)
        ->where('status', ContributionTaskStatus::Proposed)
        ->count())->toBeGreaterThan(0);

    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('automatisch bijgewerkt na een installatiekeuze', false);
});

test('workspace belooft geen automatische update als AI-dossier uit staat', function () {
    config(['ai.dossier.enabled' => false]);

    $user = User::factory()->create();
    $intake = reviewRound3Ready(reviewRound3Intake($user, 'ai-off-copy@example.com'), $user);
    $survey = app(AircoSurveyService::class);
    $indoor = $intake->aircoPlacements()->where('type', AircoPlacementType::IndoorUnit)->firstOrFail();
    $outdoor = $intake->aircoPlacements()->where('type', AircoPlacementType::OutdoorUnit)->firstOrFail();
    $survey->createInstallationOption($intake, $user, [
        'label' => 'Single-split',
        'configuration_type' => AircoConfigurationType::SingleSplit,
        'placement_ids' => [$indoor->id, $outdoor->id],
    ]);

    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('Het voorstel gebruikt alleen gegevens uit deze opname.', false)
        ->assertDontSee('automatisch bijgewerkt', false);
});

test('klant-first openingszin geeft FactSource klantantwoord', function () {
    expect(FactAcceptance::sourceFrom(PrefillSources::AI_TEXT, FactProvenance::Stated, null))
        ->toBe(FactSource::CustomerAnswer)
        ->and(FactAcceptance::sourceFrom(PrefillSources::AI_TEXT, FactProvenance::Stated, 'installer'))
        ->toBe(FactSource::InstallerRequest);

    $text = 'Woonkamer koelen op de begane grond.';
    $result = app(RequestPrefillOutcomeClassifier::class)->classifyCatalogOutput(
        [
            'evidence' => $text,
            'fills' => [[
                'question_key' => 'room_type',
                'section_instance_key' => 'room-1',
                'confidence' => 'high',
                'value' => ['value' => 'living_room'],
                'evidence' => 'Woonkamer',
                'provenance' => 'stated',
            ]],
        ],
        reviewRound3FloorCatalog(),
        [],
        $text,
        null,
    );

    $fill = collect($result['candidates'])->first(
        static fn (RequestPrefillCandidate $c): bool => $c->questionKey === 'room_type'
            && $c->disposition === RequestPrefillCandidate::DISPOSITION_FILL,
    );

    expect($fill)->not->toBeNull()
        ->and($fill->factSource)->toBe(FactSource::CustomerAnswer);
});

test('preserve annuleert stale AI-Proposed; send-by-id geeft bestaande fout', function () {
    config(['ai.dossier.enabled' => true, 'ai.provider' => 'fake']);

    $user = User::factory()->create();
    $intake = reviewRound3Ready(reviewRound3Intake($user, 'stale-tasks@example.com'), $user);

    FakeAiClient::respondUsing(static fn (AiCompletionRequest $r): array => reviewRound3SynthesisOutput($r, 'Eerste AI-taak foto.'));
    app(SynthesizeSurveyDossier::class)->handle($intake->fresh() ?? $intake, preserveProposedCustomerTasks: true);

    $first = ContributionTask::query()
        ->where('intake_id', $intake->id)
        ->where('status', ContributionTaskStatus::Proposed)
        ->sole();
    $firstId = $first->id;

    $installerTask = ContributionTask::query()->create([
        'intake_id' => $intake->id,
        'company_id' => $intake->company_id,
        'dossier_subject_id' => null,
        'intake_follow_up_item_id' => null,
        'audience' => ContributionAudience::Customer,
        'type' => 'photo',
        'prompt' => 'Installateur-eigen taak.',
        'decision_area_key' => 'placement',
        'status' => ContributionTaskStatus::Proposed,
        'requested_by' => $user->id,
        'meta' => ['source_type' => 'installer'],
    ]);

    // Tweede synthese zonder taken → stale AI-Proposed wordt gecanceld; installer-taak blijft.
    FakeAiClient::respondUsing(static function (AiCompletionRequest $r): array {
        $out = reviewRound3SynthesisOutput($r, 'Nieuwe taak.');
        $out['customer_tasks'] = [];

        return $out;
    });
    app(SynthesizeSurveyDossier::class)->handle($intake->fresh() ?? $intake, preserveProposedCustomerTasks: true);

    expect(ContributionTask::query()->find($firstId)?->status)->toBe(ContributionTaskStatus::Cancelled)
        ->and(ContributionTask::query()->find($installerTask->id)?->status)->toBe(ContributionTaskStatus::Proposed);

    $this->actingAs($user)
        ->post(route('intakes.workspace.tasks.send', [$intake, $firstId]))
        ->assertNotFound();
});
