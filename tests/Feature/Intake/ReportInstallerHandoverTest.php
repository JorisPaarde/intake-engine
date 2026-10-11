<?php

declare(strict_types=1);

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\CompleteFollowUpRound;
use App\Domains\Intake\Actions\CompleteIntake;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Actions\SubmitIntakeReview;
use App\Domains\Intake\Jobs\GenerateIntakePdfJob;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\CompletenessChecker;
use App\Domains\Intake\Services\RebuildIntakeReportHtml;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoConnectionType;
use App\Enums\AircoPlacementType;
use App\Enums\FollowUpItemType;
use App\Enums\IntakeStatus;
use App\Enums\QuestionType;
use App\Enums\ReviewDecision;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
    Mail::fake();
    Queue::fake();
    config(['ai.dossier.enabled' => false]);
});

function makeHandoverIntake(): Intake
{
    $user = User::factory()->create(['name' => 'Beoordelaar Test']);
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_name' => 'Handover Klant',
        'customer_email' => 'handover-'.uniqid('', true).'@example.com',
        'address_line' => 'Testdreef 12',
        'address_city' => 'Utrecht',
    ]);
}

function fillHandoverIntakeUntilComplete(Intake $intake): void
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
            $question = findHandoverQuestionByKey($version, $item['question_key']);

            if ($question === null) {
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
                sampleHandoverAnswerForQuestion($question),
            );
        }
    }

    throw new RuntimeException('Could not fill handover intake to completion within attempt budget.');
}

function findHandoverQuestionByKey(IntakeTemplateVersion $version, string $key): ?IntakeQuestion
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
function sampleHandoverAnswerForQuestion(IntakeQuestion $question): array
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

function completeHandoverIntake(): Intake
{
    $intake = makeHandoverIntake();
    fillHandoverIntakeUntilComplete($intake);

    return app(CompleteIntake::class)->handle($intake->fresh());
}

test('stored report includes review decision after SubmitIntakeReview', function () {
    $intake = completeHandoverIntake();
    $reviewer = User::factory()->create([
        'company_id' => $intake->company_id,
        'name' => 'Reviewer Handover',
    ]);

    app(SubmitIntakeReview::class)->handle($intake->fresh(), $reviewer, [
        'decision' => ReviewDecision::SiteVisitNeeded,
        'site_visit_needed' => true,
        'enough_information' => false,
        'summary' => 'Muurdoorvoer onzeker; eerst ter plaatse kijken.',
    ]);

    $html = $intake->fresh()->report?->html ?? '';

    expect($html)->toContain('Locatiebezoek nodig')
        ->and($html)->toContain('Voldoende informatie')
        ->and($html)->toContain('Nee')
        ->and($html)->toContain('Muurdoorvoer onzeker; eerst ter plaatse kijken.')
        ->and($html)->toContain('Reviewer Handover')
        ->and($html)->not->toContain('Beoordeel de aangeleverde aanvulling');

    Queue::assertPushed(GenerateIntakePdfJob::class, fn (GenerateIntakePdfJob $job): bool => $job->intakeId === $intake->id);
});

test('workspace installer note appears in rebuilt report with subject name', function () {
    $intake = completeHandoverIntake();
    $user = User::query()->findOrFail($intake->created_by);
    $survey = app(AircoSurveyService::class);
    $room = $survey->createRoom($intake, $user, [
        'name' => 'Woonkamer',
        'use_type' => 'living_room',
    ]);

    $before = $intake->fresh()->report?->generated_at?->toIso8601String();

    $this->travel(2)->seconds();

    $this->actingAs($user)
        ->post(route('intakes.workspace.notes.store', [$intake, $room->subject]), [
            'text' => 'Notitie over massieve buitenmuur.',
        ])
        ->assertRedirect();

    $report = $intake->fresh()->report;
    $html = $report?->html ?? '';

    expect($html)->toContain('Installateursnotities')
        ->and($html)->toContain('Notitie over massieve buitenmuur.')
        ->and($html)->toContain('Woonkamer')
        ->and($report?->generated_at?->toIso8601String())->not->toBe($before);

    Queue::assertPushed(GenerateIntakePdfJob::class, fn (GenerateIntakePdfJob $job): bool => $job->intakeId === $intake->id);

    // Installateurnotities blijven dossier-only: geen customer_tasks / klantmail-payload.
    expect($intake->fresh()->contributionTasks()->where('prompt', 'like', '%massieve buitenmuur%')->exists())->toBeFalse();
});

test('selected installation option with units and routes appears in report', function () {
    $intake = completeHandoverIntake();
    $user = User::query()->findOrFail($intake->created_by);
    $survey = app(AircoSurveyService::class);

    $room = $survey->createRoom($intake, $user, ['name' => 'Slaapkamer', 'use_type' => 'bedroom']);
    $indoor = $survey->createPlacement($intake, $user, [
        'airco_room_id' => $room->id,
        'type' => AircoPlacementType::IndoorUnit,
        'label' => 'Binnenunit slaapkamer',
    ]);
    $outdoor = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::OutdoorUnit,
        'label' => 'Buitenunit achtergevel',
    ]);

    $selected = $survey->createInstallationOption($intake, $user, [
        'label' => 'Keuze single-split geselecteerd',
        'configuration_type' => AircoConfigurationType::SingleSplit,
        'summary' => 'Eén buitenunit voor deze ruimte.',
        'placement_ids' => [$indoor->id, $outdoor->id],
    ]);
    $other = $survey->createInstallationOption($intake, $user, [
        'label' => 'Keuze singles niet gekozen',
        'configuration_type' => AircoConfigurationType::SingleSplit,
        'summary' => 'Niet de gekozen route.',
        'placement_ids' => [$indoor->id, $outdoor->id],
    ]);

    $survey->createConnection($intake, $user, $selected, [
        'type' => AircoConnectionType::Refrigerant,
        'label' => 'Koelleiding slaapkamer',
        'from_placement_id' => $indoor->id,
        'to_placement_id' => $outdoor->id,
        'status' => AircoConnectionStatus::Proposed,
        'segments' => ['Doorvoer achter unit', 'Langs gevel omlaag'],
    ]);

    $before = $intake->fresh()->report?->generated_at?->toIso8601String();
    $this->travel(2)->seconds();

    $survey->markInstallationOptionFeasible($intake, $user, $selected);
    $survey->selectInstallationOption($intake, $user, $selected);

    $report = $intake->fresh()->report;
    $html = $report?->html ?? '';

    expect($html)->toContain('Keuze single-split geselecteerd')
        ->and($html)->toContain('Binnenunit slaapkamer')
        ->and($html)->toContain('Buitenunit achtergevel')
        ->and($html)->toContain('Koelleiding slaapkamer')
        ->and($html)->toContain('Doorvoer achter unit')
        ->and($html)->not->toContain('Keuze singles niet gekozen')
        ->and($report?->generated_at?->toIso8601String())->not->toBe($before);

    Queue::assertPushed(GenerateIntakePdfJob::class, fn (GenerateIntakePdfJob $job): bool => $job->intakeId === $intake->id);

    // Unselected option must not be presented as the choice even after feasibility mark alone.
    $survey->markInstallationOptionFeasible($intake, $user, $other);
    $htmlAfterFeasibleOnly = $intake->fresh()->report?->html ?? '';
    expect($htmlAfterFeasibleOnly)->not->toContain('Keuze singles niet gekozen');
});

test('review rebuild preserves ai_summary and escapes script in summary and note', function () {
    $intake = completeHandoverIntake();
    $report = $intake->fresh()->report;
    expect($report)->not->toBeNull();

    $meta = is_array($report->meta) ? $report->meta : [];
    $meta['ai_summary'] = [
        'summary' => 'Bewaar dit AI-voorstel bij rebuild.',
        'highlights' => ['Eerste highlight'],
    ];
    $report->update(['meta' => $meta]);

    $reviewer = User::factory()->create(['company_id' => $intake->company_id]);
    app(SubmitIntakeReview::class)->handle($intake->fresh(), $reviewer, [
        'decision' => ReviewDecision::PrepareQuote,
        'site_visit_needed' => false,
        'enough_information' => true,
        'summary' => 'Samenvatting met <script>alert(1)</script> erin.',
    ]);

    $user = User::query()->findOrFail($intake->created_by);
    $room = app(AircoSurveyService::class)->createRoom($intake, $user, [
        'name' => 'Kamer met notitie',
        'use_type' => 'living_room',
    ]);
    $this->actingAs($user)
        ->post(route('intakes.workspace.notes.store', [$intake, $room->subject]), [
            'text' => 'Notitie <script>alert(2)</script> tekst.',
        ])
        ->assertRedirect();

    $fresh = $intake->fresh()->report;
    $html = $fresh?->html ?? '';

    expect($fresh?->meta['ai_summary']['summary'] ?? null)->toBe('Bewaar dit AI-voorstel bij rebuild.')
        ->and($html)->toContain('AI-voorstel (niet bindend)')
        ->and($html)->toContain('Bewaar dit AI-voorstel bij rebuild.')
        ->and($html)->toContain('Offerte voorbereiden')
        ->and($html)->toContain('Samenvatting met &lt;script&gt;alert(1)&lt;/script&gt; erin.')
        ->and($html)->toContain('Notitie &lt;script&gt;alert(2)&lt;/script&gt; tekst.')
        ->and($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->not->toContain('<script>alert(2)</script>');
});

test('follow-up completion rebuilds report and next step changes after final review', function () {
    $intake = completeHandoverIntake();
    $reviewer = User::factory()->create(['company_id' => $intake->company_id]);

    app(SubmitIntakeReview::class)->handle($intake->fresh(), $reviewer, [
        'decision' => ReviewDecision::NeedMoreInfo,
        'follow_up_items' => [
            ['type' => FollowUpItemType::Photo, 'prompt' => 'Maak een foto van de geveldoorvoer.'],
        ],
    ]);

    $round = $intake->fresh()->followUpRounds()->with('items')->firstOrFail();
    $item = $round->items->firstOrFail();
    $disk = (string) config('filesystems.media', 'local');
    $bytes = (string) file_get_contents(base_path('tests/fixtures/klanttest-20261002/meterkast-flow.jpg'));
    $path = 'intakes/'.$intake->uuid.'/follow-up/gevel.jpg';
    Storage::disk($disk)->put($path, $bytes);
    $upload = IntakeUpload::query()->create([
        'intake_id' => $intake->id,
        'question_key' => 'follow_up_'.$item->id,
        'intake_follow_up_item_id' => $item->id,
        'disk' => $disk,
        'path' => $path,
        'original_filename' => 'gevel.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => strlen($bytes),
        'checksum' => hash('sha256', $bytes),
        'sort_order' => 0,
    ]);
    $upload->storeContentAssessment(PhotoContentAssessment::ok(PhotoSubject::Other));
    markTestUploadSatisfied($upload);

    $before = $intake->fresh()->report?->generated_at?->toIso8601String();
    $this->travel(3)->seconds();

    app(CompleteFollowUpRound::class)->handle($intake->fresh(), $round->fresh(), []);

    $afterFollowUp = $intake->fresh()->report;
    expect($afterFollowUp?->generated_at?->toIso8601String())->not->toBe($before)
        ->and($afterFollowUp?->html ?? '')->toContain('Aanvullende informatie');

    $this->travel(3)->seconds();
    app(SubmitIntakeReview::class)->handle($intake->fresh(), $reviewer, [
        'decision' => ReviewDecision::SiteVisitNeeded,
        'site_visit_needed' => true,
        'enough_information' => false,
        'summary' => 'Na aanvulling nog een bezoek nodig.',
    ]);

    $finalHtml = $intake->fresh()->report?->html ?? '';
    expect($finalHtml)->toContain('Locatiebezoek nodig')
        ->and($finalHtml)->toContain('Na aanvulling nog een bezoek nodig.')
        ->and($finalHtml)->not->toContain('Beoordeel de aangeleverde aanvulling');
});

test('RebuildIntakeReportHtml preserves meta.ai_summary when called directly', function () {
    $intake = completeHandoverIntake();
    $report = $intake->fresh()->report;
    $meta = is_array($report?->meta) ? $report->meta : [];
    $meta['ai_summary'] = [
        'summary' => 'Directe rebuild behoudt samenvatting.',
        'highlights' => [],
    ];
    $report?->update(['meta' => $meta]);

    app(RebuildIntakeReportHtml::class)->handle($intake->fresh());

    expect($intake->fresh()->report?->meta['ai_summary']['summary'] ?? null)
        ->toBe('Directe rebuild behoudt samenvatting.')
        ->and($intake->fresh()->report?->html ?? '')->toContain('Directe rebuild behoudt samenvatting.');
});
