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
        /** Fractional estimated cost in cents (may be < 1). */
        public ?float $estimatedCostCents = null,
        public ?string $finishReason = null,
        public ?string $rawResponse = null,
        public ?int $providerMs = null,
        public array $modelParameters = [],
        /** OpenAI/OpenRouter completion `id` (e.g. gen-…). */
        public ?string $providerResponseId = null,
        /**
         * Provider-reported cost in currency units (USD/EUR as returned),
         * finer than integer cents — e.g. OpenRouter `usage.cost`.
         */
        public ?string $estimatedCost = null,
    ) {}
}
