<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

function makeKlantwizard14Intake(?int $templateVersion = null): Intake
{
    $user = User::factory()->create();
    $template = IntakeTemplate::query()->where('key', 'airco')->firstOrFail();
    $version = $templateVersion === null
        ? $template->latestPublishedVersion()
        : $template->versions()->where('version', $templateVersion)->firstOrFail();

    return Intake::factory()->create([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
        'access_token' => str_repeat('b', 64),
        'token_expires_at' => now()->addDays(14),
    ]);
}

test('A1: v27 combineert drain_location en drain_photo in één wizardstap', function () {
    $intake = makeKlantwizard14Intake();
    $version = $intake->fresh()->templateVersion()->with(['sections.questions'])->firstOrFail();

    expect($version->version)->toBe(27);

    $drainLocation = $version->sections->flatMap->questions->firstWhere('key', 'drain_location');
    $drainPhoto = $version->sections->flatMap->questions->firstWhere('key', 'drain_photo');

    expect($drainLocation->meta['wizard_group'] ?? null)->toBe('drain_nearby')
        ->and($drainLocation->meta['wizard_group_title'] ?? null)->toBe('Afvoer in de buurt')
        ->and($drainLocation->meta['wizard_group_help'] ?? null)->toBe('Een foto helpt de installateur. Weet je het niet? Ga gewoon verder.')
        ->and($drainPhoto->meta['wizard_group'] ?? null)->toBe('drain_nearby')
        ->and($drainPhoto->is_required)->toBeFalse()
        ->and($drainPhoto->meta['allow_skip'] ?? null)->toBeNull();

    $steps = app(IntakeStepBuilder::class)->build($intake->fresh(), $version);
    $drainSteps = collect($steps)->filter(
        static fn (array $step): bool => ($step['group_key'] ?? null) === 'drain_nearby'
            || in_array($step['question_key'] ?? null, ['drain_location', 'drain_photo'], true),
    )->values();

    expect($drainSteps)->toHaveCount(1)
        ->and($drainSteps[0]['kind'])->toBe('question_group')
        ->and($drainSteps[0]['title'])->toBe('Afvoer in de buurt')
        ->and($drainSteps[0]['group_question_keys'])->toBe(['drain_location', 'drain_photo'])
        ->and($drainSteps[0]['is_required'])->toBeFalse();

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $viewSteps = $component->viewData('steps');
    $index = collect($viewSteps)->search(
        static fn (array $step): bool => ($step['group_key'] ?? null) === 'drain_nearby',
    );
    expect($index)->not->toBeFalse();

    $component->set('stepIndex', (int) $index)
        ->set('activeStepKey', $viewSteps[(int) $index]['key'])
        ->assertSee('Afvoer in de buurt')
        ->assertSee('Een foto helpt de installateur. Weet je het niet? Ga gewoon verder.')
        ->assertSee('data-testid="drain-nearby-group"', false)
        ->assertDontSee('data-testid="photo-skip"', false);
});

test('A1: v26-intakes blijven twee aparte afvoerstappen zonder wizard_group', function () {
    seedAllAircoTemplateVersions();
    $intake = makeKlantwizard14Intake(26);
    $version = $intake->fresh()->templateVersion()->with(['sections.questions'])->firstOrFail();

    expect($version->version)->toBe(26);

    $steps = app(IntakeStepBuilder::class)->build($intake->fresh(), $version);
    $keys = array_column($steps, 'question_key');

    expect($keys)->toContain('drain_location')
        ->and($keys)->toContain('drain_photo')
        ->and(collect($steps)->firstWhere('group_key', 'drain_nearby'))->toBeNull();

    $drainPhoto = collect($steps)->firstWhere('question_key', 'drain_photo');
    expect($drainPhoto)->not->toBeNull()
        ->and($drainPhoto['kind'] ?? 'question')->toBe('question');
});

test('A1: afvoerfoto blijft optioneel — Volgende zonder foto is toegestaan', function () {
    $intake = makeKlantwizard14Intake();
    app(SaveIntakeAnswer::class)->handle($intake, 'drain_location', null, [
        'value' => 'unknown',
    ]);

    $version = $intake->fresh()->templateVersion()->with(['sections.questions.rules'])->firstOrFail();
    $step = collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version))
        ->firstWhere('group_key', 'drain_nearby');

    expect($step)->not->toBeNull()
        ->and($step['is_required'])->toBeFalse();

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->call('next')
        ->assertHasNoErrors();
});
