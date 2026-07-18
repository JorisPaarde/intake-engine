<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\CompleteIntake;
use App\Domains\Intake\Actions\GenerateIntakePdf;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Jobs\GenerateIntakePdfJob;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Domains\Intake\Services\CompletenessChecker;
use App\Enums\IntakeStatus;
use App\Enums\QuestionType;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
});

function makePdfIntake(): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_name' => 'PDF Klant',
        'customer_email' => 'pdf@example.com',
        'address_line' => 'Pdfstraat 1',
        'address_city' => 'Utrecht',
    ]);
}

function fillPdfIntake(Intake $intake): void
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
            $question = findPdfQuestion($version, $item['question_key']);

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
                samplePdfAnswer($question),
            );
        }
    }

    throw new RuntimeException('Could not fill intake for PDF test.');
}

function findPdfQuestion(IntakeTemplateVersion $version, string $key): ?IntakeQuestion
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
function samplePdfAnswer(IntakeQuestion $question): array
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

test('completing an intake dispatches the PDF generation job', function () {
    Queue::fake();

    $intake = makePdfIntake();
    fillPdfIntake($intake);

    app(CompleteIntake::class)->handle($intake->fresh());

    Queue::assertPushed(GenerateIntakePdfJob::class, function (GenerateIntakePdfJob $job) use ($intake): bool {
        return $job->intakeId === $intake->id;
    });
});

test('generate intake pdf stores a downloadable file from HTML report', function () {
    $disk = (string) config('filesystems.media', 'local');
    $intake = makePdfIntake();
    fillPdfIntake($intake);

    app(CompleteIntake::class)->handle($intake->fresh());
    $intake->refresh();

    $report = app(GenerateIntakePdf::class)->handle($intake);

    expect($report)->not->toBeNull()
        ->and($report->hasPdf())->toBeTrue()
        ->and($report->pdf_disk)->toBe($disk)
        ->and(Storage::disk($disk)->exists((string) $report->pdf_path))->toBeTrue();

    $binary = Storage::disk($disk)->get((string) $report->pdf_path);
    expect($binary)->toStartWith('%PDF');

    expect(
        IntakeActivityEvent::query()
            ->where('intake_id', $intake->id)
            ->where('event', 'report_pdf_generated')
            ->exists()
    )->toBeTrue();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('intakes.pdf', $intake))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

test('installer can queue pdf regeneration from the show page', function () {
    Queue::fake();

    $intake = makePdfIntake();
    fillPdfIntake($intake);
    app(CompleteIntake::class)->handle($intake->fresh());

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('intakes.pdf.regenerate', $intake))
        ->assertRedirect(route('intakes.show', $intake));

    Queue::assertPushed(GenerateIntakePdfJob::class);
});
