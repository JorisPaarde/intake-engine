<?php

declare(strict_types=1);

use App\Domains\AI\Actions\DeriveIntentFromRequest;
use App\Domains\AI\Actions\DerivePhotoAnswers;
use App\Domains\AI\Actions\PrefillAnswersFromKnownContext;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Exceptions\AiClientException;
use App\Domains\AI\Models\AiTrace;
use App\Domains\AI\Services\AiTraceHandle;
use App\Domains\AI\Services\AiTraceRecorder;
use App\Domains\AI\Services\AiTraceRedactor;
use App\Domains\AI\Support\PhotoDerivationProfile;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Enums\AiRunStatus;
use App\Enums\AiTraceCallType;
use App\Enums\AiTraceStatus;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    FakeAiClient::reset();
    config([
        'ai.provider' => 'fake',
        'ai.text_inference.enabled' => true,
        'ai.photo_inference.enabled' => true,
        'ai.tracing.enabled' => true,
        'ai.tracing.retention_days' => 30,
        'filesystems.media' => 'local',
    ]);
    Storage::fake('local');
});

afterEach(function () {
    FakeAiClient::reset();
});

const CASE_81_TEXT = <<<'TXT'
Fictieve QA-test. Ik wil twee slaapkamers koelen en verwarmen. Slaapkamer ouders op de 1e verdieping: 4 bij 3 meter, plafond 2,5 meter. Kinderkamer op de 1e verdieping: 3 bij 3 meter, plafond 2,5 meter. Het is een goed geïsoleerde koopwoning met vloerisolatie en een kruipruimte. Ik heb geen merkvoorkeur en geen haast. Een buitenunit tegen de achtergevel; de buren zitten dichtbij. Zichtbare leidingen in een goot vind ik prima. Over de stroomvoorziening en condensafvoer weet ik niets. De installateur moet technische keuzes bepalen.
TXT;

const CASE_80_TEXT = <<<'TXT'
Fictieve QA-test case 80. Woonkamer 6 bij 4 meter, plafond 2,6 meter, alleen koelen. Binnenunit boven bank bij buitenmuur, buitenunit liefst op grond achtertuin. Stroom onbekend.
TXT;

function makeTraceIntake(array $overrides = []): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create(array_merge([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_email' => 'trace-qa@example.com',
    ], $overrides));
}

function fixturePath(string $name): string
{
    return base_path('tests/fixtures/klanttest-20261002/'.$name);
}

test('case 81 tekstextractie legt volledige AI-trace keten vast', function () {
    $intake = makeTraceIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'request_reason', null, ['text' => CASE_81_TEXT]);

    FakeAiClient::respondUsing(function () {
        return [
            'evidence' => 'Case 81 catalogusprefill uit openingszin.',
            'fills' => [
                [
                    'question_key' => 'cooling_heating',
                    'section_instance_key' => null,
                    'confidence' => 'high',
                    'value' => ['value' => 'both'],
                    'evidence' => 'koelen en verwarmen',
                ],
                [
                    'question_key' => 'indoor_unit_count',
                    'section_instance_key' => null,
                    'confidence' => 'high',
                    'value' => ['number' => 2],
                    'evidence' => 'twee slaapkamers',
                ],
                [
                    'question_key' => 'brand_preference',
                    'section_instance_key' => null,
                    'confidence' => 'medium',
                    'value' => ['text' => 'geen'],
                    'evidence' => 'geen merkvoorkeur',
                ],
                [
                    'question_key' => 'does_not_exist_xyz',
                    'section_instance_key' => null,
                    'confidence' => 'high',
                    'value' => ['value' => 'x'],
                    'evidence' => 'moet worden afgewezen',
                ],
            ],
        ];
    });

    $run = app(PrefillAnswersFromKnownContext::class)->handle($intake);

    expect($run?->status)->toBe(AiRunStatus::Succeeded);

    $trace = AiTrace::query()
        ->where('intake_id', $intake->id)
        ->where('call_type', AiTraceCallType::TextExtraction)
        ->latest('id')
        ->first();

    expect($trace)->not->toBeNull()
        ->and($trace->status)->toBe(AiTraceStatus::Succeeded)
        ->and($trace->trace_id)->not->toBeEmpty()
        ->and($trace->ai_run_id)->toBe($run->id)
        ->and($trace->provider)->toBe('fake')
        ->and($trace->model)->toBe('fake-v1')
        ->and($trace->prompt_version)->not->toBeEmpty()
        ->and($trace->request_snapshot)->toBeArray()
        ->and($trace->raw_response)->toBeString()->not->toBeEmpty()
        ->and($trace->finish_reason)->toBe('stop')
        ->and($trace->parsed_response)->toBeArray()
        ->and($trace->field_outcomes)->toBeArray()->not->toBeEmpty()
        ->and($trace->dossier_before)->toBeArray()
        ->and($trace->dossier_after)->toBeArray()
        ->and($trace->dossier_after['changed_fields'] ?? null)->toBeArray()
        ->and($trace->remaining_questions_before)->toBeArray()
        ->and($trace->remaining_questions_before['questions'] ?? null)->toBeArray()
        ->and($trace->remaining_questions_before['next_step'] ?? false)->not->toBeFalse()
        ->and($trace->remaining_questions_after)->toBeArray()
        ->and($trace->provider_ms)->not->toBeNull();

    $stepKeys = $trace->steps()->orderBy('sequence')->pluck('step_key')->all();
    expect($stepKeys)->toContain('start')
        ->and($stepKeys)->toContain('request')
        ->and($stepKeys)->toContain('provider')
        ->and($stepKeys)->toContain('parse')
        ->and($stepKeys)->toContain('field_outcomes')
        ->and($stepKeys)->toContain('apply')
        ->and($stepKeys)->toContain('dossier_update')
        ->and($stepKeys)->toContain('customer_steps')
        ->and($stepKeys)->toContain('succeed')
        ->and($stepKeys)->not->toContain('snapshot_before')
        ->and($stepKeys)->not->toContain('customer_step');

    $blob = (string) json_encode($trace->toArray() + ['steps' => $trace->steps->toArray()]);
    expect($blob)->not->toContain('sk-')
        ->and($blob)->not->toContain('Bearer ')
        ->and($blob)->not->toContain('base64,');
});

test('case 80 lokale extractie en catalogus-AI delen traceerbare keten', function () {
    $intake = makeTraceIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'request_reason', null, ['text' => CASE_80_TEXT]);

    $run = app(DeriveIntentFromRequest::class)->handle($intake, allowExternal: true);

    expect($run)->not->toBeNull();

    $traces = AiTrace::query()->where('intake_id', $intake->id)->where('call_type', AiTraceCallType::TextExtraction)->get();
    expect($traces->count())->toBeGreaterThanOrEqual(1);

    foreach ($traces as $trace) {
        expect($trace->trace_id)->toMatch('/^[0-9a-f-]{36}$/i')
            ->and($trace->request_snapshot)->not->toBeNull()
            ->and($trace->status->value)->toBeIn(['succeeded', 'failed']);
    }
});

test('mislukte foto-AI wist geen bestaand dossierantwoord', function (string $fixtureName) {
    $intake = makeTraceIntake(['status' => IntakeStatus::InProgress]);

    IntakeAnswer::query()->create([
        'intake_id' => $intake->id,
        'question_key' => 'room_type',
        'section_instance_key' => 'room-1',
        'value' => ['value' => 'living_room'],
        'prefill_source' => DerivePhotoAnswers::SOURCE_DERIVED,
        'answered_at' => now(),
    ]);

    $fixture = fixturePath($fixtureName);
    expect(is_file($fixture))->toBeTrue();

    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'room_photos',
        'room-1',
        new UploadedFile($fixture, $fixtureName, 'image/jpeg', null, true),
    );
    app(AiTraceRecorder::class)->recordNetworkUploadMs($upload, 42);
    $upload->refresh();

    expect($upload->processing_timings)->toBeArray()
        ->and($upload->processing_timings['preprocess_ms'] ?? null)->not->toBeNull()
        ->and($upload->processing_timings['persist_ms'] ?? null)->not->toBeNull()
        ->and($upload->processing_timings['network_upload_ms'] ?? null)->toBe(42)
        ->and($upload->processing_timings['dossier_width'] ?? null)->not->toBeNull();

    FakeAiClient::alwaysFail('Simulated provider outage for trace acceptance');

    $profile = PhotoDerivationProfile::find('room');
    expect($profile)->not->toBeNull();

    $run = app(DerivePhotoAnswers::class)->handle($intake, 'room_photos', 'room-1', $profile);

    expect($run?->status)->toBe(AiRunStatus::Failed);

    $answer = IntakeAnswer::query()
        ->where('intake_id', $intake->id)
        ->where('question_key', 'room_type')
        ->where('section_instance_key', 'room-1')
        ->first();

    expect($answer)->not->toBeNull()
        ->and($answer->value)->toBe(['value' => 'living_room'])
        ->and($answer->prefill_source)->toBe(DerivePhotoAnswers::SOURCE_DERIVED);

    $trace = AiTrace::query()
        ->where('intake_id', $intake->id)
        ->where('call_type', AiTraceCallType::PhotoAnalysis)
        ->latest('id')
        ->first();

    expect($trace)->not->toBeNull()
        ->and($trace->status)->toBe(AiTraceStatus::Failed)
        ->and($trace->upload_id)->toBe($upload->id)
        ->and($trace->persist_ms)->not->toBeNull()
        ->and($trace->preprocess_ms)->not->toBeNull()
        ->and($trace->network_upload_ms)->toBe(42)
        ->and($trace->error_message)->toContain('Simulated provider outage')
        ->and($trace->photo_refs)->toBeArray();

    expect($trace->field_outcomes ?? [])->toBeEmpty();
})->with([
    'woonkamer-funda-1440.jpg',
    'woonkamer-funda-720.jpg',
]);

test('fotoanalyse-succes koppelt upload timings en stappen aan dezelfde trace', function () {
    $intake = makeTraceIntake(['status' => IntakeStatus::InProgress]);
    $fixture = fixturePath('woonkamer-funda-1440.jpg');

    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'room_photos',
        'room-1',
        new UploadedFile($fixture, 'woonkamer.jpg', 'image/jpeg', null, true),
    );

    FakeAiClient::reset();

    $profile = PhotoDerivationProfile::find('room');
    $run = app(DerivePhotoAnswers::class)->handle($intake, 'room_photos', 'room-1', $profile);

    expect($run?->status)->toBe(AiRunStatus::Succeeded);

    $trace = AiTrace::query()
        ->where('intake_id', $intake->id)
        ->where('call_type', AiTraceCallType::PhotoAnalysis)
        ->where('status', AiTraceStatus::Succeeded)
        ->latest('id')
        ->first();

    expect($trace)->not->toBeNull()
        ->and($trace->upload_id)->toBe($upload->id)
        ->and($trace->persist_ms)->toBeInt()
        ->and($trace->preprocess_ms)->toBeInt()
        ->and($trace->provider_ms)->not->toBeNull()
        ->and($trace->raw_response)->not->toBeEmpty()
        ->and($trace->steps->pluck('step_key')->all())->toContain('upload')
        ->and($trace->steps->pluck('step_key')->all())->toContain('preprocess')
        ->and($trace->steps->pluck('step_key')->all())->toContain('provider')
        ->and($trace->steps->pluck('step_key')->all())->toContain('dossier_update')
        ->and($trace->photo_refs[0]['width'] ?? null)->not->toBeNull()
        ->and($trace->photo_refs[0]['analysis_variant']['max_long_edge'] ?? null)->toBeInt();

    $blob = (string) json_encode([$trace->request_snapshot, $trace->photo_refs, $trace->raw_response]);
    expect($blob)->not->toContain('data:image')
        ->and($blob)->not->toContain('base64,');
});

test('AiTraceRedactor verwijdert API-keys authheaders en klanttokens', function () {
    $redactor = app(AiTraceRedactor::class);

    $redacted = $redactor->redact([
        'authorization' => 'Bearer sk-live-secret-value-1234567890',
        'api_key' => 'sk-abcdefghijklmnopqrstuvwxyz',
        'url' => 'https://example.test/o/AbCdEfGhIjKlMnOpQrStUvWxYz0123456789AbCdEfGhIjKlMnOpQrStUvWx',
        'image_url' => 'data:image/jpeg;base64,'.str_repeat('A', 800),
        'nested' => ['X-Api-Key' => 'sk-nested-key-abcdefgh'],
    ]);

    $json = (string) json_encode($redacted);

    expect($json)->not->toContain('sk-live-secret')
        ->and($json)->not->toContain('sk-abcdefghijklmnopqrstuvwxyz')
        ->and($json)->not->toContain('sk-nested-key')
        ->and($json)->toContain('[redacted]')
        ->and($json)->toContain('[token-redacted]')
        ->and($json)->toContain('[image-omitted')
        ->and($json)->not->toContain(str_repeat('A', 100));
});

test('AiTraceRedactor houdt lange klanttekst zonder leestekens intact', function () {
    $redactor = app(AiTraceRedactor::class);
    $longText = 'Ik wil graag een airco in de woonkamer en slaapkamer '
        .str_repeat('met goede koeling en verwarming ', 40);

    expect($redactor->looksLikeBase64Blob($longText))->toBeFalse()
        ->and($redactor->redactString($longText))->toBe($longText)
        ->and($redactor->looksLikeBase64Blob('data:image/jpeg;base64,'.str_repeat('A', 600)))->toBeTrue()
        ->and($redactor->looksLikeBase64Blob(str_repeat('A', 600)))->toBeTrue();
});

test('ai:purge-traces respecteert configureerbare bewaartermijn', function () {
    $intake = makeTraceIntake();

    $old = AiTrace::query()->create([
        'trace_id' => (string) Str::uuid(),
        'intake_id' => $intake->id,
        'call_type' => AiTraceCallType::Synthesis,
        'status' => AiTraceStatus::Succeeded,
        'started_at' => now()->subDays(40),
        'finished_at' => now()->subDays(40),
    ]);
    AiTrace::query()->whereKey($old->id)->update([
        'created_at' => now()->subDays(40),
        'updated_at' => now()->subDays(40),
    ]);
    $fresh = AiTrace::query()->create([
        'trace_id' => (string) Str::uuid(),
        'intake_id' => $intake->id,
        'call_type' => AiTraceCallType::Synthesis,
        'status' => AiTraceStatus::Succeeded,
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    Artisan::call('ai:purge-traces', ['--days' => 30]);

    expect(AiTrace::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(AiTrace::query()->whereKey($fresh->id)->exists())->toBeTrue();
});

test('dev ai-traces is alleen bereikbaar met allowlist-user', function () {
    $this->withoutVite();
    $user = User::factory()->create(['email' => 'trace-admin@example.com']);
    config([
        'devadmin.enabled' => true,
        'devadmin.emails' => ['trace-admin@example.com'],
    ]);

    $this->actingAs($user)
        ->get(route('dev.ai-traces'))
        ->assertOk()
        ->assertSee('AI-traces');

    config(['devadmin.enabled' => false]);
    $this->actingAs($user)
        ->get(route('dev.ai-traces'))
        ->assertNotFound();
});

test('dev ai-traces weigert gebruikers buiten de e-mailallowlist', function () {
    $this->withoutVite();
    config([
        'devadmin.enabled' => true,
        'devadmin.emails' => ['allowed@example.com'],
    ]);

    $user = User::factory()->create(['email' => 'other@example.com']);
    $this->actingAs($user)
        ->get(route('dev.ai-traces'))
        ->assertForbidden();
});

test('AI_TRACING_ENABLED false schrijft geen traces', function () {
    config(['ai.tracing.enabled' => false]);
    $intake = makeTraceIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'request_reason', null, ['text' => CASE_81_TEXT]);

    FakeAiClient::respondUsing(fn () => [
        'evidence' => 'kill switch',
        'fills' => [],
    ]);

    $before = AiTrace::query()->count();
    $handle = app(AiTraceRecorder::class)->start($intake, AiTraceCallType::TextExtraction);
    expect($handle->isNoop())->toBeTrue();
    $handle->step('should_not_persist', ['x' => 1]);
    $handle->succeed();

    app(PrefillAnswersFromKnownContext::class)->handle($intake);

    expect(AiTrace::query()->count())->toBe($before);
});

test('trace-fout laat business-flow intact en maskeert originele fout niet', function () {
    $intake = makeTraceIntake();
    $handle = app(AiTraceRecorder::class)->start($intake, AiTraceCallType::Synthesis);
    expect($handle->isNoop())->toBeFalse();

    // Simulate broken table by renaming — then restore.
    Schema::rename('ai_trace_steps', 'ai_trace_steps_broken_tmp');
    try {
        $handle->step('will_fail_to_write', ['ok' => false]);
        $original = new AiClientException('provider down', providerMs: 123);
        $returned = $handle->fail('outer fail', $original);
        expect($returned)->toBeInstanceOf(AiTrace::class);
        // Original exception object untouched for callers.
        expect($original->getMessage())->toBe('provider down')
            ->and($original->providerMs)->toBe(123);
    } finally {
        if (Schema::hasTable('ai_trace_steps_broken_tmp')) {
            Schema::rename('ai_trace_steps_broken_tmp', 'ai_trace_steps');
        }
    }
});

test('provider_ms wordt meegenomen bij AiClientException failure', function () {
    $intake = makeTraceIntake();
    $handle = app(AiTraceRecorder::class)->start($intake, AiTraceCallType::PhotoAnalysis);
    $handle->fail('boom', new AiClientException('timeout', providerMs: 987));

    $trace = $handle->model()->fresh();
    expect($trace->status)->toBe(AiTraceStatus::Failed)
        ->and($trace->provider_ms)->toBe(987);
});

test('helper API step hangt normalisatie aan bestaande trace', function () {
    $intake = makeTraceIntake();
    $handle = app(AiTraceRecorder::class)
        ->start($intake, AiTraceCallType::Synthesis);

    $handle->step('normalize', ['defaults' => ['length_class' => 'unknown']], durationMs: 5);
    $handle->succeed();

    $trace = $handle->model()->fresh(['steps']);
    expect($trace->status)->toBe(AiTraceStatus::Succeeded)
        ->and($trace->steps->pluck('step_key')->all())->toContain('normalize');
});

test('AiTraceHandle exposeert geen continue of setTiming API', function () {
    expect(method_exists(AiTraceRecorder::class, 'continue'))->toBeFalse()
        ->and(method_exists(AiTraceHandle::class, 'setTiming'))->toBeFalse()
        ->and(method_exists(AiTraceHandle::class, 'id'))->toBeFalse();
});
