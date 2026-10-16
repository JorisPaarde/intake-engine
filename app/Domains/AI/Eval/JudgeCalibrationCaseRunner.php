<?php

declare(strict_types=1);

namespace App\Domains\AI\Eval;

/**
 * N90: calibrates {@see EvalSemanticJudge} on hand-labelled sentences
 * (N85 fusebox "volledig bezet", N90 drain "gepland" vs "nog niet besloten").
 */
final class JudgeCalibrationCaseRunner
{
    public function __construct(
        private readonly EvalSemanticJudge $judge,
        private readonly FactScorer $factScorer,
    ) {}

    /**
     * @param  array<string, mixed>  $fixture
     * @return array<string, mixed>
     */
    public function run(array $fixture, int $repeatIndex): array
    {
        $components = ['eval_judge'];
        $scores = [];
        $log = [];
        foreach ((array) ($fixture['items'] ?? []) as $item) {
            if (! is_array($item) || ! is_string($item['id'] ?? null) || ! is_string($item['question'] ?? null)) {
                continue;
            }
            $texts = array_values(array_filter((array) ($item['texts'] ?? []), 'is_string'));
            $result = $this->judge->judge($texts, $item['question']);
            $scores['judge_calibration.'.$item['id']] = $this->factScorer->score(
                expected: $item['expected'] ?? null,
                got: $result['verdict'],
                supported: true,
                components: $components,
            );
            $log[] = ['id' => $item['id']] + $result;
        }

        return [
            'id' => $fixture['id'] ?? null,
            'kind' => 'judge_calibration',
            'repeat' => $repeatIndex,
            'components' => $components,
            'judge' => $log,
            'scores' => ['model_raw' => $scores],
            'note' => $fixture['note'] ?? null,
        ];
    }
}
