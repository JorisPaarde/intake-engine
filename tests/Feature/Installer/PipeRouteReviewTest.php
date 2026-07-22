<?php

declare(strict_types=1);

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\PipeRouteSession;
use App\Enums\PipeRouteStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

function reviewIntake(): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
    ]);
}

function proposedSession(Intake $intake): PipeRouteSession
{
    return $intake->pipeRouteSessions()->create([
        'status' => PipeRouteStatus::Proposed,
        'confidence' => 0.82,
        'proposed_route' => ['binnenunit aan noordwand', 'doorvoer door buitenmuur', 'langs gevel naar buitenunit'],
        'alternative_route' => ['via kruipruimte'],
        'uncertainties' => ['wanddikte bij doorvoer onbekend'],
        'missing_checks' => [],
    ]);
}

test('the installer detail page shows the guided pipe route panel', function () {
    $intake = reviewIntake();
    proposedSession($intake);

    $this->actingAs(User::query()->findOrFail($intake->created_by))
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee('Leidingroute (begeleid)')
        ->assertSee('langs gevel naar buitenunit')
        ->assertSee('Route goedkeuren');
});

test('the installer can approve the proposed route', function () {
    $intake = reviewIntake();
    $session = proposedSession($intake);
    $installer = User::query()->findOrFail($intake->created_by);

    $this->actingAs($installer)
        ->post(route('intakes.pipe-route.review', [$intake, $session]), ['decision' => 'approve'])
        ->assertRedirect();

    $session->refresh();
    expect($session->status)->toBe(PipeRouteStatus::Approved)
        ->and($session->approved_by)->toBe($installer->id);

    $this->assertDatabaseHas('intake_activity_events', [
        'intake_id' => $intake->id,
        'event' => 'pipe_route_reviewed',
    ]);
});

test('the installer can reject the proposed route', function () {
    $intake = reviewIntake();
    $session = proposedSession($intake);

    $this->actingAs(User::query()->findOrFail($intake->created_by))
        ->post(route('intakes.pipe-route.review', [$intake, $session]), ['decision' => 'reject'])
        ->assertRedirect();

    expect($session->refresh()->status)->toBe(PipeRouteStatus::Rejected);
});

test('a session from another intake cannot be reviewed through this intake', function () {
    $intake = reviewIntake();
    $other = reviewIntake();
    $session = proposedSession($other);

    $this->actingAs(User::query()->findOrFail($intake->created_by))
        ->post(route('intakes.pipe-route.review', [$intake, $session]), ['decision' => 'approve'])
        ->assertNotFound();

    expect($session->refresh()->status)->toBe(PipeRouteStatus::Proposed);
});
