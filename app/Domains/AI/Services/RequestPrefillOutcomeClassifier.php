<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\DTOs\RequestPrefillCandidate;
use App\Domains\AI\Support\OwnershipNormalizer;
use App\Domains\AI\Support\RoomFloorLevelExtractor;
use App\Domains\Intake\Support\FactAcceptance;
use App\Domains\Intake\Support\FactProvenance;
use App\Domains\Intake\Support\FactSource;
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

    public function __construct(
        private readonly RoomFloorLevelExtractor $floorExtractor = new RoomFloorLevelExtractor,
    ) {}

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
    public function classifyCatalogOutput(array $output, array $catalog, array $photoKeys = [], ?string $requestReason = null): array
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

            // Alleen brontekst (request_reason) — verzonnen fill-evidence mag intent niet “bewijzen”.
            if ($key === 'cooling_heating' && $this->coolingInferredFromAircoAbsence($requestReason)) {
                $candidates[] = new RequestPrefillCandidate(
                    questionKey: $key,
                    sectionInstanceKey: $instanceKey,
                    label: $label,
                    value: $rawValue !== [] ? $rawValue : null,
                    confidence: $confidence,
                    evidence: $fillEvidence,
                    disposition: RequestPrefillCandidate::DISPOSITION_REJECTED,
                    source: RequestPrefillCandidate::SOURCE_CATALOG_AI,
                    reason: 'Geen koel-/verwarmingsintentie: alleen afwezigheid van airco is geen cooling_heating.',
                    confidencePercent: FactAcceptance::normalizeConfidence($confidence),
                    factSource: FactSource::Derived,
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

            $explicitProvenance = FactProvenance::tryFromMixed($fill['provenance'] ?? null);
            $provenance = $this->resolveProvenance($fill['provenance'] ?? null, $key, $confidence);
            if (! array_key_exists('provenance', $fill) || $explicitProvenance === null) {
                $normalizations[] = [
                    'field' => ($instanceKey === null ? $key : $key.'|'.$instanceKey).'.provenance',
                    'from' => $fill['provenance'] ?? null,
                    'to' => $provenance->value,
                    'rule' => RiskRelevantPrefillKeys::contains($key)
                        ? 'provenance_default_inferred_risk'
                        : ($confidence === 'high' ? 'provenance_default_stated_legacy' : 'provenance_default_inferred'),
                ];
            }

            $confidencePercent = FactAcceptance::normalizeConfidence($confidence) ?? FactAcceptance::LEVEL_LOW;
            $sourceText = is_string($requestReason) ? $requestReason : '';
            $hasEvidenceQuote = is_string($fillEvidence) && trim($fillEvidence) !== '';

            // Expliciet stated (of stated mét evidence-claim) vereist een quote in de brontekst.
            // Legacy high zonder provenance én zonder evidence blijft stated (bestaande Fake/fixtures).
            if ($provenance === FactProvenance::Stated && $sourceText !== '') {
                $mustValidateQuote = $explicitProvenance === FactProvenance::Stated || $hasEvidenceQuote;
                if ($explicitProvenance === FactProvenance::Stated && ! $hasEvidenceQuote) {
                    $mustValidateQuote = true;
                }
                if ($mustValidateQuote && ! FactAcceptance::evidenceAppearsInSource($fillEvidence, $sourceText)) {
                    $normalizations[] = [
                        'field' => ($instanceKey === null ? $key : $key.'|'.$instanceKey).'.provenance',
                        'from' => FactProvenance::Stated->value,
                        'to' => FactProvenance::Inferred->value,
                        'rule' => 'stated_evidence_not_in_source',
                    ];
                    $provenance = FactProvenance::Inferred;
                    $confidencePercent = FactAcceptance::belowThresholdConfidence($key);
                    $confidence = FactAcceptance::levelFromPercent($confidencePercent);
                }
            }

            // Aanvraagtekst van de installateur ≠ klantantwoord.
            $factSource = $provenance === FactProvenance::Stated
                ? FactSource::InstallerRequest
                : FactSource::Derived;

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
                    confidencePercent: $confidencePercent,
                    factSource: $factSource,
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

            // Verdieping alleen per duidelijk gekoppelde ruimte — nooit globaal of begane-grond-default.
            if ($key === 'floor_level' && is_string($requestReason) && $instanceKey !== null) {
                $roomsResolvable = $this->roomTypesFromFills($rawFills) !== [];
                $linkedFloor = $this->linkedFloorLevelForInstance(
                    $requestReason,
                    $instanceKey,
                    $rawFills,
                    $catalog,
                );
                $hasFloorCue = $this->requestHasFloorCue($requestReason);
                $numbered = $this->floorExtractor->numberedFloorFromText($requestReason);

                if ($linkedFloor !== null && ($normalized['value'] ?? null) !== $linkedFloor) {
                    $normalizations[] = [
                        'field' => $key.'|'.$instanceKey,
                        'from' => $normalized['value'] ?? null,
                        'to' => $linkedFloor,
                        'rule' => 'floor_level_per_room_link',
                    ];
                    $normalized = ['value' => $linkedFloor];
                    $fillEvidence = $this->floorEvidenceQuote($requestReason, $linkedFloor) ?? $fillEvidence;
                    $provenance = FactProvenance::Stated;
                    $factSource = FactSource::InstallerRequest;
                    $confidence = 'high';
                    $confidencePercent = FactAcceptance::LEVEL_HIGH;
                } elseif ($linkedFloor !== null && ($normalized['value'] ?? null) === $linkedFloor) {
                    $fillEvidence = $this->floorEvidenceQuote($requestReason, $linkedFloor) ?? $fillEvidence;
                    $provenance = FactProvenance::Stated;
                    $factSource = FactSource::InstallerRequest;
                    $confidence = 'high';
                    $confidencePercent = FactAcceptance::LEVEL_HIGH;
                } elseif (
                    ($normalized['value'] ?? null) === 'attic'
                    && $numbered !== null
                ) {
                    $numberedAnswer = $this->floorLevelAnswer($catalog, $numbered);
                    $numberedValue = is_array($numberedAnswer) ? ($numberedAnswer['value'] ?? null) : null;
                    if (is_string($numberedValue) && $numberedValue !== 'attic') {
                        $normalizations[] = [
                            'field' => $key.'|'.$instanceKey,
                            'from' => 'attic',
                            'to' => $numberedValue,
                            'rule' => 'floor_level_prefer_numbered',
                        ];
                        $normalized = ['value' => $numberedValue];
                        $fillEvidence = $this->floorEvidenceQuote($requestReason, $numberedValue) ?? $fillEvidence;
                        $provenance = FactProvenance::Stated;
                        $factSource = FactSource::InstallerRequest;
                        $confidence = 'high';
                        $confidencePercent = FactAcceptance::LEVEL_HIGH;
                    }
                } elseif (! $hasFloorCue || ($roomsResolvable && $linkedFloor === null)) {
                    $reason = ! $hasFloorCue
                        ? 'Geen verdieping in de openingszin — niet stil invullen (geen begane-grond-default).'
                        : 'Verdieping niet eenduidig aan deze ruimte gekoppeld — leeg laten voor klantbevestiging.';
                    // Nooit losse "boven"/"beneden" als evidence (plaatsing: "boven de bank").
                    $rejectEvidence = is_string($fillEvidence)
                        && preg_match('/^(?:boven|beneden)$/iu', trim($fillEvidence)) === 1
                        ? null
                        : $fillEvidence;
                    $candidates[] = new RequestPrefillCandidate(
                        questionKey: $key,
                        sectionInstanceKey: $instanceKey,
                        label: $label,
                        value: $normalized,
                        confidence: $confidence,
                        evidence: $rejectEvidence,
                        disposition: RequestPrefillCandidate::DISPOSITION_REJECTED,
                        source: RequestPrefillCandidate::SOURCE_CATALOG_AI,
                        reason: $reason,
                        provenance: $provenance,
                        confidencePercent: $confidencePercent,
                        factSource: $factSource,
                    );

                    continue;
                }
            }

            // Letterlijke koop/huur in openingszin → stated FILL (nooit opnieuw vragen).
            if ($key === 'ownership' && is_string($requestReason) && trim($requestReason) !== '') {
                $ownershipUpgrade = $this->ownershipStatedFromRequest(
                    $requestReason,
                    is_string($normalized['value'] ?? null) ? $normalized['value'] : null,
                );
                if ($ownershipUpgrade !== null) {
                    $normalizations[] = [
                        'field' => 'ownership.provenance',
                        'from' => $provenance->value,
                        'to' => FactProvenance::Stated->value,
                        'rule' => 'ownership_literal_in_request',
                    ];
                    $normalized = ['value' => $ownershipUpgrade['value']];
                    $fillEvidence = $ownershipUpgrade['evidence'];
                    $provenance = FactProvenance::Stated;
                    $factSource = FactSource::InstallerRequest;
                    $confidence = 'high';
                    $confidencePercent = FactAcceptance::LEVEL_HIGH;
                }
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
                    confidencePercent: $confidencePercent,
                    factSource: $factSource,
                );

                continue;
            }

            $countsAsKnown = FactAcceptance::countsAsKnown(
                $confidencePercent,
                $factSource,
                $provenance,
                $key,
            );

            $disposition = $countsAsKnown
                ? RequestPrefillCandidate::DISPOSITION_FILL
                : RequestPrefillCandidate::DISPOSITION_SUGGESTION;

            $reason = null;
            if (RiskRelevantPrefillKeys::requiresConfirmation($key, $provenance)) {
                $disposition = RequestPrefillCandidate::DISPOSITION_SUGGESTION;
                $reason = 'Risicoveld met aanname — klantbevestiging nodig, niet als bevestigd opgeslagen.';
            } elseif (! $countsAsKnown) {
                if ($factSource === FactSource::Derived) {
                    $reason = 'Afgeleid of niet bevestigd — voorzet, geen feit (Klopt dit?).';
                } elseif ($confidencePercent < FactAcceptance::threshold($key)) {
                    $reason = 'Zekerheid onder drempel ('.$confidencePercent.'% < '.FactAcceptance::threshold($key).'%) — bevestiging nodig.';
                } else {
                    $reason = 'Nog te bevestigen — wordt als voorzet opgeslagen.';
                }
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
                confidencePercent: $confidencePercent,
                factSource: $factSource,
            );

            $candidates[] = $candidate;
            $fills[] = [
                'question_key' => $key,
                'section_instance_key' => $instanceKey,
                'confidence' => $confidence,
                'confidence_percent' => $confidencePercent,
                'provenance' => $provenance->value,
                'fact_source' => $factSource->value,
                'value' => $normalized,
                'evidence' => $fillEvidence,
            ];
        }

        $this->ensureOwnershipFillFromRequest(
            $candidates,
            $fills,
            $catalog,
            $labels,
            $requestReason,
            $normalizations,
        );

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
     *     room_floors?: list<string|null>,
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

        /** @var list<string|null> $roomFloors */
        $roomFloors = $output['room_floors'] ?? [];
        if ($roomFloors === [] && is_string($output['floor_level'] ?? null) && $output['floor_level'] !== '') {
            // Legacy local output zonder room_floors: alleen delen als elke ruimte dezelfde floor heeft.
            $roomFloors = array_fill(0, count($rooms), $output['floor_level']);
        }

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

            $floorLevel = $roomFloors[$index] ?? null;
            $floorAnswer = is_string($floorLevel) && $floorLevel !== ''
                ? $this->floorLevelAnswer($catalog, $floorLevel)
                : null;

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
            $label = match ($floorLevel) {
                'basement' => 'Kelder / souterrain',
                'ground' => 'Begane grond',
                '1' => '1e verdieping',
                '2' => '2e verdieping',
                '3_plus' => '3e verdieping of hoger',
                'attic' => 'Zolder',
                default => $floorLevel,
            };

            return ['text' => $label];
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
     * @return array<string, mixed>|null
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
     * @return array{value: 'owned'|'rented', evidence: string}|null
     */
    private function ownershipStatedFromRequest(string $requestReason, ?string $normalizedValue): ?array
    {
        $normalizer = new OwnershipNormalizer;
        $fromSource = $normalizer->normalize($requestReason);
        $quote = $normalizer->matchedEvidenceQuote($requestReason);

        if ($fromSource === null || $quote === null) {
            return null;
        }

        if ($normalizedValue !== null && $normalizedValue !== $fromSource) {
            return null;
        }

        return [
            'value' => $fromSource,
            'evidence' => $quote,
        ];
    }

    /**
     * @param  list<RequestPrefillCandidate>  $candidates
     * @param  list<array<string, mixed>>  $fills
     * @param  array<string, mixed>  $catalog
     * @param  array<string, string>  $labels
     * @param  list<array{field: string, from: mixed, to: mixed, rule: string}>  $normalizations
     */
    private function ensureOwnershipFillFromRequest(
        array &$candidates,
        array &$fills,
        array $catalog,
        array $labels,
        ?string $requestReason,
        array &$normalizations,
    ): void {
        if (! is_string($requestReason) || trim($requestReason) === '') {
            return;
        }

        $index = $this->catalogIndex($catalog);
        if (! isset($index['ownership'])) {
            return;
        }

        foreach ($candidates as $candidate) {
            if ($candidate->questionKey === 'ownership'
                && $candidate->disposition === RequestPrefillCandidate::DISPOSITION_FILL) {
                return;
            }
        }

        $stated = $this->ownershipStatedFromRequest($requestReason, null);
        if ($stated === null) {
            return;
        }

        // Vervang eerdere ownership-suggestie/reject door stated FILL.
        $candidates = array_values(array_filter(
            $candidates,
            static fn (RequestPrefillCandidate $c): bool => $c->questionKey !== 'ownership',
        ));
        $fills = array_values(array_filter(
            $fills,
            static fn (array $row): bool => ($row['question_key'] ?? null) !== 'ownership',
        ));

        $normalizations[] = [
            'field' => 'ownership',
            'from' => null,
            'to' => $stated['value'],
            'rule' => 'ownership_literal_inject',
        ];

        $candidates[] = new RequestPrefillCandidate(
            questionKey: 'ownership',
            sectionInstanceKey: null,
            label: $this->questionLabel($labels, 'ownership', null),
            value: ['value' => $stated['value']],
            confidence: 'high',
            evidence: $stated['evidence'],
            disposition: RequestPrefillCandidate::DISPOSITION_FILL,
            source: RequestPrefillCandidate::SOURCE_CATALOG_AI,
            reason: null,
            provenance: FactProvenance::Stated,
            confidencePercent: FactAcceptance::LEVEL_HIGH,
            factSource: FactSource::InstallerRequest,
        );

        $fills[] = [
            'question_key' => 'ownership',
            'section_instance_key' => null,
            'confidence' => 'high',
            'confidence_percent' => FactAcceptance::LEVEL_HIGH,
            'provenance' => FactProvenance::Stated->value,
            'fact_source' => FactSource::InstallerRequest->value,
            'value' => ['value' => $stated['value']],
            'evidence' => $stated['evidence'],
        ];
    }

    /**
     * @param  list<mixed>  $rawFills
     * @return array<string, string> section_instance_key => room_type
     */
    private function roomTypesFromFills(array $rawFills): array
    {
        $roomsByInstance = [];
        foreach ($rawFills as $fill) {
            if (! is_array($fill)) {
                continue;
            }
            if (($fill['question_key'] ?? null) !== 'room_type') {
                continue;
            }
            $fillInstance = $fill['section_instance_key'] ?? null;
            if (! is_string($fillInstance) || $fillInstance === '') {
                continue;
            }
            $value = $fill['value']['value'] ?? null;
            if (is_string($value) && $value !== '') {
                $roomsByInstance[$fillInstance] = $value;
            }
        }

        ksort($roomsByInstance, SORT_NATURAL);

        return $roomsByInstance;
    }

    /**
     * @param  list<mixed>  $rawFills
     * @param  array<string, mixed>  $catalog
     */
    private function linkedFloorLevelForInstance(
        string $requestReason,
        string $instanceKey,
        array $rawFills,
        array $catalog,
    ): ?string {
        if (preg_match('/^room-(\d+)$/', $instanceKey, $matches) !== 1) {
            return null;
        }

        $roomIndex = ((int) $matches[1]) - 1;
        if ($roomIndex < 0) {
            return null;
        }

        $roomsByInstance = $this->roomTypesFromFills($rawFills);
        if ($roomsByInstance === []) {
            return null;
        }

        /** @var list<'living_room'|'bedroom'|'office'|'attic'|'other'> $rooms */
        $rooms = array_values($roomsByInstance);
        if ($roomIndex >= count($rooms)) {
            return null;
        }

        $floors = $this->floorExtractor->floorsForRooms($requestReason, $rooms);
        $floor = $floors[$roomIndex] ?? null;
        if (! is_string($floor)) {
            return null;
        }

        $answer = $this->floorLevelAnswer($catalog, $floor);

        return is_array($answer) && is_string($answer['value'] ?? null)
            ? $answer['value']
            : null;
    }

    private function requestHasFloorCue(string $requestReason): bool
    {
        $normalized = mb_strtolower(trim($requestReason), 'UTF-8');
        $normalized = str_replace(['’', '‘', '´'], "'", $normalized);

        // Absolute cues — géén losse "boven"/"beneden" (plaatsing: "boven de bank").
        if (preg_match(
            '/\b(?:kelder|souterrain|begane\s+grond|(?:1(?:e|ste)?|eerste|2(?:e|de)?|tweede|3(?:e|de)?|derde|[4-9](?:e|de)?)\s+verdieping|op\s+(?:de\s+)?zolder)\b/u',
            $normalized,
        ) === 1) {
            return true;
        }

        // Relatief alleen als RoomFloorLevelExtractor: kamernaam + boven/beneden.
        return preg_match(
            '/\b(?:kinderslaapkamers?|kinderkamers?|slaapkamers?|woonkamers?|huiskamers?|werkkamers?|kantoor|kantoren|zolders?)\s*,?\s*(?:boven|beneden)\b/u',
            $normalized,
        ) === 1;
    }

    private function floorEvidenceQuote(string $requestReason, ?string $preferredFloor = null): ?string
    {
        $patterns = [
            'ground' => '/\b(?:begane\s+grond)\b/iu',
            '1' => '/\b(?:1(?:e|ste)?|eerste)\s+verdieping\b/iu',
            '2' => '/\b(?:2(?:e|de)?|tweede)\s+verdieping\b/iu',
            '3_plus' => '/\b(?:3(?:e|de)?|derde|[4-9](?:e|de)?)\s+verdieping\b/iu',
            'attic' => '/\bop\s+(?:de\s+)?zolder\b/iu',
            'basement' => '/\b(?:kelder|souterrain)\b/iu',
        ];

        if (is_string($preferredFloor) && isset($patterns[$preferredFloor])) {
            if (preg_match($patterns[$preferredFloor], $requestReason, $matches) === 1) {
                return $matches[0];
            }
        }

        // Relatief: alleen "woonkamer beneden" / "slaapkamer boven", nooit "boven de bank".
        if (in_array($preferredFloor, ['ground', '1'], true)
            && preg_match(
                '/\b(?:(?:kinderslaapkamers?|kinderkamers?|slaapkamers?|woonkamers?|huiskamers?|werkkamers?|kantoor|kantoren|zolders?)\s*,?\s*(?:boven|beneden))\b/iu',
                $requestReason,
                $matches,
            ) === 1) {
            return $matches[0];
        }

        if (preg_match(
            '/\b(?:begane\s+grond|(?:1(?:e|ste)?|eerste|2(?:e|de)?|tweede|3(?:e|de)?|derde|[4-9](?:e|de)?)\s+verdieping|op\s+(?:de\s+)?zolder|(?:(?:kinderslaapkamers?|kinderkamers?|slaapkamers?|woonkamers?|huiskamers?|werkkamers?|kantoor|kantoren|zolders?)\s*,?\s*(?:boven|beneden)))\b/iu',
            $requestReason,
            $matches,
        ) === 1) {
            return $matches[0];
        }

        return null;
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

    /**
     * "Nog geen airco" without explicit cool/heat words must not yield cooling_heating (BL-129/BL-142).
     * Alleen request_reason — AI-evidence mag geen intent verzinnen.
     */
    private function coolingInferredFromAircoAbsence(?string $requestReason): bool
    {
        $corpus = mb_strtolower(trim((string) $requestReason));

        if ($corpus === '') {
            return false;
        }

        $mentionsAbsence = str_contains($corpus, 'geen airco')
            || str_contains($corpus, 'nog geen airco')
            || str_contains($corpus, 'nog geen unit')
            || str_contains($corpus, 'nog geen installatie');

        if (! $mentionsAbsence) {
            return false;
        }

        $mentionsIntent = str_contains($corpus, 'koelen')
            || str_contains($corpus, 'koud te krijgen')
            || str_contains($corpus, 'te warm')
            || str_contains($corpus, 'afkoelen')
            || str_contains($corpus, 'verwarmen')
            || str_contains($corpus, 'verwarming')
            || str_contains($corpus, 'heating')
            || str_contains($corpus, 'cooling');

        return ! $mentionsIntent;
    }
}
