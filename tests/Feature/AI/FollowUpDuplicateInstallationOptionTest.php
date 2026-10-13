<?php

declare(strict_types=1);

use App\Domains\AI\Actions\SynthesizeSurveyDossier;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\DTOs\AiCompletionRequest;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Models\AircoInstallationOption;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\DossierManager;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoConnectionType;
use App\Enums\AircoOptionFeasibility;
use App\Enums\AircoOptionStatus;
use App\Enums\AircoPlacementType;
use App\Enums\ContributionMode;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    FakeAiClient::reset();
    Mail::fake();
    config([
        'ai.provider' => 'fake',
        'ai.dossier.enabled' => true,
    ]);
});

afterEach(function () {
    FakeAiClient::reset();
});

/** @return array<string, mixed> */
function dupeSynthesisOutput(AiCompletionRequest $request): array
{
    $placements = collect($request->input['placements'])->keyBy('type');
    $inside = $placements->get('indoor_unit')['reference'];
    $outside = $placements->get('outdoor_unit')['reference'];

    return [
        'summary' => 'Opnieuw opgebouwd multi-split voorstel.',
        'placement_proposals' => [],
        'option_proposals' => [[
            'label' => 'Multi-split systeem',
            'configuration_type' => AircoConfigurationType::SingleSplit->value,
            'summary' => 'Zelfde opstelling opnieuw voorgesteld.',
            'cost_impact' => 'medium',
            'confidence' => 0.85,
            'placement_references' => [$inside, $outside],
            'connections' => [
                [
                    'type' => 'power',
                    'label' => 'Stroomtoevoer buitenunit',
                    'from_placement_reference' => $outside,
                    'to_placement_reference' => $outside,
                    'status' => 'needs_evidence',
                    'length_class' => 'short',
                    'segments' => [],
                    'obstacles' => [],
                    'uncertainties' => [],
                    'cost_impact' => 'low',
                    'confidence' => 0.7,
                    'evidence_references' => [$outside],
                ],
            ],
        ]],
        'exceptions' => [],
        'customer_tasks' => [],
    ];
}

test('follow-up synthesis maakt geen tweede Candidate naast geselecteerd voorstel', function () {
    $user = User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Dupe Optie',
        'customer_email' => 'dupe@example.com',
        'address_line' => 'Voorstel 1',
        'address_postal_code' => '1000AA',
        'address_house_number' => 1,
        'address_city' => 'Amsterdam',
    ]);
    app(DossierManager::class)->initialize($intake);

    $survey = app(AircoSurveyService::class);
    $room = $survey->createRoom($intake, $user, [
        'name' => 'Woonkamer',
        'use_type' => 'living_room',
        'length_m' => 5,
        'width_m' => 4,
        'height_m' => 2.5,
    ]);
    $inside = $survey->createPlacement($intake, $user, [
        'airco_room_id' => $room->id,
        'type' => AircoPlacementType::IndoorUnit,
        'label' => 'Binnenunit woonkamer',
    ]);
    $outside = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::OutdoorUnit,
        'label' => 'Buitenunit tuin',
    ]);
    $selected = $survey->createInstallationOption($intake, $user, [
        'label' => 'Single-split systeem',
        'configuration_type' => AircoConfigurationType::SingleSplit,
        'placement_ids' => [$inside->id, $outside->id],
    ]);
    $selected->update([
        'status' => AircoOptionStatus::Selected,
        'feasibility' => AircoOptionFeasibility::Feasible,
        'source_type' => 'ai',
    ]);
    $survey->createConnection($intake, $user, $selected, [
        'type' => AircoConnectionType::Power,
        'label' => 'Stroomtoevoer buitenunit',
        'from_placement_id' => $outside->id,
        'to_placement_id' => $outside->id,
        'status' => AircoConnectionStatus::NeedsEvidence,
    ]);

    FakeAiClient::respondUsing(
        static fn (AiCompletionRequest $request): array => dupeSynthesisOutput($request),
    );

    expect(app(SynthesizeSurveyDossier::class)->handle($intake->fresh()))->not->toBeNull();

    $options = AircoInstallationOption::query()
        ->where('intake_id', $intake->id)
        ->orderBy('id')
        ->get();

    expect($options->where('status', AircoOptionStatus::Selected))->toHaveCount(1)
        ->and($options->where('status', AircoOptionStatus::Candidate))->toHaveCount(0)
        ->and($options)->toHaveCount(1)
        ->and($options->first()->id)->toBe($selected->id);
});

test('follow-up synthesis verwijdert geen Candidate met goedgekeurde installateursbeslissing', function () {
    $user = User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Dupe Besluit',
        'customer_email' => 'dupe-besluit@example.com',
        'address_line' => 'Voorstel 2',
        'address_postal_code' => '1000AA',
        'address_house_number' => 2,
        'address_city' => 'Amsterdam',
    ]);
    app(DossierManager::class)->initialize($intake);

    $survey = app(AircoSurveyService::class);
    $room = $survey->createRoom($intake, $user, [
        'name' => 'Woonkamer',
        'use_type' => 'living_room',
        'length_m' => 5,
        'width_m' => 4,
        'height_m' => 2.5,
    ]);
    $inside = $survey->createPlacement($intake, $user, [
        'airco_room_id' => $room->id,
        'type' => AircoPlacementType::IndoorUnit,
        'label' => 'Binnenunit woonkamer',
    ]);
    $outside = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::OutdoorUnit,
        'label' => 'Buitenunit tuin',
    ]);

    $makeOption = function (AircoOptionStatus $status) use ($survey, $intake, $user, $inside, $outside) {
        $option = $survey->createInstallationOption($intake, $user, [
            'label' => 'Single-split systeem',
            'configuration_type' => AircoConfigurationType::SingleSplit,
            'placement_ids' => [$inside->id, $outside->id],
        ]);
        $option->update([
            'status' => $status,
            'feasibility' => AircoOptionFeasibility::Feasible,
            'source_type' => 'ai',
        ]);

        return $option;
    };

    $selected = $makeOption(AircoOptionStatus::Selected);
    $candidate = $makeOption(AircoOptionStatus::Candidate);
    $survey->createConnection($intake, $user, $candidate, [
        'type' => AircoConnectionType::Power,
        'label' => 'Stroomtoevoer buitenunit',
        'from_placement_id' => null,
        'to_placement_id' => $outside->id,
        'status' => AircoConnectionStatus::Approved,
    ]);

    FakeAiClient::respondUsing(
        static fn (AiCompletionRequest $request): array => dupeSynthesisOutput($request),
    );

    expect(app(SynthesizeSurveyDossier::class)->handle($intake->fresh()))->not->toBeNull();

    $candidates = AircoInstallationOption::query()
        ->where('intake_id', $intake->id)
        ->where('status', AircoOptionStatus::Candidate)
        ->with('connections')
        ->get();

    expect(AircoInstallationOption::query()->whereKey($selected->id)->exists())->toBeTrue()
        ->and($candidates)->toHaveCount(1)
        ->and($candidates->first()->connections->pluck('status'))
        ->toContain(AircoConnectionStatus::Approved);
});

test('opties zonder placements zijn nooit duplicaat van elkaar', function () {
    $key = new ReflectionMethod(SynthesizeSurveyDossier::class, 'optionIdentityKey');
    $option = new AircoInstallationOption(['configuration_type' => AircoConfigurationType::SingleSplit]);
    $option->setRelation('placements', collect());

    $synth = (new ReflectionClass(SynthesizeSurveyDossier::class))->newInstanceWithoutConstructor();

    expect($key->invoke($synth, $option))->toBeNull();
});
