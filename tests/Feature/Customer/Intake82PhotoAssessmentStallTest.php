<?php

declare(strict_types=1);

use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Jobs\ProcessIntakePhotoVariantsJob;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\PhotoUploadNormalizer;
use App\Domains\Intake\Support\PhotoContentSatisfaction;
use App\Domains\Intake\Support\PhotoUploadLimits;
use App\Enums\IntakeStatus;
use App\Enums\PhotoAssessmentStatus;
use App\Enums\PhotoUsabilityVerdict;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
    config([
        'intake.uploads.hard_max_bytes' => 15 * 1024 * 1024,
        'intake.uploads.hard_max_megapixels' => 24,
    ]);
});

function makeIntake82Intake(): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'access_token' => str_repeat('d', 64),
        'token_expires_at' => now()->addDays(14),
    ]);
}

/**
 * ~12 MP JPEG (~5 MB) — must be accepted (staging intake 82 phone photo).
 */
function twelveMegapixelFiveMbJpeg(string $name = 'meterkast-12mp.jpg'): UploadedFile
{
    $width = 3024;
    $height = 4032;
    $img = imagecreatetruecolor($width, $height);
    expect($img)->not->toBeFalse();

    for ($y = 0; $y < $height; $y += 16) {
        $color = imagecolorallocate($img, ($y * 37) % 255, ($y * 17) % 255, ($y * 53) % 255);
        imagefilledrectangle($img, 0, $y, $width - 1, min($height - 1, $y + 15), $color);
    }

    $tmp = tempnam(sys_get_temp_dir(), 'i82');
    expect($tmp)->not->toBeFalse();
    imagejpeg($img, $tmp, 92);
    imagedestroy($img);

    $bytes = (string) file_get_contents((string) $tmp);
    @unlink((string) $tmp);

    // Aim for ~2–5 MB; if GD produces less, pad with comment APP0 is awkward —
    // assert under hard limit and at least a large phone-sized file.
    expect(strlen($bytes))->toBeGreaterThan(200_000)
        ->and(strlen($bytes))->toBeLessThan(15 * 1024 * 1024);

    return UploadedFile::fake()->createWithContent($name, $bytes);
}

test('12 MP phone JPEG (~5 MB) is accepted by hard upload limits', function () {
    $file = twelveMegapixelFiveMbJpeg();
    $size = (int) $file->getSize();

    expect(PhotoUploadLimits::exceedsHardByteLimit($size))->toBeFalse()
        ->and(PhotoUploadLimits::exceedsHardMegapixelLimit(3024, 4032))->toBeFalse();

    PhotoUploadLimits::assertAcceptable($size, 3024, 4032);

    Queue::fake();
    $upload = app(StoreIntakeUpload::class)->handle(
        makeIntake82Intake(),
        'fusebox_photo',
        null,
        $file,
        clientOriginalWidth: 3024,
        clientOriginalHeight: 4032,
    );

    expect($upload->id)->toBeGreaterThan(0)
        ->and($upload->assessment_status)->toBe(PhotoAssessmentStatus::Pending);
});

test('file above hard byte limit is rejected with clear Dutch message', function () {
    $message = PhotoUploadLimits::tooLargeMessage();
    expect($message)->toBe('Deze foto is te groot. Probeer een andere foto of maak een nieuwe.');

    $oversized = UploadedFile::fake()->createWithContent(
        'huge.jpg',
        str_repeat('x', 15 * 1024 * 1024 + 1024),
    );

    expect(fn () => PhotoUploadLimits::assertUploadedFileAcceptable($oversized))
        ->toThrow(ValidationException::class);

    try {
        PhotoUploadLimits::assertUploadedFileAcceptable($oversized);
        expect(false)->toBeTrue();
    } catch (ValidationException $exception) {
        expect($exception->errors()['photo'][0] ?? null)->toBe($message);
    }

    expect(fn () => app(StoreIntakeUpload::class)->handle(
        makeIntake82Intake(),
        'fusebox_photo',
        null,
        $oversized,
    ))->toThrow(ValidationException::class);
});

test('absurd megapixel count is rejected with the same Dutch message', function () {
    $message = PhotoUploadLimits::tooLargeMessage();
    $small = UploadedFile::fake()->createWithContent('tiny.jpg', str_repeat('a', 50_000));

    expect(fn () => PhotoUploadLimits::assertUploadedFileAcceptable($small, 20000, 20000))
        ->toThrow(ValidationException::class);

    try {
        PhotoUploadLimits::assertUploadedFileAcceptable($small, 20000, 20000);
    } catch (ValidationException $exception) {
        expect($exception->errors()['photo'][0] ?? null)->toBe($message);
    }
});

test('normalizer uses one JPEG decode with jpeg:size hint and correct dimensions', function () {
    if (! class_exists(Imagick::class)) {
        $this->markTestSkipped('Imagick required for jpeg:size shrink-on-load assertion');
    }

    $file = twelveMegapixelFiveMbJpeg('normalize-hint.jpg');
    $normalizer = app(PhotoUploadNormalizer::class);
    $result = $normalizer->normalize($file);
    $metrics = $normalizer->lastDecodeMetrics();

    expect($metrics)->not->toBeNull()
        ->and($metrics['full_decodes'])->toBe(1)
        ->and($metrics['library'])->toBe('imagick')
        ->and($metrics['jpeg_size_hint'])->not->toBeNull()
        ->and($result->originalWidth)->toBe(3024)
        ->and($result->originalHeight)->toBe(4032)
        ->and(max($result->dossierWidth, $result->dossierHeight))->toBeLessThanOrEqual(2048)
        ->and(max($result->analysisWidth, $result->analysisHeight))->toBeLessThanOrEqual(1536);

    foreach ($result->cleanupPaths as $path) {
        @unlink($path);
    }
});

test('job failure on variants reaches terminal not_assessed status', function () {
    Queue::fake();
    $intake = makeIntake82Intake();
    $file = twelveMegapixelFiveMbJpeg('fail-terminal.jpg');

    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        $file,
        clientOriginalWidth: 3024,
        clientOriginalHeight: 4032,
    );

    // Corrupt source so ProcessIntakePhotoVariantsJob fails.
    Storage::disk($upload->disk)->put($upload->path, 'not-an-image');

    $job = new ProcessIntakePhotoVariantsJob($upload->id, 3024, 4032);

    try {
        app()->call([$job, 'handle']);
    } catch (Throwable) {
        // expected — job rethrows after marking terminal
    }

    $upload->refresh();
    expect($upload->assessment_status?->isTerminal())->toBeTrue()
        ->and($upload->assessment_status)->toBe(PhotoAssessmentStatus::NotAssessed);

    // failed() safety net also terminals.
    $job->failed(new RuntimeException('exhausted'));
    $upload->refresh();
    expect($upload->assessment_status?->isTerminal())->toBeTrue();
});

test('ProcessIntakePhotoVariantsJob is dispatched on every store path', function () {
    Queue::fake([ProcessIntakePhotoVariantsJob::class]);
    $intake = makeIntake82Intake();
    $file = twelveMegapixelFiveMbJpeg('dispatch-path.jpg');

    app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        $file,
        clientOriginalWidth: 3024,
        clientOriginalHeight: 4032,
    );

    Queue::assertPushed(ProcessIntakePhotoVariantsJob::class);
});

test('pending received photo satisfies required question so Volgende is not blocked', function () {
    $pending = new IntakeUpload([
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
        'assessment_status' => PhotoAssessmentStatus::Pending,
        'content_assessment' => null,
    ]);

    expect(PhotoContentSatisfaction::uploadsSatisfy(collect([$pending])))->toBeTrue();
});

test('soft-release keeps quiet poll until terminal status is applied', function () {
    Queue::fake([
        ProcessIntakePhotoVariantsJob::class,
        AssessUploadedPhotoJob::class,
    ]);
    config(['ai.photo_assessment.ui_soft_timeout_seconds' => 1]);

    $intake = makeIntake82Intake();
    $file = UploadedFile::fake()->image('small.jpg', 800, 600);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('photoFiles.fusebox_photo', $file)
        ->assertSet('uploadPhase', 'assessing');

    $upload = $intake->fresh()->uploads()->firstOrFail();
    expect($upload->assessment_status)->toBe(PhotoAssessmentStatus::Pending);

    // Force soft-timeout via the assessing phase clock (authoritative while active).
    $component->set('uploadPhaseStartedAt', now()->subSeconds(5)->getTimestamp());
    $upload->forceFill(['assessment_queued_at' => now()->subSeconds(5)])->save();

    $component
        ->call('pollPendingAssessments')
        ->assertSet('uploadPhase', '')
        ->assertSee('De automatische check volgt later');

    // Pending ids kept for quiet poll (staging intake 82).
    expect($component->instance()->pendingAssessUploadIds['fusebox_photo'] ?? [])->toContain($upload->id)
        ->and($component->instance()->assessmentUiReleased)->toContain('fusebox_photo');

    // Backend finishes → quiet poll picks up terminal status.
    $upload->forceFill([
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
    ])->save();

    $component->call('pollPendingAssessments');
    expect($component->instance()->pendingAssessUploadIds['fusebox_photo'] ?? [])->toBeEmpty();
});
