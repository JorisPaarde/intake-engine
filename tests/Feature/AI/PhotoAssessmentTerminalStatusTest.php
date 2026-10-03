<?php

declare(strict_types=1);

use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\PhotoAssessmentLifecycle;
use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\DossierManager;
use App\Enums\AiRunType;
use App\Enums\FollowUpItemType;
use App\Enums\IntakeStatus;
use App\Enums\PhotoAssessmentStatus;
use App\Enums\PhotoUsabilityVerdict;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
    config([
        'ai.provider' => 'fake',
        'ai.photo_inference.enabled' => true,
        'ai.tracing.enabled' => true,
        'ai.photo_assessment.ui_soft_timeout_seconds' => 90,
        'ai.photo_assessment.watchdog_after_seconds' => 180,
        'ai.photo_assessment.watchdog_max_attempts' => 3,
    ]);
    FakeAiClient::reset();
});

function terminalAssessmentPhoto(string $name = 'meterkast.jpg'): UploadedFile
{
    $img = imagecreatetruecolor(1280, 960);
    imagefill($img, 0, 0, imagecolorallocate($img, 220, 220, 210));
    ob_start();
    imagejpeg($img, null, 90);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return UploadedFile::fake()->createWithContent($name, $bytes);
}

function terminalTinyPhoto(string $name = 'tiny.jpg'): UploadedFile
{
    $img = imagecreatetruecolor(40, 30);
    imagefill($img, 0, 0, imagecolorallocate($img, 220, 220, 210));
    ob_start();
    imagejpeg($img, null, 90);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return UploadedFile::fake()->createWithContent($name, $bytes);
}

function runTerminalAssessJob(int $uploadId): void
{
    runAssessUploadedPhotoJob($uploadId);
}

function makeTerminalWizardIntake(): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::InProgress,
        'access_token' => 'terminal'.str_repeat('b', 56),
    ]);

    app(DossierManager::class)->initialize($intake);

    return $intake->fresh();
}

function makeTerminalFollowUpIntake(): array
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::InProgress,
        'access_token' => 'terminalfu'.str_repeat('c', 54),
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

test('zelfde bestand op verschillende vragen krijgt elk een terminale assessment_status', function () {
    Queue::fake([AssessUploadedPhotoJob::class]);
    FakeAiClient::alwaysReturn([
        'detected_subject' => 'outdoor_unit',
        'subject_match' => 'yes',
        'confidence' => 'high',
        'evidence' => 'Buitenunit zichtbaar.',
        'empty_module_space' => 'unknown',
        'phase' => 'unknown',
    ]);

    $intake = makeTerminalWizardIntake();
    $file = terminalAssessmentPhoto('facade.jpg');

    // outdoor_location_photo heeft photo_analysis; upload twee keer naar verschillende vragen/instanties.
    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);

    // Zoek twee foto-vragen met photo_analysis in de template.
    $version = $intake->templateVersion()->with(['sections.questions'])->firstOrFail();
    $photoQuestions = [];
    foreach ($version->sections as $section) {
        foreach ($section->questions as $question) {
            if (($question->meta['photo_analysis'] ?? null) !== null) {
                $photoQuestions[] = $question->key;
            }
        }
    }

    expect(count($photoQuestions))->toBeGreaterThanOrEqual(2);

    $q1 = $photoQuestions[0];
    $q2 = $photoQuestions[1];

    $component->set('photoFiles.'.$q1, [$file])
        ->assertSet('uploadPhase', 'assessing');

    $upload1 = IntakeUpload::query()
        ->where('intake_id', $intake->id)
        ->where('question_key', $q1)
        ->firstOrFail();

    expect($upload1->assessment_status)->toBe(PhotoAssessmentStatus::Pending);

    // Zelfde bytes, andere vraag → nieuwe rij.
    $component->set('photoFiles.'.$q2, [terminalAssessmentPhoto('facade.jpg')]);

    $upload2 = IntakeUpload::query()
        ->where('intake_id', $intake->id)
        ->where('question_key', $q2)
        ->firstOrFail();

    expect($upload2->id)->not->toBe($upload1->id)
        ->and($upload2->checksum)->toBe($upload1->checksum)
        ->and($upload2->assessment_status)->toBe(PhotoAssessmentStatus::Pending);

    Queue::assertPushed(AssessUploadedPhotoJob::class, 2);

    runTerminalAssessJob($upload1->id);
    runTerminalAssessJob($upload2->id);

    $upload1->refresh();
    $upload2->refresh();

    expect($upload1->assessment_status?->isTerminal())->toBeTrue()
        ->and($upload2->assessment_status?->isTerminal())->toBeTrue();
});

test('heuristic too_small zet heuristic_rejected zonder AI-queue-wacht', function () {
    Queue::fake([AssessUploadedPhotoJob::class]);

    [$intake, $item] = makeTerminalFollowUpIntake();

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('followUpPhotoFiles.'.$item->id, terminalTinyPhoto())
        ->assertSet('uploadPhase', '');

    $upload = $item->fresh()->uploads()->firstOrFail();

    expect($upload->usability_verdict)->toBe(PhotoUsabilityVerdict::TooSmall)
        ->and($upload->assessment_status)->toBe(PhotoAssessmentStatus::HeuristicRejected)
        ->and($upload->contentAssessment())->toBeNull();

    Queue::assertNotPushed(AssessUploadedPhotoJob::class);
});

test('AI-fout zet not_assessed en koppelt upload_id op ai_runs', function () {
    Queue::fake([AssessUploadedPhotoJob::class]);
    FakeAiClient::alwaysFail('Simulated provider timeout');

    [$intake, $item] = makeTerminalFollowUpIntake();

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('followUpPhotoFiles.'.$item->id, terminalAssessmentPhoto())
        ->assertSet('uploadPhase', 'assessing');

    $upload = $item->fresh()->uploads()->firstOrFail();
    runTerminalAssessJob($upload->id);
    $upload->refresh();

    expect($upload->assessment_status)->toBe(PhotoAssessmentStatus::NotAssessed)
        ->and($upload->contentAssessment()?->status())->toBe(PhotoContentAssessment::STATUS_NOT_ASSESSED);

    $run = AiRun::query()
        ->where('intake_id', $intake->id)
        ->where('type', AiRunType::PhotoAssessment)
        ->latest('id')
        ->first();

    expect($run)->not->toBeNull()
        ->and($run->upload_id)->toBe($upload->id);
});

test('watchdog herdispatched pending uploads en markeert na max attempts', function () {
    Queue::fake([AssessUploadedPhotoJob::class]);

    [$intake, $item] = makeTerminalFollowUpIntake();

    $upload = IntakeUpload::query()->create([
        'intake_id' => $intake->id,
        'question_key' => 'follow_up_'.$item->id,
        'intake_follow_up_item_id' => $item->id,
        'disk' => (string) config('filesystems.media', 'local'),
        'path' => 'intakes/test/watchdog.jpg',
        'original_filename' => 'watchdog.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 1000,
        'checksum' => hash('sha256', 'watchdog'),
        'sort_order' => 1,
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
        'assessment_status' => PhotoAssessmentStatus::Pending,
        'assessment_queued_at' => now()->subMinutes(5),
        'assessment_attempts' => 0,
    ]);

    Artisan::call('photos:requeue-pending-assessments', ['--minutes' => 3, '--max-attempts' => 3]);

    $upload->refresh();
    expect($upload->assessment_attempts)->toBe(1)
        ->and($upload->assessment_status)->toBe(PhotoAssessmentStatus::Pending);

    Queue::assertPushed(AssessUploadedPhotoJob::class, 1);

    // Exhaust attempts.
    $upload->forceFill([
        'assessment_attempts' => 3,
        'assessment_queued_at' => now()->subMinutes(5),
        'assessment_status' => PhotoAssessmentStatus::Pending,
    ])->save();

    Artisan::call('photos:requeue-pending-assessments', ['--minutes' => 3, '--max-attempts' => 3]);

    $upload->refresh();
    expect($upload->assessment_status?->isTerminal())->toBeTrue()
        ->and($upload->contentAssessment()?->status())->toBe(PhotoContentAssessment::STATUS_NOT_ASSESSED);
});

test('poll soft-timeout laat wizard doorgaan terwijl status pending blijft', function () {
    Queue::fake([AssessUploadedPhotoJob::class]);
    config(['ai.photo_assessment.ui_soft_timeout_seconds' => 1]);

    [$intake, $item] = makeTerminalFollowUpIntake();

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('followUpPhotoFiles.'.$item->id, terminalAssessmentPhoto())
        ->assertSet('uploadPhase', 'assessing');

    $upload = $item->fresh()->uploads()->firstOrFail();
    expect($upload->assessment_status)->toBe(PhotoAssessmentStatus::Pending);

    // Forceer soft-timeout via assessment_queued_at (fallback in poll).
    $upload->forceFill(['assessment_queued_at' => now()->subSeconds(5)])->save();

    $component
        ->call('pollPendingAssessments')
        ->assertSet('uploadPhase', '')
        ->assertSee('De automatische check volgt later');

    $upload->refresh();
    expect($upload->assessment_status)->toBe(PhotoAssessmentStatus::Pending);
});

test('checksum-reuse markeert reused met bron-upload bij zelfde expected subject', function () {
    $intake = makeTerminalWizardIntake();

    $source = IntakeUpload::query()->create([
        'intake_id' => $intake->id,
        'question_key' => 'outdoor_unit_photo',
        'disk' => (string) config('filesystems.media', 'local'),
        'path' => 'intakes/test/source.jpg',
        'original_filename' => 'source.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 1000,
        'checksum' => hash('sha256', 'same-bytes'),
        'sort_order' => 1,
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'content_assessment' => PhotoContentAssessment::ok(PhotoSubject::OutdoorUnit)->toArray(),
    ]);

    $target = IntakeUpload::query()->create([
        'intake_id' => $intake->id,
        'question_key' => 'outdoor_unit_photo',
        'section_instance_key' => 'room-2',
        'disk' => (string) config('filesystems.media', 'local'),
        'path' => 'intakes/test/target.jpg',
        'original_filename' => 'target.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 1000,
        'checksum' => hash('sha256', 'same-bytes'),
        'sort_order' => 1,
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
        'assessment_status' => PhotoAssessmentStatus::Pending,
        'assessment_queued_at' => now(),
    ]);

    $reused = app(PhotoAssessmentLifecycle::class)->tryReuseFromChecksum($target, PhotoSubject::OutdoorUnit);

    expect($reused)->toBeTrue();
    $target->refresh();
    expect($target->assessment_status)->toBe(PhotoAssessmentStatus::Reused)
        ->and($target->assessment_source_upload_id)->toBe($source->id)
        ->and($target->contentAssessment()?->status())->toBe(PhotoContentAssessment::STATUS_OK);
});
