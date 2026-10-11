<?php

declare(strict_types=1);

use App\Domains\AI\Actions\SuggestAttentionPoints;
use App\Domains\AI\Actions\SummarizeIntake;
use App\Domains\AI\Eval\InterpretationEvalRunner;
use App\Domains\AI\Eval\ReportClaimsCaseRunner;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    config([
        'app.env' => 'testing',
        'ai.provider' => 'fake',
        'ai.text_inference.enabled' => true,
        'ai.eval.output_dir' => 'storage/framework/testing/eval-report-claims',
    ]);
});

test('report_claims case runner runs with FakeAiClient and scores both layers', function () {
    $fixture = json_decode(
        (string) file_get_contents(base_path('tests/Eval/fixtures/report_claims/rc-kt11-fusebox-hedged-single.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $result = app(ReportClaimsCaseRunner::class)->run($fixture, 1);

    expect($result['kind'])->toBe('report_claims')
        ->and($result['status'])->toBe('ok')
        ->and($result['runnable'])->toBeTrue()
        ->and($result['model_raw']['summary'])->toBeArray()
        ->and($result['model_raw']['attention_points'])->toBeArray()
        ->and($result['model_raw']['attention_points'])->toHaveKey('points')
        ->and($result['pipeline_final']['summary'])->toBeArray()
        ->and($result['pipeline_final']['attention_points'])->toBeArray()
        ->and($result['scores'])->toHaveKeys(['model_raw', 'pipeline_final'])
        ->and($result)->toHaveKey('pipeline_differs_from_model_raw')
        ->and($result)->toHaveKey('token_usage')
        ->and($result['prompt_versions'])->toHaveKeys(['summary', 'attention_points'])
        ->and($result['guard_meta']['summary_ceiling'])->toBe('medium');
});

test('eval:interpretation --fake --kind=report_claims writes non-baseline report', function () {
    $tmp = storage_path('framework/testing/eval-rc-'.uniqid('', true));
    File::ensureDirectoryExists($tmp);

    $exit = Artisan::call('eval:interpretation', [
        '--fake' => true,
        '--repeats' => 1,
        '--kind' => 'report_claims',
        '--output' => $tmp,
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('GEEN baseline')
        ->and($output)->toContain('report_claims prompts:')
        ->and($output)->toContain('summary=')
        ->and($output)->toContain('attention_points=')
        ->and(File::isDirectory($tmp.'/results'))->toBeTrue()
        ->and(File::exists($tmp.'/baseline'))->toBeFalse()
        ->and(File::exists($tmp.'/results/HISTORY.md'))->toBeFalse();

    $jsonFiles = glob($tmp.'/results/*.json') ?: [];
    expect($jsonFiles)->not->toBeEmpty();

    $report = json_decode((string) file_get_contents($jsonFiles[0]), true);
    expect($report)->toBeArray()
        ->and($report['is_baseline'])->toBeFalse()
        ->and($report['mode'])->toBe('fake');

    $caseIds = array_values(array_filter(array_map(
        static fn (array $case): ?string => is_string($case['id'] ?? null) ? $case['id'] : null,
        is_array($report['cases'] ?? null) ? $report['cases'] : [],
    )));
    expect($caseIds)->toContain('rc-kt11-fusebox-two-photos-conflict')
        ->and($caseIds)->toContain('rc-n20-fusebox-unsharp-then-sharp-no-conflict')
        ->and($caseIds)->toContain('rc-n20-fusebox-two-readable-photos-disagree')
        ->and($caseIds)->toContain('rc-no-fusebox-photo');

    $promptNames = array_map(
        static fn (array $p): string => (string) ($p['name'] ?? ''),
        is_array($report['prompt_fingerprint']['prompts'] ?? null) ? $report['prompt_fingerprint']['prompts'] : [],
    );
    expect($promptNames)->toContain('summary')
        ->and($promptNames)->toContain('attention_points');

    File::deleteDirectory($tmp);
});

test('interpretation eval runner includes report_claims when kind filter set', function () {
    $tmp = storage_path('framework/testing/eval-rc-runner-'.uniqid('', true));
    config(['ai.eval.output_dir' => $tmp]);

    $result = app(InterpretationEvalRunner::class)->run(repeats: 1, forceFake: true, kinds: ['report_claims']);
    $cases = $result['report']['cases'] ?? [];

    expect($result['report']['is_baseline'])->toBeFalse()
        ->and($cases)->not->toBeEmpty();

    foreach ($cases as $case) {
        expect($case['kind'] ?? null)->toBe('report_claims');
    }

    File::deleteDirectory($tmp);
});

test('summarize and attention applyPipelineToOutput match production validate behaviour', function () {
    $summaryPayload = [
        'external_facts' => [
            'fusebox_photo_assessment' => [
                'value' => ['phase' => 'three_phase', 'evidence' => '3-fase lijkt zichtbaar'],
                'source' => 'ai',
                'confidence' => 'medium',
            ],
        ],
    ];

    $summaryOut = app(SummarizeIntake::class)->applyPipelineToOutput([
        'summary' => 'Korte samenvatting van de digitale opname voor de installateur.',
        'highlights' => ['3-fase lijkt zichtbaar'],
    ], $summaryPayload);

    expect($summaryOut['summary'])->toBeString()
        ->and($summaryOut['highlights'])->toHaveCount(1);

    $attentionPayload = [
        'answer_context' => [],
        'external_fact_context' => [[
            'reference' => 'fusebox_photo_assessment@fact:x',
            'fact_key' => 'fusebox_photo_assessment',
            'label' => 'Meterkast',
            'value' => ['evidence' => '3-fase lijkt zichtbaar'],
            'confidence' => 'medium',
        ]],
        'uploads' => [],
        'follow_up' => [],
        'installer_review' => null,
        'pipe_routes' => [],
        'system_attention_points' => [],
    ];

    $points = app(SuggestAttentionPoints::class)->applyPipelineToOutput([
        'points' => [[
            'code' => 'verify_phase',
            'label' => '3-fase lijkt zichtbaar; te controleren',
            'confidence' => 'high',
            'evidence' => [[
                'source_type' => 'external_fact',
                'reference' => 'fusebox_photo_assessment@fact:x',
            ]],
        ]],
    ], $attentionPayload);

    expect($points)->toHaveCount(1)
        ->and($points[0]['confidence'])->toBe('medium');

    expect(fn () => app(SuggestAttentionPoints::class)->applyPipelineToOutput([
        'points' => [[
            'code' => 'bad_ref',
            'label' => 'Onbekende referentie',
            'confidence' => 'low',
            'evidence' => [[
                'source_type' => 'upload',
                'reference' => 'does-not-exist',
            ]],
        ]],
    ], $attentionPayload))->toThrow(ValidationException::class);
});
