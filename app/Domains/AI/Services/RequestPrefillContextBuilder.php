<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeExternalFact;

/**
 * Verzamelt al bekende intakecontext voor AI-prefill zonder identiteit of ruwe coördinaten (ADR-0013).
 */
final class RequestPrefillContextBuilder
{
    private const BLOCKED_FACT_KEYS = [
        'bag_coordinates',
        'bag_centroid',
        'bag_geometry',
        'pdok_coordinates',
        'address_coordinates',
        'customer_email',
        'customer_name',
        'customer_phone',
    ];

    /**
     * @return array{
     *     request_reason: string|null,
     *     answers: list<array{question_key: string, section_instance_key: string|null, prefill_source: string|null, value: mixed}>,
     *     external_facts: list<array{fact_key: string, source: string|null, value: mixed, confidence: mixed}>
     * }
     */
    public function build(Intake $intake): array
    {
        $answers = IntakeAnswer::query()
            ->where('intake_id', $intake->id)
            ->orderBy('id')
            ->get()
            ->map(static function (IntakeAnswer $answer): array {
                return [
                    'question_key' => $answer->question_key,
                    'section_instance_key' => $answer->section_instance_key,
                    'prefill_source' => $answer->prefill_source,
                    'value' => $answer->value,
                ];
            })
            ->all();

        $requestReason = null;
        foreach ($answers as $answer) {
            if ($answer['question_key'] === 'request_reason' && $answer['section_instance_key'] === null) {
                $text = is_array($answer['value']) ? ($answer['value']['text'] ?? null) : null;
                $requestReason = is_string($text) ? trim($text) : null;
                break;
            }
        }

        $facts = IntakeExternalFact::query()
            ->where('intake_id', $intake->id)
            ->orderBy('id')
            ->get()
            ->filter(function (IntakeExternalFact $fact): bool {
                $key = strtolower((string) $fact->fact_key);

                foreach (self::BLOCKED_FACT_KEYS as $blocked) {
                    if (str_contains($key, $blocked)) {
                        return false;
                    }
                }

                return true;
            })
            ->map(static function (IntakeExternalFact $fact): array {
                return [
                    'fact_key' => $fact->fact_key,
                    'source' => $fact->source,
                    'value' => $fact->value,
                    'confidence' => $fact->confidence,
                ];
            })
            ->values()
            ->all();

        return [
            'request_reason' => $requestReason,
            'answers' => $answers,
            'external_facts' => $facts,
        ];
    }
}
