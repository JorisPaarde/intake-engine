<?php

declare(strict_types=1);

use App\Domains\AI\Models\AiTrace;
use App\Domains\AI\Services\AiTraceExporter;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Enums\AiTraceCallType;
use App\Enums\AiTraceStatus;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake('local');
});

function exportTraceIntake(array $overrides = []): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create(array_merge([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_name' => 'Export Klant',
        'customer_email' => 'export@example.com',
    ], $overrides));
}

function makeExportTrace(Intake $intake, array $overrides = []): AiTrace
{
    return AiTrace::query()->create(array_merge([
        'trace_id' => (string) Str::uuid(),
        'correlation_id' => (string) Str::uuid(),
        'request_id' => (string) Str::uuid(),
        'intake_id' => $intake->id,
        'intake_ref_id' => $intake->id,
        'is_demo' => (bool) $intake->is_demo,
        'call_type' => AiTraceCallType::TextExtraction,
        'status' => AiTraceStatus::Succeeded,
        'provider' => 'fake',
        'model' => 'fake-v1',
        'prompt_version' => 'request-prefill-v6',
        'request_snapshot' => [
            'system' => 'Je bent assistent.',
            'user' => [
                'customer_name' => 'Jan Jansen',
                'customer_email' => 'jan@example.com',
                'phone' => '0612345678',
                'address_line' => 'Bernadottelaan 12',
                'note' => 'Bel Jan op 0612345678 of mail jan@example.com. Adres Bernadottelaan 12, 1234AB.',
            ],
        ],
        'photo_refs' => [[
            'upload_id' => 99,
            'question_key' => 'room_photos',
            'path_ref' => 'intake_upload:99',
            'original_filename' => 'woonkamer.jpg',
        ]],
        'raw_response' => '{"summary":"Bel jan@example.com"}',
        'parsed_response' => ['summary' => 'Bel jan@example.com'],
        'validation_errors' => null,
        'normalizations' => [['field' => 'length_class', 'from' => 'kort', 'to' => 'short']],
        'input_tokens' => 100,
        'output_tokens' => 40,
        'provider_ms' => 12,
        'process_ms' => 5,
        'estimated_cost_microcents' => 2500,
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ], $overrides));
}

test('ai:traces:export schrijft jsonl gegroepeerd per intake met masking', function () {
    $intake = exportTraceIntake(['is_demo' => true]);
    makeExportTrace($intake);

    $exit = Artisan::call('ai:traces:export', [
        '--intake' => [$intake->id],
        '--format' => 'jsonl',
    ]);

    expect($exit)->toBe(0);
    $files = Storage::disk('local')->files('exports');
    expect($files)->not->toBeEmpty();
    $path = $files[0];
    expect($path)->toEndWith('.jsonl');

    $body = Storage::disk('local')->get($path);
    expect($body)->toContain('"intake_ref_id":'.$intake->id)
        ->and($body)->toContain('"call_type":"text_extraction"')
        ->and($body)->toContain('"prompt_version":"request-prefill-v6"')
        ->and($body)->toContain('[redacted]')
        ->and($body)->toContain('[e-mail verwijderd]')
        ->and($body)->toContain('[telefoon verwijderd]')
        ->and($body)->not->toContain('jan@example.com')
        ->and($body)->not->toContain('0612345678')
        ->and($body)->not->toContain('Jan Jansen')
        ->and($body)->not->toContain('Bernadottelaan 12')
        ->and($body)->toContain('woonkamer.jpg')
        ->and($body)->toContain('room_photos')
        ->and($body)->not->toContain('data:image');
});

test('ai:traces:export md-format is leesbaar voor een LLM', function () {
    $intake = exportTraceIntake();
    makeExportTrace($intake, [
        'call_type' => AiTraceCallType::PhotoAnalysis,
        'prompt_version' => 'room-assessment-v1',
        'model' => 'fake-vision-v1',
    ]);

    $output = storage_path('app/exports/test-ai-traces.md');
    @unlink($output);

    $exit = Artisan::call('ai:traces:export', [
        '--intake' => [$intake->id],
        '--format' => 'md',
        '--output' => $output,
    ]);

    expect($exit)->toBe(0);
    $body = (string) file_get_contents($output);
    expect($body)->toContain('# AI-traces export')
        ->and($body)->toContain('## Intake '.$intake->id)
        ->and($body)->toContain('### Call 1: photo_analysis')
        ->and($body)->toContain('```json')
        ->and($body)->toContain('prompt_version: `room-assessment-v1`')
        ->and($body)->toContain('model: `fake-vision-v1`')
        ->and($body)->not->toContain('jan@example.com');

    @unlink($output);
});

test('ai:traces:export werkt voor demo-traces na intake-purge via intake_ref_id', function () {
    $intake = exportTraceIntake(['is_demo' => true]);
    $trace = makeExportTrace($intake);
    $intakeId = $intake->id;

    // Simulate demo purge: null intake_id, keep denorm.
    AiTrace::query()->whereKey($trace->id)->update(['intake_id' => null]);
    $intake->forceDelete();

    $exit = Artisan::call('ai:traces:export', [
        '--intake' => [$intakeId],
        '--demo-only' => true,
        '--format' => 'jsonl',
        '--output' => storage_path('app/exports/orphan-traces.jsonl'),
    ]);

    expect($exit)->toBe(0);
    $body = (string) file_get_contents(storage_path('app/exports/orphan-traces.jsonl'));
    expect($body)->toContain('"intake_ref_id":'.$intakeId)
        ->and($body)->toContain('"is_demo":true')
        ->and($body)->toContain('"intake_id":null');

    @unlink(storage_path('app/exports/orphan-traces.jsonl'));
});

test('AiTraceExporter re-applies masking as safety net', function () {
    $exporter = app(AiTraceExporter::class);
    $groups = [[
        'intake_ref_id' => 1,
        'is_demo' => false,
        'calls' => [[
            'call_type' => 'text_extraction',
            'status' => 'succeeded',
            'prompt_version' => 'x',
            'model' => 'y',
            'request_id' => 'r',
            'correlation_id' => 'c',
            'request' => [
                'system' => 'ok',
                'user' => ['customer_email' => 'leak@example.com', 'note' => 'mail leak@example.com'],
            ],
            'photo_refs' => [],
            'raw_response' => 'leak@example.com',
            'parsed_response' => null,
            'validation_errors' => null,
            'normalizations' => null,
            'input_tokens' => 1,
            'output_tokens' => 1,
            'provider_ms' => 1,
            'total_duration_ms' => 1,
            'estimated_cost_fractional_cents' => 0.1,
            'error_message' => null,
        ]],
    ]];

    $jsonl = $exporter->toJsonl($groups);
    $md = $exporter->toMarkdown($groups);

    expect($jsonl)->not->toContain('leak@example.com')
        ->and($md)->not->toContain('leak@example.com')
        ->and($jsonl)->toContain('[redacted]')
        ->and($md)->toContain('[e-mail verwijderd]');
});
