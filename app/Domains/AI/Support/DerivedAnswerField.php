<?php

declare(strict_types=1);

namespace App\Domains\AI\Support;

use App\Enums\QuestionType;

/**
 * One answer that a photo-analysis profile may derive.
 *
 * For choice questions `allowedValues` must mirror the option values of the target question
 * in the pinned template version — the AI never invents an option, and validation rejects
 * anything else. Boolean questions accept only `yes`/`no` on the wire, so the model never
 * has to reason about JSON booleans.
 */
final class DerivedAnswerField
{
    private const BOOLEAN_VALUES = ['yes', 'no'];

    /**
     * @param  list<string>  $allowedValues  accepted values excluding 'unknown'; ignored for booleans
     */
    private function __construct(
        public readonly string $outputKey,
        public readonly string $questionKey,
        public readonly QuestionType $questionType,
        public readonly array $allowedValues,
    ) {}

    /**
     * @param  list<string>  $allowedValues
     */
    public static function choice(string $outputKey, string $questionKey, array $allowedValues): self
    {
        return new self($outputKey, $questionKey, QuestionType::SingleChoice, $allowedValues);
    }

    public static function boolean(string $outputKey, string $questionKey): self
    {
        return new self($outputKey, $questionKey, QuestionType::Boolean, self::BOOLEAN_VALUES);
    }

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
        return $this->questionType === QuestionType::Boolean
            ? ['bool' => $raw === 'yes']
            : ['value' => $raw];
    }
}
