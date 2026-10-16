<?php

declare(strict_types=1);

namespace App\Domains\AI\Eval;

/**
 * Scores fixture `expected.judge_claims` with {@see EvalSemanticJudge} (N90).
 *
 * Claim shape: {id, question, forbidden?: bool (default true)}.
 * forbidden=true → correct when the judge says "no"; false → correct on "yes".
 */
final class JudgeClaimEvaluator
{
    public function __construct(
        private readonly EvalSemanticJudge $judge,
        private readonly FactScorer $factScorer,
    ) {}

    /**
     * @param  array<string, mixed>  $expected
     * @param  list<string>  $texts
     * @param  list<string>  $components
     * @return array{scores: array<string, array<string, mixed>>, log: list<array<string, mixed>>}
     */
    public function evaluate(array $expected, array $texts, array $components): array
    {
        $claims = is_array($expected['judge_claims'] ?? null) ? $expected['judge_claims'] : [];
        $scores = [];
        $log = [];
        foreach ($claims as $claim) {
            if (! is_array($claim) || ! is_string($claim['id'] ?? null) || ! is_string($claim['question'] ?? null)) {
                continue;
            }
            $forbidden = ($claim['forbidden'] ?? true) !== false;
            $result = $this->judge->judge($texts, $claim['question']);
            $scores['judge.'.$claim['id']] = $this->factScorer->score(
                expected: $forbidden ? 'no' : 'yes',
                got: $result['verdict'],
                supported: true,
                components: $components,
            ) + ['detail' => $result['quote'] !== '' ? $result['quote'] : ($result['error'] ?? '')];
            $log[] = ['id' => $claim['id']] + $result;
        }

        return ['scores' => $scores, 'log' => $log];
    }
}
