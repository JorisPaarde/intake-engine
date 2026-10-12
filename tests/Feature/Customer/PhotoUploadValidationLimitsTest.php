<?php

declare(strict_types=1);

use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Jobs\ProcessIntakePhotoVariantsJob;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Support\PhotoUploadLimits;
use App\Enums\IntakeStatus;
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
    Queue::fake([AssessUploadedPhotoJob::class, ProcessIntakePhotoVariantsJob::class]);
    config([
        'intake.uploads.max_kilobytes' => 8192,
        'intake.uploads.hard_max_bytes' => 8 * 1024 * 1024,
        'intake.uploads.too_large_message' => 'Deze foto is groter dan 8 MB. Maak een nieuwe foto of kies een kleinere.',
    ]);
});

function makePhotoLimitIntake(): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'access_token' => str_repeat('f', 64),
        'token_expires_at' => now()->addDays(14),
    ]);
}

/** ~3.1 MB JPEG-shaped payload (no personal data) for validation-limit coverage. */
function threeMbPhoneJpeg(string $name = 'phone-3mb.jpg'): UploadedFile
{
    // Minimal SOI + padding + EOI so MIME stays image/jpeg while size ≈ 3.1 MB.
    $target = 3_100_000;
    $bytes = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
        .str_repeat('A', max(0, $target - 20))
        ."\xFF\xD9";

    expect(strlen($bytes))->toBeGreaterThanOrEqual(3_100_000)
        ->and(strlen($bytes))->toBeLessThan(8 * 1024 * 1024);

    return UploadedFile::fake()->createWithContent($name, $bytes);
}

test('Livewire temporary upload rules allow at least 8 MB', function () {
    $rules = config('livewire.temporary_file_upload.rules');
    $maxKb = PhotoUploadLimits::temporaryUploadMaxKilobytes();
    expect($maxKb)->toBeGreaterThanOrEqual(8192)
        ->and($maxKb)->toBeGreaterThanOrEqual(PhotoUploadLimits::validationMaxKilobytes())
        ->and($rules)->toBeArray()
        ->and(implode('|', is_array($rules) ? $rules : []))->toContain('max:'.$maxKb);
});

test('wizard accepts a ~3.1 MB JPEG within the 8 MB app limit', function () {
    $intake = makePhotoLimitIntake();
    $file = threeMbPhoneJpeg();

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('photoClientOriginals.fusebox_photo', [
            ['width' => 2400, 'height' => 3200],
        ])
        ->set('photoFiles.fusebox_photo', $file)
        ->assertHasNoErrors();

    $upload = IntakeUpload::query()
        ->where('intake_id', $intake->id)
        ->where('question_key', 'fusebox_photo')
        ->first();

    expect($upload)->toBeInstanceOf(IntakeUpload::class)
        ->and($upload->size_bytes)->toBeGreaterThanOrEqual(3_100_000);
});

test('store rejects files above 8 MB with Dutch limit message', function () {
    $intake = makePhotoLimitIntake();
    $oversized = UploadedFile::fake()->createWithContent(
        'huge.jpg',
        str_repeat('x', 8 * 1024 * 1024 + 2048),
    );

    expect(fn () => app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        $oversized,
    ))->toThrow(ValidationException::class);

    try {
        app(StoreIntakeUpload::class)->handle($intake, 'fusebox_photo', null, $oversized);
    } catch (ValidationException $exception) {
        expect($exception->errors()['photo'][0] ?? null)->toBe(PhotoUploadLimits::tooLargeMessage())
            ->and($exception->errors()['photo'][0] ?? '')->toContain('8 MB');
    }
});

test('photo upload control blade exposes 8 MB limit copy and hard-max data attributes', function () {
    $blade = (string) file_get_contents(resource_path('views/components/customer/photo-upload-control.blade.php'));
    $prepare = (string) file_get_contents(resource_path('js/photo-prepare.js'));

    expect($blade)->toContain('data-upload-app-max-bytes=')
        ->and($blade)->toContain('data-upload-max-bytes=')
        ->and($blade)->toContain('8388608')
        ->and(PhotoUploadLimits::tooLargeMessage())->toContain('8 MB')
        ->and($prepare)->toContain('MAX_LONG_EDGE = 2560')
        ->and($prepare)->toContain('HARD_MAX_BYTES = 8 * 1024 * 1024')
        ->and($prepare)->toContain('failClosedOrFallback');
});
