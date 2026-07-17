<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\CompleteIntake;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Actions\SubmitIntakeReview;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Domains\Intake\Services\CompletenessChecker;
use App\Domains\Intake\Services\PublishIntakeTemplateFromConfig;
use App\Enums\IntakeStatus;
use App\Enums\QuestionType;
use App\Enums\ReviewDecision;
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

function makePhase5Intake(): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_name' => 'Rapport Klant',
        'customer_email' => 'rapport@example.com',
        'address_line' => 'Voorbeeldstraat 1',
        'address_city' => 'Utrecht',
    ]);
}

/**
 * Fill all currently missing required targets until the intake is complete.
 */
function fillIntakeUntilComplete(Intake $intake): void
{
    $save = app(SaveIntakeAnswer::class);
    $store = app(StoreIntakeUpload::class);
    $checker = app(CompletenessChecker::class);

    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1]);

    for ($attempt = 0; $attempt < 40; $attempt++) {
        $intake->refresh();
        $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
        $check = $checker->check($intake, $version);

        if ($check['is_complete']) {
            return;
        }

        foreach ($check['missing'] as $item) {
            $question = findQuestionByKey($version, $item['question_key']);

            if ($question === null) {
                continue;
            }

            if ($question->type === QuestionType::Photo) {
                $store->handle(
                    $intake,
                    $item['question_key'],
                    $item['section_instance_key'],
                    UploadedFile::fake()->image($item['question_key'].'.jpg', 640, 480),
                );

                continue;
            }

            $save->handle(
                $intake,
                $item['question_key'],
                $item['section_instance_key'],
                sampleAnswerForQuestion($question),
            );
        }
    }

    throw new RuntimeException('Could not fill intake to completion within attempt budget.');
}

function findQuestionByKey(IntakeTemplateVersion $version, string $key): ?IntakeQuestion
{
    foreach ($version->sections as $section) {
        foreach ($section->questions as $question) {
            if ($question->key === $key) {
                return $question;
            }
        }
    }

    return null;
}

/**
 * @return array<string, mixed>
 */
function sampleAnswerForQuestion(IntakeQuestion $question): array
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

test('incomplete intake cannot be completed', function () {
    $intake = makePhase5Intake();

    expect(fn () => app(CompleteIntake::class)->handle($intake))
        ->toThrow(ValidationException::class);

    expect($intake->fresh()->status)->toBe(IntakeStatus::Sent);
});

test('complete intake stores snapshot report and attention points', function () {
    $intake = makePhase5Intake();
    fillIntakeUntilComplete($intake);

    app(SaveIntakeAnswer::class)->handle($intake, 'free_group_known', null, ['value' => 'no']);
    app(SaveIntakeAnswer::class)->handle($intake, 'natural_fall_possible', null, ['bool' => false]);

    $completed = app(CompleteIntake::class)->handle($intake->fresh());

    expect($completed->status)->toBe(IntakeStatus::Completed)
        ->and($completed->completed_at)->not->toBeNull()
        ->and($completed->completeness_snapshot['is_complete'])->toBeTrue()
        ->and($completed->report)->not->toBeNull()
        ->and($completed->report->html)->toContain('Rapport Klant')
        ->and($completed->report->html)->toContain('Testantwoord request_reason')
        ->and($completed->attentionPoints->pluck('code')->all())
        ->toContain('no_free_group')
        ->toContain('condensate_pump_likely');
});

test('customer wizard complete refuses incomplete and succeeds when filled', function () {
    $incomplete = makePhase5Intake();

    Livewire::test(IntakeWizard::class, ['token' => $incomplete->access_token])
        ->call('complete')
        ->assertSet('completed', false)
        ->assertSet('showMissing', true);

    $intake = makePhase5Intake();
    fillIntakeUntilComplete($intake);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->call('complete')
        ->assertSet('completed', true)
        ->assertSee('Bedankt');

    expect($intake->fresh()->status)->toBe(IntakeStatus::Completed);
});

test('template republish does not change pinned completed intake report', function () {
    $intake = makePhase5Intake();
    fillIntakeUntilComplete($intake);
    $pinnedVersionId = $intake->intake_template_version_id;

    app(CompleteIntake::class)->handle($intake->fresh());
    $reportHtml = $intake->fresh()->report->html;

    $config = require database_path('data/templates/airco/v1.php');
    $config['version'] = 2;
    $config['change_notes'] = 'Fase 5 pin-test versie';
    $config['sections'][0]['questions'][0]['label'] = 'GEWIJZIGDE VRAAGLABEL';

    $v2 = app(PublishIntakeTemplateFromConfig::class)->handle($config);

    expect($v2->version)->toBe(2)
        ->and($intake->fresh()->intake_template_version_id)->toBe($pinnedVersionId)
        ->and($intake->fresh()->report->html)->toBe($reportHtml)
        ->and($intake->fresh()->report->html)->not->toContain('GEWIJZIGDE VRAAGLABEL');
});

test('installer can submit a review decision', function () {
    $intake = makePhase5Intake();
    fillIntakeUntilComplete($intake);
    app(CompleteIntake::class)->handle($intake->fresh());

    $reviewer = User::factory()->create();

    $this->actingAs($reviewer)
        ->post(route('intakes.review', $intake), [
            'decision' => ReviewDecision::PrepareQuote->value,
            'site_visit_needed' => '0',
            'enough_information' => '1',
            'summary' => 'Klaar voor offerte.',
        ])
        ->assertRedirect(route('intakes.show', $intake));

    $intake->refresh();

    expect($intake->status)->toBe(IntakeStatus::Reviewed)
        ->and($intake->reviewed_at)->not->toBeNull()
        ->and($intake->review->decision)->toBe(ReviewDecision::PrepareQuote)
        ->and($intake->review->summary)->toBe('Klaar voor offerte.');

    $this->actingAs($reviewer)
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee('Opnamerapport')
        ->assertSee('Offerte voorbereiden');
});

test('submit review action rejects non-completed intakes', function () {
    $intake = makePhase5Intake();
    $reviewer = User::factory()->create();

    expect(fn () => app(SubmitIntakeReview::class)->handle($intake, $reviewer, [
        'decision' => ReviewDecision::PrepareQuote,
    ]))->toThrow(ValidationException::class);
});
