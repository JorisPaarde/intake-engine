<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\ProgressCalculator;
use App\Domains\Intake\Services\VisibilityResolver;
use App\Enums\IntakeStatus;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

function makeAccessibleIntake(): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_name' => 'Klant Test',
        'customer_email' => 'klant@example.com',
    ]);
}

test('valid customer token opens the intake wizard', function () {
    $intake = makeAccessibleIntake();

    $this->get(route('customer.intake.show', $intake->access_token))
        ->assertOk()
        ->assertSee('Digitale Opname')
        ->assertSee('Aanvraag');
});

test('invalid or revoked customer token is refused', function () {
    $intake = makeAccessibleIntake();

    $this->get(route('customer.intake.show', str_repeat('a', 64)))
        ->assertNotFound();

    $intake->update([
        'token_revoked_at' => now(),
        'status' => IntakeStatus::Cancelled,
    ]);

    $this->get(route('customer.intake.show', $intake->access_token))
        ->assertNotFound();
});

test('token for a different intake does not expose another dossier', function () {
    $first = makeAccessibleIntake();
    $second = makeAccessibleIntake();
    $second->update([
        'customer_name' => 'Andere Klant',
        'customer_email' => 'andere@example.com',
    ]);

    $this->get(route('customer.intake.show', $first->access_token))
        ->assertOk()
        ->assertSee('Klant Test')
        ->assertDontSee('Andere Klant');
});

test('answers are saved and intake can be resumed', function () {
    $intake = makeAccessibleIntake();

    app(SaveIntakeAnswer::class)->handle($intake, 'request_reason', null, [
        'text' => 'Te warm op zolder',
    ]);

    $intake->refresh();

    expect($intake->status)->toBe(IntakeStatus::InProgress)
        ->and($intake->answers()->where('question_key', 'request_reason')->value('value'))
        ->toMatchArray(['text' => 'Te warm op zolder'])
        ->and($intake->progress_percent)->toBeGreaterThan(0);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertSet('intakeId', $intake->id)
        ->assertSet('form.request_reason.text', 'Te warm op zolder');
});

test('required questions block advancing to the next step', function () {
    $intake = makeAccessibleIntake();

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->call('next')
        ->assertSet('showMissing', true)
        ->assertSet('stepIndex', 0);
});

test('conditional show rules hide questions until matched', function () {
    $intake = makeAccessibleIntake();
    $version = $intake->templateVersion()->with(['sections.questions.rules'])->firstOrFail();

    $drainPhoto = $version->sections
        ->flatMap->questions
        ->firstWhere('key', 'drain_photo');

    expect($drainPhoto)->not->toBeNull();

    app(SaveIntakeAnswer::class)->handle($intake, 'natural_fall_possible', null, [
        'bool' => false,
    ]);

    $intake->refresh();
    $answers = [
        VisibilityResolver::compositeKey('natural_fall_possible', null) => ['bool' => false],
    ];

    $questionTypes = [];
    $sectionsByKey = [];
    foreach ($version->sections as $section) {
        foreach ($section->questions as $question) {
            $questionTypes[$question->key] = $question->type;
            $sectionsByKey[$question->key] = $section;
            $question->setRelation('section', $section);
        }
    }

    $resolved = app(VisibilityResolver::class)->resolveQuestion(
        $drainPhoto,
        null,
        $answers,
        $questionTypes,
        $sectionsByKey,
    );

    expect($resolved['visible'])->toBeFalse();

    $resolvedVisible = app(VisibilityResolver::class)->resolveQuestion(
        $drainPhoto,
        null,
        [VisibilityResolver::compositeKey('natural_fall_possible', null) => ['bool' => true]],
        $questionTypes,
        $sectionsByKey,
    );

    expect($resolvedVisible['visible'])->toBeTrue();
});

test('progress calculator includes answered questions in the percentage', function () {
    $intake = makeAccessibleIntake();
    $version = $intake->templateVersion()->with(['sections.questions.rules'])->firstOrFail();

    $before = app(ProgressCalculator::class)->calculate($intake, $version);

    expect($before['percent'])->toBe(0);

    app(SaveIntakeAnswer::class)->handle($intake, 'request_reason', null, ['text' => 'Demo']);
    app(SaveIntakeAnswer::class)->handle($intake, 'cooling_heating', null, ['value' => 'cooling']);
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);

    $intake->refresh();
    $after = app(ProgressCalculator::class)->calculate($intake, $version);

    expect($after['percent'])->toBeGreaterThan($before['percent']);
});
