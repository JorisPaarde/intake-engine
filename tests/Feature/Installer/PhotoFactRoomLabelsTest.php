<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeExternalFact;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\ExternalFactPresenter;
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

test('N34: photo derivation fact labels and gallery captions include room place', function () {
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::InProgress,
        'customer_name' => 'Foto Labels',
    ]);

    $survey = app(AircoSurveyService::class);
    // createRoom gebruikt manual-* keys; fotovragen koppelen op room-N — forceer die keys.
    $survey->createRoom($intake, $user, [
        'name' => 'Woonkamer (begane grond)',
        'use_type' => 'living_room',
    ])->update(['key' => 'room-1']);
    $survey->createRoom($intake, $user, [
        'name' => 'Grote slaapkamer (1e verdieping)',
        'use_type' => 'bedroom',
    ])->update(['key' => 'room-2']);
    $survey->createRoom($intake, $user, [
        'name' => 'Zolderslaapkamer (2e verdieping)',
        'use_type' => 'bedroom',
    ])->update(['key' => 'room-3']);

    $store = app(StoreIntakeUpload::class);
    foreach (['room-1', 'room-2', 'room-3'] as $instance) {
        $store->handle(
            $intake,
            'room_photos',
            $instance,
            UploadedFile::fake()->image($instance.'.jpg', 640, 480),
        );

        IntakeExternalFact::query()->create([
            'intake_id' => $intake->id,
            'fact_key' => 'room_photos_derivation::'.$instance,
            'source' => 'ai_photo',
            'label' => 'Foto’s van de ruimte',
            'value' => [
                'subject_match' => true,
                'evidence' => 'Overzicht van de ruimte.',
            ],
            'confidence' => 'medium',
            'captured_at' => now(),
        ]);
    }

    $presented = app(ExternalFactPresenter::class)->present($intake->fresh());
    $labels = collect($presented['facts'])->pluck('label')->all();

    expect($labels)->toContain('Foto’s van de ruimte · Woonkamer (begane grond)')
        ->and($labels)->toContain('Foto’s van de ruimte · Grote slaapkamer (1e verdieping)')
        ->and($labels)->toContain('Foto’s van de ruimte · Zolderslaapkamer (2e verdieping)');

    $groups = app(InstallerPhotoGalleryBuilder::class)->handle($intake->fresh());
    $roomCaptions = collect($groups)
        ->flatMap(static fn (array $group): array => $group['uploads'])
        ->pluck('caption')
        ->filter(static fn (string $caption): bool => str_contains($caption, 'Foto’s van de ruimte'))
        ->values()
        ->all();

    expect($roomCaptions)->toContain('Foto’s van de ruimte · Woonkamer (begane grond)')
        ->and($roomCaptions)->toContain('Foto’s van de ruimte · Grote slaapkamer (1e verdieping)')
        ->and($roomCaptions)->toContain('Foto’s van de ruimte · Zolderslaapkamer (2e verdieping)');
});

test('N34: unnamed room falls back to Ruimte N in fact and caption labels', function () {
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::InProgress,
        'customer_name' => 'Foto Fallback',
    ]);

    app(StoreIntakeUpload::class)->handle(
        $intake,
        'room_photos',
        'room-1',
        UploadedFile::fake()->image('ruimte1.jpg', 640, 480),
    );

    IntakeExternalFact::query()->create([
        'intake_id' => $intake->id,
        'fact_key' => 'room_photos_derivation::room-1',
        'source' => 'ai_photo',
        'label' => 'Foto’s van de ruimte',
        'value' => ['subject_match' => true],
        'confidence' => 'medium',
        'captured_at' => now(),
    ]);

    $presented = app(ExternalFactPresenter::class)->present($intake->fresh());
    $labels = collect($presented['facts'])->pluck('label')->all();

    expect($labels)->toContain('Foto’s van de ruimte · Ruimte 1');

    $groups = app(InstallerPhotoGalleryBuilder::class)->handle($intake->fresh());
    expect($groups[0]['uploads'][0]['caption'])->toBe('Foto’s van de ruimte · Ruimte 1');
});
