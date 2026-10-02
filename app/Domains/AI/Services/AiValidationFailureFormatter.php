<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * Formats AI output validation failures for ai_runs.error_message and logs.
 * Includes every attribute + a short rejected-value summary; never embeds
 * image bytes, secrets, or long free-text that might contain PII.
 */
final class AiValidationFailureFormatter
{
    private const int MAX_TOTAL = 1000;

    private const int MAX_VALUE = 80;

    public function fromValidator(Validator $validator): string
    {
        $parts = [];

        foreach ($validator->errors()->messages() as $attribute => $messages) {
            $rejected = $this->summarizeValue(data_get($validator->getData(), $attribute));

            foreach ($messages as $message) {
                $parts[] = $rejected === null
                    ? "{$attribute}: {$message}"
                    : "{$attribute}: {$message} [got: {$rejected}]";
            }
        }

        return $this->join($parts);
    }

    public function fromException(ValidationException $exception): string
    {
        $parts = [];
        $validator = $exception->validator;
        $data = $validator instanceof Validator
            ? $validator->getData()
            : [];

        foreach ($exception->errors() as $attribute => $messages) {
            $rejected = $this->summarizeValue(data_get($data, $attribute));

            foreach ($messages as $message) {
                $parts[] = $rejected === null
                    ? "{$attribute}: {$message}"
                    : "{$attribute}: {$message} [got: {$rejected}]";
            }
        }

        if ($parts === []) {
            return Str::limit($exception->getMessage(), self::MAX_TOTAL, '');
        }

        return $this->join($parts);
    }

    /** @param list<string> $parts */
    private function join(array $parts): string
    {
        if ($parts === []) {
            return 'AI output failed validation.';
        }

        return Str::limit(implode(' | ', $parts), self::MAX_TOTAL, '');
    }

    private function summarizeValue(mixed $value): ?string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === '') {
                return '""';
            }

            // Skip values that look like embedded media or secrets.
            if (str_starts_with($trimmed, 'data:')
                || preg_match('/^[A-Za-z0-9+\/=]{120,}$/', $trimmed) === 1
                || preg_match('/\b(sk-|or-|Bearer\s)/i', $trimmed) === 1) {
                return '[redacted]';
            }

            return Str::limit($trimmed, self::MAX_VALUE, '…');
        }

        if (is_array($value)) {
            return 'array('.count($value).')';
        }

        return null;
    }
}
