<?php

declare(strict_types=1);

use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Support\CustomerAnswerBlocks;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\ContributionMode;
use App\Enums\IntakeStatus;
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
    FakeAiClient::reset();
    config([
        'ai.provider' => 'fake',
        'ai.photo_inference.enabled' => true,
    ]);
});

afterEach(function () {
    FakeAiClient::reset();
});

function makeReview14Intake(): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::InProgress,
        'workflow_mode' => ContributionMode::Customer,
        'customer_name' => 'Review Round1',
        'customer_email' => 'review14@example.com',
        'access_token' => str_repeat('r', 64),
        'token_expires_at' => now()->addDays(14),
    ]);
}

function drainNearbyStepKey(Intake $intake): string
{
    $version = $intake->fresh()->templateVersion()->with(['sections.questions.rules'])->firstOrFail();
    $step = collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version))
        ->firstWhere('group_key', 'drain_nearby');

    expect($step)->not->toBeNull();

    return (string) $step['key'];
}

test('R1-1: keuze op afvoergroep auto-advanced niet voorbij de fotovraag', function () {
    $intake = makeReview14Intake();
    $drainKey = drainNearbyStepKey($intake);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('activeStepKey', $drainKey);

    expect($component->get('activeStepKey'))->toBe($drainKey);

    $component->set('form.drain_location.value', 'outside_nearby');

    expect($component->get('activeStepKey'))->toBe($drainKey)
        ->and($component->get('activeStepKey'))->toContain('drain');
});

test('R1-2: Toch doorgaan op drain_nearby-groep accepteert mismatch', function () {
    $intake = makeReview14Intake();
    Queue::fake([AssessUploadedPhotoJob::class]);

    FakeAiClient::alwaysReturn([
        'detected_subject' => 'outdoor_location',
        'subject_match' => 'no',
        'confidence' => 'low',
        'evidence' => 'Geen herkenbare afvoer.',
        'retake_instruction' => null,
        'drain_location' => 'unknown',
    ]);

    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'drain_photo',
        null,
        UploadedFile::fake()->image('drain-mismatch.jpg', 1200, 900),
    );
    runAssessUploadedPhotoJob($upload->id);

    $upload->refresh();
    expect($upload->contentAssessment()?->status())->toBe(PhotoContentAssessment::STATUS_WRONG_SUBJECT);

    $drainKey = drainNearbyStepKey($intake);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('activeStepKey', $drainKey)
        ->call('acceptPhotoMismatch')
        ->assertHasNoErrors();

    $upload->refresh();
    expect($upload->contentAssessment()?->customerAcceptedOverride())->toBeTrue();
});

test('R1-2: Vervang foto op drain_nearby-groep verwijdert mismatch-upload', function () {
    $intake = makeReview14Intake();
    Queue::fake([AssessUploadedPhotoJob::class]);

    FakeAiClient::alwaysReturn([
        'detected_subject' => 'outdoor_location',
        'subject_match' => 'no',
        'confidence' => 'low',
        'evidence' => 'Geen herkenbare afvoer.',
        'retake_instruction' => null,
        'drain_location' => 'unknown',
    ]);

    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'drain_photo',
        null,
        UploadedFile::fake()->image('drain-replace.jpg', 1200, 900),
    );
    runAssessUploadedPhotoJob($upload->id);
    $uploadId = $upload->id;

    $drainKey = drainNearbyStepKey($intake);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('activeStepKey', $drainKey)
        ->call('replaceMismatchedPhoto')
        ->assertHasNoErrors();

    expect(IntakeUpload::query()->whereKey($uploadId)->exists())->toBeFalse();
});

test('R1-4: wall_outlet met subject_match=no blijft ok wanneer detected in accepted set', function () {
    $accepted = PhotoSubject::acceptedSubjectsForPhotoQuestion('wall_outlet_photo', 'wall_outlet');
    expect($accepted)->toContain(PhotoSubject::Room);

    $assessment = PhotoContentAssessment::fromModelOutput(PhotoSubject::Room, [
        'detected_subject' => 'room',
        'subject_match' => 'no',
        'retake_instruction' => null,
    ], $accepted);

    expect($assessment->status())->toBe(PhotoContentAssessment::STATUS_OK);
});

test('R1-9: drain_photo accepteert Room en PipeRoute; OutdoorLocation-branch bereikbaar', function () {
    $accepted = PhotoSubject::acceptedSubjectsForPhotoQuestion('drain_photo', 'drain');

    expect($accepted)->toContain(PhotoSubject::OutdoorLocation)
        ->and($accepted)->toContain(PhotoSubject::OutdoorUnit)
        ->and($accepted)->toContain(PhotoSubject::PipeRoute)
        ->and($accepted)->toContain(PhotoSubject::Room);

    $roomOk = PhotoContentAssessment::fromModelOutput(PhotoSubject::OutdoorLocation, [
        'detected_subject' => 'room',
        'subject_match' => 'yes',
        'retake_instruction' => null,
    ], $accepted, 'drain_photo');

    expect($roomOk->status())->toBe(PhotoContentAssessment::STATUS_OK);
});

test('R1-9: gevel/tuin-advies alleen outdoor/around_house; drain behoudt bestaande tekst', function () {
    $outdoor = PhotoContentAssessment::fromModelOutput(PhotoSubject::OutdoorLocation, [
        'detected_subject' => 'outdoor_location',
        'subject_match' => 'no',
    ], PhotoSubject::acceptedSubjectsForPhotoQuestion('outdoor_location_photos', 'outdoor'), 'outdoor_location_photos');

    $around = PhotoContentAssessment::fromModelOutput(PhotoSubject::OutdoorLocation, [
        'detected_subject' => 'outdoor_location',
        'subject_match' => 'no',
    ], PhotoSubject::acceptedSubjectsForPhotoQuestion('around_house_photos', 'around_house'), 'around_house_photos');

    $drain = PhotoContentAssessment::fromModelOutput(PhotoSubject::OutdoorLocation, [
        'detected_subject' => 'outdoor_location',
        'subject_match' => 'no',
    ], PhotoSubject::acceptedSubjectsForPhotoQuestion('drain_photo', 'drain'), 'drain_photo');

    expect($outdoor->status())->toBe(PhotoContentAssessment::STATUS_WRONG_SUBJECT)
        ->and($outdoor->customerMessage())->toContain('gevel')
        ->and($around->customerMessage())->toBe($outdoor->customerMessage())
        ->and($drain->status())->toBe(PhotoContentAssessment::STATUS_WRONG_SUBJECT)
        ->and($drain->customerMessage())->not->toContain('Dit lijkt geen foto van de gevel of tuin')
        ->and($drain->customerMessage())->toContain('we hebben');
});

test('R1-6/7/10: CustomerAnswerBlocks alleen prefill_source=null, zonder consent/room-card velden', function () {
    $intake = makeReview14Intake();

    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom']);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_name', 'room-1', ['text' => 'Slaapkamer voor']);
    app(SaveIntakeAnswer::class)->handle($intake, 'floor_level', 'room-1', ['value' => '1']);
    app(SaveIntakeAnswer::class)->handle($intake, 'drain_location', null, ['value' => 'outside_nearby']);
    app(SaveIntakeAnswer::class)->handle($intake, 'privacy_consent', null, ['bool' => true]);
    app(SaveIntakeAnswer::class)->handle($intake, 'truth_confirmation', null, ['bool' => true]);

    // Prefill + stated mag niet in het blok (alleen prefill_source === null telt).
    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'ownership',
        null,
        ['value' => 'owned'],
        PrefillSources::REQUEST_TEXT,
        'stated',
    );

    $intake = $intake->fresh();
    $version = $intake->templateVersion()->with(['sections.questions'])->firstOrFail();
    $questions = $version->sections->flatMap->questions->keyBy('key');

    $blocks = CustomerAnswerBlocks::forIntake($intake);
    $itemLabels = collect($blocks)->flatMap(static fn (array $b) => collect($b['items'])->pluck('label'))->all();
    $itemValues = collect($blocks)->flatMap(static fn (array $b) => collect($b['items'])->pluck('value'))->all();

    expect($itemValues)->toContain('Regenpijp of putje buiten in de buurt');

    foreach (['privacy_consent', 'truth_confirmation', 'room_name', 'room_type', 'floor_level', 'ownership'] as $excludedKey) {
        $label = $questions->get($excludedKey)?->label;
        if (is_string($label) && $label !== '') {
            expect($itemLabels)->not->toContain($label);
        }
    }

    expect($itemValues)->not->toContain('Slaapkamer voor')
        ->and($itemValues)->not->toContain('Koop');
});
test('R1-11: bewuste installateur-clear blijft leeg na workspace-reload', function () {
    $user = User::factory()->create();
    $intake = makeReview14Intake();
    $intake->update(['created_by' => $user->id, 'company_id' => $user->company_id]);

    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom']);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_length_m', 'room-1', ['number' => 3.5]);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_width_m', 'room-1', ['number' => 3.0]);

    app(DossierManager::class)->initialize($intake->fresh());
    $room = AircoRoom::query()->where('intake_id', $intake->id)->where('key', 'room-1')->firstOrFail();

    expect((float) ($room->dimensions['length_m'] ?? 0))->toBe(3.5);

    app(AircoSurveyService::class)->updateRoom($intake, $user, $room, [
        'name' => $room->name,
        'use_type' => 'bedroom',
        'length_m' => null,
        'width_m' => null,
    ]);

    $room->refresh();
    expect($room->dimensions['dimensions_cleared_by_installer'] ?? false)->toBeTrue()
        ->and($room->dimensions['dimensions_source'] ?? null)->toBe('installer');

    app(DossierManager::class)->initialize($intake->fresh());
    $room->refresh();

    expect($room->dimensions['length_m'] ?? null)->toBeNull()
        ->and($room->dimensions['width_m'] ?? null)->toBeNull()
        ->and($room->dimensions['dimensions_cleared_by_installer'] ?? false)->toBeTrue();

    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('Maten nog leeg');
});

test('R1-12: van klant alleen bij echte klantmaten; installer/prefill anders', function () {
    $customer = CustomerAnswerBlocks::roomDimensionsCaption([
        'length_m' => 3.5,
        'width_m' => 3.0,
        'area_m2' => 10.5,
        'dimensions_source' => 'customer',
    ]);
    expect($customer)->toContain('van klant')
        ->and($customer)->toContain('3,5 × 3,0 m');

    $installer = CustomerAnswerBlocks::roomDimensionsCaption([
        'length_m' => 4.0,
        'width_m' => 3.0,
        'dimensions_source' => 'installer',
    ]);
    expect($installer)->toContain('van installateur')
        ->and($installer)->not->toContain('van klant');

    $legacyAreaInstaller = CustomerAnswerBlocks::roomDimensionsCaption([
        'area_m2' => 12.0,
        'area_source' => 'installer',
    ]);
    expect($legacyAreaInstaller)->toContain('van installateur');

    $prefill = CustomerAnswerBlocks::roomDimensionsCaption([
        'length_m' => 3.5,
        'width_m' => 3.0,
        'dimensions_source' => 'request_text',
    ]);
    expect($prefill)->not->toContain('van klant')
        ->and($prefill)->not->toContain('van installateur')
        ->and($prefill)->toContain('3,5 × 3,0 m');
});
