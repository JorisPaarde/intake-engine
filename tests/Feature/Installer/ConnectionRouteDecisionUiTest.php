<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoConnectionType;
use App\Enums\AircoPlacementType;
use App\Enums\ContributionMode;
use App\Enums\PipeRouteStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    $this->withoutVite();
});

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
    $option = $survey->createInstallationOption($intake, $user, [
        'label' => 'Single-split haalbaar',
        'configuration_type' => AircoConfigurationType::SingleSplit,
        'placement_ids' => [$inside->id, $outside->id],
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
        'to_placement_id' => $outside->id,
        'status' => AircoConnectionStatus::Proposed,
        'length_class' => 'short',
        'segments' => ['Zelfde route als koelleiding'],
        'cost_impact' => 'low',
        'confidence' => 0.8,
    ]);
    $survey->markInstallationOptionFeasible($intake, $user, $option);
    $survey->selectInstallationOption($intake, $user, $option);

    return compact('intake', 'connection', 'condensate', 'option');
}

test('proposed koelroute zonder installateursfoto toont stappen en directe beslissingen', function () {
    $user = User::factory()->create();
    ['intake' => $intake, 'connection' => $connection] = createRouteDecisionWorkspace($user);

    expect($connection->routeSession)->toBeNull();

    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('Koelleiding woonkamer')
        ->assertSee('Beoordelen in stappen')
        ->assertSee('Klantfoto’s en AI-route zijn al beschikbaar')
        ->assertSee('Eigen installateursfoto is optioneel')
        ->assertSee('Route goedkeuren')
        ->assertSee('Alleen te zien op locatie')
        ->assertSee('Eigen foto toevoegen')
        ->assertSee('data-testid="connection-route-decision"', false)
        ->assertDontSee('Route samenvatten');
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
        ->assertSee('Beoordelen in stappen')
        ->assertSee('Route samenvatten')
        ->assertSee('Alleen te zien op locatie')
        ->assertSee('Vat de routesegmenten eerst samen')
        ->getContent();

    expect($html)->toContain('data-testid="connection-route-approve-blocked"')
        ->and($html)->not->toContain('action="'.route('intakes.workspace.routes.approve', [$intake, $connection->fresh()->routeSession]).'"');
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
