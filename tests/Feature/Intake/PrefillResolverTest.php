<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\IntakePrefillResolver;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

function makeRoomsIntake(int $unitCount = 3): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
    ]);

    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => $unitCount]);

    return $intake->fresh();
}

function resolveSuggestion(Intake $intake, string $questionKey, ?string $instance): ?array
{
    $version = $intake->templateVersion()->with('sections.questions')->firstOrFail();

    return app(IntakePrefillResolver::class)->suggestionFor($intake->fresh(), $version, $questionKey, $instance);
}

test('suggests the previous room answer for a flagged question', function () {
    $intake = makeRoomsIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'floor_level', 'room-1', ['value' => '1']);

    $suggestion = resolveSuggestion($intake, 'floor_level', 'room-2');

    expect($suggestion)->not->toBeNull()
        ->and($suggestion['value'])->toBe(['value' => '1'])
        ->and($suggestion['source_label'])->toBe('Ruimtes 1');
});

test('never suggests for the first instance', function () {
    $intake = makeRoomsIntake();

    expect(resolveSuggestion($intake, 'floor_level', 'room-1'))->toBeNull();
});

test('does not suggest when the target instance is already answered', function () {
    $intake = makeRoomsIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'floor_level', 'room-1', ['value' => '1']);
    app(SaveIntakeAnswer::class)->handle($intake, 'floor_level', 'room-2', ['value' => 'ground']);

    expect(resolveSuggestion($intake, 'floor_level', 'room-2'))->toBeNull();
});

test('does not suggest for an unflagged question', function () {
    $intake = makeRoomsIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom']);

    expect(resolveSuggestion($intake, 'room_type', 'room-2'))->toBeNull();
});

test('does not suggest for a non-repeatable (null instance) question', function () {
    $intake = makeRoomsIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'request_reason', null, ['text' => 'Warm']);

    expect(resolveSuggestion($intake, 'request_reason', null))->toBeNull();
});

test('picks the nearest previous filled instance', function () {
    $intake = makeRoomsIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'floor_level', 'room-1', ['value' => '1']);
    app(SaveIntakeAnswer::class)->handle($intake, 'floor_level', 'room-2', ['value' => 'ground']);

    $suggestion = resolveSuggestion($intake, 'floor_level', 'room-3');

    expect($suggestion['value'])->toBe(['value' => 'ground'])
        ->and($suggestion['source_label'])->toBe('Ruimtes 2');
});
