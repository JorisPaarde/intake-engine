<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\CompletenessChecker;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Services\ProgressCalculator;
use App\Domains\Intake\Services\VisibilityResolver;
use App\Enums\IntakeStatus;
use App\Enums\QuestionType;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
        ->assertSee('Aanvraag')
        ->assertSee('Wat is de reden van uw aanvraag?')
        ->assertSee('Vraag 1 van');
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
        ->assertSet('stepIndex', 0)
        ->assertSet('activeStepKey', 'request::request_reason');
});

test('wizard advances one visible question at a time', function () {
    $intake = makeAccessibleIntake();

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('form.request_reason', ['text' => 'Te warm'])
        ->call('next')
        ->assertSet('showMissing', false)
        ->assertSet('stepIndex', 1)
        ->assertSet('activeStepKey', 'request::cooling_heating')
        ->assertSee('Wilt u koelen, verwarmen of beide?')
        ->assertDontSee('Hoeveel binnenunits wilt u ongeveer?');
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

test('hidden conditional questions are skipped in the question-per-step list', function () {
    $intake = makeAccessibleIntake();
    $version = $intake->templateVersion()->with(['sections.questions.rules'])->firstOrFail();

    app(SaveIntakeAnswer::class)->handle($intake, 'natural_fall_possible', null, [
        'bool' => false,
    ]);
    $intake->refresh();

    $steps = app(IntakeStepBuilder::class)->build($intake, $version);
    $questionKeys = array_column($steps, 'question_key');

    expect($questionKeys)->not->toContain('drain_photo');

    app(SaveIntakeAnswer::class)->handle($intake, 'natural_fall_possible', null, [
        'bool' => true,
    ]);
    $intake->refresh();

    $stepsVisible = app(IntakeStepBuilder::class)->build($intake, $version);
    $visibleKeys = array_column($stepsVisible, 'question_key');

    expect($visibleKeys)->toContain('drain_photo');
});

test('livewire string booleans satisfy required checks and allow next', function () {
    $intake = makeAccessibleIntake();

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('activeStepKey', 'outdoor_unit::noise_sensitive')
        ->set('stepIndex', 0) // index wordt via activeStepKey herberekend bij next
        ->set('form.noise_sensitive', ['bool' => '0'])
        ->call('next')
        ->assertSet('showMissing', false)
        ->assertSet('activeStepKey', 'pipe_route::pipe_route_photos');
});

test('single choice auto-advances after selecting an option', function () {
    $intake = makeAccessibleIntake();

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('form.request_reason.text', 'Te warm')
        ->call('next')
        ->assertSet('activeStepKey', 'request::cooling_heating')
        ->set('form.cooling_heating.value', 'cooling')
        ->assertSet('activeStepKey', 'request::indoor_unit_count')
        ->assertSet('saveMessage', 'Opgeslagen')
        ->assertSee('Hoeveel binnenunits wilt u ongeveer?');
});

test('boolean auto-advances after choosing ja or nee', function () {
    $intake = makeAccessibleIntake();

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('activeStepKey', 'outdoor_unit::noise_sensitive')
        ->set('form.noise_sensitive.bool', '0')
        ->assertSet('showMissing', false)
        ->assertSet('activeStepKey', 'pipe_route::pipe_route_photos')
        ->assertSet('saveMessage', 'Opgeslagen');
});

test('short text blur save does not auto-advance', function () {
    $intake = makeAccessibleIntake();

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('form.request_reason.text', 'Te warm')
        ->assertSet('activeStepKey', 'request::request_reason')
        ->assertSet('saveMessage', 'Opgeslagen')
        ->assertSee('Wat is de reden van uw aanvraag?');
});

test('multi choice values update does not auto-advance', function () {
    $intake = makeAccessibleIntake();

    // Geen multi_choice in airco-template; wel het .values-pad van updated() afdekken.
    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('form.request_reason.values', ['a'])
        ->assertSet('activeStepKey', 'request::request_reason')
        ->assertSet('saveMessage', 'Opgeslagen');
});

test('previous still works after an auto-advanced choice', function () {
    $intake = makeAccessibleIntake();

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('form.request_reason.text', 'Te warm')
        ->call('next')
        ->set('form.cooling_heating.value', 'cooling')
        ->assertSet('activeStepKey', 'request::indoor_unit_count')
        ->call('previous')
        ->assertSet('activeStepKey', 'request::cooling_heating')
        ->assertSee('Wilt u koelen, verwarmen of beide?');
});

test('enter on short text advances to the next step', function () {
    $intake = makeAccessibleIntake();

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertSet('activeStepKey', 'request::request_reason')
        ->call('advanceFromEnter', 'request_reason', 'text', 'Te warm op zolder')
        ->assertSet('showMissing', false)
        ->assertSet('activeStepKey', 'request::cooling_heating')
        ->assertSee('Wilt u koelen, verwarmen of beide?');
});

test('enter on number advances to the next step', function () {
    $intake = makeAccessibleIntake();

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('form.request_reason.text', 'Te warm')
        ->call('next')
        ->set('form.cooling_heating.value', 'cooling')
        ->assertSet('activeStepKey', 'request::indoor_unit_count')
        ->call('advanceFromEnter', 'indoor_unit_count', 'number', '1')
        ->assertSet('activeStepKey', 'request::brand_preference');
});

test('wizard resumes on the stored question cursor', function () {
    $intake = makeAccessibleIntake();
    $intake->update([
        'current_section_key' => 'building',
        'current_question_key' => 'ownership',
        'current_section_instance_key' => null,
    ]);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertSet('activeStepKey', 'building::ownership')
        ->assertSee('Is het een koop- of huurwoning?');
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

test('progress percent reaches 100 when all required questions are answered even if optionals are empty', function () {
    $intake = makeAccessibleIntake();
    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();

    // Vul alle verplichte vragen; laat optionele (bijv. build_year) leeg.
    $save = app(SaveIntakeAnswer::class);
    $checker = app(CompletenessChecker::class);
    $store = app(StoreIntakeUpload::class);
    Storage::fake((string) config('filesystems.media', 'local'));

    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1]);

    for ($attempt = 0; $attempt < 40; $attempt++) {
        $intake->refresh();
        $check = $checker->check($intake, $version->fresh(['sections.questions.options', 'sections.questions.rules']));

        if ($check['is_complete']) {
            break;
        }

        foreach ($check['missing'] as $item) {
            $question = $version->sections->flatMap->questions->firstWhere('key', $item['question_key']);
            if ($question === null) {
                continue;
            }

            if ($question->type === QuestionType::Photo) {
                $store->handle(
                    $intake,
                    $item['question_key'],
                    $item['section_instance_key'],
                    UploadedFile::fake()->image($item['question_key'].'.jpg'),
                );
            } elseif ($question->type === QuestionType::Boolean) {
                $save->handle($intake, $item['question_key'], $item['section_instance_key'], ['bool' => false]);
            } elseif ($question->type === QuestionType::Number) {
                $save->handle($intake, $item['question_key'], $item['section_instance_key'], ['number' => 1]);
            } elseif ($question->type === QuestionType::SingleChoice) {
                $value = $question->options->first()?->value ?? 'unknown';
                $save->handle($intake, $item['question_key'], $item['section_instance_key'], ['value' => $value]);
            } else {
                $save->handle($intake, $item['question_key'], $item['section_instance_key'], ['text' => 'ingevuld']);
            }
        }
    }

    $intake->refresh();
    $progress = app(ProgressCalculator::class)->calculate(
        $intake,
        $version->fresh(['sections.questions.rules']),
    );

    expect($progress['percent'])->toBe(100)
        ->and($progress['missing_required'])->toBe([])
        ->and($intake->answers()->where('question_key', 'build_year')->exists())->toBeFalse();
});

test('completion missing list uses readable instance labels', function () {
    $intake = makeAccessibleIntake();
    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();

    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 2]);
    $intake->refresh();

    $check = app(CompletenessChecker::class)->check($intake, $version);
    $roomMissing = collect($check['missing'])
        ->first(fn (array $item): bool => ($item['section_instance_key'] ?? null) === 'room-2');

    expect($roomMissing)->not->toBeNull()
        ->and($roomMissing['instance_label'])->toBe('Ruimtes 2')
        ->and($roomMissing['instance_label'])->not->toBe('room-2');
});

test('clicking a missing item jumps to that wizard step', function () {
    $intake = makeAccessibleIntake();
    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();

    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    $intake->refresh();

    $check = app(CompletenessChecker::class)->check($intake, $version);
    expect($check['missing'])->not->toBeEmpty();

    $target = $check['missing'][0];

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('completionMissing', $check['missing'])
        ->set('showMissing', true)
        ->call('goToMissing', $target['question_key'], $target['section_instance_key'])
        ->assertSet('showMissing', false)
        ->assertSet('activeStepKey', function (string $key) use ($target): bool {
            return str_contains($key, $target['question_key']);
        });
});

test('wizard memoizes intake and version within one request lifecycle', function () {
    $intake = makeAccessibleIntake();

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $wizard = $component->instance();

    $intakeMethod = new ReflectionMethod(IntakeWizard::class, 'intake');
    $versionMethod = new ReflectionMethod(IntakeWizard::class, 'version');
    $stepsMethod = new ReflectionMethod(IntakeWizard::class, 'steps');

    $firstIntake = $intakeMethod->invoke($wizard);
    $secondIntake = $intakeMethod->invoke($wizard);
    $firstVersion = $versionMethod->invoke($wizard);
    $secondVersion = $versionMethod->invoke($wizard);
    $firstSteps = $stepsMethod->invoke($wizard);
    $secondSteps = $stepsMethod->invoke($wizard);

    expect($firstIntake)->toBe($secondIntake)
        ->and($firstVersion)->toBe($secondVersion)
        ->and($firstSteps)->toBe($secondSteps);
});

test('wizard refreshes intake cache after saving an answer', function () {
    $intake = makeAccessibleIntake();

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $wizard = $component->instance();

    $intakeMethod = new ReflectionMethod(IntakeWizard::class, 'intake');
    $before = $intakeMethod->invoke($wizard);

    $component->set('form.request_reason.text', 'Te warm');

    $wizard = $component->instance();
    $after = $intakeMethod->invoke($wizard);

    expect($after)->not->toBe($before)
        ->and($after->answers()->where('question_key', 'request_reason')->exists())->toBeTrue();
});

test('wizard next still advances after request-local caching', function () {
    $intake = makeAccessibleIntake();

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('form.request_reason.text', 'Te warm')
        ->call('next')
        ->assertSet('activeStepKey', 'request::cooling_heating')
        ->assertSee('Wilt u koelen, verwarmen of beide?');
});

test('repeatable room questions become separate steps after unit count', function () {
    $intake = makeAccessibleIntake();
    $version = $intake->templateVersion()->with(['sections.questions'])->firstOrFail();

    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 2]);
    $intake->refresh();

    $steps = app(IntakeStepBuilder::class)->build($intake, $version);
    $roomSteps = array_values(array_filter(
        $steps,
        static fn (array $step): bool => $step['section_key'] === 'rooms',
    ));

    expect($roomSteps)->not->toBeEmpty()
        ->and($roomSteps[0]['section_instance_key'])->toBe('room-1')
        ->and(collect($roomSteps)->pluck('section_instance_key')->unique()->values()->all())
        ->toBe(['room-1', 'room-2']);
});
