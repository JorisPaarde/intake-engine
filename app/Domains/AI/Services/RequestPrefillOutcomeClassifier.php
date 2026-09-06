<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\DTOs\RequestPrefillCandidate;
use App\Enums\QuestionType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Deelt normalisatie- en classificatielogica voor catalogus-AI-prefill (productie + dry-run).
 * Geen DB-writes; geen eigen confidencegrenzen — dezelfde regels als PrefillAnswersFromKnownContext.
 */
final class RequestPrefillOutcomeClassifier
{
    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $catalog
     * @param  list<string>  $photoKeys  template photo question keys (not in fillable catalog)
     * @return array{
     *     evidence: string,
     *     fills: list<array<string, mixed>>,
     *     candidates: list<RequestPrefillCandidate>
     * }
     */
    public function classifyCatalogOutput(array $output, array $catalog, array $photoKeys = []): array
    {
        $validator = Validator::make($output, [
            'evidence' => ['required', 'string', 'min:3', 'max:500'],
            'fills' => ['present', 'array', 'max:40'],
            'fills.*.question_key' => ['required', 'string', 'max:120'],
            'fills.*.section_instance_key' => ['nullable', 'string', 'max:80'],
            'fills.*.confidence' => ['required', Rule::in(['high', 'medium', 'low'])],
            'fills.*.value' => ['required', 'array'],
            'fills.*.evidence' => ['nullable', 'string', 'max:300'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        /** @var array{evidence: string, fills: list<array<string, mixed>>} $validated */
        $validated = $validator->validated();
        $index = $this->catalogIndex($catalog);
        $labels = $this->catalogLabels($catalog);
        $fills = [];
        $candidates = [];

        foreach ($validated['fills'] as $fill) {
            $key = (string) $fill['question_key'];
            $instanceKey = $fill['section_instance_key'] ?? null;
            $instanceKey = is_string($instanceKey) && $instanceKey !== '' ? $instanceKey : null;
            $confidence = (string) $fill['confidence'];
            $evidence = isset($fill['evidence']) && is_string($fill['evidence']) ? $fill['evidence'] : null;
            $rawValue = is_array($fill['value'] ?? null) ? $fill['value'] : [];
            $label = $this->questionLabel($labels, $key, $instanceKey);
            $question = $index[$key] ?? null;

            if (in_array($key, $photoKeys, true)) {
                $candidates[] = new RequestPrefillCandidate(
                    questionKey: $key,
                    sectionInstanceKey: $instanceKey,
                    label: $label,
                    value: $rawValue !== [] ? $rawValue : null,
                    confidence: $confidence,
                    evidence: $evidence,
                    disposition: RequestPrefillCandidate::DISPOSITION_REJECTED,
                    source: RequestPrefillCandidate::SOURCE_CATALOG_AI,
                    reason: 'Fotovragen worden niet automatisch ingevuld.',
                );

                continue;
            }

            if ($question === null) {
                $candidates[] = new RequestPrefillCandidate(
                    questionKey: $key,
                    sectionInstanceKey: $instanceKey,
                    label: $label,
                    value: $rawValue !== [] ? $rawValue : null,
                    confidence: $confidence,
                    evidence: $evidence,
                    disposition: RequestPrefillCandidate::DISPOSITION_REJECTED,
                    source: RequestPrefillCandidate::SOURCE_CATALOG_AI,
                    reason: 'Onbekende vraagkey — staat niet in de templatecatalogus.',
                );

                continue;
            }

            if ($question['type'] === QuestionType::Photo->value) {
                $candidates[] = new RequestPrefillCandidate(
                    questionKey: $key,
                    sectionInstanceKey: $instanceKey,
                    label: $label,
                    value: $rawValue !== [] ? $rawValue : null,
                    confidence: $confidence,
                    evidence: $evidence,
                    disposition: RequestPrefillCandidate::DISPOSITION_REJECTED,
                    source: RequestPrefillCandidate::SOURCE_CATALOG_AI,
                    reason: 'Fotovragen worden niet automatisch ingevuld.',
                );

                continue;
            }

            if (! $question['is_repeatable'] && $instanceKey !== null) {
                $candidates[] = new RequestPrefillCandidate(
                    questionKey: $key,
                    sectionInstanceKey: $instanceKey,
                    label: $label,
                    value: $rawValue !== [] ? $rawValue : null,
                    confidence: $confidence,
                    evidence: $evidence,
                    disposition: RequestPrefillCandidate::DISPOSITION_REJECTED,
                    source: RequestPrefillCandidate::SOURCE_CATALOG_AI,
                    reason: 'Niet-herhaalbare vraag kreeg een sectie-instance — overgeslagen.',
                );

                continue;
            }

            if ($question['is_repeatable'] && $instanceKey === null) {
                $candidates[] = new RequestPrefillCandidate(
                    questionKey: $key,
                    sectionInstanceKey: null,
                    label: $label,
                    value: $rawValue !== [] ? $rawValue : null,
                    confidence: $confidence,
                    evidence: $evidence,
                    disposition: RequestPrefillCandidate::DISPOSITION_REJECTED,
                    source: RequestPrefillCandidate::SOURCE_CATALOG_AI,
                    reason: 'Herhaalbare vraag mist een sectie-instance (bijv. room-1).',
                );

                continue;
            }

            $normalized = $this->normalizeValue($question, $rawValue);

            if ($normalized === null) {
                $candidates[] = new RequestPrefillCandidate(
                    questionKey: $key,
                    sectionInstanceKey: $instanceKey,
                    label: $label,
                    value: $rawValue !== [] ? $rawValue : null,
                    confidence: $confidence,
                    evidence: $evidence,
                    disposition: RequestPrefillCandidate::DISPOSITION_REJECTED,
                    source: RequestPrefillCandidate::SOURCE_CATALOG_AI,
                    reason: $this->invalidValueReason($question, $rawValue),
                );

                continue;
            }

            if ($confidence === 'low') {
                $candidates[] = new RequestPrefillCandidate(
                    questionKey: $key,
                    sectionInstanceKey: $instanceKey,
                    label: $label,
                    value: $normalized,
                    confidence: $confidence,
                    evidence: $evidence,
                    disposition: RequestPrefillCandidate::DISPOSITION_REJECTED,
                    source: RequestPrefillCandidate::SOURCE_CATALOG_AI,
                    reason: 'Lage zekerheid — wordt niet toegepast.',
                );

                continue;
            }

            $disposition = $confidence === 'high'
                ? RequestPrefillCandidate::DISPOSITION_FILL
                : RequestPrefillCandidate::DISPOSITION_SUGGESTION;

            $candidate = new RequestPrefillCandidate(
                questionKey: $key,
                sectionInstanceKey: $instanceKey,
                label: $label,
                value: $normalized,
                confidence: $confidence,
                evidence: $evidence,
                disposition: $disposition,
                source: RequestPrefillCandidate::SOURCE_CATALOG_AI,
                reason: $disposition === RequestPrefillCandidate::DISPOSITION_SUGGESTION
                    ? 'Middelmatige zekerheid — wordt als voorzet opgeslagen.'
                    : null,
            );

            $candidates[] = $candidate;
            $fills[] = [
                'question_key' => $key,
                'section_instance_key' => $instanceKey,
                'confidence' => $confidence,
                'value' => $normalized,
                'evidence' => $evidence,
            ];
        }

        return [
            'evidence' => $validated['evidence'],
            'fills' => $fills,
            'candidates' => $candidates,
        ];
    }

    /**
     * @param  array{
     *     cooling_heating: string,
     *     rooms: list<string>,
     *     floor_level: string|null,
     *     confidence: string,
     *     evidence?: string
     * }  $output
     * @param  array<string, mixed>  $catalog
     * @return list<RequestPrefillCandidate>
     */
    public function classifyLocalOutput(array $output, array $catalog): array
    {
        $labels = $this->catalogLabels($catalog);
        $confidence = (string) $output['confidence'];
        $evidence = $output['evidence'] ?? null;
        $candidates = [];

        if ($confidence === 'low') {
            return [new RequestPrefillCandidate(
                questionKey: '_local',
                sectionInstanceKey: null,
                label: 'Lokale parser',
                value: null,
                confidence: $confidence,
                evidence: $evidence,
                disposition: RequestPrefillCandidate::DISPOSITION_REJECTED,
                source: RequestPrefillCandidate::SOURCE_LOCAL,
                reason: 'Lokale parser had lage zekerheid — niets toegepast.',
            )];
        }

        $cooling = $output['cooling_heating'];
        if ($cooling !== 'unknown') {
            $candidates[] = new RequestPrefillCandidate(
                questionKey: 'cooling_heating',
                sectionInstanceKey: null,
                label: $this->questionLabel($labels, 'cooling_heating', null),
                value: ['value' => $cooling],
                confidence: $confidence,
                evidence: $evidence,
                disposition: RequestPrefillCandidate::DISPOSITION_FILL,
                source: RequestPrefillCandidate::SOURCE_LOCAL,
            );
        }

        /** @var list<string> $rooms */
        $rooms = $output['rooms'];

        if ($rooms === []) {
            return $candidates;
        }

        $candidates[] = new RequestPrefillCandidate(
            questionKey: 'indoor_unit_count',
            sectionInstanceKey: null,
            label: $this->questionLabel($labels, 'indoor_unit_count', null),
            value: ['number' => count($rooms)],
            confidence: $confidence,
            evidence: $evidence,
            disposition: RequestPrefillCandidate::DISPOSITION_FILL,
            source: RequestPrefillCandidate::SOURCE_LOCAL,
        );

        $floorLevel = $output['floor_level'] ?? null;
        $floorAnswer = is_string($floorLevel) && $floorLevel === 'attic'
            ? $this->floorLevelAnswer($catalog, $floorLevel)
            : null;

        foreach ($rooms as $index => $roomType) {
            $instanceKey = 'room-'.($index + 1);

            $candidates[] = new RequestPrefillCandidate(
                questionKey: 'room_type',
                sectionInstanceKey: $instanceKey,
                label: $this->questionLabel($labels, 'room_type', $instanceKey),
                value: ['value' => $roomType],
                confidence: $confidence,
                evidence: $evidence,
                disposition: RequestPrefillCandidate::DISPOSITION_FILL,
                source: RequestPrefillCandidate::SOURCE_LOCAL,
            );

            if ($floorAnswer !== null) {
                $candidates[] = new RequestPrefillCandidate(
                    questionKey: 'floor_level',
                    sectionInstanceKey: $instanceKey,
                    label: $this->questionLabel($labels, 'floor_level', $instanceKey),
                    value: $floorAnswer,
                    confidence: $confidence,
                    evidence: $evidence,
                    disposition: RequestPrefillCandidate::DISPOSITION_FILL,
                    source: RequestPrefillCandidate::SOURCE_LOCAL,
                );
            }
        }

        return $candidates;
    }

    /**
     * @param  array{type: string, options: list<string>, is_repeatable: bool}  $question
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>|null
     */
    public function normalizeValue(array $question, array $value): ?array
    {
        return match ($question['type']) {
            QuestionType::SingleChoice->value => $this->normalizeChoice($question['options'], $value),
            QuestionType::MultiChoice->value => $this->normalizeMultiChoice($question['options'], $value),
            QuestionType::Number->value => isset($value['number']) && is_numeric($value['number'])
                ? ['number' => (str_contains((string) $value['number'], '.')
                    ? (float) $value['number']
                    : (int) $value['number'])]
                : null,
            QuestionType::ShortText->value, QuestionType::LongText->value => isset($value['text']) && is_string($value['text']) && trim($value['text']) !== ''
                ? ['text' => trim($value['text'])]
                : null,
            QuestionType::Boolean->value => array_key_exists('bool', $value) && is_bool($value['bool'])
                ? ['bool' => $value['bool']]
                : null,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $catalog
     * @return array<string, array{type: string, options: list<string>, is_repeatable: bool}>
     */
    public function catalogIndex(array $catalog): array
    {
        $index = [];

        foreach ($catalog['sections'] ?? [] as $section) {
            $repeatable = (bool) ($section['is_repeatable'] ?? false);

            foreach ($section['questions'] ?? [] as $question) {
                $options = [];
                foreach ($question['options'] ?? [] as $option) {
                    if (is_string($option['value'] ?? null)) {
                        $options[] = $option['value'];
                    }
                }

                $index[(string) $question['key']] = [
                    'type' => (string) $question['type'],
                    'options' => $options,
                    'is_repeatable' => $repeatable,
                ];
            }
        }

        return $index;
    }

    /**
     * @param  array<string, mixed>  $catalog
     * @return array<string, string>
     */
    public function catalogLabels(array $catalog): array
    {
        $labels = [];

        foreach ($catalog['sections'] ?? [] as $section) {
            foreach ($section['questions'] ?? [] as $question) {
                $labels[(string) $question['key']] = (string) ($question['label'] ?? $question['key']);
            }
        }

        return $labels;
    }

    /**
     * @param  array<string, string>  $labels
     */
    public function questionLabel(array $labels, string $questionKey, ?string $sectionInstanceKey): string
    {
        $base = $labels[$questionKey] ?? $questionKey;

        if ($sectionInstanceKey === null) {
            return $base;
        }

        return $base.' ('.$sectionInstanceKey.')';
    }

    /**
     * @param  array<string, mixed>  $catalog
     * @return array<string, mixed>|null
     */
    private function floorLevelAnswer(array $catalog, string $floorLevel): ?array
    {
        $index = $this->catalogIndex($catalog);
        $question = $index['floor_level'] ?? null;

        if ($question === null) {
            return null;
        }

        if ($question['type'] === QuestionType::SingleChoice->value) {
            return in_array($floorLevel, $question['options'], true)
                ? ['value' => $floorLevel]
                : null;
        }

        if (in_array($question['type'], [QuestionType::ShortText->value, QuestionType::LongText->value], true)) {
            return ['text' => 'Zolder'];
        }

        return null;
    }

    /**
     * @param  array{type: string, options: list<string>, is_repeatable: bool}  $question
     * @param  array<string, mixed>  $value
     */
    private function invalidValueReason(array $question, array $value): string
    {
        return match ($question['type']) {
            QuestionType::SingleChoice->value => 'Ongeldige keuzewaarde — past niet bij de catalogusopties.',
            QuestionType::MultiChoice->value => 'Ongeldige meerkeuze — één of meer waarden ontbreken in de catalogus.',
            QuestionType::Number->value => 'Ongeldig getal — number ontbreekt of is niet numeriek.',
            QuestionType::ShortText->value, QuestionType::LongText->value => 'Ongeldige tekst — text ontbreekt of is leeg.',
            QuestionType::Boolean->value => 'Ongeldige boolean — bool ontbreekt of is geen true/false.',
            default => 'Waarde past niet bij het vraagtype.',
        };
    }

    /**
     * @param  list<string>  $options
     * @param  array<string, mixed>  $value
     * @return array{value: string}|null
     */
    private function normalizeChoice(array $options, array $value): ?array
    {
        $choice = $value['value'] ?? null;

        if (! is_string($choice) || ! in_array($choice, $options, true)) {
            return null;
        }

        return ['value' => $choice];
    }

    /**
     * @param  list<string>  $options
     * @param  array<string, mixed>  $value
     * @return array{values: list<string>}|null
     */
    private function normalizeMultiChoice(array $options, array $value): ?array
    {
        $values = $value['values'] ?? null;

        if (! is_array($values) || $values === []) {
            return null;
        }

        $normalized = [];
        foreach ($values as $item) {
            if (! is_string($item) || ! in_array($item, $options, true)) {
                return null;
            }
            $normalized[] = $item;
        }

        return ['values' => array_values(array_unique($normalized))];
    }
}
