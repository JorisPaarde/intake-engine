<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\InstallerPhotoGalleryBuilder;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
});

function makeGalleryIntake(): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::InProgress,
        'customer_name' => 'Gallery Demo',
    ]);
}

test('installer detail page shows question labels and groups photos by section instance', function () {
    $intake = makeGalleryIntake();
    $store = app(StoreIntakeUpload::class);

    $store->handle(
        $intake,
        'room_photos',
        'room-2',
        UploadedFile::fake()->image('ruimte2.jpg', 640, 480),
    );
    $store->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('meterkast.jpg', 640, 480),
    );

    $this->actingAs(User::query()->findOrFail($intake->created_by))
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee('Foto’s van de ruimte', false)
        ->assertSee('Ruimtes 2', false)
        ->assertSee('Foto van de meterkast', false)
        ->assertSee('Elektrische installatie', false)
        ->assertDontSee('room_photos', false)
        ->assertDontSee('room-2', false)
        ->assertDontSee('fusebox_photo', false);
});

test('photo gallery builder orders groups by template section and instance', function () {
    $intake = makeGalleryIntake();
    $store = app(StoreIntakeUpload::class);

    // Store out of template order on purpose.
    $store->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('meterkast.jpg', 640, 480),
    );
    $store->handle(
        $intake,
        'room_photos',
        'room-2',
        UploadedFile::fake()->image('ruimte2.jpg', 640, 480),
    );
    $store->handle(
        $intake,
        'room_photos',
        'room-1',
        UploadedFile::fake()->image('ruimte1.jpg', 640, 480),
    );

    $groups = app(InstallerPhotoGalleryBuilder::class)->handle($intake->fresh());

    expect($groups)->toHaveCount(3)
        ->and($groups[0]['heading'])->toBe('Ruimtes 1')
        ->and($groups[0]['uploads'][0]['caption'])->toBe('Foto’s van de ruimte')
        ->and($groups[1]['heading'])->toBe('Ruimtes 2')
        ->and($groups[2]['heading'])->toBe('Elektrische installatie')
        ->and($groups[2]['uploads'][0]['caption'])->toBe('Foto van de meterkast');
});
