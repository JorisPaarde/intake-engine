<?php

declare(strict_types=1);

use App\Domains\AI\Actions\AssessFollowUpPhotoSubject;
use App\Domains\AI\Actions\AssessPhotoUsability;
use App\Domains\AI\Actions\PrefillAnswersFromKnownContext;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Models\AiTrace;
use App\Domains\AI\Services\AiGateway;
use App\Domains\AI\Services\AiTracePhotoRefBuilder;
use App\Domains\AI\Services\AiTraceRecorder;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\DossierManager;
use App\Enums\AiTraceCallType;
use App\Enums\AiTraceStatus;
use App\Enums\FollowUpItemType;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    FakeAiClient::reset();
    config([
        'ai.provider' => 'fake',
        'ai.text_inference.enabled' => true,
        'ai.photo_inference.enabled' => true,
        'ai.tracing.enabled' => true,
        'filesystems.media' => 'local',
    ]);
    Storage::fake('local');
});

afterEach(function () {
    FakeAiClient::reset();
});

function makeCoverageIntake(): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_name' => 'Trace Coverage',
        'customer_email' => 'coverage@example.com',
        'address_line' => 'Coverageweg 1',
        'is_demo' => false,
    ]);
}

/**
 * @return list<string>
 */
function requiredTraceAttributes(): array
{
    return [
        'prompt_version',
        'model',
        'request_snapshot',
        'photo_refs',
        'raw_response',
        'parsed_response',
        'validation_errors',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'provider_ms',
        'process_ms',
        'estimated_cost_cents',
        'intake_ref_id',
        'request_id',
    ];
}

function assertTraceRequiredFields(AiTrace $trace): void
{
    foreach (requiredTraceAttributes() as $attribute) {
        expect($trace->{$attribute})->not->toBeNull("{$attribute} must not be null for {$trace->call_type->value}");
    }

    expect($trace->intake_ref_id)->toBeInt()
        ->and($trace->request_id)->toBeString()->not->toBeEmpty()
        ->and($trace->prompt_version)->toBeString()->not->toBeEmpty()
        ->and($trace->model)->toBeString()->not->toBeEmpty()
        ->and($trace->request_snapshot)->toBeArray()
        ->and($trace->photo_refs)->toBeArray()
        ->and($trace->parsed_response)->toBeArray()
        ->and($trace->validation_errors)->toBeArray();
}

/**
 * @return list<AiTraceCallType>
 */
function coverageCallTypes(): array
{
    return [
        AiTraceCallType::TextExtraction,
        AiTraceCallType::RequestIntent,
        AiTraceCallType::PhotoDerive,
        AiTraceCallType::PhotoAssess,
        AiTraceCallType::FollowUpPhotoSubject,
        AiTraceCallType::Summary,
        AiTraceCallType::AttentionPoints,
        AiTraceCallType::DossierSynthesis,
        AiTraceCallType::Route,
        AiTraceCallType::RouteReview,
    ];
}

test('each AI call type fills all required trace fields via recorder and fake client', function (AiTraceCallType $callType) {
    $intake = makeCoverageIntake();
    $upload = null;

    if (in_array($callType, [
        AiTraceCallType::PhotoDerive,
        AiTraceCallType::PhotoAssess,
        AiTraceCallType::FollowUpPhotoSubject,
        AiTraceCallType::Route,
        AiTraceCallType::RouteReview,
    ], true)) {
        $upload = app(StoreIntakeUpload::class)->handle(
            $intake,
            'fusebox_photo',
            null,
            UploadedFile::fake()->image('coverage.jpg', 800, 600),
        );
    }

    FakeAiClient::alwaysReturn([
        'ok' => true,
        'summary' => 'coverage',
        'highlights' => ['a'],
        'points' => [],
        'evidence' => 'coverage evidence',
        'fills' => [],
        'detected_subject' => 'fusebox',
        'subject_match' => 'yes',
        'confidence' => 'high',
        'free_group' => 'yes',
        'phase' => 'three_phase',
        'photo_usable' => true,
        'visible_elements' => ['wand'],
        'route_possible' => true,
        'route_segments' => ['segment'],
        'missing_information' => [],
        'next_photo_instruction' => 'Volgende foto',
        'route_continuous' => true,
        'proposed_route' => ['steps' => ['a']],
        'alternative_route' => null,
        'uncertainties' => [],
        'missing_checks' => [],
        'rooms' => [],
        'outdoor_units' => [],
        'connections' => [],
        'observations' => [],
    ]);

    $trace = app(AiTraceRecorder::class)->start($intake, $callType, [
        'provider' => 'fake',
        'prompt_version' => 'coverage-'.$callType->value.'-v1',
        'upload_id' => $upload?->id,
    ]);

    $photoRefs = [];
    if ($upload !== null && ! $trace->isNoop()) {
        $photoRefs = [app(AiTracePhotoRefBuilder::class)->fromUpload($upload, $callType->value)];
        $trace->linkUpload($upload);
    }

    $trace->recordRequest(
        systemAndUser: [
            'system' => 'Coverage system prompt for '.$callType->value,
            'user' => ['task' => $callType->value, 'intake_id' => $intake->id],
        ],
        photoRefs: $photoRefs,
        promptVersion: 'coverage-'.$callType->value.'-v1',
    );

    $result = app(AiGateway::class)->complete(
        prompt: 'Coverage prompt',
        input: ['task' => $callType->value],
        promptVersion: 'coverage-'.$callType->value.'-v1',
    );
    $trace->recordProviderResult($result);
    $trace->recordParsed($result->output, [], []);
    $trace->stopProcessTimer();
    $saved = $trace->succeed();

    expect($saved->status)->toBe(AiTraceStatus::Succeeded);
    assertTraceRequiredFields($saved->fresh() ?? $saved);
    expect($saved->photo_refs === [] || isset($saved->photo_refs[0]['filename']))->toBeTrue();
})->with(coverageCallTypes());

test('text_extraction action via fake client heeft geen null required fields', function () {
    $intake = makeCoverageIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'request_reason', null, [
        'text' => 'Ik wil de woonkamer koelen, 5 bij 4 meter.',
    ]);

    FakeAiClient::respondUsing(fn () => [
        'evidence' => 'coverage prefill',
        'fills' => [[
            'question_key' => 'cooling_heating',
            'section_instance_key' => null,
            'confidence' => 'high',
            'value' => ['value' => 'cooling_only'],
            'evidence' => 'koelen',
        ]],
    ]);

    app(PrefillAnswersFromKnownContext::class)->handle($intake);

    $trace = AiTrace::query()
        ->where('intake_ref_id', $intake->id)
        ->where('call_type', AiTraceCallType::TextExtraction)
        ->latest('id')
        ->first();

    expect($trace)->not->toBeNull();
    assertTraceRequiredFields($trace);
});

test('photo_assess usability via fake/heuristic heeft geen null required fields', function () {
    $intake = makeCoverageIntake();
    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('meter.jpg', 640, 480),
    );

    app(AssessPhotoUsability::class)->handle($upload);

    $trace = AiTrace::query()
        ->where('intake_ref_id', $intake->id)
        ->where('call_type', AiTraceCallType::PhotoAssess)
        ->latest('id')
        ->first();

    expect($trace)->not->toBeNull();
    assertTraceRequiredFields($trace);
});

test('follow_up_photo_subject action via fake client heeft geen null required fields', function () {
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::InProgress,
        'customer_name' => 'Follow Up Trace',
        'customer_email' => 'followup-trace@example.com',
        'address_line' => 'Followupstraat 3',
    ]);
    app(DossierManager::class)->initialize($intake);

    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('follow-up.jpg', 800, 600),
    );

    $round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [[
        'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een foto van de meterkast.',
        'decision_area_key' => 'power',
    ]]);
    $item = $round->items()->firstOrFail();

    FakeAiClient::alwaysReturn([
        'detected_subject' => 'fusebox',
        'subject_match' => 'yes',
        'evidence' => 'Duidelijke meterkast met groepen.',
    ]);

    app(AssessFollowUpPhotoSubject::class)->handle($intake->fresh(), $item, $upload);

    $trace = AiTrace::query()
        ->where('intake_ref_id', $intake->id)
        ->where('call_type', AiTraceCallType::FollowUpPhotoSubject)
        ->latest('id')
        ->first();

    expect($trace)->not->toBeNull();
    assertTraceRequiredFields($trace);
    expect($trace->photo_refs[0]['filename'] ?? null)->not->toBeNull()
        ->and($trace->photo_refs[0]['upload_id'] ?? null)->toBe($upload->id)
        ->and($trace->photo_refs[0]['question_key'] ?? null)->toBe('fusebox_photo');
});
