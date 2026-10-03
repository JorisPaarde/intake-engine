<?php

declare(strict_types=1);

use App\Domains\AI\Actions\DerivePhotoAnswers;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Support\OwnershipNormalizer;
use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoDerivationProfile;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Support\PrefillSources;
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
        'ai.text_inference.enabled' => true,
        'ai.classification_temperature' => 0,
    ]);
});

afterEach(function () {
    FakeAiClient::reset();
});

function visionQualityIntake(array $overrides = []): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create(array_merge([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_name' => 'Vision Quality',
        'customer_email' => 'vision-quality@example.com',
        'access_token' => 'visionqual'.str_repeat('a', 54),
    ], $overrides));
}

function visionQualityFixture(string $name): UploadedFile
{
    $path = base_path('tests/fixtures/klanttest-20261002/'.$name);
    expect(is_file($path))->toBeTrue("Fixture ontbreekt: {$name}");

    return new UploadedFile($path, $name, mime_content_type($path) ?: 'image/png', null, true);
}

test('OwnershipNormalizer maps typical Dutch ownership phrasings', function (string $phrase, string $expected) {
    expect((new OwnershipNormalizer)->normalize($phrase))->toBe($expected);
})->with([
    ['owned', 'owned'],
    ['rented', 'rented'],
    ['koop', 'owned'],
    ['koophuis', 'owned'],
    ['koopwoning', 'owned'],
    ['eigen woning', 'owned'],
    ['eigen huis', 'owned'],
    ['in eigendom', 'owned'],
    ['huur', 'rented'],
    ['huurwoning', 'rented'],
    ['huurhuis', 'rented'],
    ['we huren', 'rented'],
    ['wij huren', 'rented'],
    ['ik huur', 'rented'],
    ['Het is een goed geïsoleerde koopwoning', 'owned'],
    ['We huren een appartement', 'rented'],
]);

test('OwnershipNormalizer returns null for ambiguous text', function () {
    expect((new OwnershipNormalizer)->normalize('woning in Utrecht'))->toBeNull()
        ->and((new OwnershipNormalizer)->normalize(''))->toBeNull()
        ->and((new OwnershipNormalizer)->normalize(null))->toBeNull();
});

test('route fixture with pipe_route detection never blocks customer', function () {
    $intake = visionQualityIntake();

    FakeAiClient::alwaysReturn([
        'pipe_route_description' => 'along_facade',
        'pipe_distance_indication' => 'medium',
        'drillings_needed' => 'unknown',
        'detected_subject' => 'pipe_route',
        'subject_match' => 'yes',
        'confidence' => 'high',
        'evidence' => 'Goot met leidingen, bochten en doorvoer zichtbaar; binnenunit in beeld.',
        'retake_instruction' => null,
    ]);

    app(StoreIntakeUpload::class)->handle(
        $intake,
        'pipe_route_photos',
        null,
        visionQualityFixture('route-pipe-duct-IMG_9885.png'),
    );

    app(DerivePhotoAnswers::class)->handle(
        $intake,
        'pipe_route_photos',
        null,
        PhotoDerivationProfile::require('pipe_route'),
    );

    $upload = IntakeUpload::query()
        ->where('intake_id', $intake->id)
        ->where('question_key', 'pipe_route_photos')
        ->firstOrFail();

    expect($upload->contentAssessment()?->status())->toBe(PhotoContentAssessment::STATUS_OK)
        ->and($upload->contentAssessment()?->detectedSubject())->toBe(PhotoSubject::PipeRoute)
        ->and(FakeAiClient::lastRequest()?->temperature)->toBe(0.0);
});

test('route mapping accepts room wall/ceiling photos and outdoor unit in route context', function (string $detected) {
    $expected = PhotoSubject::PipeRoute;
    $accepted = PhotoSubject::acceptedSubjectsForPhotoQuestion('pipe_route_photos', 'pipe_route');

    $assessment = PhotoContentAssessment::fromModelOutput($expected, [
        'detected_subject' => $detected,
        'subject_match' => 'no',
        'retake_instruction' => 'Maak een scherpere foto.',
    ], $accepted);

    expect($assessment->status())->toBe(PhotoContentAssessment::STATUS_OK)
        ->and($assessment->solvesContent())->toBeTrue();
})->with(['pipe_route', 'room', 'outdoor_unit', 'outdoor_location']);

test('route mapping rejects fusebox with message naming missing route part', function () {
    $expected = PhotoSubject::PipeRoute;
    $accepted = PhotoSubject::acceptedSubjectsForPhotoQuestion('pipe_route_photos', 'pipe_route');

    $assessment = PhotoContentAssessment::fromModelOutput($expected, [
        'detected_subject' => 'fusebox',
        'subject_match' => 'no',
        'retake_instruction' => null,
    ], $accepted);

    expect($assessment->status())->toBe(PhotoContentAssessment::STATUS_WRONG_SUBJECT)
        ->and($assessment->customerMessage())->toContain('leidingroute')
        ->and($assessment->customerMessage())->toContain('goot');
});

test('pipe_route detected subject never wrong_subject even without accepted set', function () {
    $assessment = PhotoContentAssessment::fromModelOutput(PhotoSubject::PipeRoute, [
        'detected_subject' => 'pipe_route',
        'subject_match' => 'no',
        'retake_instruction' => 'Vervang deze foto.',
    ]);

    expect($assessment->status())->toBe(PhotoContentAssessment::STATUS_OK);
});

test('wrong fusebox photo keeps Vervang foto messaging', function () {
    $assessment = PhotoContentAssessment::wrongSubject(PhotoSubject::Fusebox, PhotoSubject::OutdoorUnit);

    expect($assessment->customerMessage())->toContain('meterkast')
        ->and($assessment->customerMessage())->toContain('buitenunit');
});

test('room_name from customer syncs to installer airco room labels; installer rename wins', function () {
    $intake = visionQualityIntake();

    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 2]);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom'], PrefillSources::AI_TEXT);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-2', ['value' => 'bedroom'], PrefillSources::AI_TEXT);

    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    expect($intake->fresh()->aircoRooms()->where('key', 'room-1')->value('name'))->toBe('Slaapkamer 1')
        ->and($intake->fresh()->aircoRooms()->where('key', 'room-2')->value('name'))->toBe('Slaapkamer 2');

    app(SaveIntakeAnswer::class)->handle($intake, 'room_name', 'room-1', ['text' => 'Ouders']);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_name', 'room-2', ['text' => 'Kind']);

    $rooms = $intake->fresh()->aircoRooms()->orderBy('key')->get();
    expect($rooms->firstWhere('key', 'room-1')?->name)->toBe('Ouders')
        ->and($rooms->firstWhere('key', 'room-2')?->name)->toBe('Kind');

    $installer = User::factory()->create(['company_id' => $intake->company_id]);
    app(\App\Domains\Intake\Services\AircoSurveyService::class)->updateRoom(
        $intake,
        $installer,
        $rooms->firstWhere('key', 'room-1'),
        ['name' => 'Master', 'length_m' => 4.0, 'width_m' => 3.0, 'height_m' => 2.5],
    );

    app(SaveIntakeAnswer::class)->handle($intake, 'room_name', 'room-1', ['text' => 'Ouders opnieuw']);
    expect($intake->fresh()->aircoRooms()->where('key', 'room-1')->value('name'))->toBe('Master')
        ->and($intake->fresh()->aircoRooms()->where('key', 'room-1')->value('name_source'))->toBe('installer');
});

test('known ownership and room_name are not asked again in the customer wizard', function () {
    $intake = visionQualityIntake();

    app(SaveIntakeAnswer::class)->handle($intake, 'ownership', null, ['value' => 'owned'], PrefillSources::AI_TEXT);
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 2], PrefillSources::AI_TEXT);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom'], PrefillSources::AI_TEXT);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_name', 'room-1', ['text' => 'Ouders'], PrefillSources::AI_TEXT);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-2', ['value' => 'bedroom'], PrefillSources::AI_TEXT);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_name', 'room-2', ['text' => 'Kind'], PrefillSources::AI_TEXT);

    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    $steps = collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version))->pluck('question_key');

    expect($steps)->not->toContain('ownership')
        ->and($steps)->not->toContain('room_name');
});

test('catalog ownership synonym fill is normalized to owned/rented', function () {
    $intake = visionQualityIntake();
    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'request_reason',
        null,
        ['text' => 'Koophuis met twee slaapkamers koelen. Extra toelichting voor lengte.'],
    );

    FakeAiClient::alwaysReturn([
        'evidence' => 'Koophuis genoemd.',
        'fills' => [
            [
                'question_key' => 'ownership',
                'section_instance_key' => null,
                'confidence' => 'high',
                'value' => ['value' => 'koophuis'],
                'evidence' => 'koophuis',
            ],
            [
                'question_key' => 'cooling_heating',
                'section_instance_key' => null,
                'confidence' => 'high',
                'value' => ['value' => 'cooling'],
                'evidence' => null,
            ],
        ],
    ]);

    app(\App\Domains\AI\Actions\PrefillAnswersFromKnownContext::class)->handle($intake);

    expect($intake->answers()->where('question_key', 'ownership')->firstOrFail()->value)
        ->toBe(['value' => 'owned']);
});

test('empty living room with unknown outlets does not trigger wall_outlet_photo', function () {
    $intake = visionQualityIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);

    FakeAiClient::alwaysReturn([
        'room_type' => 'living_room',
        'room_size_indication' => 'large',
        'sun_exposure' => 'unknown',
        'glass_amount' => 'much',
        'room_outlet_status' => 'unknown',
        'detected_subject' => 'room',
        'subject_match' => 'yes',
        'confidence' => 'high',
        'evidence' => 'Lege woonkamer met grote ramen; geen stopcontact beoordeelbaar.',
        'retake_instruction' => null,
    ]);

    app(StoreIntakeUpload::class)->handle(
        $intake,
        'room_photos',
        'room-1',
        UploadedFile::fake()->image('woonkamer.jpg', 1200, 900),
    );

    app(DerivePhotoAnswers::class)->handle(
        $intake,
        'room_photos',
        'room-1',
        PhotoDerivationProfile::require('room'),
    );

    $version = $intake->templateVersion()->with(['sections.questions.rules'])->firstOrFail();
    $steps = collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version))->pluck('question_key');

    expect($intake->answers()->where('question_key', 'room_outlet_status')->exists())->toBeFalse()
        ->and($steps)->not->toContain('wall_outlet_photo')
        ->and($intake->answers()->where('question_key', 'glass_amount')->firstOrFail()->value)
        ->toBe(['value' => 'much']);
});
