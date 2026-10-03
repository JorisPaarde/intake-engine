<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\DTOs\RequestPrefillCandidate;
use App\Domains\AI\Support\OwnershipNormalizer;
use App\Domains\Intake\Support\FactProvenance;
use App\Domains\Intake\Support\RiskRelevantPrefillKeys;
use App\Enums\QuestionType;
use Illuminate\Validation\ValidationException;

/**
 * Deelt normalisatie- en classificatielogica voor catalogus-AI-prefill (productie + dry-run).
 * Geen DB-writes; geen eigen confidencegrenzen — dezelfde regels als PrefillAnswersFromKnownContext.
 *
 * Envelopefouten (te lange evidence, één kapotte fill) verwerpen nooit de hele extractie:
 * geldige fills blijven kandidaten; afwijkingen landen als rejected + normalizations/validation_errors.
 */
final class RequestPrefillOutcomeClassifier
{
    private const int EVIDENCE_MAX = 500;

    private const int FILL_EVIDENCE_MAX = 300;

    private const int FILLS_SOFT_MAX = 80;

    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $catalog
     * @param  list<string>  $photoKeys  template photo question keys (not in fillable catalog)
     * @return array{
     *     evidence: string,
     *     fills: list<array<string, mixed>>,
     *     candidates: list<RequestPrefillCandidate>,
     *     normalizations: list<array{field: string, from: mixed, to: mixed, rule: string}>,
     *     validation_errors: array<string, list<string>>
     * }
     */
    public function classifyCatalogOutput(array $output, array $catalog, array $photoKeys = []): array
    {
        $index = $this->catalogIndex($catalog);
        $labels = $this->catalogLabels($catalog);
        $fills = [];
        $candidates = [];
        $normalizations = [];
        /** @var array<string, list<string>> $validationErrors */
        $validationErrors = [];

        if (! array_key_exists('fills', $output) || ! is_array($output['fills'])) {
            throw ValidationException::withMessages([
                'fills' => ['Catalogus-AI-output mist een fills-array.'],
            ]);
        }

        $evidence = $this->softenEvidence($output['evidence'] ?? null, $normalizations, $validationErrors);
        /** @var list<mixed> $rawFills */
        $rawFills = array_values($output['fills']);

        foreach ($rawFills as $indexFill => $fill) {
            if ($indexFill >= self::FILLS_SOFT_MAX) {
                $validationErrors['fills'][] = 'Meer dan '.self::FILLS_SOFT_MAX.' fills — resterende overgeslagen.';
                $candidates[] = new RequestPrefillCandidate(
                    questionKey: '_fills_overflow',
                    sectionInstanceKey: null,
                    label: 'Extra fills',
                    value: null,
                    confidence: null,
                    evidence: null,
                    disposition: RequestPrefillCandidate::DISPOSITION_REJECTED,
                    source: RequestPrefillCandidate::SOURCE_CATALOG_AI,
                    reason: 'Te veel fills in één response — rest genegeerd, eerdere geldige fills blijven.',
                );
                break;
            }

            if (! is_array($fill)) {
                $attr = 'fills.'.$indexFill;
                $validationErrors[$attr][] = 'Fill is geen object.';
                $candidates[] = new RequestPrefillCandidate(
                    questionKey: '_malformed_fill',
                    sectionInstanceKey: null,
                    label: 'Ongeldige fill #'.($indexFill + 1),
                    value: null,
                    confidence: null,
                    evidence: null,
                    disposition: RequestPrefillCandidate::DISPOSITION_REJECTED,
                    source: RequestPrefillCandidate::SOURCE_CATALOG_AI,
                    reason: 'Fill is geen object — overgeslagen.',
                );

                continue;
            }

            $keyRaw = $fill['question_key'] ?? null;
            if (! is_string($keyRaw) || trim($keyRaw) === '' || mb_strlen($keyRaw) > 120) {
                $attr = 'fills.'.$indexFill.'.question_key';
                $validationErrors[$attr][] = 'Ontbrekende of ongeldige question_key.';
                $candidates[] = new RequestPrefillCandidate(
                    questionKey: '_missing_key',
                    sectionInstanceKey: null,
                    label: 'Fill zonder vraagkey',
                    value: is_array($fill['value'] ?? null) ? $fill['value'] : null,
                    confidence: is_string($fill['confidence'] ?? null) ? $fill['confidence'] : null,
                    evidence: null,
                    disposition: RequestPrefillCandidate::DISPOSITION_REJECTED,
                    source: RequestPrefillCandidate::SOURCE_CATALOG_AI,
                    reason: 'Ontbrekende of ongeldige question_key — overgeslagen.',
                );

                continue;
            }

            $key = $keyRaw;
            $instanceKey = $fill['section_instance_key'] ?? null;
            $instanceKey = is_string($instanceKey) && $instanceKey !== '' ? $instanceKey : null;
            if ($instanceKey !== null && mb_strlen($instanceKey) > 80) {
                $attr = 'fills.'.$indexFill.'.section_instance_key';
                $validationErrors[$attr][] = 'section_instance_key te lang.';
                $candidates[] = new RequestPrefillCandidate(
                    questionKey: $key,
                    sectionInstanceKey: null,
                    label: $this->questionLabel($labels, $key, null),
                    value: is_array($fill['value'] ?? null) ? $fill['value'] : null,
                    confidence: is_string($fill['confidence'] ?? null) ? $fill['confidence'] : null,
                    evidence: null,
                    disposition: RequestPrefillCandidate::DISPOSITION_REJECTED,
                    source: RequestPrefillCandidate::SOURCE_CATALOG_AI,
                    reason: 'section_instance_key te lang — overgeslagen.',
                );

                continue;
            }

            $confidenceRaw = $fill['confidence'] ?? null;
            if (! is_string($confidenceRaw) || ! in_array($confidenceRaw, ['high', 'medium', 'low'], true)) {
                $attr = 'fills.'.$indexFill.'.confidence';
                $validationErrors[$attr][] = 'Ongeldige confidence.';
                $candidates[] = new RequestPrefillCandidate(
                    questionKey: $key,
                    sectionInstanceKey: $instanceKey,
                    label: $this->questionLabel($labels, $key, $instanceKey),
                    value: is_array($fill['value'] ?? null) ? $fill['value'] : null,
                    confidence: is_string($confidenceRaw) ? $confidenceRaw : null,
                    evidence: null,
                    disposition: RequestPrefillCandidate::DISPOSITION_REJECTED,
                    source: RequestPrefillCandidate::SOURCE_CATALOG_AI,
                    reason: 'Ongeldige confidence — overgeslagen.',
                );

                continue;
            }
            $confidence = $confidenceRaw;

            if (! array_key_exists('value', $fill) || ! is_array($fill['value'])) {
                $attr = 'fills.'.$indexFill.'.value';
                $validationErrors[$attr][] = 'Waarde ontbreekt of is geen object.';
                $candidates[] = new RequestPrefillCandidate(
                    questionKey: $key,
                    sectionInstanceKey: $instanceKey,
                    label: $this->questionLabel($labels, $key, $instanceKey),
                    value: null,
                    confidence: $confidence,
                    evidence: null,
                    disposition: RequestPrefillCandidate::DISPOSITION_REJECTED,
                    source: RequestPrefillCandidate::SOURCE_CATALOG_AI,
                    reason: 'Waarde ontbreekt of is geen object — overgeslagen.',
                );

                continue;
            }

            $fillEvidence = null;
            if (isset($fill['evidence']) && is_string($fill['evidence'])) {
                $fillEvidence = $fill['evidence'];
                if (mb_strlen($fillEvidence) > self::FILL_EVIDENCE_MAX) {
                    $truncated = mb_substr($fillEvidence, 0, self::FILL_EVIDENCE_MAX);
                    $normalizations[] = [
                        'field' => ($instanceKey === null ? $key : $key.'|'.$instanceKey).'.evidence',
                        'from' => mb_strlen($fillEvidence).' chars',
                        'to' => mb_strlen($truncated).' chars',
                        'rule' => 'truncate_fill_evidence',
                    ];
                    $validationErrors['fills.'.$indexFill.'.evidence'][] = 'Evidence ingekort tot '.self::FILL_EVIDENCE_MAX.' tekens.';
                    $fillEvidence = $truncated;
                }
            }

            $rawValue = $fill['value'];
            if ($key === 'ownership') {
                $ownershipNormalized = $this->normalizeOwnershipFill($rawValue);
                if ($ownershipNormalized !== null && $ownershipNormalized !== $rawValue) {
                    $normalizations[] = [
                        'field' => 'ownership',
                        'from' => $rawValue,
                        'to' => $ownershipNormalized,
                        'rule' => 'ownership_synonym',
                    ];
                    $rawValue = $ownershipNormalized;
                }
            }
            $label = $this->questionLabel($labels, $key, $instanceKey);
            $question = $index[$key] ?? null;

            if (in_array($key, $photoKeys, true)) {
                $candidates[] = new RequestPrefillCandidate(
                    questionKey: $key,
                    sectionInstanceKey: $instanceKey,
                    label: $label,
                    value: $rawValue !== [] ? $rawValue : null,
                    confidence: $confidence,
                    evidence: $fillEvidence,
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
                    evidence: $fillEvidence,
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
                    evidence: $fillEvidence,
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
                    evidence: $fillEvidence,
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
                    evidence: $fillEvidence,
                    disposition: RequestPrefillCandidate::DISPOSITION_REJECTED,
                    source: RequestPrefillCandidate::SOURCE_CATALOG_AI,
                    reason: 'Herhaalbare vraag mist een sectie-instance (bijv. room-1).',
                );

                continue;
            }

            $provenance = $this->resolveProvenance($fill['provenance'] ?? null, $key, $confidence);
            if (! array_key_exists('provenance', $fill) || FactProvenance::tryFromMixed($fill['provenance'] ?? null) === null) {
                $normalizations[] = [
                    'field' => ($instanceKey === null ? $key : $key.'|'.$instanceKey).'.provenance',
                    'from' => $fill['provenance'] ?? null,
                    'to' => $provenance->value,
                    'rule' => RiskRelevantPrefillKeys::contains($key)
                        ? 'provenance_default_inferred_risk'
                        : ($confidence === 'high' ? 'provenance_default_stated_legacy' : 'provenance_default_inferred'),
                ];
            }

            $normalized = $this->normalizeValue($question, $rawValue);

            if ($normalized === null) {
                $candidates[] = new RequestPrefillCandidate(
                    questionKey: $key,
                    sectionInstanceKey: $instanceKey,
                    label: $label,
                    value: $rawValue !== [] ? $rawValue : null,
                    confidence: $confidence,
                    evidence: $fillEvidence,
                    disposition: RequestPrefillCandidate::DISPOSITION_REJECTED,
                    source: RequestPrefillCandidate::SOURCE_CATALOG_AI,
                    reason: $this->invalidValueReason($question, $rawValue),
                    provenance: $provenance,
                );

                continue;
            }

            if ($normalized !== $rawValue) {
                $normalizations[] = [
                    'field' => $instanceKey === null ? $key : $key.'|'.$instanceKey,
                    'from' => $rawValue,
                    'to' => $normalized,
                    'rule' => 'catalog_value',
                ];
            }

            if ($confidence === 'low' || $provenance === FactProvenance::Unknown) {
                $candidates[] = new RequestPrefillCandidate(
                    questionKey: $key,
                    sectionInstanceKey: $instanceKey,
                    label: $label,
                    value: $normalized,
                    confidence: $confidence,
                    evidence: $fillEvidence,
                    disposition: RequestPrefillCandidate::DISPOSITION_REJECTED,
                    source: RequestPrefillCandidate::SOURCE_CATALOG_AI,
                    reason: $provenance === FactProvenance::Unknown
                        ? 'Provenance unknown — wordt niet toegepast.'
                        : 'Lage zekerheid — wordt niet toegepast.',
                    provenance: $provenance,
                );

                continue;
            }

            // Stated + high → fill; anders suggestion. Risico+inferred nooit confirmed.
            $disposition = ($confidence === 'high' && $provenance === FactProvenance::Stated)
                ? RequestPrefillCandidate::DISPOSITION_FILL
                : RequestPrefillCandidate::DISPOSITION_SUGGESTION;

            $reason = null;
            if (RiskRelevantPrefillKeys::requiresConfirmation($key, $provenance)) {
                $disposition = RequestPrefillCandidate::DISPOSITION_SUGGESTION;
                $reason = 'Risicoveld met aanname — klantbevestiging nodig, niet als bevestigd opgeslagen.';
            } elseif ($disposition === RequestPrefillCandidate::DISPOSITION_SUGGESTION) {
                $reason = $provenance === FactProvenance::Inferred
                    ? 'Afgeleide aanname — wordt als voorzet opgeslagen.'
                    : 'Middelmatige zekerheid — wordt als voorzet opgeslagen.';
            }

            // High+stated op non-risk mag fill blijven; high+inferred → suggestion.
            if ($confidence === 'high' && $provenance === FactProvenance::Inferred) {
                $disposition = RequestPrefillCandidate::DISPOSITION_SUGGESTION;
                $reason ??= 'High confidence maar inferred — voorzet, geen bevestigd feit.';
            }

            $candidate = new RequestPrefillCandidate(
                questionKey: $key,
                sectionInstanceKey: $instanceKey,
                label: $label,
                value: $normalized,
                confidence: $confidence,
                evidence: $fillEvidence,
                disposition: $disposition,
                source: RequestPrefillCandidate::SOURCE_CATALOG_AI,
                reason: $reason,
                provenance: $provenance,
            );

            $candidates[] = $candidate;
            $fills[] = [
                'question_key' => $key,
                'section_instance_key' => $instanceKey,
                'confidence' => $confidence,
                'provenance' => $provenance->value,
                'value' => $normalized,
                'evidence' => $fillEvidence,
            ];
        }

        return [
            'evidence' => $evidence,
            'fills' => $fills,
            'candidates' => $candidates,
            'normalizations' => $normalizations,
            'validation_errors' => $validationErrors,
        ];
    }

    /**
     * Lange of ontbrekende top-level evidence mag nooit de hele extractie dumpen.
     *
     * @param  list<array{field: string, from: mixed, to: mixed, rule: string}>  $normalizations
     * @param  array<string, list<string>>  $validationErrors
     */
    private function softenEvidence(mixed $raw, array &$normalizations, array &$validationErrors): string
    {
        if (! is_string($raw) || trim($raw) === '') {
            $validationErrors['evidence'][] = 'Evidence ontbrak of was leeg — fallback gebruikt.';
            $normalizations[] = [
                'field' => 'evidence',
                'from' => $raw,
                'to' => 'Catalogus-AI zonder samenvatting.',
                'rule' => 'evidence_fallback',
            ];

            return 'Catalogus-AI zonder samenvatting.';
        }

        $trimmed = trim($raw);
        if (mb_strlen($trimmed) < 3) {
            $validationErrors['evidence'][] = 'Evidence te kort — fallback gebruikt.';
            $normalizations[] = [
                'field' => 'evidence',
                'from' => $trimmed,
                'to' => 'Catalogus-AI zonder samenvatting.',
                'rule' => 'evidence_fallback',
            ];

            return 'Catalogus-AI zonder samenvatting.';
        }

        if (mb_strlen($trimmed) > self::EVIDENCE_MAX) {
            $truncated = mb_substr($trimmed, 0, self::EVIDENCE_MAX);
            $validationErrors['evidence'][] = 'Evidence ingekort tot '.self::EVIDENCE_MAX.' tekens.';
            $normalizations[] = [
                'field' => 'evidence',
                'from' => mb_strlen($trimmed).' chars',
                'to' => mb_strlen($truncated).' chars',
                'rule' => 'truncate_evidence',
            ];

            return $truncated;
        }

        return $trimmed;
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
     * Ontbrekende provenance: risicokeys → inferred (veilig); overige high → stated (legacy v6-fills).
     */
    private function resolveProvenance(mixed $raw, string $questionKey, string $confidence): FactProvenance
    {
        $explicit = FactProvenance::tryFromMixed($raw);
        if ($explicit instanceof FactProvenance) {
            return $explicit;
        }

        if (RiskRelevantPrefillKeys::contains($questionKey)) {
            return FactProvenance::Inferred;
        }

        return $confidence === 'high' ? FactProvenance::Stated : FactProvenance::Inferred;
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
     * @param  array<string, mixed>  $value
     * @return array{value: string}|null
     */
    private function normalizeOwnershipFill(array $value): ?array
    {
        $choice = $value['value'] ?? null;
        if (! is_string($choice)) {
            // Sommige models leveren scalar text of nested label.
            if (isset($value['text']) && is_string($value['text'])) {
                $choice = $value['text'];
            } else {
                return null;
            }
        }

        $mapped = (new OwnershipNormalizer)->normalize($choice);

        return $mapped === null ? null : ['value' => $mapped];
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
