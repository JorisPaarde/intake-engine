<?php

declare(strict_types=1);

namespace App\Domains\AI\Eval;

use App\Domains\AI\Support\PhotoSubject;

/**
 * Measurement-only stub for photo_feedback fixtures (N16).
 *
 * Not wired into {@see InterpretationEvalRunner} yet — call from Pest or a
 * future `--kind=photo_feedback` runner. Scores customer-message strings
 * against must_contain / must_not_contain without calling the live model.
 * The production message path is {@see PhotoSubject::mismatchMessage}.
 */
final class PhotoFeedbackScorer
{
    public function __construct(
        private readonly FactScorer $factScorer,
    ) {}

    /**
     * @param  array<string, mixed>  $fixture
     * @param  string|null  $customerMessage  Override; defaults to code_path.current_customer_message
     * @return array<string, array<string, mixed>>
     */
    public function score(array $fixture, ?string $customerMessage = null): array
    {
        $expected = is_array($fixture['expected'] ?? null) ? $fixture['expected'] : [];
        $components = is_array($fixture['components'] ?? null)
            ? array_values(array_filter(
                $fixture['components'],
                static fn (mixed $v): bool => is_string($v) && $v !== '',
            ))
            : ['C5', 'C7'];

        $codePath = is_array($fixture['code_path'] ?? null) ? $fixture['code_path'] : [];
        $message = $customerMessage;
        if ($message === null) {
            $raw = $codePath['current_customer_message'] ?? null;
            $message = is_string($raw) ? $raw : null;
        }

        $statusExpected = is_string($expected['assessment_status'] ?? null)
            ? $expected['assessment_status']
            : null;
        $statusGot = is_string($codePath['assessment_status'] ?? null)
            ? $codePath['assessment_status']
            : null;

        $out = [];
        $out['assessment_status'] = $this->factScorer->score(
            expected: $statusExpected,
            got: $statusGot,
            supported: $statusExpected !== null,
            components: $components,
        );

        $mustContain = is_array($expected['customer_message_must_contain'] ?? null)
            ? $expected['customer_message_must_contain']
            : [];
        $mustNotContain = is_array($expected['customer_message_must_not_contain'] ?? null)
            ? $expected['customer_message_must_not_contain']
            : [];

        $containOk = true;
        $missing = [];
        foreach ($mustContain as $needle) {
            if (! is_string($needle) || $needle === '') {
                continue;
            }
            if ($message === null || ! str_contains(mb_strtolower($message), mb_strtolower($needle))) {
                $containOk = false;
                $missing[] = $needle;
            }
        }

        $forbiddenHits = [];
        foreach ($mustNotContain as $needle) {
            if (! is_string($needle) || $needle === '') {
                continue;
            }
            if ($message !== null && str_contains(mb_strtolower($message), mb_strtolower($needle))) {
                $containOk = false;
                $forbiddenHits[] = $needle;
            }
        }

        $messageSupported = $mustContain !== [] || $mustNotContain !== [];
        // ok-status with null message and empty must_contain → pass (no feedback expected).
        if ($statusExpected === 'ok' && $message === null && $mustContain === []) {
            $containOk = $forbiddenHits === [];
        }

        $out['customer_message'] = $this->factScorer->score(
            expected: true,
            got: $containOk,
            supported: $messageSupported || $statusExpected === 'ok',
            components: $components,
        );
        $out['customer_message']['message'] = $message;
        $out['customer_message']['missing'] = $missing;
        $out['customer_message']['forbidden_hits'] = $forbiddenHits;

        return $out;
    }
}
