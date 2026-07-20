<?php

declare(strict_types=1);

use App\Domains\AI\Actions\AssessPhotoUsability;
use App\Domains\AI\Services\PhotoUsabilityHeuristic;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\AiRunStatus;
use App\Enums\PhotoUsabilityVerdict;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

/** Solid-colour JPEG bytes at a given luminance and size. */
function jpegBytes(int $level, int $w = 800, int $h = 800): string
{
    $img = imagecreatetruecolor($w, $h);
    $colour = imagecolorallocate($img, $level, $level, $level);
    imagefill($img, 0, 0, $colour);
    ob_start();
    imagejpeg($img, null, 90);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

test('heuristic flags a dark photo', function () {
    expect(app(PhotoUsabilityHeuristic::class)->assess(jpegBytes(8)))->toBe(PhotoUsabilityVerdict::TooDark);
});

test('heuristic flags a small photo', function () {
    expect(app(PhotoUsabilityHeuristic::class)->assess(jpegBytes(200, 320, 320)))->toBe(PhotoUsabilityVerdict::TooSmall);
});

test('heuristic accepts a well-lit large photo', function () {
    expect(app(PhotoUsabilityHeuristic::class)->assess(jpegBytes(200)))->toBe(PhotoUsabilityVerdict::Ok);
});

test('heuristic stays silent on unreadable bytes', function () {
    expect(app(PhotoUsabilityHeuristic::class)->assess('not-an-image'))->toBe(PhotoUsabilityVerdict::Ok);
});

test('assess action stores the verdict and records a run', function () {
    Storage::fake('local');
    $disk = (string) config('filesystems.media', 'local');
    Storage::fake($disk);

    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create(['created_by' => $user->id, 'intake_template_version_id' => $version->id]);

    Storage::disk($disk)->put('photos/dark.jpg', jpegBytes(8));
    $upload = IntakeUpload::query()->create([
        'intake_id' => $intake->id,
        'question_key' => 'room_photos',
        'section_instance_key' => 'room-1',
        'disk' => $disk,
        'path' => 'photos/dark.jpg',
        'original_filename' => 'dark.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => strlen(jpegBytes(8)),
        'sort_order' => 1,
    ]);

    $verdict = app(AssessPhotoUsability::class)->handle($upload);

    expect($verdict)->toBe(PhotoUsabilityVerdict::TooDark)
        ->and($upload->fresh()->usability_verdict)->toBe(PhotoUsabilityVerdict::TooDark)
        ->and($intake->aiRuns()->where('type', 'photo_quality')->where('status', AiRunStatus::Succeeded->value)->exists())->toBeTrue();
});
