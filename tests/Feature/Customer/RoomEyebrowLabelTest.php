<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

function eyebrowLabelIntake(): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_name' => 'Label Test',
        'customer_email' => 'label@example.test',
    ]);
}

test('ruimte-eyebrow is Slaapkamer N van M zonder verdieping er tussen', function () {
    $intake = eyebrowLabelIntake();
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 3], PrefillSources::AI_TEXT);
    foreach (['room-1', 'room-2', 'room-3'] as $room) {
        $save->handle($intake, 'room_type', $room, ['value' => 'bedroom'], PrefillSources::AI_TEXT);
    }
    // Verdieping (gedeeld of per ruimte) mag dossiernaam verrijken, niet de klant-eyebrow.
    $save->handle($intake, 'floor_level', 'room-1', ['value' => 'basement']);
    $save->handle($intake, 'floor_level', 'room-2', ['value' => 'basement']);
    $save->handle($intake, 'floor_level', 'room-3', ['value' => 'basement']);
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    $intake = $intake->fresh(['answers', 'aircoRooms', 'templateVersion.sections.questions.options']);
    // Oorzaak staging-label: dossiernaam krijgt “, kelder / souterrain” via appendFloorLabel.
    expect($intake->aircoRooms->firstWhere('key', 'room-1')?->name)
        ->toContain('kelder / souterrain');

    $steps = app(IntakeStepBuilder::class)->build($intake, $intake->templateVersion);
    $room1Photo = collect($steps)->first(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'room_photos'
            && ($step['section_instance_key'] ?? null) === 'room-1',
    );

    expect($room1Photo)->not->toBeNull()
        ->and($room1Photo['section_title'])->toBe('Kamer 1 van 3 (slaapkamer)')
        ->and($room1Photo['section_title'])->not->toContain('kelder')
        ->and($room1Photo['section_title'])->not->toContain('souterrain')
        ->and($room1Photo['section_title'])->not->toContain(',');
});

test('floor_level basement komt uit het verdiepingsantwoord, niet uit room_type bedroom', function () {
    $intake = eyebrowLabelIntake();
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1], PrefillSources::AI_TEXT);
    $save->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom'], PrefillSources::AI_TEXT);
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    $before = $intake->fresh(['aircoRooms'])->aircoRooms->firstWhere('key', 'room-1')?->name;
    expect($before)->toBe('Slaapkamer 1')
        ->and($before)->not->toContain('kelder');

    $save->handle($intake, 'floor_level', 'room-1', ['value' => 'basement']);
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    $after = $intake->fresh(['aircoRooms'])->aircoRooms->firstWhere('key', 'room-1')?->name;
    expect($after)->toContain('kelder / souterrain');

    $steps = app(IntakeStepBuilder::class)->build(
        $intake->fresh(['answers', 'aircoRooms', 'templateVersion.sections.questions.options']),
        $intake->templateVersion,
    );
    $title = collect($steps)->first(
        static fn (array $step): bool => ($step['section_instance_key'] ?? null) === 'room-1'
            && ($step['question_key'] ?? null) === 'room_photos',
    )['section_title'] ?? null;

    expect($title)->toBe('Slaapkamer');
});
