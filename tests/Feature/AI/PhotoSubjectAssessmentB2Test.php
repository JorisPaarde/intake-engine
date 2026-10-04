<?php

declare(strict_types=1);

use App\Domains\AI\Actions\AssessFollowUpPhotoSubject;
use App\Domains\AI\Actions\AssessFuseboxPhotos;
use App\Domains\AI\Actions\DerivePhotoAnswers;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\PromptVersionRepository;
use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoDerivationProfile;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\CompleteFollowUpRound;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Actions\StoreFollowUpUpload;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\DossierDecisionArea;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\ContextualCustomerTaskBuilder;
use App\Domains\Intake\Services\DossierManager;
use App\Enums\AiRunStatus;
use App\Enums\DecisionAreaStatus;
use App\Enums\DossierNextAction;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

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

function b2Intake(array $overrides = []): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create(array_merge([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_name' => 'B2 Foto',
        'customer_email' => 'b2-foto@example.com',
        'is_demo' => false,
    ], $overrides));
}

function b2Fixture(string $name): UploadedFile
{
    $path = base_path('tests/fixtures/klanttest-20261002/'.$name);
    expect(is_file($path))->toBeTrue("Fixture ontbreekt: {$name}");

    return new UploadedFile($path, $name, mime_content_type($path) ?: 'image/png', null, true);
}

// --- P1: facade/around-house follow-up subject check ---

test('P1: placement around-house task carries structured expected photo subjects', function () {
    $intake = new Intake;
    $intake->setRelation('aircoRooms', collect());
    $intake->setRelation('aircoPlacements', collect());
    $intake->setRelation('aircoInstallationOptions', collect());

    $area = new DossierDecisionArea([
        'key' => 'placement',
        'label' => 'Plaatsing',
        'status' => DecisionAreaStatus::Blocked,
        'blocker' => 'Voeg foto’s rondom het huis toe (gevel, tuin of montageplek).',
        'next_action' => DossierNextAction::RequestContribution,
    ]);

    $draft = app(ContextualCustomerTaskBuilder::class)->forDecisionArea($intake, $area);

    expect($draft['meta']['expected_photo_subject'] ?? null)->toBe(PhotoSubject::OutdoorLocation->value)
        ->and(PhotoSubject::subjectsFromTaskMeta($draft['meta'] ?? null))->toContain(PhotoSubject::OutdoorLocation)
        ->and(PhotoSubject::acceptedSubjectsForDecisionArea('placement'))->toBeNull();
});

test('P1: empty room for facade follow-up task yields wrong_subject replacement instruction', function () {
    $user = User::factory()->create();
    $intake = b2Intake([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'status' => IntakeStatus::InProgress,
    ]);
    app(DossierManager::class)->initialize($intake);

    $draft = app(ContextualCustomerTaskBuilder::class)->forDecisionArea($intake, new DossierDecisionArea([
        'key' => 'placement',
        'status' => DecisionAreaStatus::Blocked,
        'blocker' => 'Voeg foto’s rondom het huis toe (gevel, tuin of montageplek).',
        'next_action' => DossierNextAction::RequestContribution,
    ]));
    expect($draft)->not->toBeNull();

    $round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [$draft]);
    $item = $round->items()->firstOrFail();
    $task = ContributionTask::query()->where('intake_follow_up_item_id', $item->id)->firstOrFail();

    expect($task->meta['expected_photo_subject'] ?? null)->toBe('outdoor_location');

    FakeAiClient::alwaysReturn([
        'detected_subject' => 'room',
        'subject_match' => 'no',
        'evidence' => 'Lege zolderkamer, geen gevel zichtbaar.',
    ]);

    Queue::fake([AssessUploadedPhotoJob::class]);
    $upload = app(StoreFollowUpUpload::class)->handle(
        $intake->fresh(),
        $item,
        UploadedFile::fake()->image('lege-zolder.jpg', 1200, 900),
    );

    $result = app(AssessFollowUpPhotoSubject::class)->handle($intake->fresh(), $item, $upload);
    $assessment = $result['assessment'];

    expect($assessment)->not->toBeNull()
        ->and($assessment?->status())->toBe(PhotoContentAssessment::STATUS_WRONG_SUBJECT)
        ->and($assessment?->customerMessage())->toContain('gevel')
        ->and($assessment?->customerMessage())->toContain('kamer');

    expect(fn () => app(CompleteFollowUpRound::class)->handle($intake->fresh(), $round->fresh(), []))
        ->toThrow(ValidationException::class);
});

test('P1: correct facade without existing airco satisfies around-house follow-up task', function () {
    $user = User::factory()->create();
    $intake = b2Intake([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'status' => IntakeStatus::InProgress,
    ]);
    app(DossierManager::class)->initialize($intake);

    $draft = app(ContextualCustomerTaskBuilder::class)->forDecisionArea($intake, new DossierDecisionArea([
        'key' => 'placement',
        'status' => DecisionAreaStatus::Blocked,
        'blocker' => 'Voeg foto’s rondom het huis toe (gevel, tuin of montageplek).',
        'next_action' => DossierNextAction::RequestContribution,
    ]));

    $round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [$draft]);
    $item = $round->items()->firstOrFail();

    FakeAiClient::alwaysReturn([
        'detected_subject' => 'outdoor_location',
        'subject_match' => 'yes',
        'evidence' => 'Gevel zonder bestaande buitenunit, tuin zichtbaar.',
    ]);

    Queue::fake([AssessUploadedPhotoJob::class]);
    $upload = app(StoreFollowUpUpload::class)->handle(
        $intake->fresh(),
        $item,
        b2Fixture('gevel-extra.jpg'),
    );

    $result = app(AssessFollowUpPhotoSubject::class)->handle($intake->fresh(), $item, $upload);

    expect($result['assessment']?->status())->toBe(PhotoContentAssessment::STATUS_OK)
        ->and($result['assessment']?->solvesContent())->toBeTrue()
        ->and($result['message'])->toBeNull();
});

// --- P2: missing assessment profiles ---

test('P2: wall_outlet, indoor_position, around_house and drain photos get AI assessment profiles', function (string $questionKey, string $profileName) {
    expect(PhotoDerivationProfile::find($profileName))->not->toBeNull();

    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    expect($version->version)->toBe(26);

    $question = $version->sections->flatMap->questions->firstWhere('key', $questionKey);
    expect($question)->not->toBeNull()
        ->and($question->meta['photo_analysis'] ?? null)->toBe($profileName);

    $intake = b2Intake();
    FakeAiClient::reset();

    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        $questionKey,
        $questionKey === 'wall_outlet_photo' || $questionKey === 'indoor_unit_position_photo' ? 'room-1' : null,
        UploadedFile::fake()->image($questionKey.'.jpg', 1100, 900),
    );

    runAssessUploadedPhotoJob($upload->id);

    $fresh = $upload->fresh();
    expect($fresh?->contentAssessment())->not->toBeNull()
        ->and($fresh?->contentAssessment()?->status())->not->toBe(PhotoContentAssessment::STATUS_NOT_ASSESSED);

    $skipped = AiRun::query()
        ->where('upload_id', $upload->id)
        ->where('status', AiRunStatus::Skipped)
        ->exists();
    expect($skipped)->toBeFalse();
})->with([
    ['wall_outlet_photo', 'wall_outlet'],
    ['indoor_unit_position_photo', 'indoor_position'],
    ['around_house_photos', 'around_house'],
    ['drain_photo', 'drain'],
]);

// --- P2: route photo with unit + duct ---

test('P2: indoor unit with cable duct is accepted as pipe_route not other', function () {
    $accepted = PhotoSubject::acceptedSubjectsForPhotoQuestion('pipe_route_photos', 'pipe_route');
    expect($accepted)->toContain(PhotoSubject::IndoorUnit)
        ->and($accepted)->toContain(PhotoSubject::PipeRoute);

    $assessment = PhotoContentAssessment::fromModelOutput(PhotoSubject::PipeRoute, [
        'detected_subject' => 'indoor_unit',
        'subject_match' => 'no',
        'retake_instruction' => null,
    ], $accepted);

    expect($assessment->status())->toBe(PhotoContentAssessment::STATUS_OK);

    // Prompt/schema: unit+goot must be pipe_route, never other.
    $prompt = app(PromptVersionRepository::class)->body('pipe_route_assessment');
    $meta = app(PromptVersionRepository::class)->version('pipe_route_assessment');
    expect($meta)->toBe('pipe-route-assessment-v5')
        ->and($prompt)->toContain('binnenunit mét zichtbare leidinggoot')
        ->and($prompt)->toContain('nooit')
        ->and($prompt)->toContain('other');

    $intake = b2Intake();
    FakeAiClient::alwaysReturn([
        'pipe_route_description' => 'through_room',
        'pipe_distance_indication' => 'short',
        'drillings_needed' => 'unknown',
        'detected_subject' => 'pipe_route',
        'subject_match' => 'yes',
        'confidence' => 'high',
        'evidence' => 'Daikin binnenunit met duidelijke leidinggoot en muurdoorvoer.',
        'retake_instruction' => null,
    ]);

    app(StoreIntakeUpload::class)->handle(
        $intake,
        'pipe_route_photos',
        null,
        b2Fixture('route-pipe-duct-IMG_9885.png'),
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
        ->and($upload->contentAssessment()?->detectedSubject())->toBe(PhotoSubject::PipeRoute);
});

// --- P2: fusebox free_group definition + no weak prefill ---

test('P2: fusebox prompt defines vrije groep and never prefills free_group_known', function () {
    $prompt = app(PromptVersionRepository::class)->body('fusebox_assessment');
    $version = app(PromptVersionRepository::class)->version('fusebox_assessment');

    expect($version)->toBe('fusebox-assessment-v5')
        ->and($prompt)->toContain('Vrije groep')
        ->and($prompt)->toContain('Lege modulepositie')
        ->and($prompt)->toContain('nooit');

    $intake = b2Intake();
    FakeAiClient::alwaysReturn([
        'empty_module_space' => 'visible',
        'phase' => 'one_phase',
        'detected_subject' => 'fusebox',
        'subject_match' => 'yes',
        'confidence' => 'high',
        'evidence' => 'Lege modulepositie zichtbaar; geen uitspraak over vrije groep.',
        'retake_instruction' => null,
    ]);

    app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        b2Fixture('meterkast-groot.jpg'),
    );

    app(AssessFuseboxPhotos::class)->handle($intake->fresh());

    expect($intake->answers()->where('question_key', 'free_group_known')->exists())->toBeFalse();
});

// --- P3: route/outdoor retake + mount guess ---

test('P3: pipe_route retake asking for buitenunitplek is rewritten to route instruction', function () {
    $prompt = app(PromptVersionRepository::class)->body('pipe_route_assessment');
    expect(app(PromptVersionRepository::class)->version('pipe_route_assessment'))->toBe('pipe-route-assessment-v5')
        ->and($prompt)->toContain('nooit')
        ->and($prompt)->toContain('buitenunitplek');

    $intake = b2Intake();
    // subject_match=yes zodat storeObservation draait; retake bewust fout (buitenunitplek).
    FakeAiClient::alwaysReturn([
        'pipe_route_description' => 'along_facade',
        'pipe_distance_indication' => 'medium',
        'drillings_needed' => 'unknown',
        'detected_subject' => 'outdoor_location',
        'subject_match' => 'yes',
        'confidence' => 'medium',
        'evidence' => 'Gevel bij vermoedelijke route.',
        'retake_instruction' => 'Maak een foto van de buitenunitplek.',
    ]);

    app(StoreIntakeUpload::class)->handle(
        $intake,
        'pipe_route_photos',
        null,
        UploadedFile::fake()->image('gevel-route.jpg', 1000, 800),
    );

    app(DerivePhotoAnswers::class)->handle(
        $intake,
        'pipe_route_photos',
        null,
        PhotoDerivationProfile::require('pipe_route'),
    );

    $fact = $intake->fresh()->externalFacts()
        ->where('fact_key', 'pipe_route_photos_derivation')
        ->first();

    expect($fact)->not->toBeNull()
        ->and((string) ($fact?->value['retake_instruction'] ?? ''))->toContain('leidingroute')
        ->and((string) ($fact?->value['retake_instruction'] ?? ''))->not->toContain('buitenunitplek');

    expect(PhotoSubject::PipeRoute->customerRetakePrompt())->toContain('leidingroute')
        ->and(PhotoSubject::PipeRoute->customerRetakePrompt())->not->toContain('buitenunit');
});

test('P3: outdoor assessment does not guess mount_type without clear location', function () {
    $prompt = app(PromptVersionRepository::class)->body('outdoor_assessment');
    expect(app(PromptVersionRepository::class)->version('outdoor_assessment'))->toBe('outdoor-assessment-v6')
        ->and($prompt)->toContain('Geen montage-gok')
        ->and($prompt)->toContain('zonder bestaande buitenunit');

    $intake = b2Intake();
    FakeAiClient::alwaysReturn([
        'outdoor_location' => 'unknown',
        'outdoor_mount_type' => 'wall',
        'outdoor_accessibility' => 'ladder',
        'detected_subject' => 'outdoor_location',
        'subject_match' => 'yes',
        'confidence' => 'medium',
        'evidence' => 'Voorgevel zonder duidelijke montageplek; klant noemde achtertuin.',
        'retake_instruction' => null,
    ]);

    app(StoreIntakeUpload::class)->handle(
        $intake,
        'outdoor_location_photos',
        null,
        b2Fixture('gevel-extra.jpg'),
    );

    app(DerivePhotoAnswers::class)->handle(
        $intake,
        'outdoor_location_photos',
        null,
        PhotoDerivationProfile::require('outdoor'),
    );

    expect($intake->answers()->where('question_key', 'outdoor_mount_type')->exists())->toBeFalse()
        ->and($intake->answers()->where('question_key', 'outdoor_accessibility')->exists())->toBeFalse();

    $fact = $intake->externalFacts()
        ->where('fact_key', 'outdoor_location_photos_derivation')
        ->firstOrFail();

    expect($fact->value['outdoor_mount_type'] ?? null)->toBe('unknown')
        ->and($fact->value['outdoor_accessibility'] ?? null)->toBe('unknown');
});
