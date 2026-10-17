<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Support\FactProvenance;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\IntakeStatus;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

function n37AreaIntake(): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_email' => 'n37-area@example.com',
    ]);
}

function seedN37KnownArea(Intake $intake): void
{
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'cooling_heating', null, ['value' => 'cooling'], PrefillSources::AI_TEXT);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1], PrefillSources::AI_TEXT);
    $save->handle($intake, 'room_type', 'room-1', ['value' => 'living_room'], PrefillSources::AI_TEXT);
    $save->handle(
        $intake,
        'room_area_m2',
        'room-1',
        ['number' => 30],
        PrefillSources::REQUEST_TEXT,
        FactProvenance::Stated,
        90,
        'ca. 30 m2',
    );
    $save->handle($intake, 'floor_level', 'room-1', ['value' => '0'], PrefillSources::AI_TEXT);
}

test('N37: Wijzigen oppervlak opent room_dimensions met m²-veld en terug naar overzicht', function () {
    $intake = n37AreaIntake();
    seedN37KnownArea($intake);

    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    $stepsBefore = app(IntakeStepBuilder::class)->build($intake->fresh(), $version);

    expect(collect($stepsBefore)->firstWhere('group_key', 'room_dimensions'))->toBeNull();

    $summaryIndex = collect($stepsBefore)->search(fn (array $s): bool => ($s['kind'] ?? '') === 'known_summary');
    expect($summaryIndex)->toBeInt();

    $summary = $stepsBefore[(int) $summaryIndex];
    $areaItem = collect($summary['known_items'] ?? [])->firstWhere('question_key', 'room_area_m2');
    expect($areaItem)->not->toBeNull()
        ->and((string) ($areaItem['display_value'] ?? ''))->toContain('30');

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', $summaryIndex)
        ->set('activeStepKey', '_known_summary')
        ->call('editKnownAnswer', 'room_area_m2', 'room-1');

    expect($component->get('forceShowKnown'))->toContain('room-1__room_area_m2')
        ->and($component->get('showAreaOnlyField'))->toBeTrue();

    $viewSteps = $component->viewData('steps');
    $dimIndex = collect($viewSteps)->search(
        static fn (array $step): bool => ($step['group_key'] ?? null) === 'room_dimensions',
    );
    expect($dimIndex)->not->toBeFalse()
        ->and($component->get('stepIndex'))->toBe($dimIndex)
        ->and($component->get('activeStepKey'))->toContain('room_dimensions');

    $component
        ->assertSee('data-testid="dimensions-group"', false)
        ->assertSee('data-testid="area-only-field"', false)
        ->assertSee('Oppervlak (m²)')
        ->set('form.room-1__room_area_m2.number', '28')
        ->call('next');

    $stepsAfter = app(IntakeStepBuilder::class)->build($intake->fresh(), $version);
    $summaryAfter = collect($stepsAfter)->search(fn (array $s): bool => ($s['kind'] ?? '') === 'known_summary');

    expect($component->get('stepIndex'))->toBe($summaryAfter)
        ->and($component->get('forceShowKnown'))->not->toContain('room-1__room_area_m2')
        ->and($component->get('showAreaOnlyField'))->toBeFalse();

    $area = $intake->fresh()->answers()
        ->where('question_key', 'room_area_m2')
        ->where('section_instance_key', 'room-1')
        ->firstOrFail();

    expect((float) ($area->value['number'] ?? 0))->toBe(28.0)
        ->and($area->prefill_source)->toBeNull();
});

test('N37: indexForCursor vindt room_area_m2 in question_group', function () {
    $intake = n37AreaIntake();
    seedN37KnownArea($intake);

    $version = $intake->templateVersion()->with(['sections.questions.rules'])->firstOrFail();
    $steps = app(IntakeStepBuilder::class)->build(
        $intake->fresh(),
        $version,
        [],
        ['room-1__room_area_m2'],
    );

    $index = app(IntakeStepBuilder::class)->indexForExactCursor(
        $steps,
        null,
        'room_area_m2',
        'room-1',
    );

    expect($index)->not->toBeNull()
        ->and($steps[$index]['group_key'] ?? null)->toBe('room_dimensions')
        ->and($steps[$index]['group_question_keys'] ?? [])->toContain('room_area_m2');
});

test('N37: bij bekende m² staat geen afgeleide grootteklasse als aparte Wijzigen-rij in het overzicht', function () {
    $intake = n37AreaIntake();
    seedN37KnownArea($intake);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_size_indication', 'room-1', ['value' => 'large'], PrefillSources::AI_TEXT);

    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    $steps = app(IntakeStepBuilder::class)->build($intake->fresh(), $version);
    $summary = collect($steps)->firstWhere('kind', 'known_summary');
    $keys = array_column($summary['known_items'] ?? [], 'question_key');

    expect($keys)->toContain('room_area_m2')
        ->and($keys)->not->toContain('room_size_indication');
});
