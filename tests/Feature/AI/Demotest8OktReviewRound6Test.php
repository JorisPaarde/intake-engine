<?php

declare(strict_types=1);

use App\Domains\AI\Actions\SynthesizeSurveyDossier;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\DTOs\AiCompletionRequest;
use App\Domains\AI\Jobs\SynthesizeSurveyDossierJob;
use App\Domains\AI\Models\AiRun;
use App\Domains\Intake\Actions\CompleteFollowUpRound;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoPlacementType;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\ContributionMode;
use App\Enums\ContributionTaskStatus;
use App\Enums\FollowUpItemType;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
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

function reviewRound6Intake(User $user, string $email): Intake
{
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Review Round 6',
        'customer_email' => $email,
        'address_line' => 'Testlaan 10',
        'address_postal_code' => '1000AA',
        'address_house_number' => 10,
        'address_city' => 'Amsterdam',
        'prefill' => [
            'request_reason' => 'Woonkamer koelen op de begane grond.',
        ],
    ]);

    return $intake->fresh() ?? $intake;
}

function reviewRound6Ready(Intake $intake, User $user): Intake
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

/** @return array<string, mixed> */
function reviewRound6SynthesisOutput(AiCompletionRequest $request): array
{
    $placements = collect($request->input['placements'])->keyBy('type');
    $inside = $placements->get('indoor_unit')['reference'];
    $outside = $placements->get('outdoor_unit')['reference'];
    $power = $placements->get('power_source')['reference'] ?? $outside;
    $drain = $placements->get('drain_point')['reference'] ?? $outside;

    return [
        'summary' => 'Review round 6 na follow-up.',
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
        'customer_tasks' => [],
    ];
}

test('replace-mode synthese draait na follow-up terug naar Reviewed', function () {
    config(['ai.dossier.enabled' => true, 'ai.provider' => 'fake']);
    Queue::fake();

    $user = User::factory()->create();
    $intake = reviewRound6Ready(reviewRound6Intake($user, 'reviewed-followup@example.com'), $user);
    $intake->forceFill(['status' => IntakeStatus::Reviewed, 'reviewed_at' => now()])->save();

    $root = $intake->dossierSubjects()->where('key', 'survey')->firstOrFail();
    $round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh() ?? $intake, $user, [[
        'type' => FollowUpItemType::Text,
        'prompt' => 'Hoe hoog is de plafonds in de woonkamer?',
        'decision_area_key' => 'capacity',
        'dossier_subject_id' => $root->id,
    ]]);

    expect($round->return_status)->toBe(IntakeStatus::Reviewed)
        ->and($intake->fresh()->status)->toBe(IntakeStatus::AwaitingCustomer);

    $item = $round->items()->firstOrFail();

    app(CompleteFollowUpRound::class)->handle(
        $intake->fresh() ?? $intake,
        $round->fresh() ?? $round,
        [$item->id => 'Ongeveer 2,6 meter.'],
    );

    expect($intake->fresh()->status)->toBe(IntakeStatus::Reviewed);

    Queue::assertPushed(
        SynthesizeSurveyDossierJob::class,
        static fn (SynthesizeSurveyDossierJob $job): bool => $job->intakeId === $intake->id
            && $job->preserveProposedCustomerTasks === false,
    );

    FakeAiClient::respondUsing(static fn (AiCompletionRequest $r): array => reviewRound6SynthesisOutput($r));

    (new SynthesizeSurveyDossierJob($intake->id, preserveProposedCustomerTasks: false))
        ->handle(app(SynthesizeSurveyDossier::class));

    expect(AiRun::query()
        ->where('intake_id', $intake->id)
        ->where('type', AiRunType::DossierSynthesis)
        ->whereIn('status', [AiRunStatus::Succeeded, AiRunStatus::Partial])
        ->exists())->toBeTrue()
        ->and(ContributionTask::query()
            ->where('intake_id', $intake->id)
            ->where('status', ContributionTaskStatus::Proposed)
            ->count())->toBe(0);

    // Preserve-pad blijft Reviewed overslaan.
    $before = AiRun::query()
        ->where('intake_id', $intake->id)
        ->where('type', AiRunType::DossierSynthesis)
        ->count();
    (new SynthesizeSurveyDossierJob($intake->id, preserveProposedCustomerTasks: true))
        ->handle(app(SynthesizeSurveyDossier::class));
    expect(AiRun::query()
        ->where('intake_id', $intake->id)
        ->where('type', AiRunType::DossierSynthesis)
        ->count())->toBe($before);
});
