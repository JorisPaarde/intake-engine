<?php

declare(strict_types=1);

use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\AI\Services\PhotoUsabilityHeuristic;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Jobs\ProcessIntakePhotoVariantsJob;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\IntakeStatus;
use App\Enums\PhotoUsabilityVerdict;
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
    Queue::fake([AssessUploadedPhotoJob::class]);
});

function makeBl128Intake(): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'access_token' => str_repeat('b', 64),
        'token_expires_at' => now()->addDays(14),
    ]);
}

function bl125MeterkastGroot(): UploadedFile
{
    $fixture = base_path('tests/fixtures/klanttest-20261002/meterkast-groot.jpg');
    expect(is_file($fixture))->toBeTrue();

    $size = getimagesize($fixture);
    expect($size)->not->toBeFalse()
        ->and($size[0])->toBe(3024)
        ->and($size[1])->toBe(4032)
        ->and(filesize($fixture))->toBeGreaterThan(2_000_000);

    return UploadedFile::fake()->createWithContent(
        'meterkast-groot.jpg',
        (string) file_get_contents($fixture),
    );
}

test('large phone photo fixture uploads and keeps original capture resolution', function () {
    Queue::fake([AssessUploadedPhotoJob::class]);
    $intake = makeBl128Intake();
    $file = bl125MeterkastGroot();

    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        $file,
        clientOriginalWidth: 3024,
        clientOriginalHeight: 4032,
    );

    // BL-143: variants are async; run the job to materialize dossier dims.
    $job = new ProcessIntakePhotoVariantsJob($upload->id, 3024, 4032);
    app()->call([$job, 'handle']);
    $upload = $upload->fresh();

    expect($upload->processing_timings['original_width'] ?? null)->toBe(3024)
        ->and($upload->processing_timings['original_height'] ?? null)->toBe(4032)
        ->and(max(
            (int) ($upload->processing_timings['dossier_width'] ?? 0),
            (int) ($upload->processing_timings['dossier_height'] ?? 0),
        ))->toBeLessThanOrEqual(2048);

    $verdict = app(PhotoUsabilityHeuristic::class)->assess(
        (string) Storage::disk($upload->disk)->get($upload->path),
        (int) $upload->processing_timings['original_width'],
        (int) $upload->processing_timings['original_height'],
    );

    expect($verdict)->toBe(PhotoUsabilityVerdict::Ok);
});

test('client original dimensions survive browser downscale metadata', function () {
    Queue::fake([AssessUploadedPhotoJob::class]);
    $intake = makeBl128Intake();

    // Simuleer een al verkleinde upload (zoals na canvas-downscale) met client-meta van het telefoonorigineel.
    $img = imagecreatetruecolor(1536, 2048);
    imagefill($img, 0, 0, imagecolorallocate($img, 210, 210, 200));
    ob_start();
    imagejpeg($img, null, 82);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    $file = UploadedFile::fake()->createWithContent('meterkast-downscaled.jpg', $bytes);

    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        $file,
        clientOriginalWidth: 3024,
        clientOriginalHeight: 4032,
    );

    // variants job runs sync (only Assess is faked)
    $upload = $upload->fresh();

    expect($upload->processing_timings['original_width'] ?? null)->toBe(3024)
        ->and($upload->processing_timings['original_height'] ?? null)->toBe(4032)
        ->and(max(
            (int) ($upload->processing_timings['dossier_width'] ?? 0),
            (int) ($upload->processing_timings['dossier_height'] ?? 0),
        ))->toBeLessThanOrEqual(2048);
});

test('wizard exposes fresh signed upload URL for empty-response retries', function () {
    $intake = makeBl128Intake();

    $url = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->instance()
        ->freshSignedUploadUrl();

    expect($url)->toBeString()
        ->and($url)->toContain('/upload-file')
        ->and($url)->toContain('signature=');
});

test('wizard accepts large fixture with client originals without wall-clock upload timeout copy', function () {
    $intake = makeBl128Intake();
    $file = bl125MeterkastGroot();

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('photoClientOriginals.fusebox_photo', [
            ['width' => 3024, 'height' => 4032],
        ])
        ->set('photoFiles.fusebox_photo', $file)
        ->assertHasNoErrors();

    $upload = IntakeUpload::query()
        ->where('intake_id', $intake->id)
        ->where('question_key', 'fusebox_photo')
        ->first();

    expect($upload)->toBeInstanceOf(IntakeUpload::class)
        ->and($upload->processing_timings['original_width'] ?? null)->toBe(3024)
        ->and($upload->processing_timings['original_height'] ?? null)->toBe(4032);

    $blade = (string) file_get_contents(resource_path('views/livewire/customer/intake-wizard.blade.php'));
    $appJs = (string) file_get_contents(resource_path('js/app.js'));
    $livewireJs = (string) file_get_contents(resource_path('js/livewire-resilience.js'));

    expect($blade)->toContain('armInactivityTimer')
        ->and($blade)->toContain('inactivityMs: 45000')
        ->and($blade)->toContain('serverWaitMs: 120000')
        ->and($blade)->toContain('armServerWaitTimer')
        ->and($blade)->toContain('data-client-downscale="1"')
        ->and($blade)->toContain('livewire-upload-progress')
        ->and($blade)->not->toContain('Uploaden duurde te lang')
        ->and($blade)->not->toContain(', 15000)')
        ->and($appJs)->toContain('registerLivewireUploadResilience')
        ->and($appJs)->toContain('preparePhotoForUpload')
        ->and($livewireJs)->toContain('freshSignedUploadUrl')
        ->and($livewireJs)->toContain('empty-upload-response');
});

test('app upload limit allows up to 8 MB phone photos', function () {
    expect((int) config('intake.uploads.max_kilobytes'))->toBe(8192);
});
