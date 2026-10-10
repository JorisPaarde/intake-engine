<?php

declare(strict_types=1);

use App\Domains\AI\Actions\AssessFollowUpPhotoSubject;
use App\Domains\AI\Actions\AssessFuseboxPhotos;
use App\Domains\AI\Actions\DerivePhotoAnswers;
use App\Domains\AI\Actions\PrefillAnswersFromKnownContext;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\AI\Models\AiTrace;
use App\Domains\AI\Services\PromptVersionRepository;
use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoDerivationProfile;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreFollowUpUpload;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\DossierRecord;
use App\Domains\Intake\Models\DossierSubject;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\ContextualCustomerTaskBuilder;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Support\CustomerFacingTaskText;
use App\Domains\Intake\Support\RoomAreaAcceptance;
use App\Enums\AiTraceCallType;
use App\Enums\DossierRecordKind;
use App\Enums\DossierRecordStatus;
use App\Enums\FollowUpItemType;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
    FakeAiClient::reset();
    config([
        'ai.provider' => 'fake',
        'ai.photo_inference.enabled' => true,
        'ai.text_inference.enabled' => true,
        'ai.tracing.enabled' => true,
    ]);
});

afterEach(function () {
    FakeAiClient::reset();
});

function bl133Intake(array $overrides = []): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    $requestReason = $overrides['request_reason'] ?? 'Nog geen airco';
    unset($overrides['request_reason']);

    $intake = Intake::factory()->create(array_merge([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_name' => 'Trace Fix Klant',
        'customer_email' => 'trace-fix@example.com',
    ], $overrides));

    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'request_reason',
        null,
        ['text' => $requestReason],
    );

    return $intake->fresh();
}

test('BL-133 fusebox never fills free_group_known and forces low confidence on wrong subject', function () {
    $intake = bl133Intake();
    FakeAiClient::alwaysReturn([
        'empty_module_space' => 'none_visible',
        'phase' => 'unknown',
        'detected_subject' => 'outdoor_unit',
        'subject_match' => 'no',
        'confidence' => 'high',
        'evidence' => 'Groepsruimtes lijken bezet; dit is een buitenunit.',
        'retake_instruction' => 'Dit is een buitenunit; we hebben een foto van de meterkast nodig.',
    ]);

    // BL-143: variants run sync; keep AI for the explicit AssessFuseboxPhotos call below.
    Queue::fake([AssessUploadedPhotoJob::class]);

    app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('buiten.jpg', 1200, 900),
    );

    $run = app(AssessFuseboxPhotos::class)->handle($intake);

    expect($intake->answers()->where('question_key', 'free_group_known')->exists())->toBeFalse()
        ->and($run?->output['confidence'])->toBe('low')
        ->and($run?->output['empty_module_space'])->toBe('none_visible')
        ->and($run?->output['subject_match'])->toBe('no');
});

test('BL-133 room photo can persist glass_amount unknown and glazing_type', function () {
    $intake = bl133Intake();
    expect($intake->templateVersion->version)->toBeGreaterThanOrEqual(24);

    FakeAiClient::alwaysReturn([
        'room_type' => 'living_room',
        'room_size_indication' => 'large',
        'sun_exposure' => 'unknown',
        'glass_amount' => 'unknown',
        'glazing_type' => 'unknown',
        'room_outlet_status' => 'unknown',
        'detected_subject' => 'room',
        'subject_match' => 'yes',
        'confidence' => 'medium',
        'evidence' => 'Overzichtsfoto; glasoppervlak en type niet betrouwbaar te schatten.',
        'retake_instruction' => null,
    ]);

    app(StoreIntakeUpload::class)->handle(
        $intake,
        'room_photos',
        'room-1',
        UploadedFile::fake()->image('woonkamer.jpg', 1600, 1200),
    );

    $profile = PhotoDerivationProfile::require('room');
    app(DerivePhotoAnswers::class)->handle($intake, 'room_photos', 'room-1', $profile);

    expect($intake->answers()->where('question_key', 'glass_amount')->where('section_instance_key', 'room-1')->first()?->value)
        ->toBe(['value' => 'unknown'])
        ->and($intake->answers()->where('question_key', 'sun_exposure')->where('section_instance_key', 'room-1')->first()?->value)
        ->toBe(['value' => 'unknown'])
        ->and($intake->answers()->where('question_key', 'glazing_type')->where('section_instance_key', 'room-1')->first()?->value)
        ->toBe(['value' => 'unknown']);
});

test('BL-133 room size bands match RoomAreaAcceptance single source of truth', function () {
    expect(RoomAreaAcceptance::sizeIndicationFromArea(11.9))->toBe('small')
        ->and(RoomAreaAcceptance::sizeIndicationFromArea(12.0))->toBe('medium')
        ->and(RoomAreaAcceptance::sizeIndicationFromArea(20.0))->toBe('medium')
        ->and(RoomAreaAcceptance::sizeIndicationFromArea(24.0))->toBe('large');

    $prompt = (string) file_get_contents(base_path('app/Domains/AI/Prompts/room_assessment/prompt.md'));
    $bands = RoomAreaAcceptance::sizeIndicationBandsForPrompt();

    expect($prompt)->toContain('12 m²')
        ->and($prompt)->toContain('20 m²')
        ->and($prompt)->not->toContain('15–30')
        ->and($bands)->toContain('12')
        ->and($bands)->toContain('20');
});

test('BL-133 prompt forbids cooling from Nog geen airco; a well-behaved model leaves it empty', function () {
    $prompt = app(PromptVersionRepository::class)->body('request_prefill');
    expect($prompt)->toContain('Geen intent uit afwezigheid van airco');

    $intake = bl133Intake();
    FakeAiClient::alwaysReturn([
        'evidence' => 'Nog geen airco in de woning.',
        'fills' => [],
    ]);

    app(PrefillAnswersFromKnownContext::class)->handle($intake);

    expect($intake->answers()->where('question_key', 'cooling_heating')->exists())->toBeFalse();
});

test('BL-133 installer-internal notes never become customer tasks', function () {
    expect(CustomerFacingTaskText::isInstallerInternal(
        'Ontvangen foto lijkt een buitenunit, geen meterkast — handmatig controleren',
    ))->toBeTrue();

    $intake = bl133Intake();
    app(DossierManager::class)->initialize($intake);

    $subject = DossierSubject::query()->create([
        'intake_id' => $intake->id,
        'company_id' => $intake->company_id,
        'type' => 'airco_placement',
        'key' => 'placement:test-internal',
        'label' => 'Test',
        'meta' => [],
    ]);
    $record = DossierRecord::query()->create([
        'intake_id' => $intake->id,
        'company_id' => $intake->company_id,
        'dossier_subject_id' => $subject->id,
        'kind' => DossierRecordKind::Observation,
        'key' => 'photo_suggestion',
        'value' => ['text' => 'Ontvangen foto lijkt een buitenunit, geen meterkast — handmatig controleren'],
        'actor_type' => 'ai',
        'source_type' => 'ai',
        'method' => 'test',
        'status' => DossierRecordStatus::Proposed,
        'observed_at' => now(),
    ]);

    expect(app(ContextualCustomerTaskBuilder::class)->forPhotoSuggestion($subject, $record))->toBeNull();
});

test('BL-133 follow-up photo assessment always leaves a trace and content_assessment', function () {
    $user = User::factory()->create();
    $intake = bl133Intake([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'status' => IntakeStatus::InProgress,
    ]);
    app(DossierManager::class)->initialize($intake);

    $round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [[
        'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een duidelijke foto van de meterkast.',
        'decision_area_key' => 'power',
    ]]);
    $item = $round->items()->firstOrFail();

    FakeAiClient::alwaysReturn([
        'detected_subject' => 'fusebox',
        'subject_match' => 'yes',
        'evidence' => 'Meterkast zichtbaar van voren.',
    ]);

    $upload = app(StoreFollowUpUpload::class)->handle(
        $intake->fresh(),
        $item,
        UploadedFile::fake()->image('follow-up-meterkast.jpg', 1200, 900),
    );

    $result = app(AssessFollowUpPhotoSubject::class)->handle($intake->fresh(), $item, $upload);
    $trace = AiTrace::query()
        ->where('upload_id', $upload->id)
        ->where('call_type', AiTraceCallType::FollowUpPhotoSubject)
        ->first();

    expect($result['assessment'])->not->toBeNull()
        ->and($upload->fresh()->contentAssessment())->not->toBeNull()
        ->and($trace)->not->toBeNull()
        ->and($trace?->correlation_id)->not->toBeEmpty();
});

test('BL-133 each fusebox upload gets its own correlation_id', function () {
    $intake = bl133Intake();
    FakeAiClient::alwaysReturn([
        'empty_module_space' => 'visible',
        'phase' => 'one_phase',
        'detected_subject' => 'fusebox',
        'subject_match' => 'yes',
        'confidence' => 'high',
        'evidence' => 'Meterkast met lege modulepositie.',
        'retake_instruction' => null,
    ]);

    $firstCorrelation = (string) Str::uuid();
    $secondCorrelation = (string) Str::uuid();

    // BL-143: delay AI until after we stamp per-upload correlation ids.
    Queue::fake([AssessUploadedPhotoJob::class]);

    $first = app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('meter-1.jpg', 1200, 900),
    );
    $first->forceFill(['processing_timings' => array_merge(
        is_array($first->processing_timings) ? $first->processing_timings : [],
        ['correlation_id' => $firstCorrelation],
    )])->save();

    $second = app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('meter-2.jpg', 1400, 1000),
    );
    $second->forceFill(['processing_timings' => array_merge(
        is_array($second->processing_timings) ? $second->processing_timings : [],
        ['correlation_id' => $secondCorrelation],
    )])->save();

    app(AssessFuseboxPhotos::class)->handle($intake, correlationId: 'shared-batch-correlation');

    $traces = AiTrace::query()
        ->where('intake_id', $intake->id)
        ->where('call_type', AiTraceCallType::PhotoAssess)
        ->whereIn('upload_id', [$first->id, $second->id])
        ->get()
        ->keyBy('upload_id');

    expect($traces)->toHaveCount(2)
        ->and($traces[$first->id]->correlation_id)->toBe($firstCorrelation)
        ->and($traces[$second->id]->correlation_id)->toBe($secondCorrelation)
        ->and($traces[$first->id]->correlation_id)->not->toBe($traces[$second->id]->correlation_id)
        ->and($traces[$first->id]->correlation_id)->not->toBe('shared-batch-correlation');
});

test('BL-133 follow-up without decision area still gets content_assessment via job', function () {
    $user = User::factory()->create();
    $intake = bl133Intake([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'status' => IntakeStatus::InProgress,
    ]);
    app(DossierManager::class)->initialize($intake);

    $round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [[
        'type' => FollowUpItemType::Photo,
        'prompt' => 'Extra foto van de gewenste plek.',
        'decision_area_key' => 'placement',
    ]]);
    $item = $round->items()->firstOrFail();

    // BL-143: variants sync; run AI assessment explicitly via the job helper.
    Queue::fake([AssessUploadedPhotoJob::class]);

    $upload = app(StoreFollowUpUpload::class)->handle(
        $intake->fresh(),
        $item,
        // Shortest side must be >= 640px (PhotoUsabilityHeuristic::MIN_DIMENSION).
        UploadedFile::fake()->image('extra.jpg', 1280, 960),
    );

    runAssessUploadedPhotoJob($upload->id);

    $assessment = $upload->fresh()->contentAssessment();

    expect($assessment)->not->toBeNull()
        ->and($assessment?->status())->toBe(PhotoContentAssessment::STATUS_OK);
});
