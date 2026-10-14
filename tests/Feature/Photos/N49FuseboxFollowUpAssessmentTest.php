<?php

declare(strict_types=1);

use App\Domains\AI\Actions\AssessFuseboxPhotos;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Jobs\AssessFollowUpFuseboxJob;
use App\Domains\AI\Jobs\SuggestAttentionPointsJob;
use App\Domains\AI\Jobs\SummarizeIntakeJob;
use App\Domains\AI\Jobs\SynthesizeSurveyDossierJob;
use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\CompleteFollowUpRound;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\StoreFollowUpUpload;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Actions\SubmitIntakeReview;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeExternalFact;
use App\Domains\Intake\Models\IntakeFollowUpRound;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\DossierManager;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\ContributionMode;
use App\Enums\FollowUpItemType;
use App\Enums\FollowUpRoundStatus;
use App\Enums\IntakeStatus;
use App\Enums\PhotoAssessmentStatus;
use App\Enums\PhotoUsabilityVerdict;
use App\Enums\ReviewDecision;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
    FakeAiClient::reset();
    Mail::fake();
    Queue::fake();
    config([
        'ai.provider' => 'fake',
        'ai.photo_inference.enabled' => true,
        'ai.dossier.enabled' => false,
    ]);
});

afterEach(function () {
    FakeAiClient::reset();
});

/** @return array{empty_module_space: string, phase: string, confidence: string, evidence: string, retake_instruction: null, detected_subject: string, subject_match: string} */
function n49FuseboxOutput(string $phase = 'three_phase', string $confidence = 'high'): array
{
    return [
        'empty_module_space' => 'visible',
        'phase' => $phase,
        'confidence' => $confidence,
        'detected_subject' => 'fusebox',
        'subject_match' => 'yes',
        'evidence' => 'Drie gekoppelde hoofdschakelaars zijn zichtbaar.',
        'retake_instruction' => null,
    ];
}

/** @return array{user: User, intake: Intake} */
function n49MakeIntake(): array
{
    $user = User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'N49 Fusebox',
        'customer_email' => 'n49@example.com',
        'address_line' => 'Meterkast 1',
        'address_postal_code' => '1000AA',
        'address_house_number' => 1,
        'address_city' => 'Amsterdam',
    ]);
    app(DossierManager::class)->initialize($intake);
    $intake->update(['status' => IntakeStatus::Sent]);

    return ['user' => $user, 'intake' => $intake->fresh()];
}

function n49SeedStaleWizardFusebox(Intake $intake): IntakeUpload
{
    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('meterkast-blur.jpg', 800, 600),
    );

    $upload->update([
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'content_assessment' => PhotoContentAssessment::needsClearer(
            PhotoSubject::Fusebox,
            'Maak een scherpere foto van de hoofdschakelaar.',
        )->toArray(),
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
    ]);

    IntakeExternalFact::query()->create([
        'intake_id' => $intake->id,
        'fact_key' => 'fusebox_photo_assessment',
        'label' => 'Automatische beoordeling meterkastfoto',
        'value' => [
            'phase' => 'unknown',
            'empty_module_space' => 'unknown',
            'confidence' => 'low',
            'evidence' => 'Onvoldoende scherp.',
            'upload_ids' => [$upload->id],
        ],
        'source' => AssessFuseboxPhotos::SOURCE,
        'confidence' => 'low',
        'captured_at' => now(),
    ]);

    IntakeAnswer::query()->create([
        'intake_id' => $intake->id,
        'question_key' => 'fusebox_clarity',
        'value' => ['value' => 'needs_clearer_photo'],
        'prefill_source' => 'ai_photo',
        'answered_at' => now(),
    ]);

    return $upload;
}

/** @return array{intake: Intake, round: IntakeFollowUpRound, old: IntakeUpload, new: IntakeUpload} */
function n49Evidence(): array
{
    ['user' => $user, 'intake' => $intake] = n49MakeIntake();
    $old = n49SeedStaleWizardFusebox($intake);
    $round = app(CreateCustomerContributionRequest::class)->handle($intake, $user, [[
        'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een duidelijke foto van de meterkast.',
        'decision_area_key' => 'power',
        'photo_task_subtype' => 'fusebox',
    ]]);
    $new = app(StoreFollowUpUpload::class)->handle($intake->fresh(), $round->items->firstOrFail(),
        UploadedFile::fake()->image('meterkast-nieuw.jpg', 1600, 1200));
    $new->update([
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'content_assessment' => PhotoContentAssessment::ok(PhotoSubject::Fusebox)->toArray(),
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
    ]);

    return compact('intake', 'round', 'old', 'new');
}

test('N49: versturen queued analyse zonder AI-call of wijzigingen aan fotorecords', function () {
    ['intake' => $intake, 'round' => $round, 'old' => $old, 'new' => $new] = n49Evidence();
    Bus::fake();
    $historical = AiRun::query()->create([
        'intake_id' => $intake->id,
        'upload_id' => $old->id,
        'type' => AiRunType::PhotoAssessment,
        'status' => AiRunStatus::Succeeded,
        'provider' => 'fake',
        'prompt_version' => 'fusebox-assessment-v4',
        'input_hash' => str_repeat('a', 64),
        'output' => n49FuseboxOutput('unknown', 'low'),
    ]);
    $historicalState = $historical->fresh()->getAttributes();
    $oldState = $old->fresh()->getAttributes();
    $newState = $new->fresh()->getAttributes();
    $runCount = AiRun::query()->count();
    app(CompleteFollowUpRound::class)->handle($intake->fresh(), $round->fresh(), []);
    Bus::assertDispatched(AssessFollowUpFuseboxJob::class, fn ($job) => $job->intakeId === $intake->id);
    expect(AiRun::query()->count())->toBe($runCount)
        ->and($old->fresh()->getAttributes())->toBe($oldState)
        ->and($new->fresh()->getAttributes())->toBe($newState);
    FakeAiClient::alwaysReturn(n49FuseboxOutput());
    $job = new AssessFollowUpFuseboxJob($intake->id);
    $job->handle(app(AssessFuseboxPhotos::class));
    $fact = $intake->externalFacts()->where('fact_key', 'fusebox_photo_assessment')->firstOrFail();
    expect($fact->value['phase'])->toBe('three_phase')
        ->and($fact->value['upload_ids'])->toBe([$new->id])
        ->and($historical->fresh()->getAttributes())->toBe($historicalState)
        ->and($old->fresh()->getAttributes())->toBe($oldState)
        ->and($new->fresh()->getAttributes())->toBe($newState)
        ->and($intake->answers()->where('question_key', 'fusebox_clarity')->firstOrFail()->value)->toBe(['value' => 'clear']);
    $factsBefore = $fact->getAttributes();
    $count = AiRun::query()->count();
    $job->handle(app(AssessFuseboxPhotos::class));
    expect(AiRun::query()->count())->toBe($count)
        ->and($fact->fresh()->getAttributes())->toBe($factsBefore);
});

test('N49: reviewed en gesloten opnames krijgen geen analyse of wijzigingen', function ($status, $reviewedAt) {
    ['intake' => $intake, 'round' => $round, 'old' => $old, 'new' => $new] = n49Evidence();
    $round->update(['status' => FollowUpRoundStatus::Completed, 'completed_at' => now()]);
    $intake->update(['status' => $status, 'reviewed_at' => $reviewedAt ? now() : null]);
    $fact = $intake->externalFacts()->where('fact_key', 'fusebox_photo_assessment')->firstOrFail();
    $before = $fact->getAttributes();
    $count = AiRun::query()->count();
    (new AssessFollowUpFuseboxJob($intake->id))->handle(app(AssessFuseboxPhotos::class));
    expect(AiRun::query()->count())->toBe($count)
        ->and($fact->fresh()->getAttributes())->toBe($before);
})->with([
    'reviewed' => [IntakeStatus::Reviewed, false],
    'completed reviewed' => [IntakeStatus::Completed, true],
    'closed' => [IntakeStatus::Cancelled, false],
]);

test('N49: beoordeling tijdens AI-call voorkomt toepassen van de nieuwe analyse', function () {
    ['intake' => $intake, 'round' => $round, 'new' => $new] = n49Evidence();
    $round->update(['status' => FollowUpRoundStatus::Completed, 'completed_at' => now()]);
    $fact = $intake->externalFacts()->where('fact_key', 'fusebox_photo_assessment')->firstOrFail();
    $before = $fact->getAttributes();
    $photoBefore = $new->fresh()->getAttributes();
    FakeAiClient::respondUsing(function () use ($intake): array {
        $intake->update(['status' => IntakeStatus::Reviewed, 'reviewed_at' => now()]);

        return n49FuseboxOutput();
    });
    (new AssessFollowUpFuseboxJob($intake->id))->handle(app(AssessFuseboxPhotos::class));
    expect($fact->fresh()->getAttributes())->toBe($before)
        ->and($new->fresh()->getAttributes())->toBe($photoBefore)
        ->and($intake->answers()->where('question_key', 'fusebox_clarity')->firstOrFail()->value)->toBe(['value' => 'needs_clearer_photo']);
});

test('N49: verkeerde foto queued geen meterkastanalyse', function () {
    ['intake' => $intake, 'round' => $round, 'new' => $new] = n49Evidence();
    $new->update(['content_assessment' => PhotoContentAssessment::wrongSubject(PhotoSubject::Fusebox, PhotoSubject::Room)
        ->withCustomerAcceptedMismatch()->toArray()]);
    Bus::fake();
    app(CompleteFollowUpRound::class)->handle($intake->fresh(), $round->fresh(), []);
    Bus::assertNotDispatched(AssessFollowUpFuseboxJob::class);
});

test('N49: afgerond-gereviewde opname verstuurt geen nieuwe analyse', function () {
    ['intake' => $intake, 'round' => $round] = n49Evidence();
    $intake->update(['status' => IntakeStatus::Reviewed, 'reviewed_at' => now()]);
    Bus::fake();
    expect(fn () => app(CompleteFollowUpRound::class)->handle($intake->fresh(), $round->fresh(), []))
        ->toThrow(ValidationException::class);
    Bus::assertNotDispatched(AssessFollowUpFuseboxJob::class);
    expect($round->fresh()->status)->toBe(FollowUpRoundStatus::Open);
});

test('N49: nieuwste geldige foto wint en menselijke antwoorden blijven behouden', function () {
    ['intake' => $intake, 'round' => $round, 'new' => $first] = n49Evidence();
    $round->update(['status' => FollowUpRoundStatus::Completed, 'completed_at' => now()]);
    FakeAiClient::alwaysReturn(n49FuseboxOutput('one_phase'));
    $job = new AssessFollowUpFuseboxJob($intake->id);
    $job->handle(app(AssessFuseboxPhotos::class));
    $firstRun = AiRun::query()->where('upload_id', $first->id)->firstOrFail();
    $firstRunState = $firstRun->getAttributes();
    $answer = $intake->answers()->where('question_key', 'fusebox_clarity')->firstOrFail();
    $answer->update(['prefill_source' => 'installer', 'value' => ['value' => 'needs_clearer_photo']]);
    $humanState = $answer->fresh()->getAttributes();

    $next = $intake->followUpRounds()->create([
        'round_number' => 2,
        'requested_by' => $round->requested_by,
        'status' => FollowUpRoundStatus::Open,
        'sent_at' => now(),
    ]);
    $item = $next->items()->create(['type' => FollowUpItemType::Photo, 'prompt' => 'Maak een meterkastfoto.']);
    $second = app(StoreFollowUpUpload::class)->handle($intake->fresh(), $item,
        UploadedFile::fake()->image('meterkast-laatste.jpg', 1600, 1200));
    $second->update([
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'content_assessment' => PhotoContentAssessment::ok(PhotoSubject::Fusebox)->toArray(),
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
    ]);
    $next->update(['status' => FollowUpRoundStatus::Completed, 'completed_at' => now()]);
    FakeAiClient::alwaysReturn(n49FuseboxOutput('three_phase'));
    $job->handle(app(AssessFuseboxPhotos::class));
    $fact = $intake->externalFacts()->where('fact_key', 'fusebox_photo_assessment')->firstOrFail();
    expect($fact->value['phase'])->toBe('three_phase')
        ->and($fact->value['upload_ids'])->toBe([$second->id])
        ->and($firstRun->fresh()->getAttributes())->toBe($firstRunState)
        ->and($answer->fresh()->getAttributes())->toBe($humanState);
});

test('N49: hoofdflow NeedMoreInfo → klant rondt af met nieuwe meterkastfoto queued de job', function () {
    ['user' => $user, 'intake' => $intake] = n49MakeIntake();
    n49SeedStaleWizardFusebox($intake);
    $intake->update(['status' => IntakeStatus::Completed]);
    app(SubmitIntakeReview::class)->handle($intake->fresh(), $user, [
        'decision' => ReviewDecision::NeedMoreInfo->value,
        'follow_up_items' => [['type' => FollowUpItemType::Photo, 'prompt' => 'Maak een duidelijke foto van de meterkast.']],
    ]);
    $intake = $intake->fresh();
    expect($intake->status)->toBe(IntakeStatus::AwaitingCustomer)->and($intake->reviewed_at)->not->toBeNull();
    $round = $intake->followUpRounds()->with('items')->firstOrFail();
    $new = app(StoreFollowUpUpload::class)->handle($intake, $round->items->firstOrFail(),
        UploadedFile::fake()->image('meterkast-nieuw.jpg', 1600, 1200));
    $new->update([
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'content_assessment' => PhotoContentAssessment::ok(PhotoSubject::Fusebox)->toArray(),
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
    ]);
    Bus::fake();
    app(CompleteFollowUpRound::class)->handle($intake->fresh(), $round->fresh(), []);
    Bus::assertChained([AssessFollowUpFuseboxJob::class, SummarizeIntakeJob::class, SuggestAttentionPointsJob::class, SynthesizeSurveyDossierJob::class]);
});

test('N49: falende meterkastjob blokkeert de rest van de keten niet', function () {
    ['intake' => $intake] = n49Evidence();
    Queue::fake();
    $job = (new AssessFollowUpFuseboxJob($intake->id))->chain([new SummarizeIntakeJob($intake->id)]);
    $job->failed(new MaxAttemptsExceededException('x'));
    Queue::assertPushed(SummarizeIntakeJob::class);
});
