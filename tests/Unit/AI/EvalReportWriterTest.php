<?php

declare(strict_types=1);

use App\Domains\AI\Eval\EvalReportWriter;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

afterEach(function () {
    config(['ai.eval.output_dir' => 'storage/framework/testing/eval']);
});

function evalWriterFixtureReport(bool $isBaseline): array
{
    return [
        'is_baseline' => $isBaseline,
        'mode' => $isBaseline ? 'openai' : 'fake',
        'date' => '2026-10-10',
        'commit_sha' => 'abcdef1234567890',
        'model' => 'test-model',
        'temperature' => 0.2,
        'repeats' => 1,
        'prompt_fingerprint' => [
            'combined_hash' => 'testhash1234',
            'prompts' => [],
        ],
        'scores_by_component' => [
            'C1' => [
                'model_raw' => ['correct' => 1, 'total' => 1],
                'pipeline_final' => ['correct' => 1, 'total' => 1],
            ],
        ],
        'disputed_scores' => [],
        'errors' => [],
        'spread' => null,
        'not_runnable' => [],
        'normalizer_audit' => [],
    ];
}

test('fake report writes results but not baseline or HISTORY', function () {
    $tmp = storage_path('framework/testing/eval-writer-'.uniqid('', true));
    config(['ai.eval.output_dir' => $tmp]);

    $paths = app(EvalReportWriter::class)->write(evalWriterFixtureReport(false));

    expect($paths['baseline_json'])->toBeNull()
        ->and($paths['baseline_md'])->toBeNull()
        ->and(File::exists($paths['results_json']))->toBeTrue()
        ->and(File::exists($paths['results_md']))->toBeTrue()
        ->and(File::exists($tmp.'/baseline'))->toBeFalse()
        ->and(File::exists($tmp.'/results/HISTORY.md'))->toBeFalse();

    File::deleteDirectory($tmp);
});

test('baseline report writes baseline and appends HISTORY', function () {
    $tmp = storage_path('framework/testing/eval-writer-'.uniqid('', true));
    config(['ai.eval.output_dir' => $tmp]);

    $paths = app(EvalReportWriter::class)->write(evalWriterFixtureReport(true));

    expect($paths['baseline_json'])->not->toBeNull()
        ->and(File::exists((string) $paths['baseline_json']))->toBeTrue()
        ->and(File::exists((string) $paths['baseline_md']))->toBeTrue()
        ->and(File::exists($tmp.'/results/HISTORY.md'))->toBeTrue()
        ->and(File::get($tmp.'/results/HISTORY.md'))->toContain('testhash1234');

    File::deleteDirectory($tmp);
});
