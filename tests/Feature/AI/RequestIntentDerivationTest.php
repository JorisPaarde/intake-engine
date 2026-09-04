<?php

declare(strict_types=1);

use App\Domains\AI\Actions\DeriveIntentFromRequest;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\Intake\Actions\SaveInstallerObservation;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Enums\AiRunStatus;
use App\Enums\IntakeStatus;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    FakeAiClient::reset();
    config(['ai.provider' => 'fake', 'ai.text_inference.enabled' => true]);
});

afterEach(function () {
    FakeAiClient::reset();
});

function makeIntentIntake(): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_email' => 'intentie@example.com',
    ]);
}

function answerReason(Intake $intake, string $text): void
{
    app(SaveIntakeAnswer::class)->handle($intake, 'request_reason', null, ['text' => $text]);
}

/** @return list<string> */
function intentStepKeys(Intake $intake): array
{
    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();

    return collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version))->pluck('question_key')->all();
}

test('offline local fallback answers function room count type and floor', function () {
    config(['ai.text_inference.enabled' => false]);

    $intake = makeIntentIntake();
    answerReason($intake, 'Ik wil twee airco’s om m’n slaapkamers op zolder te koelen.');

    $run = app(DeriveIntentFromRequest::class)->handle($intake, allowExternal: false);

    expect($run?->status)->toBe(AiRunStatus::Succeeded)
        ->and($run?->provider)->toBe('local')
        ->and($intake->answers()->where('question_key', 'cooling_heating')->firstOrFail()->value)->toBe(['value' => 'cooling'])
        ->and($intake->answers()->where('question_key', 'cooling_heating')->firstOrFail()->prefill_source)->toBe(DeriveIntentFromRequest::SOURCE_REQUEST_TEXT)
        ->and($intake->answers()->where('question_key', 'indoor_unit_count')->firstOrFail()->value)->toBe(['number' => 2])
        ->and($intake->answers()->where('question_key', 'room_type')->where('section_instance_key', 'room-1')->firstOrFail()->value)->toBe(['value' => 'bedroom'])
        ->and($intake->answers()->where('question_key', 'room_type')->where('section_instance_key', 'room-2')->firstOrFail()->value)->toBe(['value' => 'bedroom'])
        ->and($intake->answers()->where('question_key', 'floor_level')->where('section_instance_key', 'room-1')->firstOrFail()->value)->toBe(['value' => 'attic'])
        ->and($intake->answers()->where('question_key', 'floor_level')->where('section_instance_key', 'room-2')->firstOrFail()->value)->toBe(['value' => 'attic']);

    $steps = intentStepKeys($intake);

    expect($steps)->not->toContain('cooling_heating')
        ->and($steps)->not->toContain('indoor_unit_count')
        ->and($steps)->not->toContain('room_type')
        ->and($steps)->not->toContain('floor_level')
        ->and(collect($steps)->filter(fn (string $k): bool => $k === 'room_photos'))->toHaveCount(2);
});

test('messy restated rooms are not filled by the local parser', function () {
    config(['ai.text_inference.enabled' => false]);

    $intake = makeIntentIntake();
    answerReason(
        $intake,
        'Drie slaapkamers en woonkamer koelen woonkamers is 5 bij 7 meter en de slaapkamers 20m2 elk',
    );

    $run = app(DeriveIntentFromRequest::class)->handle($intake, allowExternal: false);

    expect($run)->toBeNull()
        ->and($intake->answers()->where('question_key', 'indoor_unit_count')->exists())->toBeFalse()
        ->and($intake->answers()->where('question_key', 'room_type')->exists())->toBeFalse();
});

test('workspace names rooms per use type not global index', function () {
    config(['ai.text_inference.enabled' => false]);

    $intake = makeIntentIntake();
    answerReason($intake, 'De slaapkamer en de woonkamer worden te warm in de zomer.');

    app(DeriveIntentFromRequest::class)->handle($intake, allowExternal: false);
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    expect($intake->fresh()->aircoRooms()->orderBy('sort_order')->pluck('name')->all())
        ->toBe(['Slaapkamer 1', 'Woonkamer 1']);
});

test('catalog AI fills restated rooms dimensions and per-type names', function () {
    $intake = makeIntentIntake();
    FakeAiClient::alwaysReturn([
        'evidence' => 'Drie slaapkamers en één woonkamer; woonkamer 5 bij 7 meter; slaapkamers alleen 20 m².',
        'fills' => [
            [
                'question_key' => 'cooling_heating',
                'section_instance_key' => null,
                'confidence' => 'high',
                'value' => ['value' => 'cooling'],
                'evidence' => null,
            ],
            [
                'question_key' => 'indoor_unit_count',
                'section_instance_key' => null,
                'confidence' => 'high',
                'value' => ['number' => 4],
                'evidence' => null,
            ],
            [
                'question_key' => 'room_type',
                'section_instance_key' => 'room-1',
                'confidence' => 'high',
                'value' => ['value' => 'bedroom'],
                'evidence' => null,
            ],
            [
                'question_key' => 'room_type',
                'section_instance_key' => 'room-2',
                'confidence' => 'high',
                'value' => ['value' => 'bedroom'],
                'evidence' => null,
            ],
            [
                'question_key' => 'room_type',
                'section_instance_key' => 'room-3',
                'confidence' => 'high',
                'value' => ['value' => 'bedroom'],
                'evidence' => null,
            ],
            [
                'question_key' => 'room_type',
                'section_instance_key' => 'room-4',
                'confidence' => 'high',
                'value' => ['value' => 'living_room'],
                'evidence' => null,
            ],
            [
                'question_key' => 'room_length_m',
                'section_instance_key' => 'room-4',
                'confidence' => 'high',
                'value' => ['number' => 5],
                'evidence' => null,
            ],
            [
                'question_key' => 'room_width_m',
                'section_instance_key' => 'room-4',
                'confidence' => 'high',
                'value' => ['number' => 7],
                'evidence' => null,
            ],
        ],
    ]);
    answerReason(
        $intake,
        'Drie slaapkamers en woonkamer koelen woonkamers is 5 bij 7 meter en de slaapkamers 20m2 elk',
    );

    $run = app(DeriveIntentFromRequest::class)->handle($intake);

    expect($run?->status)->toBe(AiRunStatus::Succeeded)
        ->and($run?->prompt_version)->toStartWith('request-prefill')
        ->and($intake->answers()->where('question_key', 'indoor_unit_count')->firstOrFail()->value)->toBe(['number' => 4])
        ->and($intake->answers()->where('question_key', 'room_length_m')->where('section_instance_key', 'room-1')->exists())->toBeFalse();

    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    expect($intake->fresh()->aircoRooms()->orderBy('sort_order')->pluck('name')->all())
        ->toBe(['Slaapkamer 1', 'Slaapkamer 2', 'Slaapkamer 3', 'Woonkamer 1']);
});

test('catalog AI prefill fills dormer outdoor placement from the openingszin', function () {
    $intake = makeIntentIntake();
    answerReason(
        $intake,
        "Twee airco's op slaapkamers om ze koud te krijgen buitenunit kan op dak dakkapel",
    );

    $run = app(DeriveIntentFromRequest::class)->handle($intake);

    expect($run?->status)->toBe(AiRunStatus::Succeeded)
        ->and($run?->prompt_version)->toStartWith('request-prefill')
        ->and($intake->answers()->where('question_key', 'cooling_heating')->firstOrFail()->value)->toBe(['value' => 'cooling'])
        ->and($intake->answers()->where('question_key', 'indoor_unit_count')->firstOrFail()->value)->toBe(['number' => 2])
        ->and($intake->answers()->where('question_key', 'outdoor_location')->firstOrFail()->value)->toBe(['value' => 'dormer'])
        ->and($intake->answers()->where('question_key', 'outdoor_mount_type')->firstOrFail()->value)->toBe(['value' => 'roof'])
        ->and($intake->answers()->where('question_key', 'outdoor_location')->firstOrFail()->prefill_source)
        ->toBe(DeriveIntentFromRequest::SOURCE_DERIVED);

    $request = FakeAiClient::lastRequest();
    expect($request)->not->toBeNull()
        ->and($request?->input)->toHaveKey('question_catalog')
        ->and($request?->input)->toHaveKey('known_context');

    $steps = intentStepKeys($intake);

    expect($steps)->not->toContain('cooling_heating')
        ->and($steps)->not->toContain('indoor_unit_count')
        ->and($steps)->not->toContain('outdoor_location')
        ->and($steps)->not->toContain('outdoor_mount_type')
        ->and($steps)->toContain('outdoor_location_photos');
});

test('a vague reason only suggests and keeps the questions', function () {
    $intake = makeIntentIntake();
    FakeAiClient::alwaysReturn([
        'evidence' => 'De aanvrager noemt warmte boven, zonder de ruimte hard te benoemen.',
        'fills' => [[
            'question_key' => 'cooling_heating',
            'section_instance_key' => null,
            'confidence' => 'medium',
            'value' => ['value' => 'cooling'],
            'evidence' => null,
        ], [
            'question_key' => 'indoor_unit_count',
            'section_instance_key' => null,
            'confidence' => 'medium',
            'value' => ['number' => 1],
            'evidence' => null,
        ]],
    ]);
    answerReason($intake, 'Het is boven altijd zo warm in de zomer.');

    app(DeriveIntentFromRequest::class)->handle($intake);

    expect($intake->answers()->where('question_key', 'indoor_unit_count')->firstOrFail()->prefill_source)
        ->toBe(DeriveIntentFromRequest::SOURCE_SUGGESTED)
        ->and(intentStepKeys($intake))->toContain('indoor_unit_count')
        ->and(intentStepKeys($intake))->toContain('cooling_heating');
});

test('low confidence stores nothing at all', function () {
    $intake = makeIntentIntake();
    FakeAiClient::alwaysReturn([
        'evidence' => 'De toelichting geeft geen uitsluitsel.',
        'fills' => [[
            'question_key' => 'cooling_heating',
            'section_instance_key' => null,
            'confidence' => 'low',
            'value' => ['value' => 'cooling'],
            'evidence' => null,
        ]],
    ]);
    answerReason($intake, 'Ik wil graag een offerte ontvangen alstublieft.');

    app(DeriveIntentFromRequest::class)->handle($intake);

    expect($intake->answers()->where('question_key', 'cooling_heating')->exists())->toBeFalse()
        ->and($intake->answers()->where('question_key', 'indoor_unit_count')->exists())->toBeFalse();
});

test('an answer the applicant already gave is never overwritten', function () {
    config(['ai.text_inference.enabled' => false]);

    $intake = makeIntentIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 4]);
    answerReason($intake, 'De slaapkamer en de woonkamer worden te warm in de zomer.');

    app(DeriveIntentFromRequest::class)->handle($intake, allowExternal: false);

    expect($intake->answers()->where('question_key', 'indoor_unit_count')->firstOrFail()->value)->toBe(['number' => 4]);
});

test('a reason too short to conclude anything is skipped without an AI call', function () {
    $intake = makeIntentIntake();
    answerReason($intake, 'Warm.');

    expect(app(DeriveIntentFromRequest::class)->handle($intake))->toBeNull()
        ->and($intake->answers()->where('question_key', 'cooling_heating')->exists())->toBeFalse();
});

test('external text inference stays off unless explicitly enabled', function () {
    config(['ai.text_inference.enabled' => false]);

    $intake = makeIntentIntake();
    answerReason($intake, 'De serre moet worden gekoeld voordat de zomer begint.');

    expect(app(DeriveIntentFromRequest::class)->handle($intake))->toBeNull()
        ->and($intake->answers()->where('question_key', 'cooling_heating')->exists())->toBeFalse()
        ->and(FakeAiClient::lastRequest())->toBeNull();
});

test('opening an older customer link repairs an installer sentence before building steps', function () {
    config(['ai.text_inference.enabled' => false]);

    $intake = makeIntentIntake();
    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'request_reason',
        null,
        ['text' => 'Ik wil twee airco’s om m’n slaapkamers op zolder te koelen.'],
        'installer',
    );

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertSet('intakeId', $intake->id);

    expect($intake->answers()->where('question_key', 'indoor_unit_count')->firstOrFail()->value)
        ->toBe(['number' => 2])
        ->and($intake->answers()->where('question_key', 'floor_level')->where('section_instance_key', 'room-2')->firstOrFail()->value)
        ->toBe(['value' => 'attic'])
        ->and(intentStepKeys($intake))->not->toContain('indoor_unit_count')
        ->and(intentStepKeys($intake))->not->toContain('room_type')
        ->and(intentStepKeys($intake))->not->toContain('floor_level');
});

test('a ground-mounted outdoor unit drops the ladder question', function () {
    $intake = makeIntentIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'outdoor_mount_type', null, ['value' => 'ground']);

    expect(intentStepKeys($intake))->not->toContain('outdoor_accessibility');

    app(SaveIntakeAnswer::class)->handle($intake, 'outdoor_mount_type', null, ['value' => 'wall']);

    expect(intentStepKeys($intake->fresh()))->toContain('outdoor_accessibility');
});

test('a short direct pipe route drops the distance question', function () {
    $intake = makeIntentIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'pipe_route_description', null, ['value' => 'short_direct']);

    expect(intentStepKeys($intake))->not->toContain('pipe_distance_indication');

    app(SaveIntakeAnswer::class)->handle($intake, 'pipe_route_description', null, ['value' => 'through_attic']);

    expect(intentStepKeys($intake->fresh()))->toContain('pipe_distance_indication');
});

test('hybrid path keeps local heuristic fills when AI returns nothing useful', function () {
    $intake = makeIntentIntake();
    FakeAiClient::alwaysReturn([
        'evidence' => 'Geen harde catalogusvulling.',
        'fills' => [],
    ]);
    answerReason($intake, 'Ik wil twee airco’s om m’n slaapkamers op zolder te koelen.');

    $run = app(DeriveIntentFromRequest::class)->handle($intake);

    expect($run?->status)->toBe(AiRunStatus::Succeeded)
        ->and($intake->answers()->where('question_key', 'cooling_heating')->firstOrFail()->value)->toBe(['value' => 'cooling'])
        ->and($intake->answers()->where('question_key', 'cooling_heating')->firstOrFail()->prefill_source)
        ->toBe(DeriveIntentFromRequest::SOURCE_REQUEST_TEXT)
        ->and($intake->answers()->where('question_key', 'floor_level')->where('section_instance_key', 'room-1')->firstOrFail()->value)
        ->toBe(['value' => 'attic'])
        ->and($intake->answers()->where('question_key', 'outdoor_location')->exists())->toBeFalse();
});

test('later installer observation reconsiders catalog prefill', function () {
    $intake = makeIntentIntake();
    answerReason($intake, 'Twee slaapkamers koelen omdat het te warm wordt.');

    app(DeriveIntentFromRequest::class)->handle($intake);

    expect($intake->answers()->where('question_key', 'outdoor_location')->exists())->toBeFalse();

    $subject = app(DossierManager::class)->initialize($intake);
    app(SaveInstallerObservation::class)->handle(
        $intake,
        User::query()->findOrFail($intake->created_by),
        $subject,
        'installer_note.test',
        'Buitenunit kan op de dakkapel.',
    );

    FakeAiClient::reset();
    app(DeriveIntentFromRequest::class)->handle($intake->fresh() ?? $intake);

    $context = FakeAiClient::lastRequest()?->input['known_context'] ?? [];
    $observationTexts = collect($context['installer_observations'] ?? [])
        ->pluck('text')
        ->all();

    expect($intake->answers()->where('question_key', 'outdoor_location')->firstOrFail()->value)
        ->toBe(['value' => 'dormer'])
        ->and($observationTexts)->toContain('Buitenunit kan op de dakkapel.');
});

test('new external facts change the prefill context hash so AI runs again', function () {
    $intake = makeIntentIntake();
    answerReason($intake, 'Twee slaapkamers koelen omdat het te warm wordt.');

    $first = app(DeriveIntentFromRequest::class)->handle($intake);
    expect($first?->status)->toBe(AiRunStatus::Succeeded);

    $intake->externalFacts()->create([
        'fact_key' => 'building_year',
        'label' => 'Bouwjaar',
        'value' => ['number' => 1985],
        'source' => 'PDOK / BAG',
        'confidence' => 'high',
        'captured_at' => now(),
    ]);

    FakeAiClient::reset();
    $second = app(DeriveIntentFromRequest::class)->handle($intake->fresh() ?? $intake);

    expect($second?->status)->toBe(AiRunStatus::Succeeded)
        ->and($second?->id)->not->toBe($first?->id)
        ->and(FakeAiClient::lastRequest()?->input['known_context']['external_facts'] ?? [])
        ->not->toBeEmpty();
});
