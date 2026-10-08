<?php

declare(strict_types=1);

use App\Domains\AI\Actions\SynthesizeSurveyDossier;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\DTOs\AiCompletionRequest;
use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\CompleteInstallerSurvey;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\LoadDemoSurveyScenario;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\AircoConnection;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAttentionPoint;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\DecisionReadinessService;
use App\Domains\Intake\Services\DossierManager;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoConnectionType;
use App\Enums\AircoOptionFeasibility;
use App\Enums\AircoOptionStatus;
use App\Enums\AircoPlacementType;
use App\Enums\AiRunStatus;
use App\Enums\AttentionPointSource;
use App\Enums\AttentionPointStatus;
use App\Enums\ContributionMode;
use App\Enums\IntakeStatus;
use App\Enums\PhotoAssessmentStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Fix bundle D — dossier synthesis, approval, example dossier (intake 101 / Test 5).
 *
 * Relies on phpunit INTAKE_SEED_LATEST_TEMPLATE_ONLY (no full v1–v26 re-seed).
 * Photo evidence uses Storage::fake bytes — no multi-megapixel fixtures.
 */
beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

function bundleDCreateRichIntake(User $user, string $email = 'intake101@example.com'): Intake
{
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Intake 101 Rijk',
        'customer_email' => $email,
        'address_line' => 'Voorbeeldstraat 12',
        'address_postal_code' => '2011AA',
        'address_house_number' => 12,
        'address_city' => 'Haarlem',
        'is_demo' => true,
    ]);
    $intake->update(['status' => IntakeStatus::InProgress]);

    $save = app(SaveIntakeAnswer::class);
    $rooms = [
        ['room-1', 'living_room', 'woonkamer', 'ground', ['length_m' => 6, 'width_m' => 4, 'height_m' => 2.5]],
        ['room-2', 'attic', 'zolderslaapkamer', '2', ['area_m2' => 15, 'area_m2_source' => 'customer']],
        ['room-3', 'bedroom', 'slaapkamer ouders', '1', ['length_m' => 4, 'width_m' => 3.5, 'height_m' => 2.5]],
        ['room-4', 'office', 'werkkamer', '1', ['length_m' => 3.5, 'width_m' => 3, 'height_m' => 2.5]],
    ];

    foreach ($rooms as [$instance, $type, $name, $floor, $dimensions]) {
        $save->handle($intake, 'room_type', $instance, ['value' => $type], null);
        $save->handle($intake, 'room_name', $instance, ['text' => $name], null);
        $save->handle($intake, 'floor_level', $instance, ['value' => $floor], null);
        if (isset($dimensions['length_m'], $dimensions['width_m'])) {
            $save->handle($intake, 'room_length_m', $instance, ['number' => $dimensions['length_m']], null);
            $save->handle($intake, 'room_width_m', $instance, ['number' => $dimensions['width_m']], null);
        }
        if (isset($dimensions['height_m'])) {
            $save->handle($intake, 'ceiling_height_m', $instance, ['number' => $dimensions['height_m']], null);
        }
        if (isset($dimensions['area_m2'])) {
            $save->handle($intake, 'room_area_m2', $instance, ['number' => $dimensions['area_m2']], null);
        }
    }

    app(DossierManager::class)->initialize($intake->fresh());

    return $intake->fresh(['aircoRooms', 'answers']) ?? $intake;
}

function bundleDSeedPartialSolution(Intake $intake, User $user, bool $withUncertainty = true): AircoConnection
{
    $survey = app(AircoSurveyService::class);
    $rooms = $intake->aircoRooms()->orderBy('sort_order')->get();
    $roomA = $rooms[0];
    $roomB = $rooms[1];

    $insideA = $survey->createPlacement($intake, $user, [
        'airco_room_id' => $roomA->id,
        'type' => AircoPlacementType::IndoorUnit,
        'label' => 'Binnenunit '.$roomA->name,
    ]);
    $insideB = $survey->createPlacement($intake, $user, [
        'airco_room_id' => $roomB->id,
        'type' => AircoPlacementType::IndoorUnit,
        'label' => 'Binnenunit '.$roomB->name,
    ]);
    $outside = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::OutdoorUnit,
        'label' => 'Buitenunit achtergevel',
    ]);
    $power = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::PowerSource,
        'label' => 'Meterkast',
    ]);
    $drain = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::DrainPoint,
        'label' => 'Afvoer',
    ]);

    foreach ([$power, $outside] as $index => $placement) {
        IntakeUpload::query()->create([
            'intake_id' => $intake->id,
            'question_key' => 'installer_evidence',
            'section_instance_key' => 'subject-'.$placement->dossier_subject_id,
            'disk' => 'local',
            'path' => 'test/bundle-d-evidence-'.$index.'.jpg',
            'original_filename' => 'evidence-'.$index.'.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
            'checksum' => hash('sha256', 'bundle-d-evidence-'.$index),
            'sort_order' => 1,
            'assessment_status' => PhotoAssessmentStatus::Assessed,
            'content_assessment' => PhotoContentAssessment::ok(PhotoSubject::Fusebox)->toArray(),
        ]);
    }

    $option = $survey->createInstallationOption($intake, $user, [
        'label' => 'Keuze voor 2 van 4 ruimtes',
        'configuration_type' => AircoConfigurationType::MultiSplit,
        'summary' => 'Alleen woonkamer en zolder.',
        'cost_impact' => 'medium',
        'placement_ids' => [$insideA->id, $insideB->id, $outside->id, $power->id, $drain->id],
    ]);

    foreach ([
        [AircoConnectionType::Refrigerant, 'Koel A', $insideA->id, $outside->id, []],
        [AircoConnectionType::Refrigerant, 'Koel B', $insideB->id, $outside->id, []],
        [AircoConnectionType::Condensate, 'Condens A', $insideA->id, $drain->id, []],
        [AircoConnectionType::Condensate, 'Condens B', $insideB->id, $drain->id, []],
        [AircoConnectionType::Power, 'Stroom', $power->id, $outside->id, $withUncertainty
            ? ['Controleer de deels onleesbare groepsaanduiding vóór de definitieve offerte.']
            : []],
    ] as [$type, $label, $from, $to, $uncertainties]) {
        $connection = $survey->createConnection($intake, $user, $option, [
            'type' => $type,
            'label' => $label,
            'from_placement_id' => $from,
            'to_placement_id' => $to,
            'status' => AircoConnectionStatus::Proposed,
            'length_class' => 'medium',
            'segments' => ['Zichtbare route'],
            'cost_impact' => 'medium',
            'confidence' => 0.8,
            'uncertainties' => $uncertainties,
        ]);
    }

    $survey->markInstallationOptionFeasible($intake, $user, $option);
    $survey->selectInstallationOption($intake, $user, $option);

    return $option->connections()->where('type', AircoConnectionType::Power)->firstOrFail();
}

test('P1 intake-101: rejected photos are excluded from synthesis budget and invented meter cupboard claims are stripped', function () {
    config([
        'ai.provider' => 'fake',
        'ai.dossier.enabled' => true,
        'ai.dossier.max_images' => 8,
    ]);
    Storage::fake('local');

    $user = User::factory()->create();
    $intake = bundleDCreateRichIntake($user, 'synthesis-101@example.com');
    $survey = app(AircoSurveyService::class);
    $room = $intake->aircoRooms->first();
    $indoor = $survey->createPlacement($intake, $user, [
        'airco_room_id' => $room->id,
        'type' => AircoPlacementType::IndoorUnit,
        'label' => 'Binnen',
    ]);
    $outdoor = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::OutdoorUnit,
        'label' => 'Buiten',
    ]);

    $okPath = "intakes/{$intake->id}/ok.jpg";
    $badPath = "intakes/{$intake->id}/rejected.jpg";
    Storage::disk('local')->put($okPath, 'ok-bytes');
    Storage::disk('local')->put($badPath, 'bad-bytes');
    Storage::disk('local')->put($okPath.'-a', 'ok-analysis');
    Storage::disk('local')->put($badPath.'-a', 'bad-analysis');

    $ok = IntakeUpload::query()->create([
        'intake_id' => $intake->id,
        'question_key' => 'room_photos',
        'section_instance_key' => $room->key,
        'disk' => 'local',
        'path' => $okPath,
        'analysis_path' => $okPath.'-a',
        'analysis_mime_type' => 'image/jpeg',
        'analysis_size_bytes' => 9,
        'analysis_checksum' => hash('sha256', 'ok-analysis'),
        'original_filename' => 'ok.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 8,
        'checksum' => hash('sha256', 'ok-bytes'),
        'sort_order' => 1,
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'content_assessment' => PhotoContentAssessment::ok(PhotoSubject::Room)->toArray(),
    ]);
    $rejected = IntakeUpload::query()->create([
        'intake_id' => $intake->id,
        'question_key' => 'fusebox_photo',
        'section_instance_key' => null,
        'disk' => 'local',
        'path' => $badPath,
        'analysis_path' => $badPath.'-a',
        'analysis_mime_type' => 'image/jpeg',
        'analysis_size_bytes' => 10,
        'analysis_checksum' => hash('sha256', 'bad-analysis'),
        'original_filename' => 'rejected.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 9,
        'checksum' => hash('sha256', 'bad-bytes'),
        'sort_order' => 1,
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'content_assessment' => PhotoContentAssessment::wrongSubject(
            PhotoSubject::Fusebox,
            PhotoSubject::Room,
        )->toArray(),
    ]);

    FakeAiClient::respondUsing(function (AiCompletionRequest $request) use ($ok, $indoor, $outdoor): array {
        $manifest = collect($request->input['image_manifest'] ?? []);
        expect($manifest->pluck('reference')->all())->toBe(['dossier_image:'.$ok->id])
            ->and($manifest->every(fn (array $row): bool => ($row['evidence_eligible'] ?? false) === true))->toBeTrue();

        $roomSubject = 'subject:'.$indoor->dossier_subject_id;
        $roomRef = 'room:'.$indoor->airco_room_id;

        return [
            'summary' => '3-fase aansluiting met vrije groepen op basis van de meterkastfoto.',
            'placement_proposals' => [[
                'key' => 'proposal:outdoor_extra',
                'type' => AircoPlacementType::OutdoorUnit->value,
                'label' => 'Buitenunit achtergevel',
                'description' => 'Zichtbaar op gevel.',
                'room_reference' => null,
                'subject_reference' => 'subject:'.$outdoor->dossier_subject_id,
                'confidence' => 0.8,
                'evidence_references' => ['dossier_image:'.$ok->id],
            ]],
            'option_proposals' => [[
                'label' => 'Optie met verzonnen meterkast',
                'configuration_type' => AircoConfigurationType::SingleSplit->value,
                'summary' => '3-fase aansluiting met vrije groepen',
                'cost_impact' => 'medium',
                'confidence' => 0.7,
                'placement_references' => ['placement:'.$indoor->id, 'placement:'.$outdoor->id],
                'connections' => [
                    [
                        'type' => 'refrigerant',
                        'label' => 'Koel',
                        'from_placement_reference' => 'placement:'.$indoor->id,
                        'to_placement_reference' => 'placement:'.$outdoor->id,
                        'status' => 'proposed',
                        'length_class' => 'short',
                        'segments' => ['Kort'],
                        'obstacles' => [],
                        'uncertainties' => [],
                        'cost_impact' => 'low',
                        'confidence' => 0.7,
                        'evidence_references' => ['dossier_image:'.$ok->id],
                    ],
                    [
                        'type' => 'condensate',
                        'label' => 'Condens',
                        'from_placement_reference' => 'placement:'.$indoor->id,
                        'to_placement_reference' => 'placement:'.$outdoor->id,
                        'status' => 'proposed',
                        'length_class' => 'short',
                        'segments' => ['Kort'],
                        'obstacles' => [],
                        'uncertainties' => [],
                        'cost_impact' => 'low',
                        'confidence' => 0.7,
                        'evidence_references' => ['dossier_image:'.$ok->id],
                    ],
                    [
                        'type' => 'power',
                        'label' => 'Stroom met vrije groep',
                        'from_placement_reference' => 'placement:'.$indoor->id,
                        'to_placement_reference' => 'placement:'.$outdoor->id,
                        'status' => 'proposed',
                        'length_class' => 'short',
                        'segments' => ['Kort'],
                        'obstacles' => [],
                        'uncertainties' => [],
                        'cost_impact' => 'low',
                        'confidence' => 0.7,
                        'evidence_references' => ['dossier_image:'.$ok->id],
                    ],
                ],
            ]],
            'exceptions' => [],
            'customer_tasks' => [],
            '_unused_room' => $roomSubject.$roomRef,
        ];
    });

    $run = app(SynthesizeSurveyDossier::class)->handle($intake->fresh());

    expect($run)->not->toBeNull()
        ->and($run->status)->toBeIn([AiRunStatus::Succeeded, AiRunStatus::Partial])
        ->and($rejected->fresh()->isDossierEvidenceEligible())->toBeFalse()
        ->and(strtolower((string) data_get($run->output, 'summary', '')))->not->toContain('vrije groep')
        ->and(strtolower((string) data_get($run->output, 'summary', '')))->not->toContain('3-fase');
});

test('P2: bulk approval blocks unresolved uncertainty and uncovered rooms with one shared rule set', function () {
    $user = User::factory()->create();
    $intake = bundleDCreateRichIntake($user, 'approve-101@example.com');
    bundleDSeedPartialSolution($intake, $user, withUncertainty: true);

    app(DecisionReadinessService::class)->recalculate($intake->fresh());
    $assessment = app(DecisionReadinessService::class)->bulkApprovalAssessment($intake->fresh());

    expect($assessment['allowed'])->toBeFalse()
        ->and($assessment['uncovered_room_names'])->toHaveCount(2)
        ->and($assessment['unresolved_uncertainties'])->not->toBeEmpty()
        ->and(collect($assessment['blockers'])->implode(' '))->toContain('onzekerheid')
        ->and(collect($assessment['blockers'])->implode(' '))->toContain('niet alle aangevraagde ruimtes');

    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('Nog niet klaar om goed te keuren')
        ->assertSee('data-testid="approval-blockers"', false)
        ->assertDontSee('data-testid="approve-proposal"', false);

    expect(fn () => app(CompleteInstallerSurvey::class)->handle($intake->fresh(), $user))
        ->toThrow(ValidationException::class);

    expect($intake->fresh()->aircoInstallationOptions->first()?->connections
        ->where('status', AircoConnectionStatus::Approved)
        ->count())->toBe(0);

    // Explicit acceptance of the uncertain route unlocks that blocker, but room coverage remains.
    $power = $intake->fresh()->aircoInstallationOptions->first()?->connections
        ->firstWhere('type', AircoConnectionType::Power);
    expect($power)->not->toBeNull();
    $power->update([
        'status' => AircoConnectionStatus::Approved,
        'approved_by' => $user->id,
        'approved_at' => now(),
    ]);

    $after = app(DecisionReadinessService::class)->bulkApprovalAssessment($intake->fresh());
    expect($after['allowed'])->toBeFalse()
        ->and(collect($after['blockers'])->implode(' '))->toContain('niet alle aangevraagde ruimtes');
});

test('P2: bulk approval blockers are visible for unslected AI candidate options (real synthesis path)', function () {
    $user = User::factory()->create();
    $intake = bundleDCreateRichIntake($user, 'approve-candidate@example.com');
    $power = bundleDSeedPartialSolution($intake, $user, withUncertainty: true);

    // Mimic post-synthesis state: AI Candidate, not Selected (demo bootstrap auto-selects; real runs do not).
    $option = $power->installationOption ?? $intake->fresh()->aircoInstallationOptions->first();
    expect($option)->not->toBeNull();
    $option->update([
        'status' => AircoOptionStatus::Candidate,
        'source_type' => 'ai',
        'selected_at' => null,
        'feasibility' => AircoOptionFeasibility::Pending,
    ]);

    $assessment = app(DecisionReadinessService::class)->bulkApprovalAssessment($intake->fresh());

    expect($assessment['allowed'])->toBeFalse()
        ->and($assessment['unresolved_uncertainties'])->not->toBeEmpty()
        ->and(collect($assessment['blockers'])->implode(' '))->toContain('Selecteer eerst')
        ->and(collect($assessment['blockers'])->implode(' '))->toContain('onzekerheid');

    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('Nog niet klaar om goed te keuren')
        ->assertSee('data-testid="approval-blockers"', false)
        ->assertSee('data-testid="approval-not-ready"', false)
        ->assertDontSee('data-testid="approve-proposal"', false);

    $this->actingAs($user)
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee('data-testid="approval-blocked-panel"', false)
        ->assertSee('data-testid="approval-blockers"', false)
        ->assertSee('onzekerheid');
});

test('P2: bulk approval panel stays visible when AI attention points exist but option was rejected', function () {
    $user = User::factory()->create();
    $intake = bundleDCreateRichIntake($user, 'approve-no-option@example.com');

    // No installation options — synthesis rejected option_proposals entirely.
    expect($intake->aircoInstallationOptions)->toBeEmpty();

    IntakeAttentionPoint::query()->create([
        'intake_id' => $intake->id,
        'code' => 'ai:test-exception',
        'label' => 'Controleer de meterkastcapaciteit handmatig.',
        'source' => AttentionPointSource::Ai,
        'status' => AttentionPointStatus::Proposed,
        'ai_confidence' => 'medium',
        'evidence' => [[
            'source_type' => 'system_attention_point',
            'reference' => 'manual:meterkast-check',
        ]],
    ]);

    $assessment = app(DecisionReadinessService::class)->bulkApprovalAssessment($intake->fresh());

    expect($assessment['allowed'])->toBeFalse()
        ->and(app(DecisionReadinessService::class)->hasOpenAiProposals($intake->fresh()))->toBeTrue()
        ->and(collect($assessment['blockers'])->implode(' '))
        ->toContain('Nog geen bruikbaar installatievoorstel');

    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('Nog niet klaar om goed te keuren')
        ->assertSee('data-testid="approval-blockers"', false)
        ->assertSee('Nog geen bruikbaar installatievoorstel')
        ->assertDontSee('data-testid="approve-proposal"', false);

    $this->actingAs($user)
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee('data-testid="approval-blocked-panel"', false)
        ->assertSee('Nog geen bruikbaar installatievoorstel')
        ->assertSee('Accepteren');
});

test('P2: customer room names and floor reach the installer dossier exactly', function () {
    $user = User::factory()->create();
    $intake = bundleDCreateRichIntake($user, 'room-names@example.com');

    $rooms = $intake->aircoRooms()->orderBy('sort_order')->get();
    expect($rooms)->toHaveCount(4)
        ->and($rooms[0]->name)->toBe('woonkamer, begane grond')
        ->and($rooms[1]->name)->toBe('zolderslaapkamer, 2e verdieping')
        ->and($rooms[2]->name)->toBe('slaapkamer ouders, 1e verdieping')
        ->and($rooms[3]->name)->toBe('werkkamer, 1e verdieping');

    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('zolderslaapkamer, 2e verdieping')
        ->assertDontSee('>Zolder 1<', false);
});

test('P2: example dossier opens a separate labelled demo intake and never mixes rooms', function () {
    $user = User::factory()->create();
    $intake = bundleDCreateRichIntake($user, 'example-mix@example.com');
    $ownRoomIds = $intake->aircoRooms()->pluck('id')->all();
    $ownRoomCount = count($ownRoomIds);
    expect($ownRoomCount)->toBe(4);

    $example = app(LoadDemoSurveyScenario::class)->handle($intake, $user);

    expect($example->id)->not->toBe($intake->id)
        ->and($example->customer_name)->toBe('Voorbeelddossier (demo)')
        ->and($example->is_demo)->toBeTrue()
        ->and($example->aircoRooms)->toHaveCount(2)
        ->and($intake->fresh()->aircoRooms)->toHaveCount(4)
        ->and($intake->fresh()->aircoRooms->pluck('id')->all())->toEqual($ownRoomIds);

    $this->actingAs($user)
        ->withSession([
            'public_demo_mode' => true,
            'public_demo_intake_id' => $intake->id,
        ])
        ->post(route('demo.scenario.load', $intake))
        ->assertRedirect(route('intakes.workspace', $example));

    $this->actingAs($user)
        ->get(route('intakes.workspace', $example))
        ->assertOk()
        ->assertSee('Voorbeelddossier (demo)')
        ->assertSee('apart gelabeld voorbeelddossier');

    // Own intake still has exactly the request rooms after workspace reload.
    app(DossierManager::class)->initialize($intake->fresh());
    expect($intake->fresh()->aircoRooms)->toHaveCount(4)
        ->and($intake->fresh()->aircoRooms->pluck('name')->implode('|'))
        ->toContain('zolderslaapkamer, 2e verdieping');
});
