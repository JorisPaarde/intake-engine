<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Enums\QuestionType;

final class AnswerValueReader
{
    /**
     * @param  array<string, mixed>|null  $value
     */
    public function isFilled(?array $value, QuestionType $type): bool
    {
        if ($value === null) {
            return false;
        }

        return match ($type) {
            QuestionType::ShortText, QuestionType::LongText => $this->isNonEmptyString($value['text'] ?? null),
            QuestionType::Number => array_key_exists('number', $value) && is_numeric($value['number']),
            QuestionType::SingleChoice => $this->isNonEmptyString($value['value'] ?? null),
            QuestionType::MultiChoice => is_array($value['values'] ?? null) && $value['values'] !== [],
            // Livewire radio's sturen "1"/"0"; geaccepteerde bool-vormen: true/false/1/0/"1"/"0"
            QuestionType::Boolean => array_key_exists('bool', $value) && $this->isPresentBool($value['bool']),
            QuestionType::Photo => is_array($value['upload_ids'] ?? null) && $value['upload_ids'] !== [],
        };
    }

    /**
     * @param  array<string, mixed>|null  $value
     */
    public function readComparable(?array $value, QuestionType $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            QuestionType::ShortText, QuestionType::LongText => $value['text'] ?? null,
            QuestionType::Number => array_key_exists('number', $value) && is_numeric($value['number'])
                ? $this->toFloat($value['number'])
                : null,
            QuestionType::SingleChoice => $value['value'] ?? null,
            QuestionType::MultiChoice => is_array($value['values'] ?? null) ? array_values($value['values']) : null,
            QuestionType::Boolean => array_key_exists('bool', $value) && $this->isPresentBool($value['bool'])
                ? $this->toBool($value['bool'])
                : null,
            QuestionType::Photo => is_array($value['upload_ids'] ?? null) ? array_values($value['upload_ids']) : null,
        };
    }

    /**
     * @param  array<string, mixed>|null  $ruleValue
     */
    public function readRuleComparable(?array $ruleValue, QuestionType $sourceType): mixed
    {
        if ($ruleValue === null) {
            return null;
        }

        return match ($sourceType) {
            QuestionType::ShortText, QuestionType::LongText => $ruleValue['text'] ?? null,
            QuestionType::Number => array_key_exists('number', $ruleValue) && is_numeric($ruleValue['number'])
                ? $this->toFloat($ruleValue['number'])
                : null,
            QuestionType::SingleChoice => $ruleValue['value'] ?? null,
            QuestionType::MultiChoice => is_array($ruleValue['values'] ?? null)
                ? array_values($ruleValue['values'])
                : null,
            QuestionType::Boolean => array_key_exists('bool', $ruleValue) && $this->isPresentBool($ruleValue['bool'])
                ? $this->toBool($ruleValue['bool'])
                : null,
            QuestionType::Photo => is_array($ruleValue['upload_ids'] ?? null)
                ? array_values($ruleValue['upload_ids'])
                : null,
        };
    }

    private function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function isPresentBool(mixed $value): bool
    {
        return is_bool($value)
            || $value === 0
            || $value === 1
            || $value === '0'
            || $value === '1';
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    private function toFloat(int|float|string $value): float
    {
        return (float) $value;
    }
}
