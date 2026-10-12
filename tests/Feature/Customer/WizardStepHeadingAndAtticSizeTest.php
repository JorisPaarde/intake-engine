<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\CompletenessChecker;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\IntakeStatus;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

function headingAtticIntake(): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'access_token' => str_repeat('a', 48).bin2hex(random_bytes(8)),
        'token_expires_at' => now()->addDays(7),
        'customer_email' => 'heading-attic@example.com',
    ]);
}

test('wizard heading wire:key volgt de actieve stap na Volgende', function () {
    $intake = headingAtticIntake();
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    $save->handle($intake, 'room_type', 'room-1', ['value' => 'living_room']);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $steps = $component->viewData('steps');

    $sizeIndex = collect($steps)->search(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'room_size_indication'
            && ($step['section_instance_key'] ?? null) === 'room-1',
    );
    $dimIndex = collect($steps)->search(
        static fn (array $step): bool => ($step['group_key'] ?? null) === 'room_dimensions'
            && ($step['section_instance_key'] ?? null) === 'room-1',
    );
    expect($sizeIndex)->not->toBeFalse()->and($dimIndex)->not->toBeFalse();

    $sizeStep = $steps[(int) $sizeIndex];
    $dimStep = $steps[(int) $dimIndex];
    $sizeKey = $sizeStep['key'];
    $dimKey = $dimStep['key'];

    $component
        ->set('stepIndex', (int) $sizeIndex)
        ->set('activeStepKey', $sizeKey)
        ->set('knownStepKeys', array_column($steps, 'key'))
        ->assertSee('data-testid="wizard-step-heading"', false)
        ->assertSee('wire:key="step-heading-'.$sizeKey.'"', false)
        ->assertSee($sizeStep['title'])
        ->set('form.room-1__room_size_indication.value', 'medium')
        ->call('next', $sizeKey)
        ->assertHasNoErrors();

    expect($component->get('activeStepKey'))->toBe($dimKey);

    $html = $component->html();
    expect($html)->toContain('wire:key="step-heading-'.$dimKey.'"')
        ->and($html)->not->toContain('wire:key="step-heading-'.$sizeKey.'"')
        ->and($html)->toContain(e($dimStep['title']))
        ->and($html)->not->toContain(e($sizeStep['title']));

    preg_match_all('/data-testid="wizard-step-heading"/', $html, $headingMarks);
    expect($headingMarks[0])->toHaveCount(1);
});

test('zolder: geen verplichte grootteklasse vóór L×B; Afronden mist room_size_indication niet', function () {
    $intake = headingAtticIntake();
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    $save->handle($intake, 'room_type', 'room-1', ['value' => 'attic']);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $viewSteps = $component->viewData('steps');

    expect(collect($viewSteps)->first(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'room_size_indication'
            && ($step['section_instance_key'] ?? null) === 'room-1',
    ))->toBeNull();

    $dimStep = collect($viewSteps)->first(
        static fn (array $step): bool => ($step['group_key'] ?? null) === 'room_dimensions'
            && ($step['section_instance_key'] ?? null) === 'room-1',
    );
    expect($dimStep)->not->toBeNull()
        ->and($dimStep['title'] ?? null)->toBe('Lengte en breedte van de ruimte');

    $sizeAnswer = $intake->fresh()->answers()
        ->where('question_key', 'room_size_indication')
        ->where('section_instance_key', 'room-1')
        ->first();
    expect($sizeAnswer)->not->toBeNull()
        ->and($sizeAnswer?->value)->toBe(['value' => 'unknown'])
        ->and($sizeAnswer?->prefill_source)->toBeNull();

    $version = $intake->fresh()->templateVersion()->with(['sections.questions.rules'])->firstOrFail();
    $missing = collect(app(CompletenessChecker::class)->check($intake->fresh(), $version)['missing'])
        ->pluck('question_key');
    expect($missing)->not->toContain('room_size_indication');
});

test('woonkamer behoudt grootteklasse vóór L×B', function () {
    $intake = headingAtticIntake();
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    $save->handle($intake, 'room_type', 'room-1', ['value' => 'living_room']);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $keys = collect($component->viewData('steps'))
        ->filter(static fn (array $step): bool => ($step['section_instance_key'] ?? null) === 'room-1')
        ->values();

    $sizePos = $keys->search(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'room_size_indication',
    );
    $dimPos = $keys->search(
        static fn (array $step): bool => ($step['group_key'] ?? null) === 'room_dimensions',
    );

    expect($sizePos)->not->toBeFalse()
        ->and($dimPos)->not->toBeFalse()
        ->and($sizePos)->toBeLessThan($dimPos);
});

test('zolder met L×B leidt size af i.p.v. unknown', function () {
    $intake = headingAtticIntake();
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    $save->handle($intake, 'room_type', 'room-1', ['value' => 'attic']);
    $save->handle($intake, 'room_length_m', 'room-1', ['number' => 5]);
    $save->handle($intake, 'room_width_m', 'room-1', ['number' => 4]);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);

    $size = $intake->fresh()->answers()
        ->where('question_key', 'room_size_indication')
        ->where('section_instance_key', 'room-1')
        ->first();

    expect($size)->not->toBeNull()
        ->and($size?->value)->toBe(['value' => 'medium'])
        ->and($size?->prefill_source)->toBe(PrefillSources::DERIVED_LXW);

    $version = $intake->fresh()->templateVersion()->with(['sections.questions.rules'])->firstOrFail();
    $builderKeys = array_column(
        app(IntakeStepBuilder::class)->build($intake->fresh(), $version),
        'question_key',
    );
    // Builder mag size nog tonen tot skip; wizard-omit + derived skip beide OK.
    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    expect(array_column($component->viewData('steps'), 'question_key'))
        ->not->toContain('room_size_indication')
        ->and($builderKeys)->not->toContain('room_size_indication');
});

test('kiezen van zolder als type verwijdert grootteklasse uit de steplijst', function () {
    $intake = headingAtticIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom']);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $steps = $component->viewData('steps');
    $typeIndex = collect($steps)->search(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'room_type'
            && ($step['section_instance_key'] ?? null) === 'room-1',
    );
    expect($typeIndex)->not->toBeFalse();
    expect(array_column($steps, 'question_key'))->toContain('room_size_indication');

    $typeKey = $steps[(int) $typeIndex]['key'];
    $component
        ->set('stepIndex', (int) $typeIndex)
        ->set('activeStepKey', $typeKey)
        ->set('form.room-1__room_type.value', 'attic')
        ->assertHasNoErrors();

    $afterKeys = array_column($component->viewData('steps'), 'question_key');
    expect($afterKeys)->not->toContain('room_size_indication')
        ->and($afterKeys)->toContain('room_length_m');
});
