<?php

declare(strict_types=1);

use App\Domains\AI\Actions\SuggestAttentionPoints;
use App\Domains\AI\Actions\SummarizeIntake;
use App\Domains\AI\Eval\ReportClaimsCaseRunner;
use App\Domains\AI\Support\SchemaEnumLabelGuard;
use App\Domains\Intake\Support\FuseboxAssessmentLabels;
use Database\Seeders\IntakeTemplateSeeder;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    config([
        'app.env' => 'testing',
        'ai.provider' => 'fake',
        'ai.text_inference.enabled' => true,
    ]);
});

test('attention pipeline leaves none_visible unchanged; Dutch label also untouched', function () {
    $payload = [
        'answer_context' => [],
        'external_fact_context' => [[
            'reference' => 'fusebox_photo_assessment@fact:x',
            'fact_key' => 'fusebox_photo_assessment',
            'label' => 'Meterkast',
            'value' => [
                'phase' => 'three_phase',
                'empty_module_space' => 'none_visible',
                'evidence' => '3-fase lijkt zichtbaar',
            ],
            'confidence' => 'medium',
        ]],
        'uploads' => [],
        'follow_up' => [],
        'installer_review' => null,
        'pipe_routes' => [],
        'system_attention_points' => [],
    ];

    $leakyLabel = "AI-analyse geeft 'none_visible' voor vrije module-ruimte; te controleren";
    $leaky = app(SuggestAttentionPoints::class)->applyPipelineToOutput([
        'points' => [[
            'code' => 'no_free_group',
            'label' => $leakyLabel,
            'confidence' => 'medium',
            'evidence' => [[
                'source_type' => 'external_fact',
                'reference' => 'fusebox_photo_assessment@fact:x',
            ]],
        ]],
    ], $payload);

    expect($leaky)->toHaveCount(1)
        ->and($leaky[0]['label'])->toBe($leakyLabel)
        ->and($leaky[0]['label'])->toContain('none_visible')
        ->and((new SchemaEnumLabelGuard)->findRawHits($leaky[0]['label']))->toBe(['none_visible']);

    $dutch = FuseboxAssessmentLabels::emptyModuleSpace('none_visible');
    $clean = app(SuggestAttentionPoints::class)->applyPipelineToOutput([
        'points' => [[
            'code' => 'verify_free_group',
            'label' => $dutch.'; te controleren',
            'confidence' => 'medium',
            'evidence' => [[
                'source_type' => 'external_fact',
                'reference' => 'fusebox_photo_assessment@fact:x',
            ]],
        ]],
    ], $payload);

    expect($clean[0]['label'])->toBe($dutch.'; te controleren');
});

test('summary pipeline leaves three_phase unchanged; enum hit is measure-only', function () {
    $payload = [
        'external_facts' => [
            'fusebox_photo_assessment' => [
                'value' => ['phase' => 'three_phase', 'evidence' => '3-fase lijkt zichtbaar'],
                'source' => 'ai',
                'confidence' => 'medium',
            ],
        ],
        'uploads' => [],
    ];

    $summaryIn = 'Korte samenvatting van de digitale opname voor de installateur met fase three_phase te controleren.';
    $highlightIn = 'Fase three_phase lijkt zichtbaar';

    $out = app(SummarizeIntake::class)->applyPipelineToOutput([
        'summary' => $summaryIn,
        'highlights' => [$highlightIn],
    ], $payload);

    expect($out['summary'])->toBe($summaryIn)
        ->and($out['highlights'][0])->toBe($highlightIn)
        ->and($out['summary'])->toContain('three_phase')
        ->and((new SchemaEnumLabelGuard)->findRawHits($out['highlights'][0]))->toBe(['three_phase']);
});

test('report_claims guard_meta logs schema enum hits without claiming a rewrite', function () {
    $runner = app(ReportClaimsCaseRunner::class);
    $method = new ReflectionMethod(ReportClaimsCaseRunner::class, 'schemaEnumGuardMeta');

    $summary = [
        'summary' => 'Korte samenvatting met enum three_phase voor de installateur te controleren.',
        'highlights' => ['Moduleplek none_visible — te controleren'],
    ];
    $points = [[
        'code' => 'verify_free_group',
        'label' => "AI-analyse geeft 'none_visible' voor vrije module-ruimte; te controleren",
        'confidence' => 'medium',
        'evidence' => [],
    ]];

    /** @var array{changed: bool, model_raw_hits: list<string>, pipeline_final_hits: list<string>} $meta */
    $meta = $method->invoke($runner, $summary, $summary, $points, $points);

    expect($meta['changed'])->toBeFalse()
        ->and($meta['model_raw_hits'])->toContain('none_visible')
        ->and($meta['model_raw_hits'])->toContain('three_phase')
        ->and($meta['pipeline_final_hits'])->toBe($meta['model_raw_hits']);
});
