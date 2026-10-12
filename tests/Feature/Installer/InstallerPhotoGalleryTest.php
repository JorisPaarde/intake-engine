<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\InstallerPhotoGalleryBuilder;
use App\Enums\FollowUpItemType;
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
    $this->withoutVite();

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
        ->assertSee('Ruimte 2', false)
        ->assertSee('Foto van de meterkast', false)
        ->assertSee('Elektrische installatie', false)
        // Interne keys niet in zichtbare tekst; ankers mogen de key bevatten.
        ->assertDontSeeText('room_photos')
        ->assertDontSeeText('room-2')
        ->assertDontSeeText('fusebox_photo');
});

test('N23: gallery thumbnails use contain and lightbox with Open origineel', function () {
    $this->withoutVite();

    $intake = makeGalleryIntake();
    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('meterkast.jpg', 1086, 1448),
    );

    $uploadUrl = route('installer.uploads.show', [$intake, $upload]);

    $html = $this->actingAs(User::query()->findOrFail($intake->created_by))
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee('object-contain', false)
        ->assertSee('aria-labelledby="photo-lightbox-title"', false)
        ->assertSee('Foto vergroten', false)
        ->assertSee('Open origineel', false)
        ->assertSee('Sluiten', false)
        ->assertSee('data-photo-lightbox-open', false)
        ->assertSee($uploadUrl, false)
        ->getContent();

    $figureChunk = '';
    if (preg_match(
        '/data-testid="gallery-upload-'.preg_quote((string) $upload->id, '/').'"[\s\S]*?<\/figure>/',
        $html,
        $matches,
    ) === 1) {
        $figureChunk = $matches[0];
    }

    expect($figureChunk)->not->toBe('')
        ->and($figureChunk)->toContain('object-contain')
        ->and($figureChunk)->not->toContain('object-cover')
        ->and($figureChunk)->toContain('data-photo-lightbox-open')
        ->and($figureChunk)->toMatch('/aria-label="[^"]*Foto vergroten/');
});

test('N16: unknown drain_photo caption is Afvoerfoto not outdoor-unit place', function () {
    $intake = makeGalleryIntake();
    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'drain_photo',
        null,
        UploadedFile::fake()->image('drain.jpg', 640, 480),
    );

    // Force the unknown-caption path (no template question meta for this key).
    $upload->forceFill(['question_key' => 'drain_photo'])->save();

    $method = new ReflectionMethod(InstallerPhotoGalleryBuilder::class, 'captionForUnknown');
    $method->setAccessible(true);
    $caption = $method->invoke(app(InstallerPhotoGalleryBuilder::class), $intake, $upload->fresh());

    expect($caption)->toBe('Afvoerfoto')
        ->and($caption)->not->toContain('buitenunit');
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
        ->and($groups[0]['heading'])->toBe('Ruimte 1')
        ->and($groups[0]['uploads'][0]['caption'])->toBe('Foto’s van de ruimte · Ruimte 1')
        ->and($groups[1]['heading'])->toBe('Ruimte 2')
        ->and($groups[1]['uploads'][0]['caption'])->toBe('Foto’s van de ruimte · Ruimte 2')
        ->and($groups[2]['heading'])->toBe('Elektrische installatie')
        ->and($groups[2]['uploads'][0]['caption'])->toBe('Foto van de meterkast');
});

test('N43: aanvullingsfoto-caption toont ronde, onderdeel en opdracht', function () {
    $intake = makeGalleryIntake();
    $user = User::query()->findOrFail($intake->created_by);
    app(DossierManager::class)->initialize($intake);

    $round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [
        [
            'type' => FollowUpItemType::Photo,
            'prompt' => 'Maak een duidelijke foto van de plek van de stroomkabel.',
            'decision_area_key' => 'power',
            'photo_task_subtype' => 'power_route',
            'dossier_subject_id' => app(DossierManager::class)->root($intake)->id,
        ],
        [
            'type' => FollowUpItemType::Photo,
            'prompt' => 'Maak een duidelijke foto van de afvoerplek.',
            'decision_area_key' => 'condensate',
            'dossier_subject_id' => app(DossierManager::class)->root($intake)->id,
        ],
    ]);

    $items = $round->items()->orderBy('id')->get();
    expect($items)->toHaveCount(2);

    $disk = (string) config('filesystems.media', 'local');
    foreach ($items as $index => $item) {
        $name = $index === 0 ? 'stroom.jpg' : 'condens.jpg';
        $path = 'intakes/'.$intake->uuid.'/follow-up/'.$name;
        Storage::disk($disk)->put($path, 'fake-bytes-'.$name);
        IntakeUpload::query()->create([
            'intake_id' => $intake->id,
            'question_key' => 'follow_up_'.$item->id,
            'intake_follow_up_item_id' => $item->id,
            'disk' => $disk,
            'path' => $path,
            'original_filename' => $name,
            'mime_type' => 'image/jpeg',
            'size_bytes' => 12,
            'checksum' => hash('sha256', $name),
            'sort_order' => $index,
        ]);
    }

    $groups = app(InstallerPhotoGalleryBuilder::class)->handle($intake->fresh());
    $followUp = collect($groups)->first(
        static fn (array $group): bool => str_starts_with($group['heading'], 'Aanvulling ronde'),
    );

    expect($followUp)->not->toBeNull()
        ->and($followUp['uploads'])->toHaveCount(2);

    $captions = array_column($followUp['uploads'], 'caption');
    expect($captions[0])->toBe('Ronde 1 · Stroomtoevoer · Plek stroomkabel')
        ->and($captions[1])->toBe('Ronde 1 · Condensafvoer')
        ->and($captions[0])->not->toContain('Maak een duidelijke foto')
        ->and($captions[1])->not->toContain('Maak een duidelijke foto')
        ->and($captions[1])->not->toContain('Afvoer');
});

test('N43: power zonder subtype/subject blijft Ronde N · gebied (geen Meterkast-gok)', function () {
    $intake = makeGalleryIntake();
    $user = User::query()->findOrFail($intake->created_by);
    app(DossierManager::class)->initialize($intake);

    $round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [[
        'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een duidelijke foto van de stroomkabel.',
        'decision_area_key' => 'power',
        'dossier_subject_id' => app(DossierManager::class)->root($intake)->id,
    ]]);
    $item = $round->items()->firstOrFail();

    $disk = (string) config('filesystems.media', 'local');
    $path = 'intakes/'.$intake->uuid.'/follow-up/kabel.jpg';
    Storage::disk($disk)->put($path, 'fake-bytes');
    IntakeUpload::query()->create([
        'intake_id' => $intake->id,
        'question_key' => 'follow_up_'.$item->id,
        'intake_follow_up_item_id' => $item->id,
        'disk' => $disk,
        'path' => $path,
        'original_filename' => 'kabel.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 10,
        'checksum' => hash('sha256', 'fake-bytes'),
        'sort_order' => 0,
    ]);

    $groups = app(InstallerPhotoGalleryBuilder::class)->handle($intake->fresh());
    $followUp = collect($groups)->first(
        static fn (array $group): bool => str_starts_with($group['heading'], 'Aanvulling ronde'),
    );

    expect($followUp)->not->toBeNull()
        ->and($followUp['uploads'][0]['caption'])->toBe('Ronde 1 · Stroomtoevoer')
        ->and($followUp['uploads'][0]['caption'])->not->toContain('Meterkast');
});
