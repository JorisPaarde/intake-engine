<?php

declare(strict_types=1);

use App\Domains\AI\Actions\SummarizeIntake;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Clients\HeuristicAiClient;
use App\Domains\AI\Contracts\AiClientInterface;
use App\Domains\AI\Jobs\SuggestAttentionPointsJob;
use App\Domains\AI\Jobs\SummarizeIntakeJob;
use App\Domains\AI\Models\AiRun;
use App\Domains\Intake\Actions\CompleteIntake;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAttentionPoint;
use App\Domains\Intake\Models\IntakeExternalFact;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Domains\Intake\Services\CompletenessChecker;
use App\Enums\AiRunStatus;
use App\Enums\AttentionPointSource;
use App\Enums\AttentionPointStatus;
use App\Enums\IntakeStatus;
use App\Enums\QuestionType;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
    FakeAiClient::reset();
    config(['ai.provider' => 'fake']);
});

afterEach(function () {
    FakeAiClient::reset();
});

function makeAiIntake(): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_name' => 'AI Klant',
        'customer_email' => 'ai@example.com',
        'address_line' => 'Testlaan 2',
        'address_city' => 'Amsterdam',
    ]);
}

function fillAiIntakeUntilComplete(Intake $intake): void
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
            $question = findAiQuestionByKey($version, $item['question_key']);

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
                sampleAiAnswerForQuestion($question),
            );
        }
    }

    throw new RuntimeException('Could not fill intake to completion within attempt budget.');
}

function findAiQuestionByKey(IntakeTemplateVersion $version, string $key): ?IntakeQuestion
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
function sampleAiAnswerForQuestion(IntakeQuestion $question): array
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

test('complete intake dispatches summarize job and still finishes when AI fails', function () {
    Queue::fake();
    FakeAiClient::alwaysFail('provider down');

    $intake = makeAiIntake();
    fillAiIntakeUntilComplete($intake);

    $completed = app(CompleteIntake::class)->handle($intake->fresh());

    expect($completed->status)->toBe(IntakeStatus::Completed)
        ->and($completed->report)->not->toBeNull()
        ->and($completed->report->html)->toContain('AI Klant')
        ->and($completed->report->html)->not->toContain('AI-voorstel');

    Queue::assertPushed(SummarizeIntakeJob::class, fn (SummarizeIntakeJob $job): bool => $job->intakeId === $completed->id);
    Queue::assertPushed(SuggestAttentionPointsJob::class, fn (SuggestAttentionPointsJob $job): bool => $job->intakeId === $completed->id);

    $run = app(SummarizeIntake::class)->handle($completed->fresh());

    expect($run->status)->toBe(AiRunStatus::Failed)
        ->and($run->error_message)->toContain('provider down')
        ->and($completed->fresh()->report->html)->not->toContain('AI-voorstel');
});

test('attention point jobs for one intake cannot overlap', function () {
    $middleware = (new SuggestAttentionPointsJob(42))->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class);
});

test('successful AI summary is attached to the HTML report', function () {
    FakeAiClient::alwaysReturn([
        'summary' => 'Samenvatting voor de installateur over deze airco-aanvraag.',
        'highlights' => [
            'Controleer de meterkastfoto.',
            'Plan eventueel een locatiebezoek.',
        ],
    ]);

    $intake = makeAiIntake();
    fillAiIntakeUntilComplete($intake);
    app(CompleteIntake::class)->handle($intake->fresh());

    // Job may already have run if queue sync; call action explicitly for certainty.
    $run = app(SummarizeIntake::class)->handle($intake->fresh());

    expect($run->status)->toBe(AiRunStatus::Succeeded)
        ->and(AiRun::query()->where('intake_id', $intake->id)->count())->toBeGreaterThan(0);

    $report = $intake->fresh()->report;

    expect($report->html)->toContain('AI-voorstel (niet bindend)')
        ->and($report->html)->toContain('Samenvatting voor de installateur')
        ->and($report->meta['ai_summary']['summary'] ?? null)->toContain('Samenvatting voor de installateur');
});

test('summary rebuild reloads authoritative attention points after a concurrent acceptance', function () {
    FakeAiClient::alwaysReturn([
        'summary' => 'Actuele samenvatting.',
        'highlights' => ['Controleer het actuele aandachtspunt.'],
    ]);

    $intake = makeAiIntake();
    fillAiIntakeUntilComplete($intake);
    app(CompleteIntake::class)->handle($intake->fresh());

    $point = IntakeAttentionPoint::query()->create([
        'intake_id' => $intake->id,
        'source' => AttentionPointSource::Ai,
        'code' => 'concurrent_acceptance',
        'label' => 'Recent geaccepteerd aandachtspunt',
        'status' => AttentionPointStatus::Proposed,
        'ai_confidence' => 'high',
        'evidence' => [['source_type' => 'answer', 'reference' => 'free_group_known']],
    ]);
    $staleIntake = $intake->fresh();
    $staleIntake->load('attentionPoints');
    $point->update(['status' => AttentionPointStatus::Accepted]);

    $run = app(SummarizeIntake::class)->handle($staleIntake);

    expect($run->error_message)->toBeNull()
        ->and($run->status)->toBe(AiRunStatus::Succeeded)
        ->and($point->fresh()->status)->toBe(AttentionPointStatus::Accepted)
        ->and($intake->fresh()->report->html)->toContain('Recent geaccepteerd aandachtspunt');
});

test('heuristic provider produces a local summary without external API', function () {
    config(['ai.provider' => 'heuristic']);
    $this->app->forgetInstance(AiClientInterface::class);
    $this->app->bind(
        AiClientInterface::class,
        fn () => new HeuristicAiClient,
    );

    $intake = makeAiIntake();
    fillAiIntakeUntilComplete($intake);
    app(CompleteIntake::class)->handle($intake->fresh());

    $run = app(SummarizeIntake::class)->handle($intake->fresh());

    expect($run->status)->toBe(AiRunStatus::Succeeded)
        ->and($run->provider)->toBe('heuristic')
        ->and($intake->fresh()->report->html)->toContain('AI-voorstel (niet bindend)');
});

test('external summary payload strips internal ids and sensitive facts recursively', function () {
    FakeAiClient::alwaysReturn([
        'summary' => 'Veilige technische samenvatting.',
        'highlights' => ['Geen interne identificaties gedeeld.'],
    ]);
    $intake = makeAiIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'free_group_known', null, ['value' => 'no']);
    $intake->answers()->where('question_key', 'free_group_known')->firstOrFail()->update([
        'value' => ['value' => 'no', 'upload_ids' => [987654]],
    ]);
    IntakeExternalFact::query()->create([
        'intake_id' => $intake->id,
        'fact_key' => 'building_type_inference',
        'label' => 'Woningtype',
        'value' => ['option' => 'corner', 'bag_building_id' => 'secret', 'upload_ids' => [123]],
        'source' => 'PDOK',
        'source_reference' => 'secret-reference',
        'confidence' => 'high',
        'captured_at' => now(),
    ]);
    IntakeExternalFact::query()->create([
        'intake_id' => $intake->id,
        'fact_key' => 'location',
        'label' => 'Locatie',
        'value' => ['latitude' => 52.1, 'longitude' => 4.3],
        'source' => 'PDOK',
        'source_reference' => 'secret-location',
        'confidence' => 'high',
        'captured_at' => now(),
    ]);

    app(SummarizeIntake::class)->handle($intake->fresh());
    $encoded = json_encode(FakeAiClient::lastRequest()?->input, JSON_THROW_ON_ERROR);

    expect($encoded)->not->toContain(
        'upload_ids',
        'bag_building_id',
        'secret-reference',
        'secret-location',
        'latitude',
        'longitude',
    );
});
