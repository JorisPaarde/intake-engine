<?php

declare(strict_types=1);

use App\Domains\AI\Models\AiRun;
use App\Domains\Intake\Actions\AddPipeRoutePhoto;
use App\Domains\Intake\Actions\ApprovePipeRoute;
use App\Domains\Intake\Actions\StartPipeRouteSession;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Models\PipeRouteSession;
use App\Domains\AI\Actions\SynthesizePipeRoute;
use App\Enums\AiRunType;
use App\Enums\PipeRouteStatus;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config([
        'ai.provider' => 'openai',
        'ai.api_key' => 'test-key',
        'ai.route.enabled' => true,
        'ai.route.escalate_below_confidence' => 0.7,
    ]);
});

function routeIntake(): Intake
{
    return Intake::factory()->create();
}

function routeUpload(Intake $intake): IntakeUpload
{
    Storage::disk('local')->put('route/photo-'.uniqid().'.jpg', 'fake-image-bytes');
    $path = collect(Storage::disk('local')->allFiles('route'))->last();

    return IntakeUpload::query()->create([
        'intake_id' => $intake->id,
        'question_key' => 'pipe_route_guided',
        'disk' => 'local',
        'path' => $path,
        'original_filename' => 'photo.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 16,
        'checksum' => hash('sha256', 'fake-image-bytes'),
        'sort_order' => 0,
    ]);
}

/**
 * @param  array<string, mixed>  $content
 */
function fakeOpenAi(array $content, string $model = 'gpt-5.6-terra'): void
{
    Http::fake([
        '*' => Http::response([
            'model' => $model,
            'choices' => [['message' => ['content' => json_encode($content)]]],
        ]),
    ]);
}

test('adding a photo analyses it with the terra model and stores the result on the segment', function () {
    fakeOpenAi([
        'photo_usable' => true,
        'visible_elements' => ['volledige binnenwand', 'bestaande muurdoorvoer'],
        'route_possible' => true,
        'route_segments' => ['doorvoer achter unit naar gevel'],
        'confidence' => 0.82,
        'missing_information' => [],
        'next_photo_instruction' => 'Fotografeer de buitengevel recht tegenover de binnenunit.',
    ]);

    $intake = routeIntake();
    $session = app(StartPipeRouteSession::class)->handle($intake);
    $segment = app(AddPipeRoutePhoto::class)->handle($session, routeUpload($intake), 'binnenunit-positie');

    expect($segment->photo_usable)->toBeTrue()
        ->and($segment->route_possible)->toBeTrue()
        ->and($segment->confidence)->toBe(0.82)
        ->and($segment->analysis['visible_elements'])->toContain('bestaande muurdoorvoer');

    $run = AiRun::query()->where('type', AiRunType::RouteAnalysis)->firstOrFail();
    expect($run->status->value)->toBe('succeeded');

    Http::assertSent(fn ($request) => $request['model'] === 'gpt-5.6-terra');

    $session->refresh();
    expect($session->next_photo_instruction)->toBe('Fotografeer de buitengevel recht tegenover de binnenunit.');
});

test('a disabled route flow stores the photo but never calls the AI', function () {
    config(['ai.route.enabled' => false]);
    Http::fake();

    $intake = routeIntake();
    $session = app(StartPipeRouteSession::class)->handle($intake);
    $segment = app(AddPipeRoutePhoto::class)->handle($session, routeUpload($intake));

    expect($segment->analysis)->toBeNull()
        ->and(AiRun::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('synthesis escalates to the sol review model when terra is unsure', function () {
    $intake = routeIntake();
    $session = app(StartPipeRouteSession::class)->handle($intake);

    // Seed a usable segment directly so synthesis has something to work with.
    $session->segments()->create([
        'sequence' => 1,
        'label' => 'binnenunit-positie',
        'photo_usable' => true,
        'route_possible' => true,
        'confidence' => 0.6,
        'analysis' => [
            'photo_usable' => true,
            'visible_elements' => ['binnenwand'],
            'route_possible' => true,
            'route_segments' => ['langs plafond'],
            'confidence' => 0.6,
            'missing_information' => [],
            'next_photo_instruction' => '',
        ],
    ]);

    Http::fake(function ($request) {
        $unsure = [
            'route_continuous' => false,
            'proposed_route' => ['binnenunit', 'doorvoer'],
            'alternative_route' => [],
            'uncertainties' => ['aansluiting op gevel onduidelijk'],
            'missing_checks' => ['foto van buitengevel'],
            'confidence' => 0.5,
            'next_photo_instruction' => 'Fotografeer de buitengevel.',
        ];
        $confident = [
            'route_continuous' => true,
            'proposed_route' => ['binnenunit', 'doorvoer', 'langs gevel naar buitenunit'],
            'alternative_route' => ['via kruipruimte'],
            'uncertainties' => [],
            'missing_checks' => [],
            'confidence' => 0.9,
            'next_photo_instruction' => '',
        ];
        $content = $request['model'] === 'gpt-5.6-sol' ? $confident : $unsure;

        return Http::response([
            'model' => $request['model'],
            'choices' => [['message' => ['content' => json_encode($content)]]],
        ]);
    });

    $session = app(SynthesizePipeRoute::class)->handle($session);

    expect($session->status)->toBe(PipeRouteStatus::Proposed)
        ->and($session->confidence)->toBe(0.9)
        ->and($session->proposed_route)->toContain('langs gevel naar buitenunit');

    Http::assertSent(fn ($request) => $request['model'] === 'gpt-5.6-terra');
    Http::assertSent(fn ($request) => $request['model'] === 'gpt-5.6-sol');
});

test('synthesis stays on terra when the route is already confident', function () {
    $intake = routeIntake();
    $session = app(StartPipeRouteSession::class)->handle($intake);
    $session->segments()->create([
        'sequence' => 1,
        'label' => 'binnenunit-positie',
        'photo_usable' => true,
        'route_possible' => true,
        'confidence' => 0.9,
        'analysis' => ['visible_elements' => [], 'route_segments' => [], 'missing_information' => []],
    ]);

    fakeOpenAi([
        'route_continuous' => true,
        'proposed_route' => ['binnenunit', 'doorvoer', 'buitenunit'],
        'alternative_route' => [],
        'uncertainties' => [],
        'missing_checks' => [],
        'confidence' => 0.88,
        'next_photo_instruction' => '',
    ], 'gpt-5.6-terra');

    app(SynthesizePipeRoute::class)->handle($session);

    Http::assertSent(fn ($request) => $request['model'] === 'gpt-5.6-terra');
    Http::assertNotSent(fn ($request) => $request['model'] === 'gpt-5.6-sol');
});

test('the installer approval is an explicit human step recorded on the session', function () {
    $intake = routeIntake();
    $installer = User::factory()->create();
    $session = $intake->pipeRouteSessions()->create(['status' => PipeRouteStatus::Proposed]);

    $session = app(ApprovePipeRoute::class)->handle($session, $installer, true);

    expect($session->status)->toBe(PipeRouteStatus::Approved)
        ->and($session->approved_by)->toBe($installer->id)
        ->and($session->approved_at)->not->toBeNull();

    $this->assertDatabaseHas('intake_activity_events', [
        'intake_id' => $intake->id,
        'event' => 'pipe_route_reviewed',
    ]);
});
