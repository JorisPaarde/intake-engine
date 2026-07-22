<?php

declare(strict_types=1);

use App\Domains\AI\Actions\DeriveIntentFromRequest;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Enums\AiRunStatus;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;

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

test('naming two rooms answers function, unit count and both room types', function () {
    $intake = makeIntentIntake();
    answerReason($intake, 'De slaapkamer en de woonkamer worden te warm in de zomer.');

    $run = app(DeriveIntentFromRequest::class)->handle($intake);

    expect($run?->status)->toBe(AiRunStatus::Succeeded)
        ->and($intake->answers()->where('question_key', 'cooling_heating')->firstOrFail()->value)->toBe(['value' => 'cooling'])
        ->and($intake->answers()->where('question_key', 'indoor_unit_count')->firstOrFail()->value)->toBe(['number' => 2])
        ->and($intake->answers()->where('question_key', 'room_type')->where('section_instance_key', 'room-1')->firstOrFail()->value)->toBe(['value' => 'bedroom'])
        ->and($intake->answers()->where('question_key', 'room_type')->where('section_instance_key', 'room-2')->firstOrFail()->value)->toBe(['value' => 'living_room']);

    $steps = intentStepKeys($intake);

    // De drie vragen zijn beantwoord en verdwijnen; de ruimtesectie is wel uitgeklapt.
    expect($steps)->not->toContain('cooling_heating')
        ->and($steps)->not->toContain('indoor_unit_count')
        ->and($steps)->not->toContain('room_type')
        ->and(collect($steps)->filter(fn (string $k): bool => $k === 'room_photos'))->toHaveCount(2);
});

test('a vague reason only suggests and keeps the questions', function () {
    $intake = makeIntentIntake();
    FakeAiClient::alwaysReturn([
        'cooling_heating' => 'cooling',
        'rooms' => ['bedroom'],
        'confidence' => 'medium',
        'evidence' => 'De aanvrager noemt warmte boven, zonder de ruimte hard te benoemen.',
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
        'cooling_heating' => 'unknown',
        'rooms' => [],
        'confidence' => 'low',
        'evidence' => 'De toelichting geeft geen uitsluitsel.',
    ]);
    answerReason($intake, 'Ik wil graag een offerte ontvangen alstublieft.');

    app(DeriveIntentFromRequest::class)->handle($intake);

    expect($intake->answers()->where('question_key', 'cooling_heating')->exists())->toBeFalse()
        ->and($intake->answers()->where('question_key', 'indoor_unit_count')->exists())->toBeFalse();
});

test('an answer the applicant already gave is never overwritten', function () {
    $intake = makeIntentIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 4]);
    answerReason($intake, 'De slaapkamer en de woonkamer worden te warm in de zomer.');

    app(DeriveIntentFromRequest::class)->handle($intake);

    expect($intake->answers()->where('question_key', 'indoor_unit_count')->firstOrFail()->value)->toBe(['number' => 4]);
});

test('a reason too short to conclude anything is skipped without an AI call', function () {
    $intake = makeIntentIntake();
    answerReason($intake, 'Warm.');

    expect(app(DeriveIntentFromRequest::class)->handle($intake))->toBeNull()
        ->and($intake->answers()->where('question_key', 'cooling_heating')->exists())->toBeFalse();
});

test('text inference stays off unless explicitly enabled', function () {
    config(['ai.text_inference.enabled' => false]);

    $intake = makeIntentIntake();
    answerReason($intake, 'De slaapkamer en de woonkamer worden te warm in de zomer.');

    expect(app(DeriveIntentFromRequest::class)->handle($intake))->toBeNull()
        ->and($intake->answers()->where('question_key', 'cooling_heating')->exists())->toBeFalse();
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
