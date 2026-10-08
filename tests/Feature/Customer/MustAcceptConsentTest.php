<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\CompleteIntake;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Domains\Intake\Services\CompletenessChecker;
use App\Domains\Intake\Support\MustAcceptQuestions;
use App\Enums\ContributionMode;
use App\Enums\QuestionType;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
});

function makeConsentIntake(): Intake
{
    $user = User::factory()->create();

    return app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Customer,
        'customer_name' => 'Toestemming Test',
        'customer_email' => 'toestemming@example.com',
        'address_line' => 'Teststraat 1',
        'address_postal_code' => '1234AB',
        'address_house_number' => 1,
        'address_city' => 'Amsterdam',
    ]);
}

/**
 * @return array<string, mixed>
 */
function consentSampleAnswer(IntakeQuestion $question): array
{
    return match ($question->type) {
        QuestionType::ShortText, QuestionType::LongText => ['text' => 'Testantwoord '.$question->key],
        QuestionType::Number => ['number' => 1],
        QuestionType::SingleChoice => ['value' => $question->options->first()?->value ?? 'yes'],
        QuestionType::MultiChoice => ['values' => [$question->options->first()?->value ?? 'a']],
        QuestionType::Boolean => ['bool' => true],
        QuestionType::Photo => ['upload_ids' => []],
    };
}

function fillConsentIntakeComplete(Intake $intake): void
{
    $save = app(SaveIntakeAnswer::class);
    $store = app(StoreIntakeUpload::class);
    $checker = app(CompletenessChecker::class);

    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1]);

    for ($attempt = 0; $attempt < 50; $attempt++) {
        $intake->refresh();
        $version = $intake->templateVersion()
            ->with(['sections.questions.options', 'sections.questions.rules'])
            ->firstOrFail();
        $check = $checker->check($intake, $version);

        if ($check['is_complete']) {
            return;
        }

        foreach ($check['missing'] as $item) {
            $question = null;
            foreach ($version->sections as $section) {
                foreach ($section->questions as $candidate) {
                    if ($candidate->key === $item['question_key']) {
                        $question = $candidate;
                        break 2;
                    }
                }
            }

            if (! $question instanceof IntakeQuestion) {
                continue;
            }

            if ($question->type === QuestionType::Photo) {
                markTestUploadSatisfied($store->handle(
                    $intake,
                    $item['question_key'],
                    $item['section_instance_key'],
                    UploadedFile::fake()->image($item['question_key'].'.jpg', 1280, 960),
                ));

                continue;
            }

            $save->handle(
                $intake,
                $item['question_key'],
                $item['section_instance_key'],
                consentSampleAnswer($question),
            );
        }
    }

    throw new RuntimeException('Could not fill consent intake to completion.');
}

test('must_accept helpers recognise keys and true-only acceptance', function () {
    expect(MustAcceptQuestions::isKey('privacy_consent'))->toBeTrue()
        ->and(MustAcceptQuestions::isKey('truth_confirmation'))->toBeTrue()
        ->and(MustAcceptQuestions::isAccepted(['bool' => false]))->toBeFalse()
        ->and(MustAcceptQuestions::isAccepted(['bool' => true]))->toBeTrue()
        ->and(MustAcceptQuestions::isAccepted(['bool' => '1']))->toBeTrue()
        ->and(MustAcceptQuestions::isAccepted(['bool' => '0']))->toBeFalse();
});

test('privacy_consent false blocks completeness and CompleteIntake', function () {
    $intake = makeConsentIntake();
    fillConsentIntakeComplete($intake);

    app(SaveIntakeAnswer::class)->handle($intake, 'privacy_consent', null, ['bool' => false]);
    $intake->refresh();

    /** @var IntakeTemplateVersion $version */
    $version = $intake->templateVersion()
        ->with(['sections.questions.options', 'sections.questions.rules'])
        ->firstOrFail();
    $check = app(CompletenessChecker::class)->check($intake, $version);

    expect($check['is_complete'])->toBeFalse();
    $privacyMissing = collect($check['missing'])->firstWhere('question_key', 'privacy_consent');
    expect($privacyMissing)->not->toBeNull()
        ->and($privacyMissing['reason'])->toBe('must_accept');

    expect(fn () => app(CompleteIntake::class)->handle($intake))
        ->toThrow(ValidationException::class);
});

test('truth_confirmation false blocks CompleteIntake', function () {
    $intake = makeConsentIntake();
    fillConsentIntakeComplete($intake);

    app(SaveIntakeAnswer::class)->handle($intake, 'truth_confirmation', null, ['bool' => false]);

    expect(fn () => app(CompleteIntake::class)->handle($intake->fresh()))
        ->toThrow(ValidationException::class);
});

test('accepted consent allows wizard completion', function () {
    $intake = makeConsentIntake();
    fillConsentIntakeComplete($intake);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->call('complete')
        ->assertSet('completed', true);

    expect($intake->fresh()->completed_at)->not->toBeNull();
});

test('wizard shows checkbox UI for privacy_consent', function () {
    $intake = makeConsentIntake();
    $version = $intake->templateVersion()->with('sections.questions')->firstOrFail();
    $sectionKey = null;
    foreach ($version->sections as $section) {
        foreach ($section->questions as $question) {
            if ($question->key === 'privacy_consent') {
                $sectionKey = $section->key;
                break 2;
            }
        }
    }

    expect($sectionKey)->not->toBeNull();

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->call('goToMissing', 'privacy_consent', null)
        ->assertSee('Ik geef toestemming')
        ->assertSeeHtml('data-testid="must-accept-checkbox"');
});
