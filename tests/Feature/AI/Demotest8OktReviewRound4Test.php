<?php

declare(strict_types=1);

use App\Domains\AI\Actions\DeriveIntentFromRequest;
use App\Domains\AI\Actions\SynthesizeSurveyDossier;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\DTOs\AiCompletionRequest;
use App\Domains\AI\Models\AiRun;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Support\FactSource;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoPlacementType;
use App\Enums\AiRunStatus;
use App\Enums\ContributionMode;
use App\Enums\ContributionTaskStatus;
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

function reviewRound4Intake(User $user, string $email, string $reason = 'Woonkamer koelen op de begane grond.'): Intake
{
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Review Round 4',
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
function reviewRound4SynthesisOutput(AiCompletionRequest $request, string $taskPrompt = 'Maak een foto van de buitenmuur.'): array
{
    $placements = collect($request->input['placements'])->keyBy('type');
    $inside = $placements->get('indoor_unit')['reference'];
    $outside = $placements->get('outdoor_unit')['reference'];
    $power = $placements->get('power_source')['reference'] ?? $outside;
    $drain = $placements->get('drain_point')['reference'] ?? $outside;
    $roomSubject = collect($request->input['rooms'])->first()['subject_reference'] ?? null;

    return [
        'summary' => 'Review round 4 voorstel.',
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

function reviewRound4Ready(Intake $intake, User $user): Intake
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

test('preserve upsert: twee taken met dezelfde sleutel in één run blijven beide bestaan', function () {
    config(['ai.dossier.enabled' => true, 'ai.provider' => 'fake']);

    $user = User::factory()->create();
    $intake = reviewRound4Ready(reviewRound4Intake($user, 'dup-key@example.com'), $user);

    FakeAiClient::respondUsing(static function (AiCompletionRequest $r): array {
        $out = reviewRound4SynthesisOutput($r, 'Eerste foto van de gevel.');
        $roomSubject = $out['customer_tasks'][0]['subject_reference'];
        $outside = $out['customer_tasks'][0]['evidence_references'][0];
        $out['customer_tasks'][] = [
            'type' => 'photo',
            'prompt' => 'Tweede foto van de gevelhoek.',
            'decision_area_key' => 'placement',
            'subject_reference' => $roomSubject,
            'reason' => 'Tweede hoek nog onzeker.',
            'evidence_references' => [$outside],
        ];

        return $out;
    });

    app(SynthesizeSurveyDossier::class)->handle($intake->fresh() ?? $intake, preserveProposedCustomerTasks: true);

    $tasks = ContributionTask::query()
        ->where('intake_id', $intake->id)
        ->where('status', ContributionTaskStatus::Proposed)
        ->where('decision_area_key', 'placement')
        ->get();

    expect($tasks)->toHaveCount(2)
        ->and($tasks->pluck('prompt')->sort()->values()->all())->toBe([
            'Eerste foto van de gevel.',
            'Tweede foto van de gevelhoek.',
        ]);
});

test('partial met afgewezen klanttaak cancelt bestaande AI-Proposed niet', function () {
    config(['ai.dossier.enabled' => true, 'ai.provider' => 'fake']);

    $user = User::factory()->create();
    $intake = reviewRound4Ready(reviewRound4Intake($user, 'partial-tasks@example.com'), $user);

    FakeAiClient::respondUsing(static fn (AiCompletionRequest $r): array => reviewRound4SynthesisOutput($r, 'Blijf staan.'));
    app(SynthesizeSurveyDossier::class)->handle($intake->fresh() ?? $intake, preserveProposedCustomerTasks: true);

    $existingId = ContributionTask::query()
        ->where('intake_id', $intake->id)
        ->where('status', ContributionTaskStatus::Proposed)
        ->value('id');
    expect($existingId)->not->toBeNull();

    FakeAiClient::respondUsing(static function (AiCompletionRequest $r): array {
        $out = reviewRound4SynthesisOutput($r, 'Nieuwe taak.');
        // Ongeldige subject_reference → task rejected; options blijven → Partial.
        $out['customer_tasks'] = [[
            'type' => 'photo',
            'prompt' => 'Deze taak wordt afgewezen.',
            'decision_area_key' => 'placement',
            'subject_reference' => 'subject:999999',
            'reason' => 'Ongeldige referentie.',
            'evidence_references' => [],
        ]];

        return $out;
    });

    $run = app(SynthesizeSurveyDossier::class)->handle(
        $intake->fresh() ?? $intake,
        preserveProposedCustomerTasks: true,
    );

    expect($run?->status)->toBe(AiRunStatus::Partial)
        ->and(ContributionTask::query()->find($existingId)?->status)->toBe(ContributionTaskStatus::Proposed)
        ->and(ContributionTask::query()->find($existingId)?->prompt)->toBe('Blijf staan.');
});

test('preserve: gewijzigde prompt cancelt oud id; send redirect zonder mail', function () {
    config(['ai.dossier.enabled' => true, 'ai.provider' => 'fake']);

    $user = User::factory()->create();
    $intake = reviewRound4Ready(reviewRound4Intake($user, 'content-change@example.com'), $user);

    FakeAiClient::respondUsing(static fn (AiCompletionRequest $r): array => reviewRound4SynthesisOutput($r, 'Oude prompt.'));
    app(SynthesizeSurveyDossier::class)->handle($intake->fresh() ?? $intake, preserveProposedCustomerTasks: true);

    $oldId = ContributionTask::query()
        ->where('intake_id', $intake->id)
        ->where('status', ContributionTaskStatus::Proposed)
        ->value('id');
    expect($oldId)->not->toBeNull();

    FakeAiClient::respondUsing(static fn (AiCompletionRequest $r): array => reviewRound4SynthesisOutput($r, 'Nieuwe prompt.'));
    app(SynthesizeSurveyDossier::class)->handle($intake->fresh() ?? $intake, preserveProposedCustomerTasks: true);

    expect(ContributionTask::query()->find($oldId)?->status)->toBe(ContributionTaskStatus::Cancelled)
        ->and(ContributionTask::query()
            ->where('intake_id', $intake->id)
            ->where('status', ContributionTaskStatus::Proposed)
            ->where('prompt', 'Nieuwe prompt.')
            ->exists())->toBeTrue();

    $this->actingAs($user)
        ->post(route('intakes.workspace.tasks.send', [$intake, $oldId]))
        ->assertRedirect(route('intakes.workspace', $intake))
        ->assertSessionHas('status', 'Dit AI-voorstel is intussen bijgewerkt. Controleer de nieuwe taak.');

    Mail::assertNothingSent();
});

test('lokale parse volgt request_reason prefill_source (klant-first → klantantwoord)', function () {
    config(['ai.text_inference.enabled' => false]);

    $user = User::factory()->create();
    $intake = reviewRound4Intake(
        $user,
        'local-customer-first@example.com',
        'Slaapkamer koelen op zolder.',
    );

    // Klant-first: openingszin zonder installer-prefill; nieuwe tekst → nieuwe local-run.
    $intake->answers()
        ->where('question_key', 'request_reason')
        ->whereNull('section_instance_key')
        ->update([
            'prefill_source' => null,
            'value' => ['text' => 'Woonkamer koelen op de begane grond.'],
        ]);
    $intake->answers()->where('question_key', '!=', 'request_reason')->delete();
    AiRun::query()->where('intake_id', $intake->id)->delete();

    $run = app(DeriveIntentFromRequest::class)->handle(
        $intake->fresh() ?? $intake,
        allowExternal: false,
    );

    expect($run?->status)->toBe(AiRunStatus::Succeeded);

    $roomType = $intake->fresh()->answers()
        ->where('question_key', 'room_type')
        ->where('section_instance_key', 'room-1')
        ->first();

    expect($roomType)->not->toBeNull()
        ->and($roomType->prefill_source)->toBe(PrefillSources::REQUEST_TEXT)
        ->and($roomType->fact_source)->toBe(FactSource::CustomerAnswer->value);
});
