<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\CompleteInstallerSurvey;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StartPipeRouteSession;
use App\Domains\Intake\Models\AircoConnection;
use App\Domains\Intake\Models\AircoInstallationOption;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\RefreshStoredIntakeReport;
use App\Domains\Intake\Support\ConnectionRouteDecisionPresenter;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoConnectionType;
use App\Enums\AircoPlacementType;
use App\Enums\ContributionMode;
use App\Enums\PipeRouteStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    $this->withoutVite();
});

/**
 * @return array{
 *     intake: Intake,
 *     connection: AircoConnection,
 *     condensate: AircoConnection,
 *     power: AircoConnection,
 *     option: AircoInstallationOption
 * }
 */
function createRouteDecisionWorkspace(User $user): array
{
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Testmans Route',
        'customer_email' => 'route-besluit@example.com',
        'address_line' => 'Voorbeeldstraat 12',
        'address_postal_code' => '1234AB',
        'address_house_number' => 12,
        'address_city' => 'Utrecht',
    ]);
    $survey = app(AircoSurveyService::class);
    $room = $survey->createRoom($intake, $user, [
        'name' => 'Woonkamer',
        'use_type' => 'living_room',
        'length_m' => 5.0,
        'width_m' => 4.0,
        'height_m' => 2.6,
    ]);
    $inside = $survey->createPlacement($intake, $user, [
        'airco_room_id' => $room->id,
        'type' => AircoPlacementType::IndoorUnit,
        'label' => 'Binnenpositie woonkamer',
    ]);
    $outside = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::OutdoorUnit,
        'label' => 'Buitenpositie',
    ]);
    $powerPoint = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::PowerSource,
        'label' => 'Meterkast',
    ]);
    $drain = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::DrainPoint,
        'label' => 'Regenpijp',
    ]);

    foreach ([$powerPoint, $outside] as $index => $placement) {
        IntakeUpload::query()->create([
            'intake_id' => $intake->id,
            'question_key' => 'installer_evidence',
            'section_instance_key' => 'subject-'.$placement->dossier_subject_id,
            'disk' => 'local',
            'path' => 'test/route-decision-evidence-'.$index.'.jpg',
            'original_filename' => $index === 0 ? 'meterkast.jpg' : 'gevel.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
            'checksum' => hash('sha256', 'route-decision-evidence-'.$index),
            'sort_order' => 1,
        ]);
    }

    $option = $survey->createInstallationOption($intake, $user, [
        'label' => 'Single-split haalbaar',
        'configuration_type' => AircoConfigurationType::SingleSplit,
        'placement_ids' => [$inside->id, $outside->id, $powerPoint->id, $drain->id],
    ]);
    $connection = $survey->createConnection($intake, $user, $option, [
        'type' => AircoConnectionType::Refrigerant,
        'label' => 'Koelleiding woonkamer',
        'from_placement_id' => $inside->id,
        'to_placement_id' => $outside->id,
        'status' => AircoConnectionStatus::Proposed,
        'length_class' => 'medium',
        'segments' => ['Binnenwand → overloop → achtergevel'],
        'cost_impact' => 'medium',
        'confidence' => 0.85,
    ]);
    $condensate = $survey->createConnection($intake, $user, $option, [
        'type' => AircoConnectionType::Condensate,
        'label' => 'Condensafvoer woonkamer',
        'from_placement_id' => $inside->id,
        'to_placement_id' => $drain->id,
        'status' => AircoConnectionStatus::Proposed,
        'length_class' => 'short',
        'segments' => ['Zelfde route als koelleiding'],
        'cost_impact' => 'low',
        'confidence' => 0.8,
    ]);
    $power = $survey->createConnection($intake, $user, $option, [
        'type' => AircoConnectionType::Power,
        'label' => 'Stroom naar buitenunit',
        'from_placement_id' => $powerPoint->id,
        'to_placement_id' => $outside->id,
        'status' => AircoConnectionStatus::Proposed,
        'length_class' => 'short',
        'segments' => ['Vanaf meterkast'],
        'cost_impact' => 'low',
        'confidence' => 0.9,
    ]);
    $survey->markInstallationOptionFeasible($intake, $user, $option);
    $survey->selectInstallationOption($intake, $user, $option);

    return compact('intake', 'connection', 'condensate', 'power', 'option');
}

test('proposed koelroute zonder installateursfoto toont stappen en directe beslissingen', function () {
    $user = User::factory()->create();
    ['intake' => $intake, 'connection' => $connection] = createRouteDecisionWorkspace($user);

    expect($connection->routeSession)->toBeNull();

    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('Koelleiding woonkamer')
        ->assertSee('Route beoordelen')
        ->assertSee('Bekijk de route en de foto’s')
        ->assertSee('Maak je eigen foto’s')
        ->assertSee('Vat de route samen')
        ->assertSee('Keur goed of kies locatiebezoek')
        ->assertSee('Route goedkeuren')
        ->assertSee('Alleen te zien op locatie')
        ->assertSee('Eigen foto toevoegen')
        ->assertSee('data-testid="connection-route-decision"', false)
        ->assertDontSee('Route samenvatten')
        ->assertDontSee('Beoordelen in stappen')
        ->assertDontSee('Eigen installateursfoto is optioneel');

    $decision = app(ConnectionRouteDecisionPresenter::class)->present($connection->fresh());
    $currentSteps = array_values(array_filter(
        $decision['steps'],
        static fn (array $step): bool => $step['state'] === 'current',
    ));
    expect($currentSteps)->toHaveCount(1)
        ->and($currentSteps[0]['label'])->toBe('Keur goed of kies locatiebezoek');
});

test('collecting route sessie legt uit waarom goedkeuren nog geblokkeerd is', function () {
    $user = User::factory()->create();
    ['intake' => $intake, 'connection' => $connection] = createRouteDecisionWorkspace($user);

    $intake->pipeRouteSessions()->create([
        'airco_connection_id' => $connection->id,
        'status' => PipeRouteStatus::Collecting,
    ]);

    $html = $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('Route beoordelen')
        ->assertSee('Route samenvatten')
        ->assertSee('Alleen te zien op locatie')
        ->assertSee('Vat eerst de route samen.')
        ->getContent();

    expect($html)->toContain('data-testid="connection-route-approve-blocked"')
        ->and($html)->not->toContain('action="'.route('intakes.workspace.routes.approve', [$intake, $connection->fresh()->routeSession]).'"');
});

test('proposed session met NeedsEvidence toont waarschuwing maar blijft goedkeurbaar', function () {
    $user = User::factory()->create();
    ['intake' => $intake, 'connection' => $connection] = createRouteDecisionWorkspace($user);

    $connection->update([
        'status' => AircoConnectionStatus::NeedsEvidence,
        'uncertainties' => ['Doorgang achter de kast is onzeker.'],
    ]);
    $intake->pipeRouteSessions()->create([
        'airco_connection_id' => $connection->id,
        'status' => PipeRouteStatus::Proposed,
        'proposed_route' => ['Via de gevel'],
        'uncertainties' => ['Doorgang achter de kast is onzeker.'],
    ]);

    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('Route goedkeuren')
        ->assertSee('Let op: er staan nog onzekerheden bij deze route.')
        ->assertSee('data-testid="connection-route-approve-warning"', false)
        ->assertDontSee('data-testid="connection-route-approve-blocked"', false);

    $this->actingAs($user)
        ->post(route('intakes.workspace.connections.decide', [$intake, $connection]), [
            'decision' => 'approve',
        ])
        ->assertRedirect();

    expect($connection->fresh()->status)->toBe(AircoConnectionStatus::Approved);
});

test('installateur kan voorgestelde koelroute direct goedkeuren of als locatie markeren', function () {
    $user = User::factory()->create();
    ['intake' => $intake, 'connection' => $connection, 'condensate' => $condensate] = createRouteDecisionWorkspace($user);

    $this->actingAs($user)
        ->from(route('intakes.workspace', $intake))
        ->post(route('intakes.workspace.connections.decide', [$intake, $connection]), [
            'decision' => 'approve',
        ])
        ->assertRedirect();

    expect($connection->fresh()->status)->toBe(AircoConnectionStatus::Approved)
        ->and($connection->fresh()->approved_by)->toBe($user->id);

    $this->actingAs($user)
        ->from(route('intakes.workspace', $intake))
        ->post(route('intakes.workspace.connections.decide', [$intake, $condensate]), [
            'decision' => 'site_visit',
        ])
        ->assertRedirect();

    expect($condensate->fresh()->status)->toBe(AircoConnectionStatus::NotRemotelyResolvable)
        ->and($condensate->fresh()->approved_by)->toBeNull();
});

test('collecting sessie blokkeert directe goedkeuring tot samenvatting', function () {
    $user = User::factory()->create();
    ['intake' => $intake, 'connection' => $connection] = createRouteDecisionWorkspace($user);

    $intake->pipeRouteSessions()->create([
        'airco_connection_id' => $connection->id,
        'status' => PipeRouteStatus::Collecting,
    ]);

    $this->actingAs($user)
        ->from(route('intakes.workspace', $intake))
        ->post(route('intakes.workspace.connections.decide', [$intake, $connection]), [
            'decision' => 'approve',
        ])
        ->assertSessionHasErrors('decision');

    expect($connection->fresh()->status)->toBe(AircoConnectionStatus::Proposed);
});

test('na goedkeuren zonder sessie blijft samenvatten bereikbaar via eigen foto en afronden', function () {
    $user = User::factory()->create();
    [
        'intake' => $intake,
        'connection' => $connection,
        'condensate' => $condensate,
        'power' => $power,
    ] = createRouteDecisionWorkspace($user);
    Storage::fake((string) config('filesystems.media', 'local'));
    config([
        'ai.photo_inference.enabled' => false,
        'ai.route.enabled' => false,
    ]);

    $this->actingAs($user)
        ->post(route('intakes.workspace.connections.decide', [$intake, $connection]), [
            'decision' => 'approve',
        ])
        ->assertRedirect();

    expect($connection->fresh()->status)->toBe(AircoConnectionStatus::Approved);

    $this->actingAs($user)
        ->post(route('intakes.workspace.photos.store', [$intake, $connection->subject]), [
            'photo' => UploadedFile::fake()->image('segment.jpg', 1200, 900),
            'route_segment_label' => 'Andere kant van de wand',
        ])
        ->assertRedirect();

    $session = $connection->fresh()->routeSession;
    expect($session)->not->toBeNull()
        ->and($session->status)->toBe(PipeRouteStatus::Collecting);

    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('Eigen foto toevoegen')
        ->assertSee('Route samenvatten');

    $session->update([
        'status' => PipeRouteStatus::Proposed,
        'proposed_route' => ['Via de gevel naar buiten'],
        'uncertainties' => [],
    ]);
    $connection->fresh()->update([
        'status' => AircoConnectionStatus::Proposed,
        'segments' => ['Via de gevel naar buiten'],
        'uncertainties' => [],
    ]);

    foreach ([$connection->fresh(), $condensate, $power] as $toApprove) {
        $this->actingAs($user)
            ->post(route('intakes.workspace.connections.decide', [$intake, $toApprove]), [
                'decision' => 'approve',
            ])
            ->assertRedirect();
    }

    app(CompleteInstallerSurvey::class)->handle($intake->fresh(), $user);

    expect($intake->fresh()->status->value)->toBe('completed');
});

test('na goedkeuren met nieuwe foto wijzen stappen naar samenvatten in plaats van alles done', function () {
    $user = User::factory()->create();
    ['intake' => $intake, 'connection' => $connection] = createRouteDecisionWorkspace($user);

    $this->actingAs($user)
        ->post(route('intakes.workspace.connections.decide', [$intake, $connection]), [
            'decision' => 'approve',
        ])
        ->assertRedirect();

    expect($connection->fresh()->status)->toBe(AircoConnectionStatus::Approved);

    $session = $intake->pipeRouteSessions()->create([
        'airco_connection_id' => $connection->id,
        'status' => PipeRouteStatus::Collecting,
    ]);
    $session->segments()->create([
        'intake_upload_id' => null,
        'sequence' => 1,
        'label' => 'Nieuwe segmentfoto',
    ]);

    $connection = $connection->fresh(['routeSession.segments']);
    expect($connection->status)->toBe(AircoConnectionStatus::Approved)
        ->and($connection->routeSession?->status)->toBe(PipeRouteStatus::Collecting);

    $decision = app(ConnectionRouteDecisionPresenter::class)->present($connection);
    $currentSteps = array_values(array_filter(
        $decision['steps'],
        static fn (array $step): bool => $step['state'] === 'current',
    ));
    $doneCount = count(array_filter(
        $decision['steps'],
        static fn (array $step): bool => $step['state'] === 'done',
    ));

    expect($decision['show_summarize'])->toBeTrue()
        ->and($currentSteps)->toHaveCount(1)
        ->and($currentSteps[0]['label'])->toBe('Vat de route samen')
        ->and($doneCount)->toBe(2)
        ->and($doneCount)->not->toBe(4);

    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('Vat de route samen')
        ->assertSee('Route samenvatten');
});

test('site_visit tijdens collecting houdt samenvatten zichtbaar', function () {
    $user = User::factory()->create();
    ['intake' => $intake, 'connection' => $connection] = createRouteDecisionWorkspace($user);

    $session = app(StartPipeRouteSession::class)->handle($intake, $connection);
    expect($session->status)->toBe(PipeRouteStatus::Collecting);

    $this->actingAs($user)
        ->post(route('intakes.workspace.connections.decide', [$intake, $connection]), [
            'decision' => 'site_visit',
        ])
        ->assertRedirect();

    expect($connection->fresh()->status)->toBe(AircoConnectionStatus::NotRemotelyResolvable)
        ->and($connection->fresh()->routeSession?->status)->toBe(PipeRouteStatus::Collecting);

    $decision = app(ConnectionRouteDecisionPresenter::class)->present($connection->fresh(['routeSession.segments']));
    expect($decision['show_summarize'])->toBeTrue();

    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('Route samenvatten');
});

test('verbinding van een andere opname geeft 404', function () {
    $user = User::factory()->create();
    ['intake' => $intake] = createRouteDecisionWorkspace($user);
    ['connection' => $otherConnection] = createRouteDecisionWorkspace($user);

    $this->actingAs($user)
        ->post(route('intakes.workspace.connections.decide', [$intake, $otherConnection]), [
            'decision' => 'approve',
        ])
        ->assertNotFound();
});

test('decide op geselecteerde keuze vernieuwt het opgeslagen rapport', function () {
    $user = User::factory()->create();
    ['intake' => $intake, 'connection' => $connection, 'condensate' => $condensate] = createRouteDecisionWorkspace($user);

    $refresh = Mockery::mock(RefreshStoredIntakeReport::class);
    $refresh->shouldReceive('handle')
        ->twice()
        ->with(Mockery::on(static fn (Intake $candidate): bool => $candidate->id === $intake->id))
        ->andReturnNull();
    $this->app->instance(RefreshStoredIntakeReport::class, $refresh);

    $this->actingAs($user)
        ->post(route('intakes.workspace.connections.decide', [$intake, $connection]), [
            'decision' => 'approve',
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->post(route('intakes.workspace.connections.decide', [$intake, $condensate]), [
            'decision' => 'site_visit',
        ])
        ->assertRedirect();
});

test('N26: condensate NeedsEvidence blocked reason points to overview ask', function () {
    $user = User::factory()->create();
    ['condensate' => $condensate] = createRouteDecisionWorkspace($user);

    $condensate->update([
        'status' => AircoConnectionStatus::NeedsEvidence,
    ]);

    $decision = app(ConnectionRouteDecisionPresenter::class)->present($condensate->fresh());

    expect($decision['can_approve'])->toBeFalse()
        ->and($decision['approve_blocked_reason'])->toBe(
            'Er mist nog bewijs. Voeg een foto toe, kies locatiebezoek of stel de klant één vraag voor alle condensafvoeren via het overzicht.',
        );
});

test('N26: workspace shows customer drain answer on condensate cards only when customer chose it', function () {
    $user = User::factory()->create();
    ['intake' => $intake] = createRouteDecisionWorkspace($user);

    app(SaveIntakeAnswer::class)->handle($intake, 'drain_location', null, ['value' => 'outside_nearby']);

    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake->fresh()))
        ->assertOk()
        ->assertSee('data-testid="condensate-customer-drain-note"', false)
        ->assertSee('Volgens de klant: regenpijp of putje buiten in de buurt. Nog niet gecontroleerd.', false);

    app(SaveIntakeAnswer::class)->handle(
        $intake->fresh(),
        'drain_location',
        null,
        ['value' => 'outside_nearby'],
        'ai_photo',
    );

    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake->fresh()))
        ->assertOk()
        ->assertDontSee('data-testid="condensate-customer-drain-note"', false)
        ->assertDontSee('Volgens de klant: regenpijp of putje buiten in de buurt. Nog niet gecontroleerd.', false);

    app(SaveIntakeAnswer::class)->handle($intake->fresh(), 'drain_location', null, ['value' => 'unknown']);

    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake->fresh()))
        ->assertOk()
        ->assertDontSee('Volgens de klant:', false);
});
