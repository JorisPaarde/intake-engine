<?php

declare(strict_types=1);

namespace App\Domains\AI\Eval;

use App\Domains\AI\DTOs\AiCompletionResult;

/**
 * Eval-only: sums real provider cost (OpenRouter usage.cost, USD) of every
 * AI call made during one eval run, at the client boundary (N90).
 */
final class EvalCostMeter
{
    private float $totalUsd = 0.0;

    private int $calls = 0;

    private int $callsWithCost = 0;

    /** @var array<string, float> */
    private array $byPromptVersion = [];

    public function record(string $promptVersion, AiCompletionResult $result): void
    {
        $this->calls++;
        if (! is_numeric($result->estimatedCost)) {
            return;
        }
        $cost = (float) $result->estimatedCost;
        $this->callsWithCost++;
        $this->totalUsd += $cost;
        $this->byPromptVersion[$promptVersion] = ($this->byPromptVersion[$promptVersion] ?? 0.0) + $cost;
    }

    /**
     * @return array{total_usd: float, calls: int, calls_with_cost: int, by_prompt_version: array<string, float>}
     */
    public function summary(): array
    {
        ksort($this->byPromptVersion);

        return [
            'total_usd' => round($this->totalUsd, 6),
            'calls' => $this->calls,
            'calls_with_cost' => $this->callsWithCost,
            'by_prompt_version' => array_map(static fn (float $v): float => round($v, 6), $this->byPromptVersion),
        ];
    }
}
