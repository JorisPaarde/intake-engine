<?php

declare(strict_types=1);

use App\Domains\AI\Services\TemplateQuestionCatalogBuilder;
use App\Domains\Intake\Actions\CompleteIntake;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Domains\Intake\Services\CompletenessChecker;
use App\Domains\Intake\Support\CustomerConsentPresenter;
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

function makeConsentIntake(ContributionMode $mode = ContributionMode::Customer): Intake
{
    $user = User::factory()->create();

    return app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => $mode,
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

test('must_accept helpers recognise true-only acceptance and reject prefill', function () {
    expect(MustAcceptQuestions::isAccepted(['bool' => false]))->toBeFalse()
        ->and(MustAcceptQuestions::isAccepted(['bool' => true]))->toBeTrue()
        ->and(MustAcceptQuestions::isAccepted(['bool' => '1']))->toBeTrue()
        ->and(MustAcceptQuestions::isAccepted(['bool' => '0']))->toBeFalse()
        ->and(MustAcceptQuestions::isAccepted(['bool' => true], 'ai_catalog'))->toBeFalse()
        ->and(MustAcceptQuestions::isAccepted(['bool' => true], 'request_prefill'))->toBeFalse();
});

test('AI question catalog excludes must-accept keys', function () {
    $intake = makeConsentIntake();
    $catalog = app(TemplateQuestionCatalogBuilder::class)->build($intake);

    $keys = collect($catalog['sections'])
        ->flatMap(static fn (array $section): array => $section['questions'])
        ->pluck('key')
        ->all();

    expect($keys)->not->toContain('privacy_consent')
        ->and($keys)->not->toContain('truth_confirmation');

    foreach (config('intake.must_accept_question_keys', []) as $mustAcceptKey) {
        expect($keys)->not->toContain($mustAcceptKey);
    }
});

test('prefilled true consent is not acceptance and blocks completion', function () {
    $intake = makeConsentIntake();
    fillConsentIntakeComplete($intake);

    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'privacy_consent',
        null,
        ['bool' => true],
        'request_prefill',
    );
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

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->call('goToMissing', 'privacy_consent', null);

    // Prefill must not tick the checkbox in the customer form.
    expect(data_get($component->get('form'), 'privacy_consent.bool'))->toBeNull()
        ->and(data_get($component->get('form'), 'privacy_consent'))->toBeNull();
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

test('ticked consent does not show refusal text when another field is missing', function () {
    $intake = makeConsentIntake();
    fillConsentIntakeComplete($intake);

    // Leave a photo missing elsewhere while consent stays accepted.
    $upload = $intake->uploads()->where('question_key', 'fusebox_photo')->first()
        ?? $intake->uploads()->first();
    expect($upload)->not->toBeNull();
    $upload->delete();

    $html = Livewire::test(IntakeWizard::class, ['token' => $intake->fresh()->access_token])
        ->call('goToMissing', 'privacy_consent', null)
        ->set('form.privacy_consent.bool', true)
        ->set('showMissing', true)
        ->html();

    expect($html)->toContain('data-testid="must-accept-checkbox"')
        ->and($html)->not->toContain(MustAcceptQuestions::refusalMessage())
        ->and($html)->not->toContain('data-testid="must-accept-refusal"');
});

test('unticked consent shows refusal text when showMissing', function () {
    $intake = makeConsentIntake();

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->call('goToMissing', 'privacy_consent', null)
        ->set('form.privacy_consent.bool', false)
        ->set('showMissing', true)
        ->assertSee(MustAcceptQuestions::refusalMessage())
        ->assertSeeHtml('data-testid="must-accept-refusal"');
});

test('consent presenter has three states: given, not given, not asked', function () {
    $presenter = app(CustomerConsentPresenter::class);

    $installer = makeConsentIntake(ContributionMode::Installer);
    $installerConsent = $presenter->present($installer->fresh('answers'));
    expect($installerConsent['asked'])->toBeFalse()
        ->and($installerConsent['given'])->toBeFalse()
        ->and($installerConsent['label'])->toBe('Toestemming klant: niet gevraagd (installateursopname)')
        ->and($installerConsent['detail'])->toBe('niet gevraagd (installateursopname)');

    $hybrid = makeConsentIntake(ContributionMode::Hybrid);
    $hybridConsent = $presenter->present($hybrid->fresh('answers'));
    expect($hybridConsent['asked'])->toBeFalse()
        ->and($hybridConsent['label'])->toBe('Toestemming klant: niet gevraagd (installateursopname)');

    $customer = makeConsentIntake(ContributionMode::Customer);
    $openConsent = $presenter->present($customer->fresh('answers'));
    expect($openConsent['asked'])->toBeTrue()
        ->and($openConsent['given'])->toBeFalse()
        ->and($openConsent['label'])->toBe('Toestemming klant: niet gegeven')
        ->and($openConsent['detail'])->toBe('niet gegeven');

    $answeredAt = now()->setTimezone(config('app.timezone'))->setTime(10, 12);
    app(SaveIntakeAnswer::class)->handle($customer, 'privacy_consent', null, ['bool' => true]);
    $customer->answers()->where('question_key', 'privacy_consent')->update(['answered_at' => $answeredAt]);

    $given = $presenter->present($customer->fresh('answers'));
    expect($given['given'])->toBeTrue()
        ->and($given['label'])->toContain('Toestemming klant: gegeven op')
        ->and($given['label'])->toContain('10:12')
        ->and($given['detail'])->toContain('gegeven op')
        ->and($given['detail'])->not->toContain('Toestemming klant:');

    app(SaveIntakeAnswer::class)->handle($customer, 'privacy_consent', null, ['bool' => false]);
    $refused = $presenter->present($customer->fresh('answers'));
    expect($refused['given'])->toBeFalse()
        ->and($refused['asked'])->toBeTrue()
        ->and($refused['label'])->toBe('Toestemming klant: niet gegeven');
});
