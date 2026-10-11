<?php

declare(strict_types=1);

use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeUpload;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
    FakeAiClient::reset();
    config([
        'ai.provider' => 'fake',
        'ai.photo_inference.enabled' => true,
        'ai.tracing.enabled' => true,
    ]);
});

afterEach(function () {
    FakeAiClient::reset();
});

function makeKlantwizard14PhotoIntake(): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
        'customer_name' => 'Foto consistentie',
        'customer_email' => 'foto-consistentie@example.com',
        'is_demo' => false,
    ]);
}

test('A4: subject_match=no met detected outdoor_location geeft advies bij outdoor, around_house en drain', function () {
    $intake = makeKlantwizard14PhotoIntake();

    // Pin de echte A4-fix: detected in accepted set + subject_match=no.
    FakeAiClient::alwaysReturn([
        'outdoor_location' => 'unknown',
        'outdoor_mount_type' => 'wall',
        'outdoor_accessibility' => 'easy_ground',
        'drain_location' => 'unknown',
        'detected_subject' => 'outdoor_location',
        'subject_match' => 'no',
        'confidence' => 'low',
        'evidence' => 'Gevel/tuin niet betrouwbaar herkenbaar.',
        'retake_instruction' => null,
    ]);

    $questionKeys = [
        'outdoor_location_photos',
        'around_house_photos',
        'drain_photo',
    ];

    Queue::fake([AssessUploadedPhotoJob::class]);

    // Verschillende afmetingen → andere checksum (geen reuse van outdoor-advies op drain).
    $sizes = [
        'outdoor_location_photos' => [1200, 900],
        'around_house_photos' => [1210, 910],
        'drain_photo' => [1220, 920],
    ];

    foreach ($questionKeys as $questionKey) {
        [$w, $h] = $sizes[$questionKey];
        $upload = app(StoreIntakeUpload::class)->handle(
            $intake,
            $questionKey,
            null,
            UploadedFile::fake()->image($questionKey.'-grey.jpg', $w, $h),
        );
        runAssessUploadedPhotoJob($upload->id);
    }

    $messages = [];
    foreach ($questionKeys as $questionKey) {
        $upload = IntakeUpload::query()
            ->where('intake_id', $intake->id)
            ->where('question_key', $questionKey)
            ->firstOrFail();

        $assessment = $upload->contentAssessment();
        expect($assessment)->not->toBeNull()
            ->and($assessment?->status())->toBe(PhotoContentAssessment::STATUS_WRONG_SUBJECT)
            ->and($assessment?->expectedSubject())->toBe(PhotoSubject::OutdoorLocation)
            ->and($assessment?->customerMessage())->not->toBeNull();

        $messages[$questionKey] = $assessment?->customerMessage();
    }

    expect($messages['outdoor_location_photos'])->toContain('gevel')
        ->and($messages['around_house_photos'])->toBe($messages['outdoor_location_photos'])
        // A4 status for drain stays wrong_subject (OutdoorLocation force); wording only differs.
        ->and($messages['drain_photo'])->not->toBe($messages['outdoor_location_photos'])
        ->and($messages['drain_photo'])->toBe(
            'Dit lijkt geen foto van de afvoer. Maak een foto van de plek waar de condensafvoer kan lozen, zoals een regenpijp, putje of goot.'
        )
        ->and($messages['drain_photo'])->not->toContain('gevel, tuin of buitenplek')
        ->and($messages['drain_photo'])->not->toContain('buitenunit')
        ->and($messages['drain_photo'])->not->toContain('we hebben');
});

test('N16: drain_photo mismatch never shows outdoor-unit place sentence', function () {
    $intake = makeKlantwizard14PhotoIntake();

    FakeAiClient::alwaysReturn([
        'drain_location' => 'unknown',
        'detected_subject' => 'indoor_unit',
        'subject_match' => 'no',
        'confidence' => 'low',
        'evidence' => 'Woonkamerinterieur; geen afvoerplek zichtbaar.',
        'retake_instruction' => null,
    ]);

    Queue::fake([AssessUploadedPhotoJob::class]);

    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'drain_photo',
        null,
        UploadedFile::fake()->image('drain-livingroom.jpg', 1230, 930),
    );
    runAssessUploadedPhotoJob($upload->id);

    $assessment = $upload->fresh()?->contentAssessment();
    expect($assessment)->not->toBeNull()
        ->and($assessment?->status())->toBe(PhotoContentAssessment::STATUS_WRONG_SUBJECT)
        ->and($assessment?->expectedSubject())->toBe(PhotoSubject::OutdoorLocation)
        ->and($assessment?->expectedSubject()?->value)->toBe('outdoor_location')
        ->and($assessment?->customerMessage())->toBe(
            'Dit lijkt geen foto van de afvoer; we zien de binnenunit. Maak een foto van de plek waar de condensafvoer kan lozen, zoals een regenpijp, putje of goot.'
        )
        ->and($assessment?->customerMessage())->not->toContain('gevel, tuin of buitenplek')
        ->and($assessment?->customerMessage())->not->toContain('buitenplek voor de unit');
});

test('N16: drain_photo model input expected_subject outdoor_location; stored verdict keys match main', function () {
    $intake = makeKlantwizard14PhotoIntake();

    FakeAiClient::alwaysReturn([
        'drain_location' => 'unknown',
        'detected_subject' => 'indoor_unit',
        'subject_match' => 'no',
        'confidence' => 'low',
        'evidence' => 'Woonkamerinterieur; geen afvoerplek zichtbaar.',
        'retake_instruction' => null,
    ]);

    Queue::fake([AssessUploadedPhotoJob::class]);

    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'drain_photo',
        null,
        UploadedFile::fake()->image('drain-model-input.jpg', 1240, 940),
    );
    runAssessUploadedPhotoJob($upload->id);

    $request = FakeAiClient::lastRequest();
    expect($request)->not->toBeNull()
        ->and($request?->input['expected_subject'] ?? null)->toBe('outdoor_location');

    $raw = $upload->fresh()?->content_assessment;
    expect($raw)->toBeArray()
        ->and(array_keys($raw ?? []))->toEqualCanonicalizing([
            'status',
            'expected_subject',
            'detected_subject',
            'customer_message',
        ])
        ->and($raw)->not->toHaveKey('question_key')
        ->and($raw['expected_subject'] ?? null)->toBe('outdoor_location');
});
