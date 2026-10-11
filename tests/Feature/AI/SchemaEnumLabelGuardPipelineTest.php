<?php

declare(strict_types=1);

use App\Domains\AI\Actions\SuggestAttentionPoints;
use App\Domains\AI\Actions\SummarizeIntake;
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

test('attention pipeline replaces none_visible in label; Dutch label passes untouched', function () {
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

    $leaky = app(SuggestAttentionPoints::class)->applyPipelineToOutput([
        'points' => [[
            'code' => 'no_free_group',
            'label' => "AI-analyse geeft 'none_visible' voor vrije module-ruimte; te controleren",
            'confidence' => 'medium',
            'evidence' => [[
                'source_type' => 'external_fact',
                'reference' => 'fusebox_photo_assessment@fact:x',
            ]],
        ]],
    ], $payload);

    $dutch = FuseboxAssessmentLabels::emptyModuleSpace('none_visible');
    expect($leaky)->toHaveCount(1)
        ->and($leaky[0]['label'])->toContain($dutch)
        ->and($leaky[0]['label'])->not->toContain('none_visible');

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

test('summary pipeline replaces three_phase enum token in highlights', function () {
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

    $out = app(SummarizeIntake::class)->applyPipelineToOutput([
        'summary' => 'Korte samenvatting van de digitale opname voor de installateur met fase three_phase te controleren.',
        'highlights' => ['Fase three_phase lijkt zichtbaar'],
    ], $payload);

    $phaseLabel = FuseboxAssessmentLabels::phase('three_phase');
    expect($out['summary'])->toContain($phaseLabel)
        ->and($out['summary'])->not->toContain('three_phase')
        ->and($out['highlights'][0])->toContain($phaseLabel)
        ->and($out['highlights'][0])->not->toContain('three_phase');
});
