<?php

declare(strict_types=1);

use App\Domains\AI\Actions\SynthesizeSurveyDossier;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\DTOs\AiCompletionRequest;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Models\AircoInstallationOption;
use App\Domains\Intake\Models\AircoPlacementOption;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\DossierRecord;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\DossierManager;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoPlacementType;
use App\Enums\AiRunStatus;
use App\Enums\ContributionMode;
use App\Enums\ContributionTaskStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

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

/** @return array{0: Intake, 1: User} */
function synthesisSurveyWithPlacements(): array
{
    $user = User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Synthese Klant',
        'customer_email' => 'persoonlijk@example.com',
        'address_line' => 'Privélaan 99',
        'address_postal_code' => '1000AA',
        'address_city' => 'Amsterdam',
    ]);
    $survey = app(AircoSurveyService::class);
    $room = $survey->createRoom($intake, $user, [
        'name' => 'Slaapkamer',
        'use_type' => 'bedroom',
        'length_m' => 4.0,
        'width_m' => 3.0,
        'height_m' => 2.6,
    ]);
    $survey->createPlacement($intake, $user, [
        'airco_room_id' => $room->id,
        'type' => AircoPlacementType::IndoorUnit,
        'label' => 'Binnenpositie boven de deur',
    ]);
    $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::OutdoorUnit,
        'label' => 'Buitenpositie op plat dak',
    ]);
    $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::PowerSource,
        'label' => 'Meterkast',
    ]);
    $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::DrainPoint,
        'label' => 'Regenwaterafvoer',
    ]);

    return [$intake->fresh(), $user];
}

/** @return array<string, mixed> */
function validDossierSynthesisOutput(AiCompletionRequest $request, string $label = 'AI-optie A'): array
{
    $placements = collect($request->input['placements'])->keyBy('type');
    $inside = $placements->get('indoor_unit')['reference'];
    $outside = $placements->get('outdoor_unit')['reference'];
    $power = $placements->get('power_source')['reference'];
    $drain = $placements->get('drain_point')['reference'];
    $roomSubject = collect($request->input['rooms'])->first()['subject_reference'];

    return [
        'summary' => 'De slaapkamer heeft één aannemelijke single-splitopstelling; stroom blijft een integrale veiligheidscontrole.',
        'placement_proposals' => [],
        'option_proposals' => [[
            'label' => $label,
            'configuration_type' => AircoConfigurationType::SingleSplit->value,
            'summary' => 'Eén binnen- en buitenunit via de zichtbare gevelroute.',
            'cost_impact' => 'medium',
            'confidence' => 0.88,
            'placement_references' => [$inside, $outside, $power, $drain],
            'connections' => [
                [
                    'type' => 'refrigerant',
                    'label' => 'Koelleiding slaapkamer',
                    'from_placement_reference' => $inside,
                    'to_placement_reference' => $outside,
                    'status' => 'proposed',
                    'length_class' => 'short',
                    'segments' => ['Door buitenmuur naar plat dak'],
                    'obstacles' => [],
                    'uncertainties' => [],
                    'cost_impact' => 'low',
                    'confidence' => 0.9,
                    'evidence_references' => [$inside, $outside],
                ],
                [
                    'type' => 'condensate',
                    'label' => 'Condensafvoer slaapkamer',
                    'from_placement_reference' => $inside,
                    'to_placement_reference' => $drain,
                    'status' => 'proposed',
                    'length_class' => 'short',
                    'segments' => ['Met natuurlijk verval naar de afvoer'],
                    'obstacles' => [],
                    'uncertainties' => [],
                    'cost_impact' => 'low',
                    'confidence' => 0.86,
                    'evidence_references' => [$inside, $drain],
                ],
                [
                    'type' => 'power',
                    'label' => 'Stroomtoevoer buitenunit',
                    'from_placement_reference' => $power,
                    'to_placement_reference' => $outside,
                    'status' => 'needs_evidence',
                    'length_class' => 'medium',
                    'segments' => [],
                    'obstacles' => [],
                    'uncertainties' => ['Groepscapaciteit nog niet leesbaar'],
                    'cost_impact' => 'unknown',
                    'confidence' => 0.6,
                    'evidence_references' => [$power],
                ],
            ],
        ]],
        'exceptions' => [[
            'code' => 'verify_power_capacity',
            'label' => 'Groepscapaciteit is nog niet leesbaar.',
            'decision_area_key' => 'power',
            'confidence' => 'medium',
            'evidence_references' => [$power],
        ]],
        'customer_tasks' => [[
            'type' => 'photo',
            'prompt' => 'Maak één scherpe foto recht van voren waarop alle labels in de meterkast leesbaar zijn. Open geen afdekkappen.',
            'decision_area_key' => 'power',
            'subject_reference' => $roomSubject,
            'reason' => 'Deze foto bepaalt of een nieuwe groep in de offerte moet worden opgenomen.',
            'evidence_references' => [$power],
        ]],
    ];
}

test('dossier image budget takes one recent image per dossier part before taking seconds', function () {
    [$intake] = synthesisSurveyWithPlacements();
    Storage::fake('local');
    config(['ai.dossier.max_images' => 4]);
    $expectedIds = [];

    foreach (range(1, 4) as $group) {
        foreach (range(1, 2) as $sequence) {
            $path = "intakes/{$intake->id}/group-{$group}-{$sequence}.jpg";
            $analysisPath = "intakes/{$intake->id}/group-{$group}-{$sequence}-analysis.jpg";
            Storage::disk('local')->put($path, "dossier-{$group}-{$sequence}");
            Storage::disk('local')->put($analysisPath, "analysis-{$group}-{$sequence}");
            $upload = IntakeUpload::query()->create([
                'intake_id' => $intake->id,
                'question_key' => 'group_'.$group,
                'section_instance_key' => null,
                'disk' => 'local',
                'path' => $path,
                'analysis_path' => $analysisPath,
                'analysis_mime_type' => 'image/jpeg',
                'analysis_size_bytes' => strlen("analysis-{$group}-{$sequence}"),
                'analysis_checksum' => hash('sha256', "analysis-{$group}-{$sequence}"),
                'original_filename' => "group-{$group}-{$sequence}.jpg",
                'mime_type' => 'image/jpeg',
                'size_bytes' => strlen("dossier-{$group}-{$sequence}"),
                'checksum' => hash('sha256', "dossier-{$group}-{$sequence}"),
                'sort_order' => $sequence,
            ]);

            if ($sequence === 2) {
                $expectedIds[] = $upload->id;
            }
        }
    }

    FakeAiClient::respondUsing(
        fn (AiCompletionRequest $request): array => validDossierSynthesisOutput($request),
    );
    $run = app(SynthesizeSurveyDossier::class)->handle($intake->fresh());
    $manifest = FakeAiClient::lastRequest()?->input['image_manifest'] ?? [];

    expect($run?->status)->toBe(AiRunStatus::Succeeded)
        ->and(collect($manifest)->pluck('question_key')->unique())->toHaveCount(4)
        ->and(collect($manifest)->pluck('reference')->all())->toBe(
            collect($expectedIds)->map(static fn (int $id): string => 'dossier_image:'.$id)->all(),
        );
});

test('AI synthesis can create image-grounded placement proposals before composing an option', function () {
    $user = User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Beeldbewijs Klant',
        'customer_email' => 'beeldbewijs@example.com',
        'address_line' => 'Teststraat 1',
        'address_postal_code' => '1000AA',
        'address_city' => 'Amsterdam',
    ]);
    $room = app(AircoSurveyService::class)->createRoom($intake, $user, [
        'name' => 'Slaapkamer',
        'use_type' => 'bedroom',
    ]);
    Storage::fake('local');

    $uploads = collect([
        ['room_photos', 'room-1', 'room'],
        ['outdoor_location_photos', null, 'outdoor'],
        ['fusebox_photo', null, 'power'],
        ['condensate_photo', null, 'drain'],
    ])->map(function (array $definition, int $index) use ($intake): IntakeUpload {
        [$questionKey, $sectionInstanceKey, $name] = $definition;
        $path = "intakes/{$intake->id}/{$name}.jpg";
        $analysisPath = "intakes/{$intake->id}/{$name}-analysis.jpg";
        Storage::disk('local')->put($path, 'dossier-'.$name);
        Storage::disk('local')->put($analysisPath, 'analysis-'.$name);

        return IntakeUpload::query()->create([
            'intake_id' => $intake->id,
            'question_key' => $questionKey,
            'section_instance_key' => $sectionInstanceKey,
            'intake_follow_up_item_id' => null,
            'disk' => 'local',
            'path' => $path,
            'analysis_path' => $analysisPath,
            'analysis_mime_type' => 'image/jpeg',
            'analysis_size_bytes' => strlen('analysis-'.$name),
            'analysis_checksum' => hash('sha256', 'analysis-'.$name),
            'original_filename' => $name.'.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => strlen('dossier-'.$name),
            'checksum' => hash('sha256', 'dossier-'.$name),
            'sort_order' => $index,
        ]);
    });
    app(DossierManager::class)->initialize($intake->fresh());

    FakeAiClient::respondUsing(function (AiCompletionRequest $request) use ($room): array {
        $images = collect($request->input['image_manifest'])->keyBy('question_key');
        $roomImage = $images->get('room_photos')['reference'];
        $outdoorImage = $images->get('outdoor_location_photos')['reference'];
        $powerImage = $images->get('fusebox_photo')['reference'];
        $drainImage = $images->get('condensate_photo')['reference'];
        $roomReference = collect($request->input['rooms'])->first()['reference'];
        $roomSubject = collect($request->input['rooms'])->first()['subject_reference'];
        $rootSubject = collect($request->input['subjects'])->firstWhere('type', 'survey')['reference'];

        expect($roomReference)->toBe('room:'.$room->id);

        return [
            'summary' => 'Vier zichtbare kandidaatposities vormen samen één te beoordelen single-splitopstelling.',
            'placement_proposals' => [
                [
                    'key' => 'proposal:indoor_slaapkamer',
                    'type' => 'indoor_unit',
                    'label' => 'Binnenpositie slaapkamer',
                    'description' => 'Vrije wandzone boven de deur, zichtbaar op de overzichtsfoto.',
                    'room_reference' => $roomReference,
                    'subject_reference' => $roomSubject,
                    'confidence' => 0.86,
                    'evidence_references' => [$roomImage],
                ],
                [
                    'key' => 'proposal:outdoor_plat_dak',
                    'type' => 'outdoor_unit',
                    'label' => 'Buitenpositie plat dak',
                    'description' => 'Vrije zone op het zichtbare platte dak.',
                    'room_reference' => null,
                    'subject_reference' => $rootSubject,
                    'confidence' => 0.8,
                    'evidence_references' => [$outdoorImage],
                ],
                [
                    'key' => 'proposal:power_meterkast',
                    'type' => 'power_source',
                    'label' => 'Meterkast',
                    'description' => 'Zichtbare meterkast; elektrische geschiktheid blijft te beoordelen.',
                    'room_reference' => null,
                    'subject_reference' => $rootSubject,
                    'confidence' => 0.75,
                    'evidence_references' => [$powerImage],
                ],
                [
                    'key' => 'proposal:drain_regenpijp',
                    'type' => 'drain_point',
                    'label' => 'Regenpijp',
                    'description' => 'Zichtbare regenpijp als kandidaat-afvoerpunt.',
                    'room_reference' => null,
                    'subject_reference' => $rootSubject,
                    'confidence' => 0.72,
                    'evidence_references' => [$drainImage],
                ],
            ],
            'option_proposals' => [[
                'label' => 'Single-split slaapkamer',
                'configuration_type' => 'single_split',
                'summary' => 'Kandidaatopstelling op basis van vier aangeleverde beelden.',
                'cost_impact' => 'unknown',
                'confidence' => 0.73,
                'placement_references' => [
                    'proposal:indoor_slaapkamer',
                    'proposal:outdoor_plat_dak',
                    'proposal:power_meterkast',
                    'proposal:drain_regenpijp',
                ],
                'connections' => [
                    [
                        'type' => 'refrigerant',
                        'label' => 'Koelleiding slaapkamer',
                        'from_placement_reference' => 'proposal:indoor_slaapkamer',
                        'to_placement_reference' => 'proposal:outdoor_plat_dak',
                        'status' => 'needs_evidence',
                        'length_class' => 'unknown',
                        'segments' => [],
                        'obstacles' => [],
                        'uncertainties' => ['Route is niet aaneengesloten zichtbaar.'],
                        'cost_impact' => 'unknown',
                        'confidence' => 0.55,
                        'evidence_references' => [$roomImage, $outdoorImage],
                    ],
                    [
                        'type' => 'condensate',
                        'label' => 'Condensafvoer slaapkamer',
                        'from_placement_reference' => 'proposal:indoor_slaapkamer',
                        'to_placement_reference' => 'proposal:drain_regenpijp',
                        'status' => 'needs_evidence',
                        'length_class' => 'unknown',
                        'segments' => [],
                        'obstacles' => [],
                        'uncertainties' => ['Voldoende verval is niet vastgesteld.'],
                        'cost_impact' => 'unknown',
                        'confidence' => 0.5,
                        'evidence_references' => [$roomImage, $drainImage],
                    ],
                    [
                        'type' => 'power',
                        'label' => 'Stroomtoevoer buitenunit',
                        'from_placement_reference' => 'proposal:power_meterkast',
                        'to_placement_reference' => 'proposal:outdoor_plat_dak',
                        'status' => 'needs_evidence',
                        'length_class' => 'unknown',
                        'segments' => [],
                        'obstacles' => [],
                        'uncertainties' => ['Groepscapaciteit en kabelroute zijn niet vastgesteld.'],
                        'cost_impact' => 'unknown',
                        'confidence' => 0.45,
                        'evidence_references' => [$powerImage, $outdoorImage],
                    ],
                ],
            ]],
            'exceptions' => [],
            'customer_tasks' => [],
        ];
    });

    $run = app(SynthesizeSurveyDossier::class)->handle($intake->fresh());

    expect($run?->status)->toBe(AiRunStatus::Succeeded)
        ->and(FakeAiClient::lastRequest()?->images)->toHaveCount(4)
        ->and(FakeAiClient::lastRequest()?->images[0]->binary)->toStartWith('analysis-')
        ->and(FakeAiClient::lastRequest()?->input['image_manifest'])->toHaveCount(4)
        ->and(AircoPlacementOption::query()->where('intake_id', $intake->id)->count())->toBe(4)
        ->and(AircoPlacementOption::query()
            ->where('intake_id', $intake->id)
            ->where('source_type', 'ai')
            ->where('source_id', $run?->id)
            ->count())->toBe(4)
        ->and(AircoInstallationOption::query()->where('intake_id', $intake->id)->sole()->placements)->toHaveCount(4)
        ->and($uploads)->toHaveCount(4);

    $replacementRun = app(SynthesizeSurveyDossier::class)->handle($intake->fresh());

    expect($replacementRun?->status)->toBe(AiRunStatus::Succeeded)
        ->and(AircoInstallationOption::query()->where('intake_id', $intake->id)->count())->toBe(1)
        ->and(AircoPlacementOption::query()->where('intake_id', $intake->id)->count())->toBe(4)
        ->and(AircoPlacementOption::query()
            ->where('intake_id', $intake->id)
            ->where('source_id', $replacementRun?->id)
            ->count())->toBe(4);
});

test('AI synthesis stores evidence-bound options and tasks as proposals without activating customer access', function () {
    [$intake] = synthesisSurveyWithPlacements();
    $intake->externalFacts()->create([
        'fact_key' => 'privacy_regression',
        'label' => 'Veilige woningcontext',
        'value' => [
            'roof_type' => 'flat',
            'address' => 'Privélaan 99',
            'geometry' => ['coordinates' => [4.1, 52.1]],
            'media_path' => 'intakes/private/aerial.png',
        ],
        'source' => 'test',
        'source_reference' => 'private-object-123',
        'source_url' => 'https://example.invalid/private',
        'confidence' => 'high',
        'captured_at' => now(),
    ]);
    app(DossierManager::class)->initialize($intake->fresh());
    FakeAiClient::respondUsing(fn (AiCompletionRequest $request): array => validDossierSynthesisOutput($request));

    $run = app(SynthesizeSurveyDossier::class)->handle($intake);

    expect($run?->status)->toBe(AiRunStatus::Succeeded);
    $option = AircoInstallationOption::query()->where('intake_id', $intake->id)->sole();
    $task = ContributionTask::query()
        ->where('intake_id', $intake->id)
        ->where('status', ContributionTaskStatus::Proposed)
        ->sole();

    expect($option->source_type)->toBe('ai')
        ->and($option->confidence)->toBe(0.88)
        ->and($option->connections)->toHaveCount(3)
        ->and($option->connections->every(
            fn ($connection): bool => $connection->status !== AircoConnectionStatus::Approved,
        ))->toBeTrue()
        ->and($task->meta['source_type'])->toBe('ai')
        ->and($intake->fresh()->customer_access_enabled)->toBeFalse()
        ->and($intake->fresh()->workflow_mode)->toBe(ContributionMode::Installer)
        ->and(DossierRecord::query()->where('key', 'ai_dossier_synthesis')->exists())->toBeTrue();

    $sentPayload = json_encode(FakeAiClient::lastRequest()?->input);
    expect($sentPayload)
        ->not->toContain('persoonlijk@example.com')
        ->not->toContain('Privélaan 99')
        ->not->toContain('private-object-123')
        ->not->toContain('intakes/private/aerial.png')
        ->toContain('flat');
});

test('AI synthesis is idempotent and an invalid ungrounded response leaves existing proposals untouched', function () {
    [$intake] = synthesisSurveyWithPlacements();
    FakeAiClient::respondUsing(fn (AiCompletionRequest $request): array => validDossierSynthesisOutput($request));
    app(SynthesizeSurveyDossier::class)->handle($intake);
    $firstOption = AircoInstallationOption::query()->where('intake_id', $intake->id)->sole();

    FakeAiClient::respondUsing(function (AiCompletionRequest $request): array {
        $output = validDossierSynthesisOutput($request, 'Ongegrond voorstel');
        $output['option_proposals'][0]['connections'][0]['evidence_references'] = ['bestaat:niet'];

        return $output;
    });
    $failed = app(SynthesizeSurveyDossier::class)->handle($intake->fresh());

    expect($failed?->status)->toBe(AiRunStatus::Failed)
        ->and(AircoInstallationOption::query()->where('intake_id', $intake->id)->count())->toBe(1)
        ->and(AircoInstallationOption::query()->where('intake_id', $intake->id)->sole()->id)->toBe($firstOption->id);

    FakeAiClient::respondUsing(fn (AiCompletionRequest $request): array => validDossierSynthesisOutput($request, 'Vernieuwd voorstel'));
    app(SynthesizeSurveyDossier::class)->handle($intake->fresh());

    expect(AircoInstallationOption::query()->where('intake_id', $intake->id)->count())->toBe(1)
        ->and(AircoInstallationOption::query()->where('intake_id', $intake->id)->sole()->label)->toBe('Vernieuwd voorstel')
        ->and(ContributionTask::query()
            ->where('intake_id', $intake->id)
            ->where('status', ContributionTaskStatus::Proposed)
            ->count())->toBe(1);
});

test('installer explicitly sends an AI proposed task before customer access becomes active', function () {
    [$intake, $user] = synthesisSurveyWithPlacements();
    FakeAiClient::respondUsing(fn (AiCompletionRequest $request): array => validDossierSynthesisOutput($request));
    app(SynthesizeSurveyDossier::class)->handle($intake);
    $task = ContributionTask::query()
        ->where('intake_id', $intake->id)
        ->where('status', ContributionTaskStatus::Proposed)
        ->sole();

    $this->actingAs($user)
        ->post(route('intakes.workspace.tasks.send', [$intake, $task]))
        ->assertRedirect(route('intakes.workspace', $intake));

    expect($intake->fresh()->workflow_mode)->toBe(ContributionMode::Hybrid)
        ->and($intake->fresh()->customer_access_enabled)->toBeTrue()
        ->and($task->fresh()->status)->toBe(ContributionTaskStatus::Cancelled)
        ->and($intake->followUpRounds()->where('purpose', 'contribution')->exists())->toBeTrue();
});
