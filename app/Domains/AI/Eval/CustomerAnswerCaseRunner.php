<?php

declare(strict_types=1);

namespace App\Domains\AI\Eval;

use App\Domains\Intake\Actions\ApplyFollowUpTextContribution;
use ReflectionMethod;

/**
 * Meet C6: nok-/knieschothoogte uit klantantwoord (bestaande regex via reflection, geen nieuwe regels).
 */
final class CustomerAnswerCaseRunner
{
    public function __construct(
        private readonly FactScorer $scorer,
    ) {}

    /**
     * @param  array<string, mixed>  $fixture
     * @return array<string, mixed>
     */
    public function run(array $fixture, int $repeatIndex): array
    {
        $text = is_string($fixture['text'] ?? null) ? $fixture['text'] : '';
        $expected = is_array($fixture['expected'] ?? null) ? $fixture['expected'] : [];

        // model_raw: geen AI-call — de "ruwe" extractie is wat de regex ziet (code-interpretatie).
        // Voor C6 is model_raw = not_supported (geen model); pipeline_final = parseHeightHints.
        $hints = $this->invokeParseHeightHints($text);

        $pipelineFacts = [
            'ridge_height_m' => $hints['peak_height_m'],
            'knee_wall_height_m' => $hints['knee_wall_height_m'],
            'sloped_roof' => $hints['mentions_sloped_roof'] ? true : null,
            'ceiling_height_m' => null, // expliciet: nooit als gemiddelde plafondhoogte
        ];

        // Varianten die "Nok" gebruiken i.p.v. "hoogste punt" → huidige regex mist die (meten).
        $scores = [
            'model_raw' => $this->scoreLayer($expected, [
                'ridge_height_m' => null,
                'knee_wall_height_m' => null,
                'sloped_roof' => null,
                'ceiling_height_m' => null,
            ], supported: false),
            'pipeline_final' => $this->scoreLayer($expected, $pipelineFacts, supported: true),
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
            'model_raw' => null,
            'pipeline_final' => $pipelineFacts,
            'facts' => [
                'model_raw' => null,
                'pipeline_final' => $pipelineFacts,
            ],
            'scores' => $scores,
            'note' => $fixture['note'] ?? null,
            'runnable' => true,
        ];
    }

    /**
     * @return array{peak_height_m: float|null, knee_wall_height_m: float|null, mentions_sloped_roof: bool}
     */
    private function invokeParseHeightHints(string $text): array
    {
        $method = new ReflectionMethod(ApplyFollowUpTextContribution::class, 'parseHeightHints');
        $method->setAccessible(true);
        /** @var ApplyFollowUpTextContribution $instance */
        $instance = app(ApplyFollowUpTextContribution::class);
        /** @var array{peak_height_m: float|null, knee_wall_height_m: float|null, mentions_sloped_roof: bool} $hints */
        $hints = $method->invoke($instance, $text);

        return $hints;
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
