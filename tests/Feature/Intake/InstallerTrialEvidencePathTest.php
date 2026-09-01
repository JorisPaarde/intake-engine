<?php

declare(strict_types=1);

use App\Domains\AI\Actions\AssessFuseboxPhotos;
use App\Domains\AI\Actions\DerivePhotoAnswers;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Support\PhotoDerivationProfile;
use App\Domains\Intake\Actions\EnrichIntakeAddress;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Data\EnergyLabel;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\DecisionReadinessService;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Enums\DecisionAreaStatus;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
    FakeAiClient::reset();
    config([
        'ai.provider' => 'fake',
        'ai.photo_inference.enabled' => true,
    ]);
});

afterEach(function () {
    FakeAiClient::reset();
});

function makeBl074Intake(): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_name' => 'Trial Klant',
        'customer_email' => 'trial@example.com',
    ]);
}

/** @return list<string> */
function bl074StepKeys(Intake $intake): array
{
    $version = $intake->templateVersion()
        ->with(['sections.questions.options', 'sections.questions.rules'])
        ->firstOrFail();

    return collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version))
        ->pluck('question_key')
        ->all();
}

test('airco v12 requires meterkast and around-house photos without a standalone phase question', function () {
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    expect($version->version)->toBe(12);

    $electrical = $version->sections()->where('key', 'electrical')->firstOrFail();
    $outdoor = $version->sections()->where('key', 'outdoor_unit')->firstOrFail();
    $building = $version->sections()->where('key', 'building')->firstOrFail();
    $rooms = $version->sections()->where('key', 'rooms')->firstOrFail();

    $fusebox = $electrical->questions()->where('key', 'fusebox_photo')->firstOrFail();
    $around = $outdoor->questions()->where('key', 'around_house_photos')->firstOrFail();

    expect($fusebox->is_required)->toBeTrue()
        ->and($around->is_required)->toBeTrue()
        ->and($electrical->questions()->where('key', 'electrical_phase')->exists())->toBeFalse()
        ->and($electrical->questions()->where('key', 'fusebox_photo_extra')->exists())->toBeTrue()
        ->and($building->questions()->where('key', 'crawl_space_present')->exists())->toBeTrue()
        ->and($building->questions()->where('key', 'floor_insulation')->exists())->toBeTrue()
        ->and($rooms->questions()->where('key', 'room_length_m')->exists())->toBeTrue()
        ->and($rooms->questions()->where('key', 'wall_outlet_photo')->exists())->toBeTrue();
});

test('blurry fusebox assessment asks for an extra meterkast photo instead of a phase question', function () {
    $intake = makeBl074Intake();
    FakeAiClient::alwaysReturn([
        'free_group' => 'unknown',
        'phase' => 'unknown',
        'confidence' => 'low',
        'evidence' => 'De foto is onscherp; fase en groepen zijn niet leesbaar.',
        'retake_instruction' => 'Fotografeer dichterbij en scherper.',
    ]);

    app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('meterkast-blur.jpg', 800, 600),
    );

    app(AssessFuseboxPhotos::class)->handle($intake);

    $clarity = $intake->answers()->where('question_key', 'fusebox_clarity')->firstOrFail();
    $fact = $intake->externalFacts()->where('fact_key', 'fusebox_photo_assessment')->firstOrFail();
    $steps = bl074StepKeys($intake);

    expect($clarity->value)->toBe(['value' => 'needs_clearer_photo'])
        ->and($fact->value['phase'])->toBe('unknown')
        ->and($steps)->toContain('fusebox_photo_extra')
        ->and($steps)->not->toContain('fusebox_clarity')
        ->and($steps)->not->toContain('electrical_phase');
});

test('clear fusebox assessment stores phase and skips the extra meterkast photo', function () {
    $intake = makeBl074Intake();
    FakeAiClient::alwaysReturn([
        'free_group' => 'yes',
        'phase' => 'three_phase',
        'confidence' => 'high',
        'evidence' => 'Drie faseleidingen en een vrije groep zijn zichtbaar.',
        'retake_instruction' => null,
    ]);

    app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('meterkast.jpg', 1200, 900),
    );

    app(AssessFuseboxPhotos::class)->handle($intake);

    $steps = bl074StepKeys($intake);
    $fact = $intake->externalFacts()->where('fact_key', 'fusebox_photo_assessment')->firstOrFail();

    expect($fact->value['phase'])->toBe('three_phase')
        ->and($intake->answers()->where('question_key', 'fusebox_clarity')->firstOrFail()->value)
        ->toBe(['value' => 'clear'])
        ->and($steps)->not->toContain('fusebox_photo_extra')
        ->and($steps)->not->toContain('electrical_phase');
});

test('room photo without visible outlets requires an extra wall photo not a yes-no question', function () {
    $intake = makeBl074Intake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);

    FakeAiClient::alwaysReturn([
        'room_type' => 'bedroom',
        'room_size_indication' => 'medium',
        'sun_exposure' => 'medium',
        'glass_amount' => 'average',
        'room_outlet_status' => 'needs_photo',
        'confidence' => 'high',
        'evidence' => 'De ruimte is zichtbaar maar geen stopcontact staat in beeld.',
        'retake_instruction' => null,
    ]);

    app(StoreIntakeUpload::class)->handle(
        $intake,
        'room_photos',
        'room-1',
        UploadedFile::fake()->image('kamer.jpg', 1200, 900),
    );

    app(DerivePhotoAnswers::class)->handle(
        $intake,
        'room_photos',
        'room-1',
        PhotoDerivationProfile::require('room'),
    );

    $status = $intake->answers()
        ->where('question_key', 'room_outlet_status')
        ->where('section_instance_key', 'room-1')
        ->firstOrFail();
    $steps = bl074StepKeys($intake);

    expect($status->value)->toBe(['value' => 'needs_photo'])
        ->and($steps)->toContain('wall_outlet_photo')
        ->and($steps)->not->toContain('room_outlet_status');
});

test('visible outlets on the room photo skip the extra wall photo', function () {
    $intake = makeBl074Intake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);

    FakeAiClient::alwaysReturn([
        'room_type' => 'living_room',
        'room_size_indication' => 'large',
        'sun_exposure' => 'high',
        'glass_amount' => 'much',
        'room_outlet_status' => 'present',
        'confidence' => 'high',
        'evidence' => 'Twee stopcontacten zijn zichtbaar op de linkerwand.',
        'retake_instruction' => null,
    ]);

    app(StoreIntakeUpload::class)->handle(
        $intake,
        'room_photos',
        'room-1',
        UploadedFile::fake()->image('kamer-outlet.jpg', 1200, 900),
    );

    app(DerivePhotoAnswers::class)->handle(
        $intake,
        'room_photos',
        'room-1',
        PhotoDerivationProfile::require('room'),
    );

    $steps = bl074StepKeys($intake);

    expect($intake->answers()->where('question_key', 'room_outlet_status')->firstOrFail()->value)
        ->toBe(['value' => 'present'])
        ->and($steps)->not->toContain('wall_outlet_photo')
        ->and($steps)->not->toContain('room_outlet_status');
});

test('floor insulation is skipped when EP-Online already answered insulation', function () {
    $intake = makeBl074Intake();

    $enricher = app(EnrichIntakeAddress::class);
    $method = new \ReflectionMethod($enricher, 'prefillFromEnergyLabel');
    $method->invoke(
        $enricher,
        $intake,
        new EnergyLabel(
            energyClass: 'A',
            energyDemandKwhM2: 40.0,
            buildingType: 'rijwoning',
            buildingClass: 'W',
            registeredAt: '2024-01-01',
            validUntil: null,
        ),
    );

    $steps = bl074StepKeys($intake);

    expect($intake->answers()->where('question_key', 'floor_insulation')->firstOrFail()->value)
        ->toBe(['value' => 'yes'])
        ->and($intake->answers()->where('question_key', 'floor_insulation')->firstOrFail()->prefill_source)
        ->toBe('epo')
        ->and($steps)->not->toContain('floor_insulation');
});

test('decision readiness blocks power without meterkast photo and placement without around-house photo', function () {
    $intake = makeBl074Intake();
    $areas = app(DecisionReadinessService::class)->recalculate($intake);

    expect($areas->firstWhere('key', 'power')?->status)->toBe(DecisionAreaStatus::Blocked)
        ->and($areas->firstWhere('key', 'power')?->blocker)->toContain('meterkastfoto')
        ->and($areas->firstWhere('key', 'placement')?->status)->toBe(DecisionAreaStatus::Blocked)
        ->and($areas->firstWhere('key', 'placement')?->blocker)->toContain('rondom het huis');

    app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('meterkast.jpg', 800, 600),
    );
    app(StoreIntakeUpload::class)->handle(
        $intake,
        'around_house_photos',
        null,
        UploadedFile::fake()->image('gevel.jpg', 800, 600),
    );

    $areas = app(DecisionReadinessService::class)->recalculate($intake->fresh());

    expect($areas->firstWhere('key', 'power')?->blocker)->not->toContain('meterkastfoto')
        ->and($areas->firstWhere('key', 'placement')?->blocker)->not->toContain('rondom het huis');
});
