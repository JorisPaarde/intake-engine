<?php

declare(strict_types=1);

use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\PhotoAssessmentLifecycle;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeFollowUpItem;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\DossierManager;
use App\Enums\AiRunType;
use App\Enums\FollowUpItemType;
use App\Enums\IntakeStatus;
use App\Enums\PhotoAssessmentStatus;
use App\Enums\PhotoUsabilityVerdict;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
    config([
        'ai.provider' => 'fake',
        'ai.photo_inference.enabled' => true,
        'ai.tracing.enabled' => true,
        'ai.photo_assessment.watchdog_after_seconds' => 180,
        'ai.photo_assessment.watchdog_max_attempts' => 3,
        'ai.photo_assessment.watchdog_max_age_hours' => 24,
        'ai.photo_assessment.watchdog_max_per_run' => 20,
    ]);
    FakeAiClient::reset();
    Queue::fake([AssessUploadedPhotoJob::class]);
});

function bl133MakeIntake(IntakeStatus $status = IntakeStatus::InProgress): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
        'status' => $status,
        'access_token' => 'bl133'.bin2hex(random_bytes(28)),
    ]);

    app(DossierManager::class)->initialize($intake);

    return $intake->fresh();
}

/**
 * @return array{0: Intake, 1: IntakeFollowUpItem}
 */
function bl133MakeFollowUpIntake(IntakeStatus $status = IntakeStatus::InProgress): array
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
        'status' => $status,
        'access_token' => 'bl133fu'.bin2hex(random_bytes(27)),
    ]);

    app(DossierManager::class)->initialize($intake);

    $round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [[
        'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een duidelijke foto van de meterkast voor de stroomtoevoer.',
        'decision_area_key' => 'power',
    ]]);

    $item = $round->items()->firstOrFail();
    ContributionTask::query()
        ->where('intake_follow_up_item_id', $item->id)
        ->update(['decision_area_key' => 'power']);

    return [$intake->fresh(), $item->fresh()];
}

function bl133PendingUpload(Intake $intake, array $overrides = []): IntakeUpload
{
    $defaults = [
        'intake_id' => $intake->id,
        'question_key' => 'outdoor_unit_photo',
        'disk' => (string) config('filesystems.media', 'local'),
        'path' => 'intakes/test/bl133-'.uniqid('', true).'.jpg',
        'original_filename' => 'bl133.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 1000,
        'checksum' => hash('sha256', uniqid('bl133-', true)),
        'sort_order' => 1,
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
        'assessment_status' => PhotoAssessmentStatus::Pending,
        'assessment_queued_at' => now()->subMinutes(5),
        'assessment_attempts' => 0,
    ];

    $createdAt = $overrides['created_at'] ?? now()->subMinutes(10);
    $updatedAt = $overrides['updated_at'] ?? now()->subMinutes(5);
    unset($overrides['created_at'], $overrides['updated_at']);

    $upload = IntakeUpload::query()->create(array_merge($defaults, $overrides));
    $upload->forceFill([
        'created_at' => $createdAt,
        'updated_at' => $updatedAt,
    ])->saveQuietly();

    return $upload->fresh();
}

test('legacy pending backfill (attempts=0) wordt niet herqueued door watchdog', function () {
    $intake = bl133MakeIntake(IntakeStatus::Completed);

    $upload = bl133PendingUpload($intake, [
        'assessment_attempts' => 0,
        'created_at' => now()->subDays(30),
        'assessment_queued_at' => now()->subMinutes(5),
    ]);

    Artisan::call('photos:requeue-pending-assessments', [
        '--minutes' => 3,
        '--max-attempts' => 3,
    ]);

    $upload->refresh();
    expect($upload->assessment_status)->toBe(PhotoAssessmentStatus::Pending)
        ->and($upload->assessment_attempts)->toBe(0);

    Queue::assertNothingPushed();
});

test('verse pipeline-upload stuck in pending na 3 min wordt herqueued', function () {
    [$intake, $item] = bl133MakeFollowUpIntake(IntakeStatus::InProgress);

    $upload = bl133PendingUpload($intake, [
        'question_key' => 'follow_up_'.$item->id,
        'intake_follow_up_item_id' => $item->id,
        'assessment_attempts' => 1,
        'assessment_queued_at' => now()->subMinutes(5),
        'created_at' => now()->subMinutes(10),
    ]);

    Artisan::call('photos:requeue-pending-assessments', [
        '--minutes' => 3,
        '--max-attempts' => 3,
    ]);

    $upload->refresh();
    expect($upload->assessment_attempts)->toBe(2)
        ->and($upload->assessment_status)->toBe(PhotoAssessmentStatus::Pending);

    Queue::assertPushed(AssessUploadedPhotoJob::class, 1);
});

test('submitted intake krijgt geen AI van AssessUploadedPhotoJob — alleen terminal status', function () {
    [$intake, $item] = bl133MakeFollowUpIntake(IntakeStatus::InProgress);

    $intake->forceFill(['status' => IntakeStatus::Completed, 'completed_at' => now()])->save();

    $upload = bl133PendingUpload($intake->fresh(), [
        'question_key' => 'follow_up_'.$item->id,
        'intake_follow_up_item_id' => $item->id,
        'assessment_attempts' => 1,
        'assessment_queued_at' => now(),
    ]);

    $answerCountBefore = $intake->fresh()->answers()->count();

    FakeAiClient::reset();
    FakeAiClient::alwaysReturn([
        'detected_subject' => 'fusebox',
        'subject_match' => 'yes',
        'confidence' => 'high',
        'evidence' => 'Meterkast zichtbaar.',
        'free_group' => 'unknown',
        'phase' => 'unknown',
    ]);

    runAssessUploadedPhotoJob($upload->id);

    $upload->refresh();
    expect($upload->assessment_status?->isTerminal())->toBeTrue()
        ->and($upload->assessment_status)->toBe(PhotoAssessmentStatus::NotAssessed)
        ->and($intake->fresh()->answers()->count())->toBe($answerCountBefore)
        ->and(FakeAiClient::lastRequest())->toBeNull();

    expect(
        AiRun::query()
            ->where('intake_id', $intake->id)
            ->where('type', AiRunType::PhotoAssessment)
            ->where('upload_id', $upload->id)
            ->count()
    )->toBe(0);
});

test('watchdog slaat submitted intakes over en zet ze terminal', function () {
    $intake = bl133MakeIntake(IntakeStatus::Completed);

    $upload = bl133PendingUpload($intake, [
        'assessment_attempts' => 1,
        'assessment_queued_at' => now()->subMinutes(5),
        'created_at' => now()->subMinutes(10),
    ]);

    Artisan::call('photos:requeue-pending-assessments', [
        '--minutes' => 3,
        '--max-attempts' => 3,
    ]);

    $upload->refresh();
    expect($upload->assessment_status?->isTerminal())->toBeTrue();
    Queue::assertNothingPushed();
});

test('watchdog respecteert max-per-run cap', function () {
    config(['ai.photo_assessment.watchdog_max_per_run' => 2]);

    [$intake, $item] = bl133MakeFollowUpIntake(IntakeStatus::InProgress);

    $uploads = [];
    for ($i = 0; $i < 5; $i++) {
        $uploads[] = bl133PendingUpload($intake, [
            'question_key' => 'follow_up_'.$item->id,
            'intake_follow_up_item_id' => $item->id,
            'assessment_attempts' => 1,
            'assessment_queued_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(10),
            'checksum' => hash('sha256', 'cap-'.$i),
            'path' => 'intakes/test/bl133-cap-'.$i.'.jpg',
        ]);
    }

    Artisan::call('photos:requeue-pending-assessments', [
        '--minutes' => 3,
        '--max-attempts' => 3,
        '--max-per-run' => 2,
    ]);

    Queue::assertPushed(AssessUploadedPhotoJob::class, 2);

    $stillPendingAtOne = collect($uploads)
        ->map(fn (IntakeUpload $u) => $u->fresh())
        ->filter(fn (IntakeUpload $u) => (int) $u->assessment_attempts === 1)
        ->count();

    expect($stillPendingAtOne)->toBe(3);
});

test('watchdog slaat uploads ouder dan max-age over', function () {
    [$intake, $item] = bl133MakeFollowUpIntake(IntakeStatus::InProgress);

    $upload = bl133PendingUpload($intake, [
        'question_key' => 'follow_up_'.$item->id,
        'intake_follow_up_item_id' => $item->id,
        'assessment_attempts' => 1,
        'assessment_queued_at' => now()->subMinutes(5),
        'created_at' => now()->subHours(48),
    ]);

    Artisan::call('photos:requeue-pending-assessments', [
        '--minutes' => 3,
        '--max-attempts' => 3,
        '--max-age-hours' => 24,
    ]);

    $upload->refresh();
    expect($upload->assessment_attempts)->toBe(1)
        ->and($upload->assessment_status)->toBe(PhotoAssessmentStatus::Pending);

    Queue::assertNothingPushed();
});

test('lifecycle dispatch zet pipeline-marker assessment_attempts >= 1', function () {
    $intake = bl133MakeIntake(IntakeStatus::InProgress);

    $upload = bl133PendingUpload($intake, [
        'assessment_attempts' => 0,
        'assessment_status' => PhotoAssessmentStatus::Pending,
        'assessment_queued_at' => null,
    ]);

    app(PhotoAssessmentLifecycle::class)->dispatch($upload);

    $upload->refresh();
    expect($upload->assessment_attempts)->toBeGreaterThanOrEqual(1)
        ->and($upload->assessment_status)->toBe(PhotoAssessmentStatus::Pending)
        ->and($upload->assessment_queued_at)->not->toBeNull();

    Queue::assertPushed(AssessUploadedPhotoJob::class, 1);
});

test('legacy-skip migratie zet pending attempts=0 om naar not_assessed', function () {
    $closed = bl133MakeIntake(IntakeStatus::Completed);
    $legacy = bl133PendingUpload($closed, [
        'assessment_attempts' => 0,
        'created_at' => now()->subDays(40),
        'assessment_queued_at' => now()->subMinutes(1),
    ]);

    $open = bl133MakeIntake(IntakeStatus::InProgress);
    // Fresh pipeline upload already marked — must not be converted.
    $fresh = bl133PendingUpload($open, [
        'assessment_attempts' => 1,
        'created_at' => now()->subMinutes(2),
        'assessment_queued_at' => now()->subMinutes(2),
    ]);

    // Also convert open intake with attempts=0 and no ai_run (backfill pattern).
    $openLegacy = bl133PendingUpload($open, [
        'assessment_attempts' => 0,
        'created_at' => now()->subDays(10),
        'assessment_queued_at' => now()->subMinutes(1),
        'checksum' => hash('sha256', 'open-legacy'),
        'path' => 'intakes/test/bl133-open-legacy.jpg',
    ]);

    $migration = require database_path('migrations/2026_10_03_220000_skip_legacy_pending_photo_assessments.php');
    expect(Schema::hasColumn('intake_uploads', 'assessment_status'))->toBeTrue();
    $migration->up();

    $legacy->refresh();
    $fresh->refresh();
    $openLegacy->refresh();

    expect($legacy->assessment_status)->toBe(PhotoAssessmentStatus::NotAssessed)
        ->and($legacy->assessment_queued_at)->toBeNull()
        ->and($fresh->assessment_status)->toBe(PhotoAssessmentStatus::Pending)
        ->and($fresh->assessment_attempts)->toBe(1)
        ->and($openLegacy->assessment_status)->toBe(PhotoAssessmentStatus::NotAssessed);

    // Idempotent second run.
    $migration->up();
    expect($legacy->fresh()->assessment_status)->toBe(PhotoAssessmentStatus::NotAssessed);
});
