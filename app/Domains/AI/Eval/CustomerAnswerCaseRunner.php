<?php

declare(strict_types=1);

namespace App\Domains\AI\Eval;

use App\Domains\AI\Actions\InterpretFollowUpText;

/**
 * Meet C6: hoogtehints uit klantantwoord via het AI-pad (ADR-0016).
 */
final class CustomerAnswerCaseRunner
{
    public function __construct(
        private readonly FactScorer $scorer,
        private readonly InterpretFollowUpText $interpretFollowUpText,
    ) {}

    /**
     * @param  array<string, mixed>  $fixture
     * @return array<string, mixed>
     */
    public function run(array $fixture, int $repeatIndex): array
    {
        $text = is_string($fixture['text'] ?? null) ? $fixture['text'] : '';
        $expected = is_array($fixture['expected'] ?? null) ? $fixture['expected'] : [];

        $hints = $this->interpretFollowUpText->extractHeightHints($text);

        $facts = [
            'ridge_height_m' => $hints['peak_height_m'],
            'knee_wall_height_m' => $hints['knee_wall_height_m'],
            'sloped_roof' => $hints['mentions_sloped_roof'] ? true : null,
            'ceiling_height_m' => null,
        ];

        $scores = [
            'model_raw' => $this->scoreLayer($expected, $facts, supported: true),
            'pipeline_final' => $this->scoreLayer($expected, $facts, supported: true),
        ];

        return [
            'id' => $fixture['id'] ?? null,
            'kind' => 'customer_answer',
            'repeat' => $repeatIndex,
            'source_kind' => $fixture['source_kind'] ?? null,
            'origin' => $fixture['origin'] ?? null,
            'status' => 'ok',
            'error' => null,
            'task_prompt' => $fixture['task_prompt'] ?? null,
            'room' => $fixture['room'] ?? null,
            'model_raw' => $hints,
            'pipeline_final' => $facts,
            'facts' => [
                'model_raw' => $facts,
                'pipeline_final' => $facts,
            ],
            'scores' => $scores,
            'note' => $fixture['note'] ?? null,
            'runnable' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $got
     * @return array<string, array<string, mixed>>
     */
    private function scoreLayer(array $expected, array $got, bool $supported): array
    {
        $out = [];
        foreach (['ridge_height_m', 'knee_wall_height_m', 'sloped_roof', 'ceiling_height_m'] as $key) {
            if (! array_key_exists($key, $expected)) {
                continue;
            }
            $out[$key] = $this->scorer->score(
                expected: $expected[$key],
                got: $got[$key] ?? null,
                supported: $supported,
                components: ['C6'],
            );
        }

        return $out;
    }
}
