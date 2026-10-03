<?php

declare(strict_types=1);

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Models\AiTrace;
use App\Domains\AI\Services\AiTraceRedactor;
use App\Domains\Intake\Actions\HardDeleteIntake;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\AiTraceCallType;
use App\Enums\AiTraceStatus;
use App\Enums\IntakeStatus;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake('local');
    config([
        'ai.tracing.enabled' => true,
        'ai.tracing.retention_days' => 30,
        'filesystems.media' => 'local',
    ]);
});

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

test('demo intake purge keeps ai_traces with denormalised intake_ref_id (ai_runs cascade)', function () {
    $intake = observabilityIntake([
        'is_demo' => true,
        'customer_name' => 'Demo Trace',
        'customer_email' => 'demo-trace@demo.invalid',
        'address_line' => 'Demolaan 1',
        'address_house_number' => 1,
    ]);

    // Seed directly — same contract as AiTraceRetentionExportTest. This test asserts
    // purge retention (traces survive, runs cascade), not the prefill path.
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
        'input_hash' => hash('sha256', 'observability-demo-purge'),
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
