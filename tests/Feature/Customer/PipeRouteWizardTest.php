<?php

declare(strict_types=1);

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\PipeRouteSession;
use App\Enums\PipeRouteStatus;
use App\Livewire\Customer\PipeRouteWizard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake((string) config('filesystems.media', 'local'));
    config([
        'ai.provider' => 'openai',
        'ai.api_key' => 'test-key',
        'ai.route.enabled' => true,
    ]);
});

function wizardIntake(): Intake
{
    return Intake::factory()->create();
}

test('the guided route page renders for a valid customer token', function () {
    $intake = wizardIntake();

    $this->get(route('customer.pipe-route', $intake->access_token))
        ->assertOk()
        ->assertSee('Leidingroute vastleggen');
});

test('a customer photo becomes an analysed route segment', function () {
    Http::fake([
        '*' => Http::response([
            'model' => 'gpt-5.6-terra',
            'choices' => [['message' => ['content' => json_encode([
                'photo_usable' => true,
                'visible_elements' => ['binnenwand'],
                'route_possible' => true,
                'route_segments' => ['langs plafond'],
                'confidence' => 0.8,
                'missing_information' => [],
                'next_photo_instruction' => 'Fotografeer de buitengevel.',
            ])]]],
        ]),
    ]);

    $intake = wizardIntake();

    Livewire::test(PipeRouteWizard::class, ['token' => $intake->access_token])
        ->set('label', 'binnenunit-positie')
        ->set('photo', UploadedFile::fake()->image('wall.jpg', 800, 600))
        ->call('addPhoto')
        ->assertHasNoErrors()
        ->assertSee('Fotografeer de buitengevel.');

    $session = PipeRouteSession::query()->where('intake_id', $intake->id)->firstOrFail();
    expect($session->segments)->toHaveCount(1)
        ->and($session->segments->first()->photo_usable)->toBeTrue()
        ->and($session->segments->first()->label)->toBe('binnenunit-positie');
});

test('the customer can summarise the route once photos exist', function () {
    $intake = wizardIntake();
    $session = $intake->pipeRouteSessions()->create(['status' => PipeRouteStatus::Collecting]);
    $session->segments()->create([
        'sequence' => 1,
        'label' => 'binnenunit-positie',
        'photo_usable' => true,
        'route_possible' => true,
        'confidence' => 0.8,
        'analysis' => ['visible_elements' => [], 'route_segments' => [], 'missing_information' => []],
    ]);

    Http::fake([
        '*' => Http::response([
            'model' => 'gpt-5.6-terra',
            'choices' => [['message' => ['content' => json_encode([
                'route_continuous' => true,
                'proposed_route' => ['binnenunit', 'doorvoer', 'buitenunit'],
                'alternative_route' => [],
                'uncertainties' => [],
                'missing_checks' => [],
                'confidence' => 0.85,
                'next_photo_instruction' => '',
            ])]]],
        ]),
    ]);

    Livewire::test(PipeRouteWizard::class, ['token' => $intake->access_token])
        ->call('synthesize')
        ->assertSee('Voorgestelde route')
        ->assertSee('doorvoer');

    expect($session->refresh()->status)->toBe(PipeRouteStatus::Proposed);
});
