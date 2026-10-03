<?php

declare(strict_types=1);

namespace App\Domains\AI\DTOs;

final readonly class AiCompletionResult
{
    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $modelParameters
     */
    public function __construct(
        public array $output,
        public string $provider,
        public ?string $model = null,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?int $totalTokens = null,
        public int $imageCount = 0,
        /**
         * Coarse cost in whole cents (ceil) for display / legacy booking.
         * Fine provider cost lives in {@see $estimatedCost}; fractional budget
         * reconciliation uses microcents derived from that fine value.
         */
        public ?int $estimatedCostCents = null,
        public ?string $finishReason = null,
        public ?string $rawResponse = null,
        public ?int $providerMs = null,
        public array $modelParameters = [],
        /** OpenAI/OpenRouter completion `id` (e.g. gen-…). */
        public ?string $providerResponseId = null,
        /**
         * Unrounded provider-reported cost in currency units (USD/EUR as returned),
         * finer than integer cents — e.g. OpenRouter `usage.cost`. Never ceil/floor here.
         */
        public ?string $estimatedCost = null,
    ) {}
}
