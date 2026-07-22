<?php

declare(strict_types=1);

namespace App\Domains\AI\Support;

/**
 * One answer that a photo-analysis profile may derive.
 *
 * `allowedValues` must mirror the option values of the target question in the pinned
 * template version — the AI never invents an option, and validation rejects anything else.
 */
final class DerivedAnswerField
{
    /**
     * @param  list<string>  $allowedValues  option values accepted from the model, excluding 'unknown'
     */
    public function __construct(
        public readonly string $outputKey,
        public readonly string $questionKey,
        public readonly array $allowedValues,
    ) {}

    /**
     * The wire shape the model must return, including the always-allowed escape hatch.
     *
     * @return list<string>
     */
    public function schemaValues(): array
    {
        return [...$this->allowedValues, 'unknown'];
    }

    /**
     * @return array<string, mixed>
     */
    public function answerValue(string $raw): array
    {
        return ['value' => $raw];
    }
}
