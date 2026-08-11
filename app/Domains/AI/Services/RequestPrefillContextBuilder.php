<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\Intake\Models\DossierRecord;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeExternalFact;
use App\Enums\DossierRecordKind;
use App\Enums\DossierRecordStatus;

/**
 * Verzamelt al bekende intakecontext voor AI-prefill zonder identiteit of ruwe coördinaten (ADR-0013/0014).
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
     *     external_facts: list<array{fact_key: string, source: string|null, value: mixed, confidence: mixed}>,
     *     installer_observations: list<array{method: string|null, text: string}>
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

        $observations = DossierRecord::query()
            ->where('intake_id', $intake->id)
            ->where('kind', DossierRecordKind::Observation)
            ->where('status', DossierRecordStatus::Established)
            ->whereNull('superseded_by_id')
            ->orderBy('id')
            ->limit(20)
            ->get()
            ->map(static function (DossierRecord $record): ?array {
                $text = $record->value['text'] ?? null;
                if (! is_string($text)) {
                    return null;
                }

                $text = trim($text);
                if ($text === '' || mb_strlen($text) < 3) {
                    return null;
                }

                return [
                    'method' => $record->method,
                    'text' => mb_substr($text, 0, 500),
                ];
            })
            ->filter()
            ->values()
            ->all();

        return [
            'request_reason' => $requestReason,
            'answers' => $answers,
            'external_facts' => $facts,
            'installer_observations' => $observations,
        ];
    }
}
