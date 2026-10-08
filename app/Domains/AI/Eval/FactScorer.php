<?php

declare(strict_types=1);

namespace App\Domains\AI\Eval;

/**
 * Scoort één feit: correct / wrong / missing / not_supported (+ disputed apart).
 */
final class FactScorer
{
    /**
     * @param  list<string>  $components
     * @param  list<mixed>|null  $acceptableAlternates
     * @return array{
     *     status: 'correct'|'wrong'|'missing'|'not_supported',
     *     expected: mixed,
     *     got: mixed,
     *     disputed: bool,
     *     disputed_reason: string|null,
     *     components: list<string>
     * }
     */
    public function score(
        mixed $expected,
        mixed $got,
        bool $supported,
        array $components,
        bool $disputed = false,
        ?string $disputedReason = null,
        ?array $acceptableAlternates = null,
    ): array {
        if (! $supported) {
            return [
                'status' => 'not_supported',
                'expected' => $expected,
                'got' => $got,
                'disputed' => $disputed,
                'disputed_reason' => $disputedReason,
                'components' => $components,
            ];
        }

        $status = $this->compare($expected, $got, $acceptableAlternates);

        return [
            'status' => $status,
            'expected' => $expected,
            'got' => $got,
            'disputed' => $disputed,
            'disputed_reason' => $disputedReason,
            'components' => $components,
        ];
    }

    /**
     * @param  list<mixed>|null  $acceptableAlternates
     * @return 'correct'|'wrong'|'missing'
     */
    private function compare(mixed $expected, mixed $got, ?array $acceptableAlternates): string
    {
        if ($this->valuesEqual($expected, $got)) {
            return 'correct';
        }

        if ($acceptableAlternates !== null) {
            foreach ($acceptableAlternates as $alt) {
                if ($this->valuesEqual($alt, $got)) {
                    return 'correct';
                }
            }
        }

        if ($got === null || $got === '' || $got === []) {
            return 'missing';
        }

        // Verwacht null (niet genoemd) maar kreeg wél een waarde → fout (hallucinatie).
        if ($expected === null) {
            return 'wrong';
        }

        return 'wrong';
    }

    private function valuesEqual(mixed $a, mixed $b): bool
    {
        if (is_float($a) || is_float($b)) {
            if (! is_numeric($a) || ! is_numeric($b)) {
                return false;
            }

            return abs((float) $a - (float) $b) < 0.051;
        }

        if (is_bool($a) || is_bool($b)) {
            return (bool) $a === (bool) $b;
        }

        if (is_int($a) || is_int($b)) {
            if (! is_numeric($a) || ! is_numeric($b)) {
                return false;
            }

            return (int) $a === (int) $b;
        }

        return $a === $b;
    }
}
