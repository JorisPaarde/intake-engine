<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\DeleteIntakeUpload;
use App\Domains\Intake\Actions\GenerateIntakePdf;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\GeneratedReport;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

function mediaDiskIntake(): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
    ]);
}

/**
 * Register a local fake under the real disk name "s3" so MEDIA_DISK=s3 can be
 * exercised without AWS credentials or network calls.
 */
function fakeMediaDisks(): void
{
    config([
        'filesystems.disks.s3' => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/s3'),
            'throw' => false,
        ],
    ]);

    Storage::fake('local');
    Storage::fake('s3');
}

test('new uploads store and write to the configured MEDIA_DISK', function () {
    fakeMediaDisks();
    config(['filesystems.media' => 's3']);

    $intake = mediaDiskIntake();
    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('meterkast.jpg', 800, 600),
    );

    expect($upload->disk)->toBe('s3')
        ->and(Storage::disk('s3')->exists($upload->path))->toBeTrue()
        ->and(Storage::disk('s3')->exists((string) $upload->analysis_path))->toBeTrue()
        ->and(Storage::disk('local')->exists($upload->path))->toBeFalse();

    $this->get(route('customer.uploads.show', [
        'token' => $intake->access_token,
        'upload' => $upload,
    ]))->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');
});

test('existing upload rows keep their stored disk after MEDIA_DISK switches', function () {
    fakeMediaDisks();
    config(['filesystems.media' => 'local']);

    $intake = mediaDiskIntake();
    $legacy = app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('oud.jpg', 640, 480),
    );

    expect($legacy->disk)->toBe('local');
    Storage::disk('local')->assertExists($legacy->path);
    Storage::disk('local')->assertExists((string) $legacy->analysis_path);

    $legacyPath = $legacy->path;
    $legacyAnalysis = (string) $legacy->analysis_path;
    $legacyBytes = Storage::disk('local')->get($legacyPath);

    // Owner switches media to S3; historical rows must stay on their original disk.
    config(['filesystems.media' => 's3']);

    $fresh = app(StoreIntakeUpload::class)->handle(
        $intake->fresh(),
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('nieuw.jpg', 800, 600),
    );

    $legacy->refresh();

    expect($fresh->disk)->toBe('s3')
        ->and($legacy->disk)->toBe('local')
        ->and($legacy->path)->toBe($legacyPath)
        ->and($legacy->analysis_path)->toBe($legacyAnalysis)
        ->and(Storage::disk('local')->get($legacyPath))->toBe($legacyBytes)
        ->and(Storage::disk('s3')->exists($legacyPath))->toBeFalse()
        ->and(Storage::disk('s3')->exists($fresh->path))->toBeTrue()
        ->and(Storage::disk('local')->exists($fresh->path))->toBeFalse();

    $this->get(route('customer.uploads.show', [
        'token' => $intake->access_token,
        'upload' => $legacy,
    ]))->assertOk();

    $this->get(route('customer.uploads.show', [
        'token' => $intake->access_token,
        'upload' => $fresh,
    ]))->assertOk();
});

test('deleting a legacy upload removes files from its stored disk only', function () {
    fakeMediaDisks();
    config(['filesystems.media' => 'local']);

    $intake = mediaDiskIntake();
    $legacy = app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('oud.jpg', 640, 480),
    );

    config(['filesystems.media' => 's3']);

    $path = $legacy->path;
    $analysisPath = (string) $legacy->analysis_path;

    app(DeleteIntakeUpload::class)->handle($intake, $legacy);

    expect(Storage::disk('local')->exists($path))->toBeFalse()
        ->and(Storage::disk('local')->exists($analysisPath))->toBeFalse()
        ->and(IntakeUpload::query()->whereKey($legacy->id)->exists())->toBeFalse();
});

test('generated PDFs record the MEDIA_DISK at generation time', function () {
    fakeMediaDisks();
    config(['filesystems.media' => 's3']);

    $intake = mediaDiskIntake();
    GeneratedReport::query()->create([
        'intake_id' => $intake->id,
        'html' => '<html><body><h1>Testrapport</h1></body></html>',
        'generated_at' => now(),
    ]);

    $report = app(GenerateIntakePdf::class)->handle($intake->fresh());

    expect($report)->toBeInstanceOf(GeneratedReport::class)
        ->and($report->pdf_disk)->toBe('s3')
        ->and(Storage::disk('s3')->exists((string) $report->pdf_path))->toBeTrue()
        ->and(Storage::disk('local')->exists((string) $report->pdf_path))->toBeFalse();
});
