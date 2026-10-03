<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\HardDeleteIntake;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeUpload;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

test('hard delete removes the intake upload directory including empty leftovers', function () {
    $root = sys_get_temp_dir().'/intake-hard-delete-'.uniqid('', true);
    mkdir($root, 0777, true);

    config([
        'filesystems.disks.hard_delete_test' => [
            'driver' => 'local',
            'root' => $root,
            'throw' => false,
        ],
        'filesystems.media' => 'hard_delete_test',
    ]);

    $disk = 'hard_delete_test';
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
    ]);

    $path = 'intakes/'.$intake->uuid.'/room_photos/room-1/photo.jpg';
    $analysis = 'intakes/'.$intake->uuid.'/room_photos/room-1/analysis/photo.jpg';
    Storage::disk($disk)->put($path, 'image-bytes');
    Storage::disk($disk)->put($analysis, 'analysis-bytes');

    IntakeUpload::query()->create([
        'intake_id' => $intake->id,
        'question_key' => 'room_photos',
        'section_instance_key' => 'room-1',
        'disk' => $disk,
        'path' => $path,
        'analysis_path' => $analysis,
        'original_filename' => 'photo.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 11,
        'sort_order' => 0,
    ]);

    $absoluteIntakeDir = $root.'/intakes/'.$intake->uuid;
    expect(is_dir($absoluteIntakeDir))->toBeTrue();

    app(HardDeleteIntake::class)->handle($intake);

    expect(Intake::withTrashed()->whereKey($intake->id)->exists())->toBeFalse()
        ->and(Storage::disk($disk)->exists($path))->toBeFalse()
        ->and(Storage::disk($disk)->exists($analysis))->toBeFalse()
        ->and(is_dir($absoluteIntakeDir))->toBeFalse();

    // Cleanup temp root.
    @rmdir($root.'/intakes');
    @rmdir($root);
});
