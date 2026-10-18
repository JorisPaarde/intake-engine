<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Services\ProgressCalculator;
use App\Domains\Intake\Support\FactProvenance;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

function n63Intake(?array $size = null, ?string $sizeSource = null, string $areaSource = PrefillSources::REQUEST_TEXT): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_email' => 'n63@example.com',
    ]);
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'cooling_heating', null, ['value' => 'cooling'], PrefillSources::AI_TEXT);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1], PrefillSources::AI_TEXT);
    $save->handle($intake, 'room_type', 'room-1', ['value' => 'living_room'], PrefillSources::AI_TEXT);
    $save->handle($intake, 'room_area_m2', 'room-1', ['number' => 30], $areaSource, FactProvenance::Stated, 90, 'ca. 30 m2');
    $save->handle($intake, 'floor_level', 'room-1', ['value' => '0'], PrefillSources::AI_TEXT);
    if ($size !== null) {
        $save->handle($intake, 'room_size_indication', 'room-1', $size, $sizeSource);
    }

    return $intake->fresh();
}

function n63SizeSteps(Intake $intake): array
{
    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();

    return collect(app(IntakeStepBuilder::class)->build($intake, $version))
        ->filter(fn (array $s): bool => ($s['question_key'] ?? null) === 'room_size_indication'
            || in_array('room_size_indication', $s['group_question_keys'] ?? [], true))
        ->values()
        ->all();
}

test('N63: bekende m² zonder grootteklasse-antwoord → geen grootteklasse-stap', function () {
    expect(n63SizeSteps(n63Intake()))->toBe([]);
});

test('N63: bekende m² + afwijkende foto-grootteklasse → geen grootteklasse-stap', function () {
    expect(n63SizeSteps(n63Intake(['value' => 'medium'], PrefillSources::AI_PHOTO)))->toBe([]);
});

test('N63: bekende m² telt grootteklasse niet als open verplichte vraag', function () {
    $intake = n63Intake();
    $progress = app(ProgressCalculator::class)->calculate($intake, $intake->templateVersion()->firstOrFail());
    $json = json_encode($progress);

    expect($json)->not->toContain('room_size_indication');
});
