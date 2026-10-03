<?php

declare(strict_types=1);

use App\Domains\AI\Actions\AssessFollowUpPhotoSubject;
use App\Domains\AI\Actions\AssessPhotoUsability;
use App\Domains\AI\Actions\DerivePhotoAnswers;
use App\Domains\AI\Actions\PrefillAnswersFromKnownContext;
use App\Domains\AI\Actions\SynthesizePipeRoute;
use App\Domains\AI\Actions\SynthesizeSurveyDossier;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Models\AiTrace;
use App\Domains\AI\Services\AiTraceRedactor;
use App\Domains\AI\Support\PhotoDerivationProfile;
use App\Domains\Intake\Actions\AddPipeRoutePhoto;
use App\Domains\Intake\Actions\HardDeleteIntake;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StartPipeRouteSession;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeFollowUpItem;
use App\Domains\Intake\Models\IntakeFollowUpRound;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Enums\AircoPlacementType;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\AiTraceCallType;
use App\Enums\AiTraceStatus;
use App\Enums\ContributionAudience;
use App\Enums\ContributionTaskStatus;
use App\Enums\FollowUpItemType;
use App\Enums\FollowUpRoundStatus;
use App\Enums\IntakeStatus;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    FakeAiClient::reset();
    Storage::fake('local');
    config([
        'ai.provider' => 'fake',
        'ai.text_inference.enabled' => true,
        'ai.photo_inference.enabled' => true,
        'ai.dossier.enabled' => true,
        'ai.route.enabled' => true,
        'ai.tracing.enabled' => true,
        'ai.tracing.retention_days' => 30,
        'filesystems.media' => 'local',
    ]);
});

afterEach(function () {
    FakeAiClient::reset();
});

/** @return list<string> */
function requiredTraceFields(): array
{
    return [
        'prompt_version',
        'model',
        'request_snapshot',
        'raw_response',
        'parsed_response',
        'input_tokens',
        'output_tokens',
        'provider_ms',
        'process_ms',
        'estimated_cost_microcents',
        'intake_ref_id',
        'request_id',
    ];
}

function assertRequiredTraceFields(AiTrace $trace, bool $expectPhotos = false): void
{
    $label = $trace->call_type->value.'#'.($trace->subject_type ?? 'none').':'.($trace->subject_id ?? 'none');

    foreach (requiredTraceFields() as $field) {
        expect($trace->{$field})->not->toBeNull("Trace field {$field} must be filled for {$label}");
    }

    expect($trace->request_snapshot)->toBeArray("request_snapshot array for {$label}")
        ->and($trace->request_snapshot)->toHaveKey('system')
        ->and($trace->request_snapshot)->toHaveKey('user')
        ->and($trace->intake_ref_id)->toBeInt()
        ->and($trace->request_id)->toBeString()->not->toBeEmpty()
        ->and($trace->status)->toBeIn([AiTraceStatus::Succeeded, AiTraceStatus::Failed]);

    if ($expectPhotos) {
        expect($trace->photo_refs)->toBeArray("photo_refs array for {$label}")->not->toBeEmpty("photo_refs non-empty for {$label}");
        $json = (string) json_encode($trace->photo_refs);
        expect($json)->not->toContain('data:image')
            ->and($json)->not->toContain(str_repeat('A', 100));
    }
}

function observabilityIntake(array $overrides = []): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create(array_merge([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_name' => 'Trace Coverage',
        'customer_email' => 'coverage@example.com',
        'address_line' => 'Coverageweg 9',
        'address_postal_code' => '1000AA',
        'address_house_number' => 9,
        'address_city' => 'Amsterdam',
    ], $overrides));
}

function observabilityFixtureUpload(Intake $intake, string $questionKey = 'room_photos', ?string $instance = 'room-1'): IntakeUpload
{
    $path = base_path('tests/fixtures/klanttest-20261002/woonkamer-funda-720.jpg');
    expect(is_file($path))->toBeTrue();

    // Template photo questions go through the normal upload pipeline.
    if (in_array($questionKey, ['room_photos', 'outdoor_photos', 'fusebox_photo', 'pipe_route_photos'], true)) {
        $file = new UploadedFile($path, 'room.jpg', 'image/jpeg', null, true);

        return app(StoreIntakeUpload::class)->handle($intake, $questionKey, $instance, $file);
    }

    $bytes = (string) file_get_contents($path);
    $disk = (string) config('filesystems.media', 'local');
    $dossier = 'intakes/'.$intake->uuid.'/observability/'.Str::ulid()->toBase32().'.jpg';
    $analysis = 'intakes/'.$intake->uuid.'/observability/analysis/'.Str::ulid()->toBase32().'.jpg';
    Storage::disk($disk)->put($dossier, $bytes);
    Storage::disk($disk)->put($analysis, $bytes);

    return IntakeUpload::query()->create([
        'intake_id' => $intake->id,
        'question_key' => $questionKey,
        'section_instance_key' => $instance,
        'intake_follow_up_item_id' => null,
        'disk' => $disk,
        'path' => $dossier,
        'analysis_path' => $analysis,
        'original_filename' => 'room.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => strlen($bytes),
        'checksum' => hash('sha256', $bytes),
        'analysis_mime_type' => 'image/jpeg',
        'analysis_size_bytes' => strlen($bytes),
        'analysis_checksum' => hash('sha256', $bytes),
        'sort_order' => 0,
        'processing_timings' => ['persist_ms' => 1, 'preprocess_ms' => 1],
    ]);
}

test('demo intake purge keeps ai_traces with denormalised intake_ref_id (ai_runs cascade)', function () {
    $intake = observabilityIntake([
        'is_demo' => true,
        'customer_name' => 'Demo Trace',
        'customer_email' => 'demo-trace@demo.invalid',
        'address_line' => 'Demolaan 1',
        'address_house_number' => 1,
    ]);

    // Seed directly — same contract as AiTraceRetentionExportTest. Prefill may skip when
    // known context is empty; this test asserts purge retention, not the prefill path.
    $trace = AiTrace::query()->create([
        'trace_id' => (string) Str::uuid(),
        'correlation_id' => (string) Str::uuid(),
        'request_id' => (string) Str::uuid(),
        'intake_id' => $intake->id,
        'intake_ref_id' => $intake->id,
        'is_demo' => true,
        'call_type' => AiTraceCallType::TextExtraction,
        'status' => AiTraceStatus::Succeeded,
        'provider' => 'fake',
        'model' => 'fake-v1',
        'prompt_version' => 'test-v1',
        'request_snapshot' => ['system' => 'test', 'user' => ['ok' => true]],
        'photo_refs' => [],
        'raw_response' => '{"ok":true}',
        'parsed_response' => ['ok' => true],
        'validation_errors' => [],
        'normalizations' => [],
        'input_tokens' => 10,
        'output_tokens' => 5,
        'total_tokens' => 15,
        'provider_ms' => 3,
        'process_ms' => 4,
        'estimated_cost_cents' => 1,
        'started_at' => now(),
        'finished_at' => now(),
    ]);
    $run = AiRun::query()->create([
        'intake_id' => $intake->id,
        'type' => AiRunType::RequestIntent,
        'provider' => 'fake',
        'model' => 'fake-v1',
        'prompt_version' => 'test-v1',
        'status' => AiRunStatus::Succeeded,
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    expect($trace->intake_ref_id)->toBe($intake->id)
        ->and($trace->is_demo)->toBeTrue();

    $traceId = $trace->id;
    $runId = $run->id;
    $intakeId = $intake->id;

    $intake->forceFill([
        'created_at' => Carbon::now()->subHours(3),
        'token_expires_at' => Carbon::now()->subHour(),
    ])->save();

    Artisan::call('intakes:purge-demos');

    expect(Intake::withTrashed()->whereKey($intakeId)->exists())->toBeFalse();

    $survivingTrace = AiTrace::query()->find($traceId);
    // ai_runs remain cascadeOnDelete (BL-125): operational records without intake are meaningless.
    expect($survivingTrace)->not->toBeNull()
        ->and($survivingTrace->intake_id)->toBeNull()
        ->and($survivingTrace->intake_ref_id)->toBe($intakeId)
        ->and($survivingTrace->is_demo)->toBeTrue()
        ->and(AiRun::query()->whereKey($runId)->exists())->toBeFalse();
});

test('ai:purge-traces verwijdert oude traces inclusief wees-traces na demo-purge', function () {
    $user = User::factory()->create();
    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'is_demo' => true,
        'status' => IntakeStatus::Sent,
    ]);

    $old = AiTrace::query()->create([
        'trace_id' => (string) Str::uuid(),
        'intake_id' => $intake->id,
        'intake_ref_id' => $intake->id,
        'is_demo' => true,
        'request_id' => (string) Str::uuid(),
        'call_type' => AiTraceCallType::Synthesis,
        'status' => AiTraceStatus::Succeeded,
        'started_at' => now()->subDays(40),
        'finished_at' => now()->subDays(40),
    ]);
    AiTrace::query()->whereKey($old->id)->update([
        'created_at' => now()->subDays(40),
        'updated_at' => now()->subDays(40),
    ]);

    app(HardDeleteIntake::class)->handle($intake);

    expect(AiTrace::query()->whereKey($old->id)->value('intake_id'))->toBeNull();

    Artisan::call('ai:purge-traces', ['--days' => 30]);

    expect(AiTrace::query()->whereKey($old->id)->exists())->toBeFalse();
});

test('AiTraceRedactor maskeert namen e-mail telefoon straat en huisnummer', function () {
    $redactor = app(AiTraceRedactor::class);

    $redacted = $redactor->redact([
        'customer_name' => 'Jan Jansen',
        'address_line' => 'Bernadottelaan 12A',
        'address_house_number' => 12,
        'address_postal_code' => '1234 AB',
        'customer_email' => 'jan@example.com',
        'phone' => '06-12345678',
        'note' => 'Bel Jan op 0612345678 of mail jan@example.com. Adres: Bernadottelaan 12, 1234AB Amsterdam.',
    ]);

    $json = (string) json_encode($redacted, JSON_UNESCAPED_UNICODE);

    expect($redacted['customer_name'])->toBe('[naam verwijderd]')
        ->and($redacted['address_line'])->toBe('[adres verwijderd]')
        ->and($json)->toContain('[e-mail verwijderd]')
        ->and($json)->toContain('[telefoon verwijderd]')
        ->and($json)->toContain('[adres verwijderd]')
        ->and($json)->not->toContain('Jan Jansen')
        ->and($json)->not->toContain('Bernadottelaan 12')
        ->and($json)->not->toContain('jan@example.com')
        ->and($json)->not->toContain('06-12345678');
});

test('elke AI-calltype vult verplichte tracevelden consistent', function () {
    $intake = observabilityIntake();
    $user = User::query()->findOrFail($intake->created_by);

    // 1) Text prefill / extraction
    app(SaveIntakeAnswer::class)->handle($intake, 'request_reason', null, [
        'text' => 'Ik wil de slaapkamer koelen en verwarmen.',
    ]);
    FakeAiClient::respondUsing(fn () => [
        'evidence' => 'Slaapkamer koelen en verwarmen.',
        'fills' => [[
            'question_key' => 'cooling_heating',
            'section_instance_key' => null,
            'confidence' => 'high',
            'value' => ['value' => 'both'],
            'evidence' => 'koelen en verwarmen',
            'provenance' => 'stated',
        ]],
    ]);
    app(PrefillAnswersFromKnownContext::class)->handle($intake->fresh());
    $textTrace = AiTrace::query()
        ->where('intake_id', $intake->id)
        ->where('call_type', AiTraceCallType::TextExtraction)
        ->latest('id')
        ->first();
    expect($textTrace)->not->toBeNull();
    assertRequiredTraceFields($textTrace);

    // 2) Photo derive
    $upload = observabilityFixtureUpload($intake);
    FakeAiClient::reset();
    app(DerivePhotoAnswers::class)->handle(
        $intake->fresh(),
        'room_photos',
        'room-1',
        PhotoDerivationProfile::require('room'),
    );
    $photoTrace = AiTrace::query()
        ->where('intake_id', $intake->id)
        ->where('call_type', AiTraceCallType::PhotoAnalysis)
        ->where('upload_id', $upload->id)
        ->where('subject_type', '!=', 'upload')
        ->latest('id')
        ->first();
    expect($photoTrace)->not->toBeNull();
    assertRequiredTraceFields($photoTrace, expectPhotos: true);

    // 2b) Local photo usability assess (heuristic — still fills required observability fields)
    app(AssessPhotoUsability::class)->handle($upload->fresh());
    $assessTrace = AiTrace::query()
        ->where('intake_id', $intake->id)
        ->where('call_type', AiTraceCallType::PhotoAnalysis)
        ->where('upload_id', $upload->id)
        ->where('subject_type', 'upload')
        ->latest('id')
        ->first();
    expect($assessTrace)->not->toBeNull();
    assertRequiredTraceFields($assessTrace, expectPhotos: true);

    // 3) Follow-up photo subject
    $round = IntakeFollowUpRound::query()->create([
        'intake_id' => $intake->id,
        'requested_by' => $user->id,
        'round_number' => 1,
        'purpose' => 'contribution',
        'status' => FollowUpRoundStatus::Open,
        'sent_at' => now(),
    ]);
    $item = IntakeFollowUpItem::query()->create([
        'intake_follow_up_round_id' => $round->id,
        'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een foto van de meterkast.',
    ]);
    ContributionTask::query()->create([
        'intake_id' => $intake->id,
        'company_id' => $intake->company_id,
        'audience' => ContributionAudience::Customer,
        'type' => FollowUpItemType::Photo,
        'status' => ContributionTaskStatus::Open,
        'decision_area_key' => 'power',
        'prompt' => 'Maak een foto van de meterkast.',
        'intake_follow_up_item_id' => $item->id,
        'requested_by' => $user->id,
    ]);
    $followUpUpload = observabilityFixtureUpload($intake, 'follow_up_photo', null);
    $followUpUpload->forceFill(['intake_follow_up_item_id' => $item->id])->save();
    FakeAiClient::reset();
    app(AssessFollowUpPhotoSubject::class)->handle($intake->fresh(), $item, $followUpUpload);
    $followUpTrace = AiTrace::query()
        ->where('intake_id', $intake->id)
        ->where('subject_type', 'follow_up_item')
        ->where('subject_id', (string) $item->id)
        ->latest('id')
        ->first();
    expect($followUpTrace)->not->toBeNull();
    assertRequiredTraceFields($followUpTrace, expectPhotos: true);

    // 4) Dossier synthesis — even a failed/empty proposal run must fill usage fields.
    $survey = app(AircoSurveyService::class);
    $room = $survey->createRoom($intake, $user, [
        'name' => 'Slaapkamer',
        'use_type' => 'bedroom',
        'length_m' => 4.0,
        'width_m' => 3.0,
        'height_m' => 2.6,
    ]);
    $survey->createPlacement($intake, $user, [
        'airco_room_id' => $room->id,
        'type' => AircoPlacementType::IndoorUnit,
        'label' => 'Binnen',
    ]);
    $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::OutdoorUnit,
        'label' => 'Buiten',
    ]);
    $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::PowerSource,
        'label' => 'Stroom',
    ]);
    $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::DrainPoint,
        'label' => 'Afvoer',
    ]);
    FakeAiClient::reset();
    FakeAiClient::respondUsing(function ($request) {
        $placements = collect($request->input['placements'] ?? [])->keyBy('type');
        $inside = $placements->get('indoor_unit')['reference'] ?? 'placement:1';
        $outside = $placements->get('outdoor_unit')['reference'] ?? 'placement:2';
        $power = $placements->get('power_source')['reference'] ?? 'placement:3';
        $drain = $placements->get('drain_point')['reference'] ?? 'placement:4';
        $evidence = collect($request->input['image_manifest'] ?? [])->pluck('reference')->first()
            ?? collect($request->input['placements'] ?? [])->pluck('reference')->first()
            ?? 'placement:1';

        return [
            'summary' => 'Single-split voorstel uit coverage-test.',
            'placement_proposals' => [],
            'option_proposals' => [[
                'label' => 'Coverage optie',
                'configuration_type' => 'single_split',
                'summary' => 'Binnen- en buitenpositie met drie verbindingen.',
                'cost_impact' => 'medium',
                'confidence' => 0.8,
                'placement_references' => [$inside, $outside, $power, $drain],
                'connections' => [
                    [
                        'type' => 'refrigerant',
                        'label' => 'Koel',
                        'from_placement_reference' => $inside,
                        'to_placement_reference' => $outside,
                        'status' => 'proposed',
                        'length_class' => 'short',
                        'segments' => [],
                        'obstacles' => [],
                        'uncertainties' => [],
                        'cost_impact' => 'low',
                        'confidence' => 0.8,
                        'evidence_references' => [$evidence],
                    ],
                    [
                        'type' => 'condensate',
                        'label' => 'Condens',
                        'from_placement_reference' => $inside,
                        'to_placement_reference' => $drain,
                        'status' => 'proposed',
                        'length_class' => 'short',
                        'segments' => [],
                        'obstacles' => [],
                        'uncertainties' => [],
                        'cost_impact' => 'low',
                        'confidence' => 0.8,
                        'evidence_references' => [$evidence],
                    ],
                    [
                        'type' => 'power',
                        'label' => 'Stroom',
                        'from_placement_reference' => $power,
                        'to_placement_reference' => $outside,
                        'status' => 'proposed',
                        'length_class' => 'short',
                        'segments' => [],
                        'obstacles' => [],
                        'uncertainties' => [],
                        'cost_impact' => 'low',
                        'confidence' => 0.8,
                        'evidence_references' => [$evidence],
                    ],
                ],
            ]],
            'exceptions' => [],
            'customer_tasks' => [],
        ];
    });
    app(SynthesizeSurveyDossier::class)->handle($intake->fresh());
    $dossierTrace = AiTrace::query()
        ->where('intake_id', $intake->id)
        ->where('call_type', AiTraceCallType::Synthesis)
        ->whereNull('subject_type')
        ->latest('id')
        ->first()
        ?? AiTrace::query()
            ->where('intake_id', $intake->id)
            ->where('call_type', AiTraceCallType::Synthesis)
            ->latest('id')
            ->first();
    expect($dossierTrace)->not->toBeNull();
    assertRequiredTraceFields($dossierTrace);

    // 5) Route photo (via AddPipeRoutePhoto) + route review synthesis
    $session = app(StartPipeRouteSession::class)->handle($intake->fresh());
    $routeUpload = observabilityFixtureUpload($intake, 'pipe_route_guided', null);
    FakeAiClient::reset();
    app(AddPipeRoutePhoto::class)->handle($session, $routeUpload, 'start');
    $routePhotoTrace = AiTrace::query()
        ->where('intake_id', $intake->id)
        ->where('subject_type', 'pipe_route_segment')
        ->latest('id')
        ->first();
    expect($routePhotoTrace)->not->toBeNull();
    assertRequiredTraceFields($routePhotoTrace, expectPhotos: true);

    FakeAiClient::reset();
    app(SynthesizePipeRoute::class)->handle($session->fresh());
    $routeReviewTrace = AiTrace::query()
        ->where('intake_id', $intake->id)
        ->where('subject_type', 'pipe_route_session')
        ->latest('id')
        ->first();
    expect($routeReviewTrace)->not->toBeNull();
    assertRequiredTraceFields($routeReviewTrace, expectPhotos: true);
});
