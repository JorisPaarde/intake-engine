<?php

declare(strict_types=1);

use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Jobs\ProcessIntakePhotoVariantsJob;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\IntakeStatus;
use App\Enums\PhotoAssessmentStatus;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
});

function makeBl138Intake(): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'access_token' => str_repeat('c', 64),
        'token_expires_at' => now()->addDays(14),
    ]);
}

/**
 * 3024×4032 progressive JPEG (~like meterkast-groot) generated in-process.
 */
function bl138ProgressiveJpeg(string $name = 'meterkast-groot.jpg'): UploadedFile
{
    $img = imagecreatetruecolor(3024, 4032);
    imagefill($img, 0, 0, imagecolorallocate($img, 190, 185, 175));
    // Add a dark band so usability is not "too dark".
    imagefilledrectangle($img, 200, 200, 2800, 3800, imagecolorallocate($img, 220, 220, 210));

    $tmp = tempnam(sys_get_temp_dir(), 'bl138');
    expect($tmp)->not->toBeFalse();
    // Progressive JPEG via GD.
    imageinterlace($img, true);
    imagejpeg($img, $tmp, 85);
    imagedestroy($img);

    $bytes = (string) file_get_contents((string) $tmp);
    @unlink((string) $tmp);

    // Confirm progressive SOF marker present in the generated fixture.
    expect(substr_count($bytes, "\xFF\xC2"))->toBeGreaterThan(0);
    expect(strlen($bytes))->toBeGreaterThan(50_000);

    return UploadedFile::fake()->createWithContent($name, $bytes);
}

test('store intake upload persists source bytes quickly and queues variant processing', function () {
    Queue::fake([ProcessIntakePhotoVariantsJob::class, AssessUploadedPhotoJob::class]);
    $intake = makeBl138Intake();
    $file = bl138ProgressiveJpeg();

    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        $file,
        clientOriginalWidth: 3024,
        clientOriginalHeight: 4032,
    );

    expect($upload)->toBeInstanceOf(IntakeUpload::class)
        ->and($upload->assessment_status)->toBe(PhotoAssessmentStatus::Pending)
        ->and($upload->processing_timings['variants_pending'] ?? null)->toBeTrue()
        ->and($upload->analysis_path)->toBeNull()
        ->and(Storage::disk($upload->disk)->exists($upload->path))->toBeTrue();

    Queue::assertPushedOn(ProcessIntakePhotoVariantsJob::QUEUE, ProcessIntakePhotoVariantsJob::class);
    Queue::assertNotPushed(AssessUploadedPhotoJob::class);
});

test('process variants job finishes progressive jpeg and reaches terminal or queued AI', function () {
    Queue::fake([ProcessIntakePhotoVariantsJob::class, AssessUploadedPhotoJob::class]);
    $intake = makeBl138Intake();
    $file = bl138ProgressiveJpeg();

    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        $file,
        clientOriginalWidth: 3024,
        clientOriginalHeight: 4032,
    );

    Queue::assertPushed(ProcessIntakePhotoVariantsJob::class, function (ProcessIntakePhotoVariantsJob $job) use ($upload): bool {
        return $job->uploadId === $upload->id
            && $job->clientOriginalWidth === 3024
            && $job->clientOriginalHeight === 4032;
    });

    $job = new ProcessIntakePhotoVariantsJob($upload->id, 3024, 4032);
    app()->call([$job, 'handle']);

    $fresh = $upload->fresh();
    expect($fresh)->toBeInstanceOf(IntakeUpload::class)
        ->and($fresh->processing_timings['variants_ready'] ?? null)->toBeTrue()
        ->and($fresh->analysis_path)->not->toBeNull()
        ->and($fresh->processing_timings['original_width'] ?? null)->toBe(3024)
        ->and($fresh->processing_timings['original_height'] ?? null)->toBe(4032);

    Queue::assertPushed(AssessUploadedPhotoJob::class);
});

test('wizard recovers after failed upload state and accepts a new progressive photo', function () {
    Queue::fake([ProcessIntakePhotoVariantsJob::class, AssessUploadedPhotoJob::class]);
    $intake = makeBl138Intake();
    $first = bl138ProgressiveJpeg('gevel-extra.jpg');
    $second = bl138ProgressiveJpeg('demo-rear-facade.jpg');

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $component->set('photoFiles.fusebox_photo', $first);
    $component->set('photoFiles.fusebox_photo', []);
    $url = $component->instance()->freshSignedUploadUrl();
    expect($url)->toContain('/upload-file');

    $component->set('photoFiles.fusebox_photo', $second)->assertHasNoErrors();

    expect(IntakeUpload::query()->where('intake_id', $intake->id)->where('question_key', 'fusebox_photo')->count())
        ->toBeGreaterThanOrEqual(1);
});

test('retry after simulated Livewire update 503 still stores photo and queues assessment', function () {
    // Staging intake 81: upload-file 200 + tmp ready, then one of two concurrent
    // Livewire updates got LiteSpeed 503 — photo never reached photo_quality.
    // Simulate: first finish/store never ran; client retries _finishUpload with the
    // same tmp bytes → StoreIntakeUpload + variants job + AssessUploadedPhotoJob.
    Queue::fake([ProcessIntakePhotoVariantsJob::class, AssessUploadedPhotoJob::class]);
    $intake = makeBl138Intake();
    $file = bl138ProgressiveJpeg('meterkast-retry.jpg');

    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        $file,
        clientOriginalWidth: 3024,
        clientOriginalHeight: 4032,
    );

    expect($upload->assessment_status)->toBe(PhotoAssessmentStatus::Pending)
        ->and($upload->processing_timings['variants_pending'] ?? null)->toBeTrue();

    Queue::assertPushed(ProcessIntakePhotoVariantsJob::class);
    Queue::assertNotPushed(AssessUploadedPhotoJob::class);

    runProcessIntakePhotoVariantsJob($upload->id, 3024, 4032);

    Queue::assertPushed(AssessUploadedPhotoJob::class);

    // Idempotent second finish (double-retry after a 503 that actually persisted):
    // same checksum → same row, no second variants job from this call's assert window
    // beyond the one already pushed above.
    $again = app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        $file,
        clientOriginalWidth: 3024,
        clientOriginalHeight: 4032,
    );

    expect($again->id)->toBe($upload->id)
        ->and(IntakeUpload::query()->where('intake_id', $intake->id)->where('question_key', 'fusebox_photo')->count())
        ->toBe(1);
});

test('js encodes BL-143 update-retry reuse tmp + client 2000px + busy copy', function () {
    $blade = (string) file_get_contents(resource_path('views/components/customer/photo-upload-control.blade.php'));
    $intakeWizard = (string) file_get_contents(resource_path('views/livewire/customer/intake-wizard.blade.php'));
    $followUpWizard = (string) file_get_contents(resource_path('views/livewire/customer/follow-up-wizard.blade.php'));
    $resilience = (string) file_get_contents(resource_path('js/server-resilience.js'));
    $livewireJs = (string) file_get_contents(resource_path('js/livewire-resilience.js'));
    $prepare = (string) file_get_contents(resource_path('js/photo-prepare.js'));
    $appJs = (string) file_get_contents(resource_path('js/app.js'));

    expect($prepare)->toContain('MAX_LONG_EDGE = 2000')
        ->and($prepare)->toContain('JPEG_QUALITY = 0.85')
        ->and($prepare)->toContain("imageOrientation: 'from-image'")
        ->and($prepare)->toContain('downscale-timeout')
        ->and($prepare)->toContain('wireModelUploadTargets')
        ->and($prepare)->toContain('followUpPhotoClientOriginals')
        ->and($resilience)->toContain('2_000')
        ->and($resilience)->toContain('20_000')
        ->and($resilience)->toContain('60_000')
        ->and($resilience)->toContain('MAX_ATTEMPTS = 3')
        ->and($resilience)->toContain('RETRY_AFTER_CAP_MS = 90_000')
        ->and($livewireJs)->toContain("name === '_finishUpload'")
        ->and($livewireJs)->toContain('freshSignedUploadUrl')
        ->and($livewireJs)->toContain('registerPollPauseWhileBusy')
        ->and($livewireJs)->toContain('selectReplayTargets')
        ->and($livewireJs)->toContain('uploadInFlightByComponent')
        ->and($livewireJs)->toContain("name === '\$set'")
        ->and($appJs)->toContain('preparePhotoForUpload')
        ->and($appJs)->toContain('intake:photo-prep-start')
        ->and($appJs)->toContain('wireModelUploadTargets')
        ->and($appJs)->toContain('originalsProperty')
        ->and($appJs)->toContain('followUpPhotoFiles → followUpPhotoClientOriginals')
        // Shared upload control used by intake + follow-up (installer test 4 / intake 100).
        ->and($blade)->toContain('clearLivewireUpload')
        ->and($blade)->toContain('clientUploading')
        ->and($blade)->toContain('armInactivityTimer')
        ->and($blade)->toContain('armServerWaitTimer')
        ->and($blade)->toContain('Even geduld, we proberen het opnieuw.')
        ->and($blade)->toContain('De server is even druk. Probeer het zo opnieuw.')
        ->and($blade)->toContain('data-testid="upload-retry-button"')
        ->and($blade)->toContain('data-client-downscale="1"')
        ->and($blade)->toContain('data-upload-timing="1"')
        ->and($blade)->toContain("'pendingAssessUploadIds' => []")
        ->and($blade)->not->toContain('Uploaden lijkt vast te zitten')
        ->and($blade)->toContain('x-bind:disabled="(clientUploading && ! uploadTimedOut) || prepBusy"')
        ->and($intakeWizard)->toContain('x-customer.photo-upload-control')
        ->and($intakeWizard)->toContain(':pending-assess-upload-ids="$pendingAssessUploadIds"')
        ->and($followUpWizard)->toContain('x-customer.photo-upload-control')
        ->and($followUpWizard)->toContain('tone="followup"')
        ->and($followUpWizard)->toContain('wire-model="followUpPhotoFiles.{{ $item->id }}"')
        ->and($followUpWizard)->toContain(':pending-assess-upload-ids="$pendingAssessUploadIds"')
        ->and($followUpWizard)->toContain(':assessment-ui-released="$assessmentUiReleased"');
});
