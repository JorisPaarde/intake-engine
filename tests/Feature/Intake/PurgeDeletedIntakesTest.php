<?php

declare(strict_types=1);

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeUpload;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
    config(['intake.retention.soft_delete_days' => 30]);
});

test('purge-deleted hard-deletes intakes past retention and removes media files', function () {
    $disk = (string) config('filesystems.media', 'local');
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    $expired = Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'customer_name' => 'Te Purgen',
    ]);

    $path = 'intakes/'.$expired->uuid.'/room_photo/test.jpg';
    Storage::disk($disk)->put($path, 'fake-image');

    IntakeUpload::query()->create([
        'intake_id' => $expired->id,
        'question_key' => 'room_photo',
        'section_instance_key' => 'room-1',
        'disk' => $disk,
        'path' => $path,
        'original_filename' => 'test.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 10,
        'sort_order' => 0,
    ]);

    $pdfPath = 'intakes/'.$expired->uuid.'/reports/rapport.pdf';
    Storage::disk($disk)->put($pdfPath, '%PDF-fake');
    $expired->report()->create([
        'html' => '<html><body>Rapport</body></html>',
        'pdf_disk' => $disk,
        'pdf_path' => $pdfPath,
        'pdf_generated_at' => now(),
        'generated_at' => now(),
    ]);

    $expired->delete();
    Intake::withTrashed()->whereKey($expired->id)->update([
        'deleted_at' => now()->subDays(31),
    ]);

    $recent = Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'customer_name' => 'Nog Bewaren',
    ]);
    $recent->delete();
    Intake::withTrashed()->whereKey($recent->id)->update([
        'deleted_at' => now()->subDays(5),
    ]);

    $active = Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'customer_name' => 'Actief',
    ]);

    Artisan::call('intakes:purge-deleted');

    expect(Intake::withTrashed()->whereKey($expired->id)->exists())->toBeFalse()
        ->and(Intake::withTrashed()->whereKey($recent->id)->exists())->toBeTrue()
        ->and(Intake::query()->whereKey($active->id)->exists())->toBeTrue()
        ->and(Storage::disk($disk)->exists($path))->toBeFalse()
        ->and(Storage::disk($disk)->exists($pdfPath))->toBeFalse();
});

test('purge-deleted keeps soft-deleted intakes within retention window', function () {
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
    ]);
    $intake->delete();

    Artisan::call('intakes:purge-deleted');

    expect(Intake::onlyTrashed()->whereKey($intake->id)->exists())->toBeTrue();
});
