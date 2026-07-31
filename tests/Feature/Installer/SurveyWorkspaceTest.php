<?php

declare(strict_types=1);

use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\Intake\Actions\ApprovePipeRoute;
use App\Domains\Intake\Actions\CompleteFollowUpRound;
use App\Domains\Intake\Actions\CompleteInstallerSurvey;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\StartPipeRouteSession;
use App\Domains\Intake\Mail\CustomerIntakeLinkMail;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\DossierEvidenceLink;
use App\Domains\Intake\Models\DossierRecord;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\DecisionReadinessService;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoConnectionType;
use App\Enums\AircoOptionStatus;
use App\Enums\AircoPlacementType;
use App\Enums\ContributionMode;
use App\Enums\ContributionTaskStatus;
use App\Enums\DecisionAreaStatus;
use App\Enums\DossierRecordStatus;
use App\Enums\FollowUpItemType;
use App\Enums\IntakeStatus;
use App\Enums\PipeRouteStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Mail::fake();
    config(['ai.dossier.enabled' => false]);
});

afterEach(function () {
    FakeAiClient::reset();
});

function createInstallerSurveyForWorkspace(User $user, string $email = 'zelf@example.com'): Intake
{
    return app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Zelf Opnemen',
        'customer_email' => $email,
        'address_line' => 'Testlaan 10',
        'address_postal_code' => '1000AA',
        'address_house_number' => 10,
        'address_city' => 'Amsterdam',
    ]);
}

test('installer can start a self-performed survey without exposing or mailing a customer link', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('intakes.store'), [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer->value,
        'customer_name' => 'Zelf Opnemen',
        'customer_email' => 'zelf@example.com',
        'address_line' => 'Testlaan 10',
        'address_postal_code' => '1000AA',
        'address_house_number' => 10,
        'address_city' => 'Amsterdam',
    ]);

    $intake = Intake::query()->where('customer_email', 'zelf@example.com')->firstOrFail();

    $response->assertRedirect(route('intakes.workspace', $intake));
    expect($intake->status)->toBe(IntakeStatus::Draft)
        ->and($intake->workflow_mode)->toBe(ContributionMode::Installer)
        ->and($intake->customer_access_enabled)->toBeFalse()
        ->and($intake->dossierSubjects()->where('key', 'survey')->exists())->toBeTrue();
    Mail::assertNotSent(CustomerIntakeLinkMail::class);

    $this->get(route('customer.intake.show', $intake->access_token))->assertNotFound();
    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('Technische opname')
        ->assertSee('Gerichte klanttaak')
        ->assertSee('Woninggegevens');
});

test('legacy customer link actions cannot expose an installer-only survey', function () {
    $user = User::factory()->create();
    $intake = createInstallerSurveyForWorkspace($user, 'geen-link@example.com');
    $inactiveToken = $intake->access_token;

    $this->actingAs($user)
        ->post(route('intakes.send-link', $intake))
        ->assertRedirect(route('intakes.show', $intake))
        ->assertSessionHas('status', 'Geen klantlink actief. Stuur vanuit de technische opname eerst een gerichte klantopdracht.');

    $this->actingAs($user)
        ->post(route('intakes.regenerate-token', $intake))
        ->assertRedirect(route('intakes.show', $intake))
        ->assertSessionHas('status', 'Geen klantlink actief. Stuur vanuit de technische opname eerst een gerichte klantopdracht.');

    expect($intake->fresh()->access_token)->toBe($inactiveToken)
        ->and($intake->fresh()->customer_access_enabled)->toBeFalse();
    Mail::assertNotSent(CustomerIntakeLinkMail::class);
});

test('workspace attaches photos and notes to the relevant object without exposing dossier internals', function () {
    $user = User::factory()->create();
    $intake = createInstallerSurveyForWorkspace($user, 'context@example.com');
    $room = app(AircoSurveyService::class)->createRoom($intake, $user, [
        'name' => 'Slaapkamer',
        'use_type' => 'bedroom',
    ]);

    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('Woninggegevens')
        ->assertSee('Automatisch opgehaald voor deze opname. Hier staan alleen gegevens die kunnen helpen bij de installatie.')
        ->assertSee('Foto maken')
        ->assertSee('Technische notitie')
        ->assertDontSee('Camera en bewijs')
        ->assertDontSee('Vakwaarneming')
        ->assertDontSee('Telefonisch vastgesteld')
        ->assertDontSee('name="key"', false)
        ->assertDontSee('name="method"', false)
        ->assertDontSee('name="dossier_subject_id"', false);

    $this->actingAs($user)
        ->post(route('intakes.workspace.notes.store', [$intake, $room->subject]), [
            'text' => 'Massieve buitenmuur, vanaf de grond bereikbaar.',
            'key' => 'door_gebruiker_bepaald',
            'method' => 'phone',
        ])
        ->assertRedirect(route('intakes.workspace', $intake))
        ->assertSessionHas('status', 'Technische notitie toegevoegd.');

    $note = DossierRecord::query()
        ->where('dossier_subject_id', $room->dossier_subject_id)
        ->where('source_type', 'installer')
        ->sole();

    expect($note->key)->toStartWith('installer_note.')
        ->and($note->key)->not->toBe('door_gebruiker_bepaald')
        ->and($note->method)->toBe('installer_note')
        ->and($note->confidence)->toBe(1.0)
        ->and($note->status)->toBe(DossierRecordStatus::Established)
        ->and($note->value['text'])->toBe('Massieve buitenmuur, vanaf de grond bereikbaar.');
});

test('workspace refuses a subject from another intake for contextual notes', function () {
    $user = User::factory()->create();
    $intake = createInstallerSurveyForWorkspace($user, 'eerste-context@example.com');
    $other = createInstallerSurveyForWorkspace($user, 'tweede-context@example.com');
    $otherRoom = app(AircoSurveyService::class)->createRoom($other, $user, [
        'name' => 'Andere slaapkamer',
        'use_type' => 'bedroom',
    ]);

    $this->actingAs($user)
        ->post(route('intakes.workspace.notes.store', [$intake, $otherRoom->subject]), [
            'text' => 'Mag niet worden opgeslagen.',
        ])
        ->assertNotFound();

    expect(DossierRecord::query()
        ->where('intake_id', $intake->id)
        ->where('source_type', 'installer')
        ->exists())->toBeFalse();
});

test('workspace stores a room photo in context and exposes AI output only as a proposal', function () {
    $user = User::factory()->create();
    $intake = createInstallerSurveyForWorkspace($user, 'contextfoto@example.com');
    $room = app(AircoSurveyService::class)->createRoom($intake, $user, [
        'name' => 'Slaapkamer Joris',
        'use_type' => 'bedroom',
    ]);
    Storage::fake((string) config('filesystems.media', 'local'));
    config([
        'ai.provider' => 'fake',
        'ai.photo_inference.enabled' => true,
        'ai.photo_inference.observation_min_confidence' => 0.65,
    ]);
    FakeAiClient::alwaysReturn([
        'observations' => [[
            'text' => 'Gemetselde buitenmuur is vanaf de vloer bereikbaar.',
            'impact' => 'installation',
            'confidence' => 0.9,
        ]],
    ]);

    $this->actingAs($user)
        ->post(route('intakes.workspace.photos.store', [$intake, $room->subject]), [
            'photo' => UploadedFile::fake()->image('wand.jpg', 1200, 900),
            'dossier_subject_id' => $intake->dossierSubjects()->where('key', 'survey')->value('id'),
            'method' => 'phone',
        ])
        ->assertRedirect(route('intakes.workspace', $intake))
        ->assertSessionHas('status', 'Foto opgeslagen. Controleer de voorgestelde technische constatering.');

    $upload = $intake->uploads()->sole();
    $proposal = DossierRecord::query()
        ->where('dossier_subject_id', $room->dossier_subject_id)
        ->where('source_type', 'ai')
        ->sole();
    $request = FakeAiClient::lastRequest();

    expect($proposal->status)->toBe(DossierRecordStatus::Proposed)
        ->and($proposal->method)->toBe('photo_inference')
        ->and(DossierEvidenceLink::query()
            ->where('dossier_subject_id', $room->dossier_subject_id)
            ->where('dossier_record_id', $proposal->id)
            ->where('evidence_type', 'intake_upload')
            ->where('evidence_id', $upload->id)
            ->exists())->toBeTrue()
        ->and($request?->images)->toHaveCount(1)
        ->and(array_key_exists('label', $request?->input['subject'] ?? []))->toBeFalse()
        ->and(json_encode($request?->input, JSON_THROW_ON_ERROR))->not->toContain('Slaapkamer Joris')
        ->and(DossierRecord::query()
            ->where('dossier_subject_id', $intake->dossierSubjects()->where('key', 'survey')->value('id'))
            ->where('source_type', 'ai')
            ->exists())->toBeFalse();
});

test('workspace derives a route photo connection from its contextual subject', function () {
    $user = User::factory()->create();
    $intake = createInstallerSurveyForWorkspace($user, 'routefoto@example.com');
    $survey = app(AircoSurveyService::class);
    $room = $survey->createRoom($intake, $user, [
        'name' => 'Slaapkamer',
        'use_type' => 'bedroom',
    ]);
    $inside = $survey->createPlacement($intake, $user, [
        'airco_room_id' => $room->id,
        'type' => AircoPlacementType::IndoorUnit,
        'label' => 'Binnenpositie',
    ]);
    $outside = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::OutdoorUnit,
        'label' => 'Buitenpositie',
    ]);
    $option = $survey->createInstallationOption($intake, $user, [
        'label' => 'Optie A',
        'configuration_type' => AircoConfigurationType::SingleSplit,
        'placement_ids' => [$inside->id, $outside->id],
    ]);
    $connection = $survey->createConnection($intake, $user, $option, [
        'type' => AircoConnectionType::Refrigerant,
        'label' => 'Koelleiding',
        'from_placement_id' => $inside->id,
        'to_placement_id' => $outside->id,
        'status' => AircoConnectionStatus::NeedsEvidence,
    ]);
    Storage::fake((string) config('filesystems.media', 'local'));
    config([
        'ai.photo_inference.enabled' => false,
        'ai.route.enabled' => false,
    ]);

    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertDontSee('name="airco_connection_id"', false);

    $this->actingAs($user)
        ->post(route('intakes.workspace.photos.store', [$intake, $connection->subject]), [
            'photo' => UploadedFile::fake()->image('doorvoer.jpg', 1200, 900),
            'route_segment_label' => 'Andere kant van de wand',
            'airco_connection_id' => 999999,
        ])
        ->assertRedirect(route('intakes.workspace', $intake))
        ->assertSessionHas('status', 'Foto opgeslagen als routesegment.');

    $session = $intake->pipeRouteSessions()
        ->where('airco_connection_id', $connection->id)
        ->sole();
    $segment = $session->segments()->sole();

    expect($segment->label)->toBe('Andere kant van de wand')
        ->and($segment->upload?->section_instance_key)->toBe('subject-'.$connection->dossier_subject_id)
        ->and($intake->pipeRouteSessions()->count())->toBe(1);
});

test('installer-only survey can temporarily expose exactly one targeted customer task and return to installer work', function () {
    $user = User::factory()->create();
    $intake = createInstallerSurveyForWorkspace($user, 'hybride@example.com');
    $root = $intake->dossierSubjects()->where('key', 'survey')->firstOrFail();
    $inactiveToken = $intake->access_token;

    $round = app(CreateCustomerContributionRequest::class)->handle($intake, $user, [[
        'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een leesbare foto van de volledige meterkast met het kastdeurtje open.',
        'decision_area_key' => 'power',
        'dossier_subject_id' => $root->id,
    ]]);

    $intake->refresh();
    expect($intake->status)->toBe(IntakeStatus::AwaitingCustomer)
        ->and($intake->workflow_mode)->toBe(ContributionMode::Hybrid)
        ->and($intake->customer_access_enabled)->toBeTrue()
        ->and($intake->access_token)->not->toBe($inactiveToken)
        ->and($round->return_status)->toBe(IntakeStatus::InProgress);

    $this->get(route('customer.intake.show', $inactiveToken))->assertNotFound();
    $this->get(route('customer.intake.show', $intake->access_token))
        ->assertOk()
        ->assertSee('Maak een leesbare foto van de volledige meterkast')
        ->assertDontSee('Wat is de reden van uw aanvraag?');

    $item = $round->items()->firstOrFail();
    $item->update([
        'response_text' => null,
        'answered_at' => now(),
    ]);
    $item->uploads()->create([
        'intake_id' => $intake->id,
        'question_key' => 'follow_up_'.$item->id,
        'disk' => 'local',
        'path' => 'test/meterkast.jpg',
        'original_filename' => 'meterkast.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 100,
        'checksum' => hash('sha256', 'meterkast'),
        'sort_order' => 1,
    ]);

    app(CompleteFollowUpRound::class)->handle($intake, $round, []);

    $task = ContributionTask::query()
        ->where('intake_follow_up_item_id', $item->id)
        ->firstOrFail();
    expect($intake->fresh()->status)->toBe(IntakeStatus::InProgress)
        ->and($intake->fresh()->customer_access_enabled)->toBeFalse()
        ->and($task->status)->toBe(ContributionTaskStatus::Completed)
        ->and($task->dossier_subject_id)->toBe($root->id)
        ->and(DossierRecord::query()
            ->where('source_type', 'intake_follow_up_item')
            ->where('source_id', $item->id)
            ->exists())->toBeTrue();

    $this->get(route('customer.intake.show', $intake->access_token))->assertNotFound();
});

test('two bedroom survey compares a multi-split option and approves all three connection types integrally', function () {
    $user = User::factory()->create();
    $intake = createInstallerSurveyForWorkspace($user, 'twee-kamers@example.com');
    $survey = app(AircoSurveyService::class);

    $parents = $survey->createRoom($intake, $user, [
        'name' => 'Slaapkamer ouders',
        'use_type' => 'bedroom',
        'length_m' => 4.2,
        'width_m' => 3.5,
        'height_m' => 2.6,
    ]);
    $children = $survey->createRoom($intake, $user, [
        'name' => 'Slaapkamer kinderen',
        'use_type' => 'bedroom',
        'length_m' => 3.8,
        'width_m' => 3.1,
        'height_m' => 2.6,
    ]);
    $insideParents = $survey->createPlacement($intake, $user, [
        'airco_room_id' => $parents->id,
        'type' => AircoPlacementType::IndoorUnit,
        'label' => 'Boven de slaapkamerdeur',
    ]);
    $insideChildren = $survey->createPlacement($intake, $user, [
        'airco_room_id' => $children->id,
        'type' => AircoPlacementType::IndoorUnit,
        'label' => 'Vrije wand naast het raam',
    ]);
    $outside = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::OutdoorUnit,
        'label' => 'Platte dak van de aanbouw',
    ]);
    $power = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::PowerSource,
        'label' => 'Nieuwe groep in meterkast',
    ]);
    $drain = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::DrainPoint,
        'label' => 'Regenwaterafvoer achtergevel',
    ]);
    $option = $survey->createInstallationOption($intake, $user, [
        'label' => 'Optie A · één multi-split',
        'configuration_type' => AircoConfigurationType::MultiSplit,
        'summary' => 'Eén buitenunit bedient beide slaapkamers.',
        'cost_impact' => 'medium',
        'placement_ids' => [
            $insideParents->id,
            $insideChildren->id,
            $outside->id,
            $power->id,
            $drain->id,
        ],
    ]);

    foreach ([
        [AircoConnectionType::Refrigerant, 'Koelleiding ouders', $insideParents->id, $outside->id],
        [AircoConnectionType::Refrigerant, 'Koelleiding kinderen', $insideChildren->id, $outside->id],
        [AircoConnectionType::Condensate, 'Condens ouders', $insideParents->id, $drain->id],
        [AircoConnectionType::Condensate, 'Condens kinderen', $insideChildren->id, $drain->id],
        [AircoConnectionType::Power, 'Stroom naar buitenunit', $power->id, $outside->id],
    ] as [$type, $label, $from, $to]) {
        $survey->createConnection($intake, $user, $option, [
            'type' => $type,
            'label' => $label,
            'from_placement_id' => $from,
            'to_placement_id' => $to,
            'status' => AircoConnectionStatus::Proposed,
            'length_class' => 'medium',
            'segments' => ['Zichtbare en aannemelijke route'],
            'cost_impact' => 'medium',
            'confidence' => 0.9,
        ]);
    }
    $survey->selectInstallationOption($intake, $user, $option);

    $quoteBefore = app(DecisionReadinessService::class)
        ->recalculate($intake->fresh())
        ->firstWhere('key', 'quote');
    expect($quoteBefore->status)->toBe(DecisionAreaStatus::Review);

    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('Geselecteerd voorstel integraal goedkeuren');

    app(CompleteInstallerSurvey::class)->handle($intake->fresh(), $user);

    expect($option->fresh()->status)->toBe(AircoOptionStatus::Selected)
        ->and($option->connections()->where('status', AircoConnectionStatus::Approved)->count())->toBe(5)
        ->and($option->connections()->where('type', AircoConnectionType::Refrigerant)->count())->toBe(2)
        ->and($option->connections()->where('type', AircoConnectionType::Condensate)->count())->toBe(2)
        ->and($option->connections()->where('type', AircoConnectionType::Power)->count())->toBe(1);

    $quoteAfter = app(DecisionReadinessService::class)
        ->recalculate($intake->fresh())
        ->firstWhere('key', 'quote');
    expect($quoteAfter->status)->toBe(DecisionAreaStatus::Ready);

    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('Integraal goedgekeurd')
        ->assertDontSee('Geselecteerd voorstel integraal goedkeuren')
        ->assertDontSee('Als onderdeel van voorstel goedkeuren');
});

test('a connection cannot use a placement outside its installation option', function () {
    $user = User::factory()->create();
    $intake = createInstallerSurveyForWorkspace($user, 'route-integriteit@example.com');
    $survey = app(AircoSurveyService::class);
    $room = $survey->createRoom($intake, $user, [
        'name' => 'Slaapkamer',
        'use_type' => 'bedroom',
    ]);
    $inside = $survey->createPlacement($intake, $user, [
        'airco_room_id' => $room->id,
        'type' => AircoPlacementType::IndoorUnit,
        'label' => 'Binnenpositie',
    ]);
    $outside = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::OutdoorUnit,
        'label' => 'Buitenpositie A',
    ]);
    $unrelatedOutside = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::OutdoorUnit,
        'label' => 'Buitenpositie B',
    ]);
    $option = $survey->createInstallationOption($intake, $user, [
        'label' => 'Optie A',
        'configuration_type' => AircoConfigurationType::SingleSplit,
        'placement_ids' => [$inside->id, $outside->id],
    ]);

    expect(fn () => $survey->createConnection($intake, $user, $option, [
        'type' => AircoConnectionType::Refrigerant,
        'label' => 'Ongeldige route',
        'from_placement_id' => $inside->id,
        'to_placement_id' => $unrelatedOutside->id,
        'status' => AircoConnectionStatus::Proposed,
    ]))->toThrow(ValidationException::class);
});

test('new route evidence safely reopens an approved connection without creating a duplicate session', function () {
    $user = User::factory()->create();
    $intake = createInstallerSurveyForWorkspace($user, 'route-heropenen@example.com');
    $survey = app(AircoSurveyService::class);
    $room = $survey->createRoom($intake, $user, [
        'name' => 'Slaapkamer',
        'use_type' => 'bedroom',
    ]);
    $inside = $survey->createPlacement($intake, $user, [
        'airco_room_id' => $room->id,
        'type' => AircoPlacementType::IndoorUnit,
        'label' => 'Binnenpositie',
    ]);
    $outside = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::OutdoorUnit,
        'label' => 'Buitenpositie',
    ]);
    $option = $survey->createInstallationOption($intake, $user, [
        'label' => 'Optie A',
        'configuration_type' => AircoConfigurationType::SingleSplit,
        'placement_ids' => [$inside->id, $outside->id],
    ]);
    $connection = $survey->createConnection($intake, $user, $option, [
        'type' => AircoConnectionType::Refrigerant,
        'label' => 'Koelleiding',
        'from_placement_id' => $inside->id,
        'to_placement_id' => $outside->id,
        'status' => AircoConnectionStatus::Approved,
    ]);
    $session = $intake->pipeRouteSessions()->create([
        'airco_connection_id' => $connection->id,
        'status' => PipeRouteStatus::Approved,
        'approved_by' => $user->id,
        'approved_at' => now(),
    ]);

    $reopened = app(StartPipeRouteSession::class)->handle($intake->fresh(), $connection);

    expect($reopened->id)->toBe($session->id)
        ->and($reopened->status)->toBe(PipeRouteStatus::Collecting)
        ->and($reopened->approved_by)->toBeNull()
        ->and($connection->fresh()->status)->toBe(AircoConnectionStatus::NeedsEvidence)
        ->and($connection->fresh()->approved_by)->toBeNull()
        ->and($intake->pipeRouteSessions()->where('airco_connection_id', $connection->id)->count())->toBe(1);
});

test('rejecting a proposed route returns its connection to evidence needed', function () {
    $user = User::factory()->create();
    $intake = createInstallerSurveyForWorkspace($user, 'route-afwijzen@example.com');
    $survey = app(AircoSurveyService::class);
    $room = $survey->createRoom($intake, $user, [
        'name' => 'Slaapkamer',
        'use_type' => 'bedroom',
    ]);
    $inside = $survey->createPlacement($intake, $user, [
        'airco_room_id' => $room->id,
        'type' => AircoPlacementType::IndoorUnit,
        'label' => 'Binnenpositie',
    ]);
    $outside = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::OutdoorUnit,
        'label' => 'Buitenpositie',
    ]);
    $option = $survey->createInstallationOption($intake, $user, [
        'label' => 'Optie A',
        'configuration_type' => AircoConfigurationType::SingleSplit,
        'placement_ids' => [$inside->id, $outside->id],
    ]);
    $connection = $survey->createConnection($intake, $user, $option, [
        'type' => AircoConnectionType::Refrigerant,
        'label' => 'Koelleiding',
        'from_placement_id' => $inside->id,
        'to_placement_id' => $outside->id,
        'status' => AircoConnectionStatus::Proposed,
    ]);
    $session = $intake->pipeRouteSessions()->create([
        'airco_connection_id' => $connection->id,
        'status' => PipeRouteStatus::Proposed,
        'proposed_route' => ['Via de buitenmuur'],
        'confidence' => 0.7,
    ]);

    app(ApprovePipeRoute::class)->handle($session, $user, false);

    expect($session->fresh()->status)->toBe(PipeRouteStatus::Rejected)
        ->and($connection->fresh()->status)->toBe(AircoConnectionStatus::NeedsEvidence)
        ->and($connection->fresh()->approved_by)->toBeNull()
        ->and($connection->fresh()->uncertainties)->toContain(
            'De voorgestelde route is door de installateur afgewezen.',
        );
});

test('installer cannot complete a survey while decisive technical evidence is missing', function () {
    $user = User::factory()->create();
    $intake = createInstallerSurveyForWorkspace($user, 'onvolledig@example.com');
    app(AircoSurveyService::class)->createRoom($intake, $user, [
        'name' => 'Slaapkamer',
        'use_type' => 'bedroom',
    ]);

    expect(fn () => app(CompleteInstallerSurvey::class)->handle($intake->fresh(), $user))
        ->toThrow(ValidationException::class);
    expect($intake->fresh()->status)->not->toBe(IntakeStatus::Completed);
});

test('another company cannot open the technical workspace', function () {
    $owner = User::factory()->create();
    $otherCompanyUser = User::factory()->create();
    $intake = createInstallerSurveyForWorkspace($owner, 'tenantgrens@example.com');

    $this->actingAs($otherCompanyUser)
        ->get(route('intakes.workspace', $intake))
        ->assertForbidden();
});
