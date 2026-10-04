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
 * Verzamelt al bekende intakecontext voor AI-prefill zonder identiteit of ruwe
 * coördinaten (ADR-0013/0014). Precieze lat/lng, parcel-IDs en luchtfoto’s
 * worden uitgesloten; {@see lastPrivacyRedactions()} documenteert dat voor de trace.
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
        // Align with IntakeAttentionContextBuilder: precise location / parcel / aerial.
        'location',
        'parcel_ids',
        'aerial_image',
    ];

    /** @var list<array{field: string, action: string}> */
    private array $privacyRedactions = [];

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
        $this->privacyRedactions = [];

        $answers = IntakeAnswer::query()
            ->where('intake_id', $intake->id)
            ->orderBy('id')
            ->get()
            ->map(function (IntakeAnswer $answer): array {
                $value = $answer->value;
                if (is_array($value)) {
                    $value = $this->scrubLocationFields($value, 'answers.'.$answer->question_key);
                }

                return [
                    'question_key' => $answer->question_key,
                    'section_instance_key' => $answer->section_instance_key,
                    'prefill_source' => $answer->prefill_source,
                    'value' => $value,
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
                    if ($key === $blocked || str_contains($key, $blocked)) {
                        $this->privacyRedactions[] = [
                            'field' => 'external_facts.'.$fact->fact_key,
                            'action' => 'fact_excluded',
                        ];

                        return false;
                    }
                }

                return true;
            })
            ->map(function (IntakeExternalFact $fact): array {
                $value = $this->scrubLocationFields($fact->value, 'external_facts.'.$fact->fact_key);

                return [
                    'fact_key' => $fact->fact_key,
                    'source' => $fact->source,
                    'value' => $value,
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
                $text = $record->value['text'] ?? $record->value['response_text'] ?? null;
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

    /**
     * @return list<array{field: string, action: string}>
     */
    public function lastPrivacyRedactions(): array
    {
        return $this->privacyRedactions;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function scrubLocationFields(array $value, string $path): array
    {
        $clean = [];

        foreach ($value as $key => $item) {
            $keyString = is_string($key) ? strtolower($key) : (string) $key;
            $childPath = $path.'.'.$key;

            if ($this->isLocationKey($keyString)) {
                $this->privacyRedactions[] = [
                    'field' => $childPath,
                    'action' => 'location_removed',
                ];

                continue;
            }

            $clean[$key] = is_array($item)
                ? $this->scrubLocationFields($item, $childPath)
                : $item;
        }

        return $clean;
    }

    private function isLocationKey(string $key): bool
    {
        return in_array($key, [
            'latitude',
            'longitude',
            'lat',
            'lon',
            'lng',
            'altitude',
            'alt',
            'gps',
            'coordinates',
            'coordinate',
            'coords',
            'geo',
            'geolocation',
            'center_latitude',
            'center_longitude',
            'geometry',
            'bbox',
            'bounding_box',
            'centroid',
        ], true)
            || str_contains($key, 'gps')
            || str_contains($key, 'geolocation')
            || str_starts_with($key, 'coordinate')
            || str_starts_with($key, 'bbox');
    }
}
