<?php

declare(strict_types=1);

use App\Domains\AI\Actions\SynthesizeSurveyDossier;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\DTOs\AiCompletionRequest;
use App\Domains\AI\Support\InstallerCandidateDecisionPreserver;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\DecideAircoConnection;
use App\Domains\Intake\Actions\SaveInstallerObservation;
use App\Domains\Intake\Models\AircoConnection;
use App\Domains\Intake\Models\AircoInstallationOption;
use App\Domains\Intake\Models\DossierRecord;
use App\Domains\Intake\Models\DossierSubject;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\PipeRouteSession;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Support\ConnectionRouteDecisionPresenter;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoOptionFeasibility;
use App\Enums\AircoOptionStatus;
use App\Enums\AircoPlacementType;
use App\Enums\ContributionMode;
use App\Enums\PipeRouteStatus;
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

/** @return array{0: Intake, 1: User} */
function n29SurveyWithPlacements(): array
{
    $user = User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Routebesluit Klant',
        'customer_email' => 'routebesluit@example.com',
        'address_line' => 'Voorbeeldlaan 8',
        'address_postal_code' => '1000AA',
        'address_house_number' => 8,
        'address_city' => 'Utrecht',
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
function n29SynthesisOutput(AiCompletionRequest $request, string $label, string $refrigerantSegment): array
{
    $placements = collect($request->input['placements'])->keyBy('type');
    $inside = $placements->get('indoor_unit')['reference'];
    $outside = $placements->get('outdoor_unit')['reference'];
    $power = $placements->get('power_source')['reference'];
    $drain = $placements->get('drain_point')['reference'];
    $roomSubject = collect($request->input['rooms'])->first()['subject_reference'];

    return [
        'summary' => 'Single-split voorstel voor de slaapkamer.',
        'placement_proposals' => [],
        'option_proposals' => [[
            'label' => $label,
            'configuration_type' => AircoConfigurationType::SingleSplit->value,
            'summary' => 'Eén binnen- en buitenunit via de gevelroute.',
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
                    'segments' => [$refrigerantSegment],
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
                    'status' => 'needs_evidence',
                    'length_class' => 'short',
                    'segments' => ['Met natuurlijk verval naar de afvoer'],
                    'obstacles' => [],
                    'uncertainties' => ['Eindpunt nog te bevestigen'],
                    'cost_impact' => 'low',
                    'confidence' => 0.7,
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
        'exceptions' => [],
        'customer_tasks' => [[
            'type' => 'photo',
            'prompt' => 'Maak één scherpe foto van de meterkastlabels.',
            'decision_area_key' => 'power',
            'subject_reference' => $roomSubject,
            'reason' => 'Nodig voor de stroomroute.',
            'evidence_references' => [$power],
        ]],
    ];
}

test('N29 candidate route decisions and note survive AI rebuild without Deze keuze', function () {
    $this->withoutVite();
    [$intake, $user] = n29SurveyWithPlacements();

    FakeAiClient::respondUsing(
        static fn (AiCompletionRequest $request): array => n29SynthesisOutput(
            $request,
            'AI-optie eerste',
            'Door buitenmuur naar plat dak',
        ),
    );
    expect(app(SynthesizeSurveyDossier::class)->handle($intake->fresh()))->not->toBeNull();

    $option = AircoInstallationOption::query()
        ->where('intake_id', $intake->id)
        ->where('source_type', 'ai')
        ->where('status', AircoOptionStatus::Candidate)
        ->sole();
    expect($option->feasibility)->toBe(AircoOptionFeasibility::Pending);

    $survey = app(AircoSurveyService::class);
    $survey->markInstallationOptionFeasible($intake, $user, $option);

    $refrigerant = AircoConnection::query()
        ->where('airco_installation_option_id', $option->id)
        ->where('type', 'refrigerant')
        ->sole();

    app(DecideAircoConnection::class)->handle($intake, $refrigerant, $user, 'approve');

    $subject = DossierSubject::query()->findOrFail($refrigerant->dossier_subject_id);
    app(SaveInstallerObservation::class)->handle(
        $intake,
        $user,
        $subject,
        'installer_note.'.uniqid('', true),
        'Testnotitie: achtergevelroute ter plaatse controleren.',
    );

    $oldOptionId = $option->id;
    $oldConnectionId = $refrigerant->id;

    FakeAiClient::respondUsing(
        static fn (AiCompletionRequest $request): array => n29SynthesisOutput(
            $request,
            'AI-optie herbouwd',
            'Via de achtergevel naar het plat dak',
        ),
    );
    expect(app(SynthesizeSurveyDossier::class)->handle($intake->fresh()))->not->toBeNull();

    $options = AircoInstallationOption::query()
        ->where('intake_id', $intake->id)
        ->where('source_type', 'ai')
        ->where('status', AircoOptionStatus::Candidate)
        ->get();

    expect($options)->toHaveCount(1)
        ->and($options->sole()->id)->not->toBe($oldOptionId)
        ->and($options->sole()->label)->toBe('AI-optie herbouwd')
        ->and($options->sole()->feasibility)->toBe(AircoOptionFeasibility::Feasible);

    $freshRefrigerant = AircoConnection::query()
        ->where('airco_installation_option_id', $options->sole()->id)
        ->where('type', 'refrigerant')
        ->sole();

    expect($freshRefrigerant->id)->not->toBe($oldConnectionId)
        ->and($freshRefrigerant->status)->toBe(AircoConnectionStatus::Approved)
        ->and($freshRefrigerant->approved_by)->toBe($user->id);

    $note = DossierRecord::query()
        ->where('dossier_subject_id', $freshRefrigerant->dossier_subject_id)
        ->where('method', 'installer_note')
        ->where('source_type', 'installer')
        ->get()
        ->first(static fn (DossierRecord $record): bool => ($record->value['text'] ?? null) === 'Testnotitie: achtergevelroute ter plaatse controleren.');

    expect($note)->not->toBeNull();

    $decision = app(ConnectionRouteDecisionPresenter::class)->present($freshRefrigerant->fresh(['subject', 'routeSession']));
    expect($decision['proposal_updated_notice'])
        ->toBe(InstallerCandidateDecisionPreserver::NOTICE_TEXT);

    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('Goedgekeurd')
        ->assertSee('Testnotitie: achtergevelroute ter plaatse controleren.')
        ->assertSee('Je besluit blijft staan. Het voorstel is daarna bijgewerkt.')
        ->assertSee('Haalbaar');
});

test('N29 approved connection keeps pipe route session and segments across rebuild', function () {
    [$intake, $user] = n29SurveyWithPlacements();

    FakeAiClient::respondUsing(
        static fn (AiCompletionRequest $request): array => n29SynthesisOutput(
            $request,
            'AI-optie met routesessie',
            'Door buitenmuur naar plat dak',
        ),
    );
    app(SynthesizeSurveyDossier::class)->handle($intake->fresh());

    $option = AircoInstallationOption::query()
        ->where('intake_id', $intake->id)
        ->where('source_type', 'ai')
        ->sole();
    app(AircoSurveyService::class)->markInstallationOptionFeasible($intake, $user, $option);

    $refrigerant = AircoConnection::query()
        ->where('airco_installation_option_id', $option->id)
        ->where('type', 'refrigerant')
        ->sole();

    $session = $intake->pipeRouteSessions()->create([
        'airco_connection_id' => $refrigerant->id,
        'status' => PipeRouteStatus::Proposed,
        'proposed_route' => ['Binnenwand', 'overloop', 'achtergevel'],
        'uncertainties' => [],
        'confidence' => 0.9,
    ]);
    $segment = $session->segments()->create([
        'intake_upload_id' => null,
        'sequence' => 1,
        'label' => 'Segment achtergevel',
        'photo_usable' => true,
        'route_possible' => true,
        'confidence' => 0.9,
        'analysis' => ['route_segments' => ['achtergevel']],
    ]);

    app(DecideAircoConnection::class)->handle($intake, $refrigerant, $user, 'approve');

    $oldConnectionId = $refrigerant->id;
    $sessionId = $session->id;
    $segmentId = $segment->id;

    FakeAiClient::respondUsing(
        static fn (AiCompletionRequest $request): array => n29SynthesisOutput(
            $request,
            'AI-optie herbouwd met routesessie',
            'Via de achtergevel naar het plat dak',
        ),
    );
    app(SynthesizeSurveyDossier::class)->handle($intake->fresh());

    $freshOption = AircoInstallationOption::query()
        ->where('intake_id', $intake->id)
        ->where('source_type', 'ai')
        ->where('status', AircoOptionStatus::Candidate)
        ->sole();
    $freshRefrigerant = AircoConnection::query()
        ->where('airco_installation_option_id', $freshOption->id)
        ->where('type', 'refrigerant')
        ->sole();

    expect($freshRefrigerant->id)->not->toBe($oldConnectionId)
        ->and($freshRefrigerant->status)->toBe(AircoConnectionStatus::Approved);

    $movedSession = PipeRouteSession::query()->whereKey($sessionId)->first();
    expect($movedSession)->not->toBeNull()
        ->and($movedSession?->airco_connection_id)->toBe($freshRefrigerant->id)
        ->and($movedSession?->status)->toBe(PipeRouteStatus::Approved)
        ->and($movedSession?->proposed_route)->toBe(['Binnenwand', 'overloop', 'achtergevel']);

    expect($movedSession?->segments()->whereKey($segmentId)->exists())->toBeTrue()
        ->and($movedSession?->segments()->sole()->label)->toBe('Segment achtergevel');

    expect(PipeRouteSession::query()->where('airco_connection_id', $oldConnectionId)->exists())->toBeFalse();
});

test('N29 selected option decisions stay untouched across rebuild', function () {
    [$intake, $user] = n29SurveyWithPlacements();

    FakeAiClient::respondUsing(
        static fn (AiCompletionRequest $request): array => n29SynthesisOutput(
            $request,
            'AI-optie selectie',
            'Door buitenmuur naar plat dak',
        ),
    );
    app(SynthesizeSurveyDossier::class)->handle($intake->fresh());

    $option = AircoInstallationOption::query()
        ->where('intake_id', $intake->id)
        ->where('source_type', 'ai')
        ->sole();
    $survey = app(AircoSurveyService::class);
    $survey->markInstallationOptionFeasible($intake, $user, $option);
    $survey->selectInstallationOption($intake, $user, $option);

    $refrigerant = AircoConnection::query()
        ->where('airco_installation_option_id', $option->id)
        ->where('type', 'refrigerant')
        ->sole();
    app(DecideAircoConnection::class)->handle($intake, $refrigerant, $user, 'approve');

    FakeAiClient::respondUsing(
        static fn (AiCompletionRequest $request): array => n29SynthesisOutput(
            $request,
            'AI-optie naast selectie',
            'Andere AI-route',
        ),
    );
    app(SynthesizeSurveyDossier::class)->handle($intake->fresh());

    $selected = AircoInstallationOption::query()
        ->where('intake_id', $intake->id)
        ->where('status', AircoOptionStatus::Selected)
        ->sole();

    expect($selected->id)->toBe($option->id)
        ->and($selected->feasibility)->toBe(AircoOptionFeasibility::Feasible);

    expect(AircoConnection::query()->whereKey($refrigerant->id)->sole()->status)
        ->toBe(AircoConnectionStatus::Approved);

    // New candidate may appear beside the selected option; selected itself is preserved.
    expect(
        AircoInstallationOption::query()
            ->where('intake_id', $intake->id)
            ->where('source_type', 'ai')
            ->count()
    )->toBeGreaterThanOrEqual(1);
});

test('N29 rebuild without installer decisions still replaces candidates without stacking', function () {
    [$intake] = n29SurveyWithPlacements();

    FakeAiClient::respondUsing(
        static fn (AiCompletionRequest $request): array => n29SynthesisOutput(
            $request,
            'AI-optie A',
            'Door buitenmuur naar plat dak',
        ),
    );
    app(SynthesizeSurveyDossier::class)->handle($intake->fresh());
    expect(AircoInstallationOption::query()->where('intake_id', $intake->id)->count())->toBe(1);

    FakeAiClient::respondUsing(
        static fn (AiCompletionRequest $request): array => n29SynthesisOutput(
            $request,
            'AI-optie B',
            'Via dakkapel',
        ),
    );
    app(SynthesizeSurveyDossier::class)->handle($intake->fresh());

    $options = AircoInstallationOption::query()
        ->where('intake_id', $intake->id)
        ->where('source_type', 'ai')
        ->where('status', AircoOptionStatus::Candidate)
        ->get();

    expect($options)->toHaveCount(1)
        ->and($options->sole()->label)->toBe('AI-optie B')
        ->and($options->sole()->feasibility)->toBe(AircoOptionFeasibility::Pending);

    $refrigerant = AircoConnection::query()
        ->where('airco_installation_option_id', $options->sole()->id)
        ->where('type', 'refrigerant')
        ->sole();
    expect($refrigerant->status)->toBe(AircoConnectionStatus::Proposed);
});

test('N69 installer-described power route keeps its description across AI rebuild (defined, then approved)', function (bool $approve) {
    $this->withoutVite();
    [$intake, $user] = n29SurveyWithPlacements();
    FakeAiClient::respondUsing(static fn (AiCompletionRequest $r): array => n29SynthesisOutput($r, 'AI-optie', 'Door buitenmuur'));
    app(SynthesizeSurveyDossier::class)->handle($intake->fresh());

    $power = AircoConnection::query()->where('intake_id', $intake->id)->where('type', 'power')->sole();
    $route = 'Van meterkast in de gang via kabelgoot langs de achtergevel naar buitenunit in de tuin.';
    $this->actingAs($user)
        ->post(route('intakes.workspace.connections.define', [$intake, $power]), ['segments_text' => $route])
        ->assertRedirect();
    if ($approve) {
        app(DecideAircoConnection::class)->handle($intake, $power->fresh(), $user, 'approve');
    }

    FakeAiClient::respondUsing(static fn (AiCompletionRequest $r): array => n29SynthesisOutput($r, 'AI-optie herbouwd', 'Door buitenmuur'));
    app(SynthesizeSurveyDossier::class)->handle($intake->fresh());

    $fresh = AircoConnection::query()->where('intake_id', $intake->id)->where('type', 'power')
        ->whereHas('installationOption', fn ($q) => $q->where('status', '!=', AircoOptionStatus::Rejected))->get();
    expect($fresh)->toHaveCount(1);
    $fresh = $fresh->sole();
    expect($fresh->segments)->toBe([$route])
        ->and($fresh->uncertainties)->toBe([])
        ->and($fresh->status)->toBe($approve ? AircoConnectionStatus::Approved : AircoConnectionStatus::Proposed);
})->with(['alleen vastgelegd' => false, 'vastgelegd en goedgekeurd' => true]);

test('N77 approved route survives a rebuild that leaves its start point open', function () {
    [$intake, $user] = n29SurveyWithPlacements();

    FakeAiClient::respondUsing(
        static fn (AiCompletionRequest $request): array => n29SynthesisOutput($request, 'AI-optie eerste', 'Door buitenmuur naar plat dak'),
    );
    expect(app(SynthesizeSurveyDossier::class)->handle($intake->fresh()))->not->toBeNull();

    $option = AircoInstallationOption::query()->where('intake_id', $intake->id)->where('status', AircoOptionStatus::Candidate)->sole();
    $refrigerant = AircoConnection::query()->where('airco_installation_option_id', $option->id)->where('type', 'refrigerant')->sole();
    $indoorId = $refrigerant->from_placement_id;
    expect($indoorId)->not->toBeNull();
    app(DecideAircoConnection::class)->handle($intake, $refrigerant, $user, 'approve');

    // Rebuild: same option, but the refrigerant route lost its indoor start point.
    FakeAiClient::respondUsing(static function (AiCompletionRequest $request): array {
        $output = n29SynthesisOutput($request, 'AI-optie herbouwd', 'Via de achtergevel naar het plat dak');
        $output['option_proposals'][0]['connections'][0]['from_placement_reference'] = null;

        return $output;
    });
    expect(app(SynthesizeSurveyDossier::class)->handle($intake->fresh()))->not->toBeNull();

    $options = AircoInstallationOption::query()->where('intake_id', $intake->id)->where('status', AircoOptionStatus::Candidate)->get();
    expect($options)->toHaveCount(1);

    $fresh = AircoConnection::query()->where('airco_installation_option_id', $options->sole()->id)->where('type', 'refrigerant')->sole();
    expect($fresh->status)->toBe(AircoConnectionStatus::Approved)
        ->and($fresh->approved_by)->toBe($user->id)
        ->and($fresh->from_placement_id)->toBe($indoorId)
        ->and($fresh->label)->toBe('Koelleiding slaapkamer');
});
