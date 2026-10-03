<?php

declare(strict_types=1);

use App\Domains\AI\Models\AiTrace;
use App\Domains\AI\Services\AiTraceExporter;
use App\Domains\Intake\Actions\HardDeleteIntake;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Enums\AiTraceCallType;
use App\Enums\AiTraceStatus;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
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
        'estimated_cost_cents' => 1,
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ], $overrides));
}

test('ai:traces:export schrijft jsonl gegroepeerd per intake met masking', function () {
    $intake = exportTraceIntake(['is_demo' => true]);
    makeExportTrace($intake);

    $outDir = storage_path('app/exports/test-export-'.Str::random(6));
    $exit = Artisan::call('ai:traces:export', [
        '--intake' => [(string) $intake->id],
        '--format' => 'jsonl',
        '--output' => $outDir.'/bundle',
    ]);

    expect($exit)->toBe(0);
    $jsonl = collect(glob($outDir.'/bundle*.jsonl') ?: [])->first();
    expect($jsonl)->not->toBeNull();

    $body = (string) file_get_contents((string) $jsonl);
    expect($body)->toContain('"intake_ref_id":'.$intake->id)
        ->and($body)->toContain('"call_type":"text_extraction"')
        ->and($body)->toContain('"prompt_version":"request-prefill-v6"')
        ->and($body)->toContain('[e-mail verwijderd]')
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

    $outDir = storage_path('app/exports/test-md-'.Str::random(6));
    $exit = Artisan::call('ai:traces:export', [
        '--intake' => [(string) $intake->id],
        '--format' => 'md',
        '--output' => $outDir.'/bundle',
    ]);

    expect($exit)->toBe(0);
    $md = collect(glob($outDir.'/bundle*.md') ?: [])->first();
    expect($md)->not->toBeNull();
    $body = (string) file_get_contents((string) $md);
    expect($body)->toContain('# AI-trace export')
        ->and($body)->toContain('## Intake '.$intake->id)
        ->and($body)->toContain('### Call 1: photo_analysis')
        ->and($body)->toContain('```json')
        ->and($body)->not->toContain('jan@example.com');
});

test('ai:traces:export werkt voor demo-traces na intake-purge via intake_ref_id', function () {
    $intake = exportTraceIntake(['is_demo' => true]);
    makeExportTrace($intake, ['is_demo' => true]);
    $intakeId = $intake->id;
    app(HardDeleteIntake::class)->handle($intake);

    $outDir = storage_path('app/exports/test-orphan-'.Str::random(6));
    $exit = Artisan::call('ai:traces:export', [
        '--intake' => [(string) $intakeId],
        '--demo-only' => true,
        '--format' => 'jsonl',
        '--output' => $outDir.'/orphan',
    ]);

    expect($exit)->toBe(0);
    $jsonl = collect(glob($outDir.'/orphan*.jsonl') ?: [])->first();
    expect($jsonl)->not->toBeNull();
    $body = (string) file_get_contents((string) $jsonl);
    expect($body)->toContain('"intake_ref_id":'.$intakeId)
        ->and($body)->toContain('"is_demo":true')
        ->and($body)->toContain('"intake_id":null');
});

test('ai:traces:export splits into parts under size cap with manifest and photo refs only', function () {
    config([
        'ai.tracing.export_max_part_bytes' => 2_500,
        'ai.tracing.export_max_part_chars' => 2_500,
    ]);

    $a = exportTraceIntake();
    $b = exportTraceIntake();
    $c = exportTraceIntake();

    foreach ([$a, $b, $c] as $intake) {
        makeExportTrace($intake, [
            'raw_response' => str_repeat('x', 1_200),
            'request_snapshot' => [
                'system' => 'Je bent assistent.',
                'user' => ['note' => str_repeat('payload-', 80)],
            ],
            'photo_refs' => [[
                'upload_id' => 42,
                'question_key' => 'room_photos',
                'original_filename' => 'kamer.jpg',
                'data_uri' => 'data:image/jpeg;base64,'.str_repeat('A', 200),
            ]],
        ]);
    }

    $outDir = storage_path('app/exports/test-parts-'.Str::random(6));
    $exit = Artisan::call('ai:traces:export', [
        '--intake' => [$a->id.','.$b->id.','.$c->id],
        '--format' => 'jsonl',
        '--output' => $outDir.'/split',
    ]);

    expect($exit)->toBe(0);
    $jsonlParts = glob($outDir.'/split-part*-of-*.jsonl') ?: [];
    $manifestPath = collect(glob($outDir.'/split*manifest.json') ?: [])->first();

    expect(count($jsonlParts))->toBe(3)
        ->and($manifestPath)->not->toBeNull();

    $manifest = json_decode((string) file_get_contents((string) $manifestPath), true, 512, JSON_THROW_ON_ERROR);
    expect($manifest['totals']['parts'])->toBe(3)
        ->and($manifest['parts'])->toHaveCount(3);

    $intakeIdsAcrossParts = [];
    foreach ($manifest['parts'] as $part) {
        expect($part['intake_ids'])->toHaveCount(1);
        $intakeIdsAcrossParts[] = $part['intake_ids'][0];
        $partBody = (string) file_get_contents($part['files']['jsonl']['path']);
        expect($partBody)->not->toContain('data:image')
            ->and($partBody)->not->toContain(str_repeat('A', 50))
            ->and($partBody)->toContain('kamer.jpg')
            ->and($partBody)->toContain('room_photos');
    }

    expect($intakeIdsAcrossParts)->toContain($a->id, $b->id, $c->id);

    $output = Artisan::output();
    expect($output)->toContain('part 1/3')
        ->and($output)->toContain('Manifest:');
});

test('AiTraceExporter re-applies masking as safety net on call payloads', function () {
    $intake = exportTraceIntake();
    $trace = makeExportTrace($intake, [
        'request_snapshot' => [
            'system' => 'ok',
            'user' => ['customer_email' => 'leak@example.com', 'note' => 'mail leak@example.com'],
        ],
        'raw_response' => 'leak@example.com',
    ]);

    $payload = app(AiTraceExporter::class)->callPayload($trace);
    $encoded = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);

    expect($encoded)->not->toContain('leak@example.com')
        ->and($encoded)->toContain('[e-mail verwijderd]');
});
