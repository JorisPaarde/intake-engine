<?php

declare(strict_types=1);

use App\Domains\AI\Models\AiTrace;
use App\Domains\AI\Services\AiTraceExporter;
use App\Domains\AI\Services\AiTraceRedactor;
use App\Domains\Intake\Actions\HardDeleteIntake;
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
    config([
        'ai.tracing.enabled' => true,
        'ai.tracing.retention_days' => 30,
        'filesystems.media' => 'local',
    ]);
    Storage::fake('local');
});

function makeRetentionIntake(array $overrides = []): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create(array_merge([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'is_demo' => false,
        'customer_name' => 'Jan de Vries',
        'customer_email' => 'jan@example.com',
        'address_line' => 'Voorbeeldstraat 12',
    ], $overrides));
}

function seedTrace(Intake $intake, array $overrides = []): AiTrace
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
        'prompt_version' => 'test-v1',
        'request_snapshot' => [
            'system' => 'test',
            'user' => [
                'customer_name' => $intake->customer_name,
                'customer_email' => $intake->customer_email,
                'address_line' => $intake->address_line,
                'note' => 'Bel Jan de Vries op 0612345678 of mail jan@example.com, Voorbeeldstraat 12',
            ],
        ],
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
    ], $overrides));
}

test('demo intake purge houdt AI-traces intact via nullOnDelete en intake_ref_id', function () {
    $intake = makeRetentionIntake(['is_demo' => true]);
    $disk = (string) config('filesystems.media', 'local');
    $dir = 'intakes/'.$intake->uuid.'/room_photos';
    Storage::disk($disk)->put($dir.'/photo.jpg', 'fake-image');
    Storage::disk($disk)->put($dir.'/analysis/photo.jpg', 'fake-analysis');
    expect(Storage::disk($disk)->exists($dir.'/photo.jpg'))->toBeTrue();

    $trace = seedTrace($intake, [
        'call_type' => AiTraceCallType::Summary,
        'is_demo' => true,
    ]);
    $traceId = $trace->id;
    $intakeId = $intake->id;

    app(HardDeleteIntake::class)->handle($intake);

    expect(Intake::withTrashed()->whereKey($intakeId)->exists())->toBeFalse();

    $surviving = AiTrace::query()->whereKey($traceId)->first();
    expect($surviving)->not->toBeNull()
        ->and($surviving->intake_id)->toBeNull()
        ->and($surviving->intake_ref_id)->toBe($intakeId)
        ->and($surviving->is_demo)->toBeTrue();

    expect(Storage::disk($disk)->directoryExists('intakes/'.$intake->uuid))->toBeFalse();
});

test('ai:purge-traces verwijdert traces ouder dan N dagen inclusief wees-demo-traces', function () {
    $intake = makeRetentionIntake(['is_demo' => true]);
    $old = seedTrace($intake, ['is_demo' => true]);
    AiTrace::query()->whereKey($old->id)->update([
        'created_at' => now()->subDays(40),
        'updated_at' => now()->subDays(40),
        'intake_id' => null,
    ]);

    $fresh = seedTrace($intake, [
        'call_type' => AiTraceCallType::RequestIntent,
        'is_demo' => true,
    ]);

    Artisan::call('ai:purge-traces', ['--days' => 30]);

    expect(AiTrace::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(AiTrace::query()->whereKey($fresh->id)->exists())->toBeTrue();
});

test('ai:traces:export bundelt drie intakes in jsonl en md met masking', function () {
    $a = makeRetentionIntake(['customer_name' => 'Anna Bakker', 'customer_email' => 'anna@example.com', 'address_line' => 'Kerkstraat 4']);
    $b = makeRetentionIntake(['customer_name' => 'Bert Jansen', 'customer_email' => 'bert@example.com', 'address_line' => 'Dorpsstraat 8']);
    $c = makeRetentionIntake(['is_demo' => true, 'customer_name' => 'Carla Demo', 'customer_email' => 'carla@demo.invalid', 'address_line' => 'Demolaan 1']);

    seedTrace($a, ['call_type' => AiTraceCallType::TextExtraction]);
    seedTrace($b, ['call_type' => AiTraceCallType::Summary]);
    seedTrace($c, ['call_type' => AiTraceCallType::DossierSynthesis, 'is_demo' => true]);

    $outDir = storage_path('app/exports/test-bundle-'.Str::random(6));
    Artisan::call('ai:traces:export', [
        '--intake' => [$a->id.','.$b->id, (string) $c->id],
        '--output' => $outDir.'/bundle',
    ]);

    $jsonl = collect(glob($outDir.'/bundle*.jsonl') ?: [])->first();
    $md = collect(glob($outDir.'/bundle*.md') ?: [])->first();
    $manifest = collect(glob($outDir.'/bundle*manifest.json') ?: [])->first();

    expect($jsonl)->not->toBeNull()
        ->and($md)->not->toBeNull()
        ->and($manifest)->not->toBeNull();

    $jsonlBody = (string) file_get_contents((string) $jsonl);
    $mdBody = (string) file_get_contents((string) $md);

    expect(substr_count($jsonlBody, "\n"))->toBeGreaterThanOrEqual(3)
        ->and($jsonlBody)->toContain('"intake_id":'.$a->id)
        ->and($jsonlBody)->toContain('"intake_id":'.$b->id)
        ->and($jsonlBody)->toContain('"intake_id":'.$c->id)
        ->and($jsonlBody)->not->toContain('anna@example.com')
        ->and($jsonlBody)->not->toContain('Anna Bakker')
        ->and($jsonlBody)->toContain('[e-mail verwijderd]')
        ->and($mdBody)->toContain('## Intake '.$a->id)
        ->and($mdBody)->toContain('## Intake '.$c->id)
        ->and($mdBody)->toContain('Demo: ja')
        ->and($mdBody)->toContain('```json');
});

test('ai:traces:export splitst boven size-budget op intakegrenzen met manifest', function () {
    config([
        'ai.tracing.export_max_part_bytes' => 800,
        'ai.tracing.export_max_part_chars' => 800,
    ]);

    $one = makeRetentionIntake();
    $two = makeRetentionIntake();
    seedTrace($one, [
        'raw_response' => str_repeat('A', 400),
        'request_snapshot' => ['system' => str_repeat('S', 200), 'user' => ['x' => str_repeat('U', 200)]],
    ]);
    seedTrace($two, [
        'raw_response' => str_repeat('B', 400),
        'request_snapshot' => ['system' => str_repeat('T', 200), 'user' => ['y' => str_repeat('V', 200)]],
    ]);

    $outDir = storage_path('app/exports/test-split-'.Str::random(6));
    Artisan::call('ai:traces:export', [
        '--intake' => [(string) $one->id, (string) $two->id],
        '--format' => 'jsonl',
        '--output' => $outDir.'/split',
    ]);

    $parts = glob($outDir.'/split-part*-of-*.jsonl') ?: [];
    $manifestPath = collect(glob($outDir.'/split*manifest.json') ?: [])->first();

    expect(count($parts))->toBeGreaterThanOrEqual(2)
        ->and($manifestPath)->not->toBeNull();

    $manifest = json_decode((string) file_get_contents((string) $manifestPath), true);
    expect($manifest['totals']['parts'])->toBeGreaterThanOrEqual(2)
        ->and($manifest['parts'])->toBeArray()->not->toBeEmpty();
});

test('ai:traces:export werkt voor gepurgede demo-intakes via intake_ref_id', function () {
    $intake = makeRetentionIntake(['is_demo' => true]);
    $id = $intake->id;
    seedTrace($intake, ['is_demo' => true, 'raw_response' => '{"demo":true}']);
    app(HardDeleteIntake::class)->handle($intake);

    expect(AiTrace::query()->where('intake_ref_id', $id)->whereNull('intake_id')->exists())->toBeTrue();

    $outDir = storage_path('app/exports/test-purged-'.Str::random(6));
    Artisan::call('ai:traces:export', [
        '--intake' => [(string) $id],
        '--demo-only' => true,
        '--format' => 'md',
        '--output' => $outDir.'/purged',
    ]);

    $md = collect(glob($outDir.'/purged*.md') ?: [])->first();
    expect($md)->not->toBeNull();
    $body = (string) file_get_contents((string) $md);
    expect($body)->toContain('## Intake '.$id)
        ->and($body)->toContain('Demo: ja');
});

test('ai:traces:export relative --output exports/ verdubbelt niet en print absolute paden', function () {
    $intake = makeRetentionIntake();
    seedTrace($intake);

    $slug = 'staging-pathfix-'.Str::random(6);
    Artisan::call('ai:traces:export', [
        '--intake' => [(string) $intake->id],
        '--format' => 'jsonl',
        '--output' => 'exports/'.$slug.'/',
    ]);

    $expectedDir = storage_path('app/exports/'.$slug);
    $doubledDir = storage_path('app/exports/exports/'.$slug);

    expect(is_dir($expectedDir))->toBeTrue()
        ->and(is_dir($doubledDir))->toBeFalse();

    $jsonl = collect(glob($expectedDir.'/ai-traces-*.jsonl') ?: [])->first();
    $manifest = collect(glob($expectedDir.'/ai-traces-*manifest.json') ?: [])->first();
    expect($jsonl)->not->toBeNull()->and($manifest)->not->toBeNull();

    $console = Artisan::output();
    $jsonlAbsolute = realpath((string) $jsonl) ?: (string) $jsonl;
    $manifestAbsolute = realpath((string) $manifest) ?: (string) $manifest;

    expect($console)->toContain('Bestanden (absolute paden):')
        ->and($console)->toContain($jsonlAbsolute)
        ->and($console)->toContain($manifestAbsolute)
        ->and($console)->not->toContain(storage_path('app/exports/exports/'));

    $slug2 = 'staging-pathfix2-'.Str::random(6);
    Artisan::call('ai:traces:export', [
        '--intake' => [(string) $intake->id],
        '--format' => 'md',
        '--output' => 'storage/app/exports/'.$slug2.'/',
    ]);

    expect(is_dir(storage_path('app/exports/'.$slug2)))->toBeTrue()
        ->and(is_dir(storage_path('app/exports/storage/app/exports/'.$slug2)))->toBeFalse();
});

test('AiTraceRedactor maskeert namen e-mail telefoon straat en huisnummer', function () {
    $redactor = app(AiTraceRedactor::class)->withKnownPii([
        'customer_name' => 'Sophie Vermeer',
        'customer_email' => 'sophie@example.com',
        'customer_phone' => '06-98765432',
        'address_line' => 'Lindenlaan 22b',
    ]);

    $redacted = $redactor->redact([
        'customer_name' => 'Sophie Vermeer',
        'address_line' => 'Lindenlaan 22b',
        'free_text' => 'Hallo Sophie Vermeer, mail sophie@example.com of bel 06-98765432. Adres Lindenlaan 22b. Ook Kerkstraat 9 is relevant.',
    ]);

    $json = (string) json_encode($redacted, JSON_UNESCAPED_UNICODE);

    expect($json)->not->toContain('Sophie Vermeer')
        ->and($json)->not->toContain('sophie@example.com')
        ->and($json)->not->toContain('06-98765432')
        ->and($json)->not->toContain('Lindenlaan 22b')
        ->and($json)->not->toContain('Kerkstraat 9')
        ->and($json)->toContain('[naam verwijderd]')
        ->and($json)->toContain('[e-mail verwijderd]')
        ->and($json)->toContain('[telefoon verwijderd]')
        ->and($json)->toContain('[adres verwijderd]');
});

test('AiTraceExporter past masking opnieuw toe als safety net', function () {
    $intake = makeRetentionIntake([
        'customer_name' => 'Hidden Name',
        'customer_email' => 'hidden@example.com',
        'address_line' => 'Geheimestraat 99',
    ]);
    $trace = seedTrace($intake, [
        'request_snapshot' => [
            'system' => 'x',
            'user' => 'Neem contact op met Hidden Name via hidden@example.com op Geheimestraat 99',
        ],
    ]);

    $payload = app(AiTraceExporter::class)->callPayload($trace);
    $encoded = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);

    expect($encoded)->not->toContain('Hidden Name')
        ->and($encoded)->not->toContain('hidden@example.com')
        ->and($encoded)->not->toContain('Geheimestraat 99');
});
