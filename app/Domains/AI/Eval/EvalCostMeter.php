<?php

declare(strict_types=1);

namespace App\Domains\AI\Eval;

use Illuminate\Http\Client\Events\ResponseReceived;

/**
 * Eval-only: sums the real provider cost (OpenRouter `usage.cost`, USD) of every
 * chat-completion HTTP response during one eval run (N90). Listening at the HTTP
 * layer also counts billed retries and responses later rejected as invalid JSON.
 */
final class EvalCostMeter
{
    private float $totalUsd = 0.0;

    private int $calls = 0;

    private int $callsWithCost = 0;

    public function onResponse(ResponseReceived $event): void
    {
        if (! str_ends_with(rtrim($event->request->url(), '/'), '/chat/completions')) {
            return;
        }
        $this->calls++;
        $cost = $event->response->json('usage.cost');
        if (! is_numeric($cost)) {
            return;
        }
        $this->callsWithCost++;
        $this->totalUsd += (float) $cost;
    }

    /**
     * @return array{total_usd: float, calls: int, calls_with_cost: int}
     */
    public function summary(): array
    {
        return [
            'total_usd' => round($this->totalUsd, 6),
            'calls' => $this->calls,
            'calls_with_cost' => $this->callsWithCost,
        ];
    }
}
