<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\ApprovePipeRoute;
use App\Domains\Intake\Actions\CompleteFollowUpRound;
use App\Domains\Intake\Actions\CompleteInstallerSurvey;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\StartPipeRouteSession;
use App\Domains\Intake\Mail\CustomerIntakeLinkMail;
use App\Domains\Intake\Models\ContributionTask;
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
use App\Enums\FollowUpItemType;
use App\Enums\IntakeStatus;
use App\Enums\PipeRouteStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Mail::fake();
    config(['ai.dossier.enabled' => false]);
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
        ->assertSee('Automatisch voor u gevonden');
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
