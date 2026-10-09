<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Support\PrefillSources;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

function makeKlantwizard14AreaIntake(): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
        'access_token' => str_repeat('c', 64),
        'token_expires_at' => now()->addDays(14),
    ]);
}

test('A3 repro: na invullen L en B is de volgende stap geen vloeroppervlak-m²', function () {
    $intake = makeKlantwizard14AreaIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom']);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $viewSteps = $component->viewData('steps');
    $dimIndex = collect($viewSteps)->search(
        static fn (array $step): bool => ($step['group_key'] ?? null) === 'room_dimensions',
    );
    expect($dimIndex)->not->toBeFalse();

    $dimStep = $viewSteps[(int) $dimIndex];
    $lengthKey = 'room-1__room_length_m';
    $widthKey = 'room-1__room_width_m';

    $component
        ->set('stepIndex', (int) $dimIndex)
        ->set('activeStepKey', $dimStep['key'])
        ->set("form.{$lengthKey}.number", '3.5')
        ->set("form.{$widthKey}.number", '3')
        ->call('next');

    $intake->refresh();
    $area = $intake->answers()->where('question_key', 'room_area_m2')->where('section_instance_key', 'room-1')->first();
    expect($area)->not->toBeNull()
        ->and($area?->prefill_source)->toBe(PrefillSources::DERIVED_LXW)
        ->and((float) ($area?->value['number'] ?? 0))->toBe(10.5);

    $afterSteps = $component->viewData('steps');
    $afterIndex = (int) $component->get('stepIndex');
    $afterStep = $afterSteps[$afterIndex] ?? null;

    expect($afterStep)->not->toBeNull()
        ->and($afterStep['question_key'] ?? null)->not->toBe('room_area_m2')
        ->and($afterStep['group_key'] ?? null)->not->toBe('room_dimensions');

    $keys = array_column($afterSteps, 'question_key');
    expect($keys)->not->toContain('room_area_m2');

    // Ook na reload: geen m²-stap meer.
    $reloaded = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $reloadedKeys = array_column($reloaded->viewData('steps'), 'question_key');
    expect($reloadedKeys)->not->toContain('room_area_m2');

    $builderSteps = app(IntakeStepBuilder::class)->build(
        $intake->fresh(),
        $intake->fresh()->templateVersion()->with(['sections.questions.rules'])->firstOrFail(),
    );
    expect(array_column($builderSteps, 'question_key'))->not->toContain('room_area_m2');
});

test('A3: L×B en m² staan op één matenscherm; m²-veld opent via link', function () {
    $intake = makeKlantwizard14AreaIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom']);

    $version = $intake->fresh()->templateVersion()->with(['sections.questions.rules'])->firstOrFail();
    $steps = app(IntakeStepBuilder::class)->build($intake->fresh(), $version);
    $dimStep = collect($steps)->firstWhere('group_key', 'room_dimensions');

    expect($dimStep)->not->toBeNull()
        ->and($dimStep['group_question_keys'] ?? [])->toBe(['room_length_m', 'room_width_m', 'room_area_m2'])
        ->and(array_column($steps, 'question_key'))->not->toContain('room_area_m2');

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $viewSteps = $component->viewData('steps');
    $dimIndex = collect($viewSteps)->search(
        static fn (array $step): bool => ($step['group_key'] ?? null) === 'room_dimensions',
    );
    expect($dimIndex)->not->toBeFalse();

    $component
        ->set('stepIndex', (int) $dimIndex)
        ->set('activeStepKey', $viewSteps[(int) $dimIndex]['key'])
        ->assertSee('data-testid="dimensions-group"', false)
        ->assertSee('data-testid="live-room-area"', false)
        ->assertSee('Weet je alleen het oppervlak in m²?')
        ->assertSee('data-testid="dimensions-skip"', false)
        ->assertDontSee('data-testid="area-only-field"', false)
        ->call('revealAreaOnlyField')
        ->assertSet('showAreaOnlyField', true)
        ->assertSee('data-testid="area-only-field"', false)
        ->assertSee('Oppervlak (m²)')
        ->assertDontSee('data-testid="reveal-area-only"', false);
});

test('A3: leeg m² op het matenscherm overschrijft derived_lxw niet', function () {
    $intake = makeKlantwizard14AreaIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom']);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $viewSteps = $component->viewData('steps');
    $dimIndex = collect($viewSteps)->search(
        static fn (array $step): bool => ($step['group_key'] ?? null) === 'room_dimensions',
    );
    expect($dimIndex)->not->toBeFalse();

    $dimStep = $viewSteps[(int) $dimIndex];
    $areaKey = 'room-1__room_area_m2';

    $component
        ->set('stepIndex', (int) $dimIndex)
        ->set('activeStepKey', $dimStep['key'])
        ->set('form.room-1__room_length_m.number', '4')
        ->set('form.room-1__room_width_m.number', '3')
        ->set("form.{$areaKey}.number", '')
        ->call('next');

    $intake->refresh();
    $area = $intake->answers()->where('question_key', 'room_area_m2')->where('section_instance_key', 'room-1')->first();
    expect($area)->not->toBeNull()
        ->and($area?->prefill_source)->toBe(PrefillSources::DERIVED_LXW)
        ->and((float) ($area?->value['number'] ?? 0))->toBe(12.0);
});

test('A3: alleen m² invullen via hetzelfde scherm slaat L×B over', function () {
    $intake = makeKlantwizard14AreaIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom']);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $viewSteps = $component->viewData('steps');
    $dimIndex = collect($viewSteps)->search(
        static fn (array $step): bool => ($step['group_key'] ?? null) === 'room_dimensions',
    );
    expect($dimIndex)->not->toBeFalse();

    $dimStep = $viewSteps[(int) $dimIndex];

    $component
        ->set('stepIndex', (int) $dimIndex)
        ->set('activeStepKey', $dimStep['key'])
        ->call('revealAreaOnlyField')
        ->set('form.room-1__room_area_m2.number', '18')
        ->call('next');

    $intake->refresh();
    $area = $intake->answers()->where('question_key', 'room_area_m2')->where('section_instance_key', 'room-1')->first();
    expect($area)->not->toBeNull()
        ->and((float) ($area?->value['number'] ?? 0))->toBe(18.0)
        ->and($area?->prefill_source)->toBeNull();

    $afterStep = $component->viewData('steps')[(int) $component->get('stepIndex')] ?? null;
    expect($afterStep)->not->toBeNull()
        ->and($afterStep['group_key'] ?? null)->not->toBe('room_dimensions')
        ->and($afterStep['question_key'] ?? null)->not->toBe('room_area_m2');
});
