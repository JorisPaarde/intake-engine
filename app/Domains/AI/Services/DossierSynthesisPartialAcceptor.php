<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Support\DerivedClaimConfidenceGuard;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoConnectionType;
use App\Enums\AircoPlacementType;
use App\Enums\FollowUpItemType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validates dossier-synthesis output per proposal. Accepts valid items, drops
 * invalid ones with reasons (field_outcomes + validation_errors). An option with
 * invalid connections is dropped as a whole; its valid placements stay.
 */
final class DossierSynthesisPartialAcceptor
{
    public function __construct(
        private readonly AiValidationFailureFormatter $failureFormatter,
        private readonly DerivedClaimConfidenceGuard $claimGuard,
    ) {}

    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $input
     * @return array{
     *     accepted: array<string, mixed>,
     *     field_outcomes: list<array<string, mixed>>,
     *     validation_errors: array<string, list<string>>,
     *     has_accepted_proposals: bool,
     *     had_rejections: bool,
     *     summary_message: string|null
     * }
     */
    public function accept(array $output, array $input): array
    {
        $outcomes = [];
        /** @var array<string, list<string>> $validationErrors */
        $validationErrors = [];

        $summary = $this->acceptSummary($output, $outcomes, $validationErrors);
        if ($summary === null) {
            return [
                'accepted' => [
                    'summary' => '',
                    'placement_proposals' => [],
                    'option_proposals' => [],
                    'exceptions' => [],
                    'customer_tasks' => [],
                ],
                'field_outcomes' => $outcomes,
                'validation_errors' => $validationErrors,
                'has_accepted_proposals' => false,
                'had_rejections' => true,
                'summary_message' => $this->summarizeErrors($validationErrors),
            ];
        }

        $context = $this->buildReferenceContext($input);
        $placements = $context['placements'];
        $disallowedEvidence = $context['disallowed_evidence'];
        $freeGroup = $context['free_group'];
        $subjectsWithRoomPhoto = $context['subjects_with_room_photo'];

        if (($freeGroup === 'no' || $freeGroup === null) && $this->claimsElectricalInvention($summary)) {
            $outcomes[] = $this->outcome(
                'summary',
                'rejected',
                'summary',
                $freeGroup === 'no'
                    ? 'Samenvatting spreekt vrije groep tegen de meterkastbeoordeling (geen vrije groep).'
                    : 'Samenvatting verzint een elektrische conclusie zonder meterkastbeoordeling.',
            );
            $validationErrors['summary'][] = $freeGroup === 'no'
                ? 'Samenvatting spreekt vrije groep tegen de meterkastbeoordeling (geen vrije groep).'
                : 'Samenvatting verzint een elektrische conclusie zonder meterkastbeoordeling.';
            $summary = $this->stripFreeGroupClaims($summary);
            // Keep a neutral summary so valid placements/options can still land.
            if (trim($summary) === '' || $this->claimsElectricalInvention($summary)) {
                $summary = 'Technische voorzet op basis van beschikbaar bewijs; controleer stroomvoorziening op de meterkastfoto.';
            }
            $outcomes[] = $this->outcome('summary', 'accepted', 'summary', 'Samenvatting genormaliseerd: elektrische claim zonder meterkastbewijs verwijderd.');
        }

        if ($this->claimsInventedCustomerWish($summary)) {
            $outcomes[] = $this->outcome(
                'summary',
                'rejected',
                'summary',
                'Samenvatting verzint een klantwens (bijv. multi-split) zonder letterlijke klanttekst.',
            );
            $validationErrors['summary'][] = 'Samenvatting verzint een klantwens zonder letterlijke klanttekst.';
            $summary = $this->stripInventedCustomerWishes($summary);
            if (trim($summary) === '' || $this->claimsInventedCustomerWish($summary)) {
                $summary = 'Technische voorzet op basis van beschikbaar bewijs; controleer gewenste opstelling met de klant.';
            }
            $outcomes[] = $this->outcome('summary', 'accepted', 'summary', 'Samenvatting genormaliseerd: verzonnen klantwens verwijderd.');
        }

        $sourceCeiling = $this->claimGuard->ceilingFromSynthesisInput($input);
        $summaryNormalized = $this->claimGuard->normalizeDerivedText($summary, $sourceCeiling);
        if ($summaryNormalized['hedged'] || $this->claimsOverstatedPhotoFact($summary)) {
            $outcomes[] = $this->outcome(
                'summary',
                'rejected',
                'summary',
                'Samenvatting stelt een onzekere foto-observatie als feit (bijv. 3-fase aanwezig).',
            );
            $validationErrors['summary'][] = 'Samenvatting stelt een onzekere foto-observatie als feit.';
            $summary = $summaryNormalized['hedged']
                ? $summaryNormalized['text']
                : $this->hedgeOverstatedPhotoFacts($summary);
            $outcomes[] = $this->outcome('summary', 'accepted', 'summary', 'Samenvatting genormaliseerd: foto-observatie als onzeker geformuleerd.');
        }

        $acceptedPlacements = [];
        foreach ($this->arrayRows($output['placement_proposals'] ?? null) as $index => $proposal) {
            $path = 'placement_proposals.'.$index;
            $result = $this->acceptPlacement($proposal, $path, $context, $placements, $disallowedEvidence, $freeGroup);
            if ($result['accepted'] !== null) {
                $acceptedPlacements[] = $result['accepted'];
                $placements->put($result['accepted']['key'], $result['accepted'] + [
                    'reference' => $result['accepted']['key'],
                ]);
                $outcomes[] = $this->outcome($path, 'accepted', 'placement_proposal', null);
            } else {
                $outcomes[] = $this->outcome($path, 'rejected', 'placement_proposal', $result['reason']);
                $validationErrors[$path][] = $result['reason'] ?? 'Ongeldige positie.';
            }
        }

        $acceptedOptions = [];
        foreach ($this->arrayRows($output['option_proposals'] ?? null) as $index => $option) {
            $path = 'option_proposals.'.$index;
            $result = $this->acceptOption($option, $path, $placements, $context['evidence'], $disallowedEvidence, $freeGroup);
            if ($result['accepted'] !== null) {
                $acceptedOptions[] = $result['accepted'];
                $outcomes[] = $this->outcome($path, 'accepted', 'option_proposal', null);
            } else {
                $outcomes[] = $this->outcome($path, 'rejected', 'option_proposal', $result['reason']);
                $validationErrors[$path][] = $result['reason'] ?? 'Ongeldige installatieoptie.';
            }
        }

        $acceptedExceptions = [];
        foreach ($this->arrayRows($output['exceptions'] ?? null) as $index => $exception) {
            $path = 'exceptions.'.$index;
            $result = $this->acceptException(
                $exception,
                $path,
                $context['evidence'],
                $disallowedEvidence,
                $freeGroup,
                $sourceCeiling,
            );
            if ($result['accepted'] !== null) {
                $acceptedExceptions[] = $result['accepted'];
                $outcomes[] = $this->outcome($path, 'accepted', 'exception', null);
            } else {
                $outcomes[] = $this->outcome($path, 'rejected', 'exception', $result['reason']);
                $validationErrors[$path][] = $result['reason'] ?? 'Ongeldige uitzondering.';
            }
        }

        $acceptedTasks = [];
        foreach ($this->arrayRows($output['customer_tasks'] ?? null) as $index => $task) {
            $path = 'customer_tasks.'.$index;
            $result = $this->acceptCustomerTask(
                $task,
                $path,
                $context['subjects'],
                $context['evidence'],
                $disallowedEvidence,
                $subjectsWithRoomPhoto,
            );
            if ($result['accepted'] !== null) {
                $acceptedTasks[] = $result['accepted'];
                $outcomes[] = $this->outcome($path, 'accepted', 'customer_task', null);
            } else {
                $outcomes[] = $this->outcome($path, 'rejected', 'customer_task', $result['reason']);
                $validationErrors[$path][] = $result['reason'] ?? 'Ongeldige klanttaak.';
            }
        }

        $rawPlacementCount = count($this->arrayRows($output['placement_proposals'] ?? null));
        $rawOptionCount = count($this->arrayRows($output['option_proposals'] ?? null));
        $rawExceptionCount = count($this->arrayRows($output['exceptions'] ?? null));
        $rawTaskCount = count($this->arrayRows($output['customer_tasks'] ?? null));

        $hadRejections = count($acceptedPlacements) < $rawPlacementCount
            || count($acceptedOptions) < $rawOptionCount
            || count($acceptedExceptions) < $rawExceptionCount
            || count($acceptedTasks) < $rawTaskCount
            || $validationErrors !== [];

        $hasAccepted = $acceptedPlacements !== []
            || $acceptedOptions !== [];

        // Exceptions/tasks alone never replace an existing candidate set.
        if (! $hasAccepted && ($acceptedExceptions !== [] || $acceptedTasks !== [])) {
            foreach ($acceptedExceptions as $index => $_) {
                $path = 'exceptions.'.$index;
                $outcomes[] = $this->outcome($path, 'rejected', 'exception', 'Geen geldige positie- of installatievoorstellen; uitzonderingen niet toegepast.');
                $validationErrors[$path][] = 'Geen geldige positie- of installatievoorstellen; uitzonderingen niet toegepast.';
            }
            foreach ($acceptedTasks as $index => $_) {
                $path = 'customer_tasks.'.$index;
                $outcomes[] = $this->outcome($path, 'rejected', 'customer_task', 'Geen geldige positie- of installatievoorstellen; klanttaken niet toegepast.');
                $validationErrors[$path][] = 'Geen geldige positie- of installatievoorstellen; klanttaken niet toegepast.';
            }
            $acceptedExceptions = [];
            $acceptedTasks = [];
            $hadRejections = true;
        }

        return [
            'accepted' => [
                'summary' => $summary,
                'placement_proposals' => $acceptedPlacements,
                'option_proposals' => $acceptedOptions,
                'exceptions' => $acceptedExceptions,
                'customer_tasks' => $acceptedTasks,
            ],
            'field_outcomes' => $outcomes,
            'validation_errors' => $validationErrors,
            'has_accepted_proposals' => $hasAccepted,
            'had_rejections' => $hadRejections,
            'summary_message' => $hasAccepted
                ? ($hadRejections ? $this->summarizeErrors($validationErrors) : null)
                : ($this->summarizeErrors($validationErrors) ?? 'Geen geldige AI-voorstellen overgebleven.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  list<array<string, mixed>>  $outcomes
     * @param  array<string, list<string>>  $validationErrors
     */
    private function acceptSummary(array $output, array &$outcomes, array &$validationErrors): ?string
    {
        $validator = Validator::make($output, [
            'summary' => ['required', 'string', 'max:800'],
        ]);

        if ($validator->fails()) {
            $reason = $this->failureFormatter->fromValidator($validator);
            $outcomes[] = $this->outcome('summary', 'rejected', 'summary', $reason);
            $messages = [];
            foreach ($validator->errors()->get('summary') as $message) {
                if (is_string($message)) {
                    $messages[] = $message;
                }
            }
            $validationErrors['summary'] = $messages;

            return null;
        }

        /** @var string $summary */
        $summary = $validator->validated()['summary'];
        $outcomes[] = $this->outcome('summary', 'accepted', 'summary', null);

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @param  array{
     *     rooms: Collection<array-key, array<string, mixed>>,
     *     subjects: list<string>,
     *     subjects_by_ref: array<string, array<string, mixed>>,
     *     evidence: list<string>,
     *     placements: Collection<array-key, array<string, mixed>>,
     *     disallowed_evidence: list<string>,
     *     free_group: string|null,
     *     subjects_with_room_photo: list<string>
     * }  $context
     * @param  Collection<array-key, array<string, mixed>>  $placements
     * @param  list<string>  $disallowedEvidence
     * @return array{accepted: array<string, mixed>|null, reason: string|null}
     */
    private function acceptPlacement(
        array $proposal,
        string $path,
        array $context,
        $placements,
        array $disallowedEvidence,
        ?string $freeGroup,
    ): array {
        $validator = Validator::make(
            ['item' => $proposal],
            [
                'item.key' => ['required', 'string', 'regex:/^proposal:[a-z0-9_]+$/'],
                'item.type' => ['required', Rule::enum(AircoPlacementType::class)],
                'item.label' => ['required', 'string', 'max:160'],
                'item.description' => ['required', 'string', 'max:1500'],
                'item.room_reference' => ['present', 'nullable', 'string', 'regex:/^room:\d+$/'],
                'item.subject_reference' => ['required', 'string', 'regex:/^subject:\d+$/'],
                'item.confidence' => ['required', 'numeric', 'between:0,1'],
                'item.evidence_references' => ['required', 'array', 'min:1', 'max:20'],
                'item.evidence_references.*' => ['required', 'string', 'max:160'],
            ],
        );

        if ($validator->fails()) {
            return ['accepted' => null, 'reason' => $this->prefixedFailure($path, $validator)];
        }

        /** @var array<string, mixed> $item */
        $item = $validator->validated()['item'];
        $type = is_string($item['type']) ? $item['type'] : (string) $item['type'];

        // Staging intake 84 / runs 283–285: models reuse prior airco_placement
        // subject refs. Canonicalize to the parent room/survey subject first.
        $item['subject_reference'] = $this->canonicalizeSubjectReference(
            (string) $item['subject_reference'],
            $context,
        );

        $room = $item['room_reference'] === null
            ? null
            : $context['rooms']->get($item['room_reference']);

        if (! in_array($item['subject_reference'], $context['subjects'], true)
            || ($item['room_reference'] !== null && ! is_array($room))
            || ($type === AircoPlacementType::IndoorUnit->value && ! is_array($room))
            || (is_array($room) && ($room['subject_reference'] ?? null) !== $item['subject_reference'])) {
            return [
                'accepted' => null,
                'reason' => 'Een AI-positie verwijst niet naar het bijbehorende dossieronderdeel of de gewenste ruimte.',
            ];
        }

        if ($placements->has($item['key'])) {
            return ['accepted' => null, 'reason' => 'Dubbele proposal-sleutel.'];
        }

        if (($freeGroup === 'no' || $freeGroup === null) && $this->claimsElectricalInvention(
            (string) $item['label'].' '.(string) $item['description'],
        )) {
            return [
                'accepted' => null,
                'reason' => $freeGroup === 'no'
                    ? 'Positie claimt vrije groep terwijl de meterkastbeoordeling geen vrije groep zag.'
                    : 'Positie verzint een elektrische conclusie zonder meterkastbeoordeling.',
            ];
        }

        try {
            $this->assertUniqueReferences($item['evidence_references']);
            $this->assertEvidenceReferences($item['evidence_references'], $context['evidence'], $disallowedEvidence);
        } catch (ValidationException $e) {
            return ['accepted' => null, 'reason' => $this->failureFormatter->fromException($e)];
        }

        $item['type'] = $type;

        return ['accepted' => $item, 'reason' => null];
    }

    /**
     * @param  array<string, mixed>  $option
     * @param  Collection<string, array<string, mixed>>  $placements
     * @param  list<string>  $evidence
     * @param  list<string>  $disallowedEvidence
     * @return array{accepted: array<string, mixed>|null, reason: string|null}
     */
    private function acceptOption(
        array $option,
        string $path,
        $placements,
        array $evidence,
        array $disallowedEvidence,
        ?string $freeGroup,
    ): array {
        $option = $this->remapSubjectRefsToPlacements($option, $placements);
        $stripped = $this->stripUnresolvedSubjectRefs($option);
        $option = $stripped['option'];
        $strippedConnectionReasons = $stripped['connection_reasons'];

        if (($freeGroup === 'no' || $freeGroup === null) && $this->claimsElectricalInvention(
            (string) ($option['label'] ?? '').' '.(string) ($option['summary'] ?? ''),
        )) {
            return [
                'accepted' => null,
                'reason' => $freeGroup === 'no'
                    ? 'Installatieoptie claimt vrije groep terwijl de meterkastbeoordeling geen vrije groep zag.'
                    : 'Installatieoptie verzint een elektrische conclusie zonder meterkastbeoordeling.',
            ];
        }

        $validator = Validator::make(
            ['item' => $option],
            [
                'item.label' => ['required', 'string', 'max:160'],
                'item.configuration_type' => ['required', Rule::enum(AircoConfigurationType::class)],
                'item.summary' => ['required', 'string', 'max:2000'],
                'item.cost_impact' => ['required', 'in:low,medium,high,unknown'],
                'item.confidence' => ['required', 'numeric', 'between:0,1'],
                'item.placement_references' => ['required', 'array', 'min:2', 'max:20'],
                'item.placement_references.*' => ['required', 'string', 'regex:/^(placement:\d+|proposal:[a-z0-9_]+)$/'],
                // Soft min: incomplete connection sets fail the type-completeness check
                // below and drop only this option (partial accept keeps placements).
                'item.connections' => ['required', 'array', 'min:1', 'max:40'],
                'item.connections.*.type' => ['required', Rule::enum(AircoConnectionType::class)],
                'item.connections.*.label' => ['required', 'string', 'max:180'],
                'item.connections.*.from_placement_reference' => ['present', 'nullable', 'string', 'regex:/^(placement:\d+|proposal:[a-z0-9_]+)$/'],
                'item.connections.*.to_placement_reference' => ['present', 'nullable', 'string', 'regex:/^(placement:\d+|proposal:[a-z0-9_]+)$/'],
                'item.connections.*.status' => [
                    'required',
                    Rule::in([
                        AircoConnectionStatus::Proposed->value,
                        AircoConnectionStatus::NeedsEvidence->value,
                        AircoConnectionStatus::NotRemotelyResolvable->value,
                    ]),
                ],
                'item.connections.*.length_class' => ['required', 'in:short,medium,long,unknown'],
                'item.connections.*.segments' => ['present', 'array', 'max:20'],
                'item.connections.*.segments.*' => ['string', 'max:200'],
                'item.connections.*.obstacles' => ['present', 'array', 'max:20'],
                'item.connections.*.obstacles.*' => ['string', 'max:200'],
                'item.connections.*.uncertainties' => ['present', 'array', 'max:20'],
                'item.connections.*.uncertainties.*' => ['string', 'max:200'],
                'item.connections.*.cost_impact' => ['required', 'in:low,medium,high,unknown'],
                'item.connections.*.confidence' => ['required', 'numeric', 'between:0,1'],
                'item.connections.*.evidence_references' => ['present', 'array', 'min:1', 'max:20'],
                'item.connections.*.evidence_references.*' => ['string', 'max:160'],
            ],
        );

        if ($validator->fails()) {
            $reason = $this->prefixedFailure($path, $validator);
            if ($strippedConnectionReasons !== []) {
                $reason .= ' | '.implode(' | ', $strippedConnectionReasons);
            }

            return ['accepted' => null, 'reason' => $reason];
        }

        /** @var array<string, mixed> $item */
        $item = $validator->validated()['item'];
        $configurationType = is_string($item['configuration_type'])
            ? $item['configuration_type']
            : (string) $item['configuration_type'];
        $item['configuration_type'] = $configurationType;

        $optionReferences = $item['placement_references'];
        try {
            $this->assertUniqueReferences($optionReferences);
        } catch (ValidationException $e) {
            return ['accepted' => null, 'reason' => $this->failureFormatter->fromException($e)];
        }

        // Remap connection endpoints that still point at older placement:IDs onto
        // matching proposal: keys in this option (same subject), so a refresh with
        // new placement proposals is not rejected while refrigerant/condensate
        // still cite the previous ids.
        $item = $this->remapStaleConnectionPlacementRefs($item, $optionReferences, $placements);
        $optionReferences = $item['placement_references'];

        $optionPlacements = $placements->only($optionReferences);
        $configuration = AircoConfigurationType::from($configurationType);
        $indoorCount = $optionPlacements->where('type', AircoPlacementType::IndoorUnit->value)->count();
        $outdoorCount = $optionPlacements->where('type', AircoPlacementType::OutdoorUnit->value)->count();
        $validConfiguration = match ($configuration) {
            AircoConfigurationType::SingleSplit => $indoorCount === 1 && $outdoorCount === 1,
            AircoConfigurationType::MultiSplit => $indoorCount >= 2 && $outdoorCount === 1,
            AircoConfigurationType::MultipleSingleSplits => $indoorCount >= 2
                && $outdoorCount === $indoorCount,
        };

        if ($optionPlacements->count() !== count($optionReferences)
            || ! $optionPlacements->contains('type', AircoPlacementType::IndoorUnit->value)
            || ! $optionPlacements->contains('type', AircoPlacementType::OutdoorUnit->value)
            || ! $validConfiguration) {
            return [
                'accepted' => null,
                'reason' => 'Een AI-optie verwijst niet naar een geldige combinatie van onderbouwde binnen- en buitenposities.',
            ];
        }

        $connections = $this->arrayRows($item['connections'] ?? null);

        // Rewrite outdoor→outdoor power onto power_source when present (run 282),
        // otherwise keep as needs_evidence with null from (intake 85).
        $connections = $this->repairInvalidPowerEndpoints($connections, $optionPlacements);
        $item['connections'] = $connections;

        // Run 282: model covered only one indoor with refrigerant/condensate.
        // Fill missing per-indoor links deterministically as needs_evidence.
        $connections = $this->ensurePerIndoorConnections(
            $connections,
            $optionPlacements,
            $optionReferences,
            $evidence,
        );
        $item['connections'] = $connections;

        $connectionTypes = collect($connections)->map(
            static fn (array $connection): string => is_string($connection['type'] ?? null)
                ? $connection['type']
                : (string) ($connection['type'] ?? ''),
        );

        foreach (AircoConnectionType::cases() as $type) {
            if (! $connectionTypes->contains($type->value)) {
                $reason = 'Iedere AI-optie moet koel-, condens- en stroomverbindingen bevatten.';
                if ($strippedConnectionReasons !== []) {
                    $reason .= ' | '.implode(' | ', $strippedConnectionReasons);
                }

                return [
                    'accepted' => null,
                    'reason' => $reason,
                ];
            }
        }

        foreach ([AircoConnectionType::Refrigerant, AircoConnectionType::Condensate] as $requiredType) {
            $connectionsForType = collect($connections)->filter(
                static fn (array $connection): bool => (
                    is_string($connection['type'] ?? null)
                        ? $connection['type']
                        : (string) ($connection['type'] ?? '')
                ) === $requiredType->value,
            );
            $indoorReferences = $optionPlacements
                ->where('type', AircoPlacementType::IndoorUnit->value)
                ->keys();
            $coversEveryIndoorPlacement = $indoorReferences->every(
                static fn (string $reference): bool => $connectionsForType->contains(
                    static fn (array $connection): bool => in_array(
                        $reference,
                        [
                            $connection['from_placement_reference'] ?? null,
                            $connection['to_placement_reference'] ?? null,
                        ],
                        true,
                    ),
                ),
            );

            if (! $coversEveryIndoorPlacement) {
                return [
                    'accepted' => null,
                    'reason' => 'Iedere AI-binnenpositie moet een eigen koel- en condensverbinding hebben.',
                ];
            }
        }

        foreach ($connections as $connectionIndex => $connection) {
            foreach (['from_placement_reference', 'to_placement_reference'] as $key) {
                $reference = $connection[$key] ?? null;
                if ($reference !== null && ! in_array($reference, $optionReferences, true)) {
                    return [
                        'accepted' => null,
                        'reason' => 'Een AI-verbinding verwijst naar een positie buiten de voorgestelde optie.',
                    ];
                }
            }

            if (($freeGroup === 'no' || $freeGroup === null) && $this->claimsElectricalInvention(
                (string) ($connection['label'] ?? ''),
            )) {
                return [
                    'accepted' => null,
                    'reason' => 'connections.'.$connectionIndex.': '
                        .($freeGroup === 'no'
                            ? 'stroomclaim spreekt meterkastbeoordeling tegen (geen vrije groep).'
                            : 'stroomclaim zonder meterkastbeoordeling.'),
                ];
            }

            try {
                $this->assertUniqueReferences($connection['evidence_references']);
                $this->assertEvidenceReferences($connection['evidence_references'], $evidence, $disallowedEvidence);
            } catch (ValidationException $e) {
                return [
                    'accepted' => null,
                    'reason' => 'connections.'.$connectionIndex.': '.$this->failureFormatter->fromException($e),
                ];
            }

            $connections[$connectionIndex]['type'] = is_string($connection['type'] ?? null)
                ? $connection['type']
                : (string) ($connection['type'] ?? '');
        }

        $item['connections'] = $connections;

        return ['accepted' => $item, 'reason' => null];
    }

    /**
     * When placement_references use proposal:keys but connections still cite
     * placement:N from a prior run, remap each endpoint onto the unique
     * proposal in this option that shares the same subject_reference.
     *
     * @param  array<string, mixed>  $option
     * @param  list<string>  $optionReferences
     * @param  Collection<string, array<string, mixed>>  $placements
     * @return array<string, mixed>
     */
    private function remapStaleConnectionPlacementRefs(
        array $option,
        array $optionReferences,
        $placements,
    ): array {
        if (! isset($option['connections']) || ! is_array($option['connections'])) {
            return $option;
        }

        $proposalBySubject = [];
        foreach ($optionReferences as $reference) {
            if (! str_starts_with($reference, 'proposal:')) {
                continue;
            }
            $placement = $placements->get($reference);
            $subject = is_array($placement) ? ($placement['subject_reference'] ?? null) : null;
            if (! is_string($subject) || $subject === '') {
                continue;
            }
            if (array_key_exists($subject, $proposalBySubject)) {
                // Ambiguous: more than one proposal for this subject — skip remap.
                $proposalBySubject[$subject] = null;

                continue;
            }
            $proposalBySubject[$subject] = $reference;
        }

        foreach ($option['connections'] as $index => $connection) {
            if (! is_array($connection)) {
                continue;
            }
            foreach (['from_placement_reference', 'to_placement_reference'] as $key) {
                $reference = $connection[$key] ?? null;
                if (! is_string($reference)
                    || ! str_starts_with($reference, 'placement:')
                    || in_array($reference, $optionReferences, true)) {
                    continue;
                }
                $existing = $placements->get($reference);
                $subject = is_array($existing) ? ($existing['subject_reference'] ?? null) : null;
                if (! is_string($subject) || ! array_key_exists($subject, $proposalBySubject)) {
                    continue;
                }
                $mapped = $proposalBySubject[$subject];
                if ($mapped !== null) {
                    $option['connections'][$index][$key] = $mapped;
                }
            }
        }

        return $option;
    }

    /**
     * Prod run-243: models sometimes emit subject:N where placement:N was meant.
     * Remap only when exactly one placement belongs to that subject.
     *
     * @param  array<string, mixed>  $option
     * @param  Collection<string, array<string, mixed>>  $placements
     * @return array<string, mixed>
     */
    private function remapSubjectRefsToPlacements(array $option, $placements): array
    {
        $resolve = function (mixed $reference) use ($placements): mixed {
            if (! is_string($reference) || preg_match('/^subject:\d+$/', $reference) !== 1) {
                return $reference;
            }

            $matches = $placements
                ->filter(static fn (array $placement): bool => ($placement['subject_reference'] ?? null) === $reference)
                ->keys()
                ->values();

            return $matches->count() === 1 ? $matches->first() : $reference;
        };

        if (isset($option['placement_references']) && is_array($option['placement_references'])) {
            $option['placement_references'] = array_values(array_map(
                $resolve,
                $option['placement_references'],
            ));
        }

        if (isset($option['connections']) && is_array($option['connections'])) {
            foreach ($option['connections'] as $index => $connection) {
                if (! is_array($connection)) {
                    continue;
                }
                foreach (['from_placement_reference', 'to_placement_reference'] as $key) {
                    if (array_key_exists($key, $connection)) {
                        $option['connections'][$index][$key] = $resolve($connection[$key]);
                    }
                }
            }
        }

        return $option;
    }

    /**
     * After remap: drop unresolved subject: refs from placement_references, and
     * drop only the connections that still carry subject: endpoints (ambiguous /
     * unknown mapping). Cardinality is re-checked by the option validator.
     *
     * @param  array<string, mixed>  $option
     * @return array{option: array<string, mixed>, connection_reasons: list<string>}
     */
    private function stripUnresolvedSubjectRefs(array $option): array
    {
        $reasons = [];

        if (isset($option['placement_references']) && is_array($option['placement_references'])) {
            $kept = [];
            foreach ($option['placement_references'] as $reference) {
                if (is_string($reference) && preg_match('/^subject:\d+$/', $reference) === 1) {
                    $reasons[] = 'placement_references: subject-ref '.$reference.' kon niet eenduidig naar een placement worden omgezet.';

                    continue;
                }
                $kept[] = $reference;
            }
            $option['placement_references'] = $kept;
        }

        if (isset($option['connections']) && is_array($option['connections'])) {
            $keptConnections = [];
            foreach ($option['connections'] as $index => $connection) {
                if (! is_array($connection)) {
                    continue;
                }
                $from = $connection['from_placement_reference'] ?? null;
                $to = $connection['to_placement_reference'] ?? null;
                $unresolved = [];
                foreach (['from' => $from, 'to' => $to] as $label => $reference) {
                    if (is_string($reference) && preg_match('/^subject:\d+$/', $reference) === 1) {
                        $unresolved[] = $label.'_placement_reference='.$reference;
                    }
                }
                if ($unresolved !== []) {
                    $reasons[] = 'connections.'.$index.': subject-ref niet eenduidig omzetbaar ['.implode(', ', $unresolved).'] — verbinding genegeerd.';

                    continue;
                }
                $keptConnections[] = $connection;
            }
            $option['connections'] = $keptConnections;
        }

        return ['option' => $option, 'connection_reasons' => $reasons];
    }

    /**
     * @param  array<string, mixed>  $exception
     * @param  list<string>  $evidence
     * @param  list<string>  $disallowedEvidence
     * @param  'low'|'medium'|'high'|null  $sourceCeiling
     * @return array{accepted: array<string, mixed>|null, reason: string|null}
     */
    private function acceptException(
        array $exception,
        string $path,
        array $evidence,
        array $disallowedEvidence,
        ?string $freeGroup,
        ?string $sourceCeiling = null,
    ): array {
        $validator = Validator::make(
            ['item' => $exception],
            [
                'item.code' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
                'item.label' => ['required', 'string', 'max:500'],
                'item.decision_area_key' => ['required', 'in:request,capacity,placement,refrigerant,condensate,power,cost_risks'],
                'item.confidence' => ['required', 'in:low,medium,high'],
                'item.evidence_references' => ['present', 'array', 'min:1', 'max:20'],
                'item.evidence_references.*' => ['string', 'max:160'],
            ],
        );

        if ($validator->fails()) {
            return ['accepted' => null, 'reason' => $this->prefixedFailure($path, $validator)];
        }

        /** @var array<string, mixed> $item */
        $item = $validator->validated()['item'];

        if (($freeGroup === 'no' || $freeGroup === null) && $this->claimsElectricalInvention((string) $item['label'])) {
            return [
                'accepted' => null,
                'reason' => $freeGroup === 'no'
                    ? 'Uitzondering claimt vrije groep terwijl de meterkastbeoordeling geen vrije groep zag.'
                    : 'Uitzondering verzint een elektrische conclusie zonder meterkastbeoordeling.',
            ];
        }

        try {
            $this->assertUniqueReferences($item['evidence_references']);
            $this->assertEvidenceReferences($item['evidence_references'], $evidence, $disallowedEvidence);
        } catch (ValidationException $e) {
            return ['accepted' => null, 'reason' => $this->failureFormatter->fromException($e)];
        }

        $normalized = $this->claimGuard->normalizeDerivedText((string) $item['label'], $sourceCeiling);
        $item['label'] = $normalized['text'];
        $item['confidence'] = $this->claimGuard->capConfidence(
            is_string($item['confidence']) ? $item['confidence'] : 'medium',
            $sourceCeiling,
        );

        return ['accepted' => $item, 'reason' => null];
    }

    /**
     * @param  array<string, mixed>  $task
     * @param  list<string>  $subjects
     * @param  list<string>  $evidence
     * @param  list<string>  $disallowedEvidence
     * @param  list<string>  $subjectsWithRoomPhoto
     * @return array{accepted: array<string, mixed>|null, reason: string|null}
     */
    private function acceptCustomerTask(
        array $task,
        string $path,
        array $subjects,
        array $evidence,
        array $disallowedEvidence,
        array $subjectsWithRoomPhoto,
    ): array {
        $validator = Validator::make(
            ['item' => $task],
            [
                'item.type' => ['required', Rule::enum(FollowUpItemType::class)],
                'item.prompt' => ['required', 'string', 'max:500'],
                'item.decision_area_key' => ['required', 'in:request,capacity,placement,refrigerant,condensate,power,cost_risks'],
                'item.subject_reference' => ['present', 'nullable', 'string', 'regex:/^subject:\d+$/'],
                'item.reason' => ['required', 'string', 'max:500'],
                'item.evidence_references' => ['present', 'array', 'max:20'],
                'item.evidence_references.*' => ['string', 'max:160'],
            ],
        );

        if ($validator->fails()) {
            return ['accepted' => null, 'reason' => $this->prefixedFailure($path, $validator)];
        }

        /** @var array<string, mixed> $item */
        $item = $validator->validated()['item'];
        $item['type'] = is_string($item['type']) ? $item['type'] : (string) $item['type'];

        if ($item['subject_reference'] !== null && ! in_array($item['subject_reference'], $subjects, true)) {
            return [
                'accepted' => null,
                'reason' => 'Een AI-klanttaak verwijst naar een onbekend dossieronderdeel.',
            ];
        }

        // Staging intake 77: don't re-ask for a wall/room photo the customer already uploaded.
        if ($item['type'] === FollowUpItemType::Photo->value
            && is_string($item['subject_reference'])
            && in_array($item['subject_reference'], $subjectsWithRoomPhoto, true)
            && $this->asksForRoomOrWallPhoto((string) $item['prompt'].' '.(string) $item['reason'])) {
            return [
                'accepted' => null,
                'reason' => 'Klanttaak overgeslagen: voor dit onderwerp staat al een bruikbare muur-/ruimtefoto in het dossier.',
            ];
        }

        try {
            $this->assertUniqueReferences($item['evidence_references']);
            $this->assertEvidenceReferences($item['evidence_references'], $evidence, $disallowedEvidence);
        } catch (ValidationException $e) {
            return ['accepted' => null, 'reason' => $this->failureFormatter->fromException($e)];
        }

        return ['accepted' => $item, 'reason' => null];
    }

    private function claimsElectricalInvention(string $text): bool
    {
        return $this->claimGuard->claimsUnequivocalElectricalFact($text)
            || $this->claimGuard->claimsOverconfidentFact($text)
            || $this->claimsFreeGroupAvailable($text)
            || (bool) preg_match('/\bfree[_\s-]?group|groepenaanduiding\s+leesbaar\b/u', mb_strtolower($text));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     rooms: Collection<array-key, array<string, mixed>>,
     *     subjects: list<string>,
     *     subjects_by_ref: array<string, array<string, mixed>>,
     *     evidence: list<string>,
     *     placements: Collection<array-key, array<string, mixed>>,
     *     disallowed_evidence: list<string>,
     *     free_group: string|null,
     *     subjects_with_room_photo: list<string>
     * }
     */
    private function buildReferenceContext(array $input): array
    {
        $placements = collect($this->arrayRows($input['placements'] ?? null))->keyBy('reference');
        $rooms = collect($this->arrayRows($input['rooms'] ?? null))->keyBy('reference');
        $subjectsByRef = [];
        foreach ($this->arrayRows($input['subjects'] ?? null) as $row) {
            $reference = $row['reference'] ?? null;
            if (! is_string($reference) || $reference === '') {
                continue;
            }
            $subjectsByRef[$reference] = $row;
        }
        $subjects = $placements
            ->pluck('subject_reference')
            ->merge(array_keys($subjectsByRef))
            ->merge($rooms->pluck('subject_reference'))
            ->merge(collect($this->arrayRows($input['dossier_records'] ?? null))->pluck('subject_reference'))
            ->filter(static fn (mixed $reference): bool => is_string($reference))
            ->unique()
            ->values()
            ->all();

        $disallowed = [];
        foreach ($this->arrayRows($input['image_manifest'] ?? null) as $image) {
            $reference = $image['reference'] ?? null;
            if (! is_string($reference) || $reference === '') {
                continue;
            }
            if (($image['evidence_eligible'] ?? true) === false) {
                $disallowed[] = $reference;
            }
        }

        $policy = is_array($input['synthesis_policy'] ?? null) ? $input['synthesis_policy'] : [];
        $freeGroup = is_string($policy['free_group'] ?? null) ? $policy['free_group'] : null;
        $covered = [];
        foreach ($policy['subjects_with_room_photo'] ?? [] as $subjectRef) {
            if (is_string($subjectRef) && $subjectRef !== '') {
                $covered[] = $subjectRef;
            }
        }

        return [
            'rooms' => $rooms,
            'subjects' => $subjects,
            'subjects_by_ref' => $subjectsByRef,
            'evidence' => $this->allReferences($input),
            'placements' => $placements,
            'disallowed_evidence' => array_values(array_unique($disallowed)),
            'free_group' => $freeGroup,
            'subjects_with_room_photo' => array_values(array_unique($covered)),
        ];
    }

    /**
     * Walk airco_placement subjects up to their room/survey parent.
     *
     * @param  array{subjects_by_ref: array<string, array<string, mixed>>}  $context
     */
    private function canonicalizeSubjectReference(string $reference, array $context): string
    {
        $subjectsByRef = $context['subjects_by_ref'];
        $current = $reference;
        for ($i = 0; $i < 10; $i++) {
            $subject = $subjectsByRef[$current] ?? null;
            if (! is_array($subject)) {
                break;
            }
            $type = is_string($subject['type'] ?? null) ? $subject['type'] : null;
            if ($type !== 'airco_placement') {
                break;
            }
            $parent = $subject['parent_reference'] ?? null;
            if (! is_string($parent) || $parent === '') {
                break;
            }
            $current = $parent;
        }

        return $current;
    }

    /**
     * Outdoor→outdoor power is invalid. Prefer remapping onto a power_source in
     * the option when available (run 282). When no power_source exists (intake 85),
     * keep the connection as needs_evidence with a null from-endpoint so
     * type-completeness still passes and the installer sees an open stroomroute.
     *
     * @param  list<array<string, mixed>>  $connections
     * @param  Collection<string, array<string, mixed>>  $optionPlacements
     * @return list<array<string, mixed>>
     */
    private function repairInvalidPowerEndpoints(array $connections, $optionPlacements): array
    {
        $powerSourceRef = $optionPlacements
            ->filter(static fn (array $placement): bool => ($placement['type'] ?? null) === AircoPlacementType::PowerSource->value)
            ->keys()
            ->first();
        $outdoorRef = $optionPlacements
            ->filter(static fn (array $placement): bool => ($placement['type'] ?? null) === AircoPlacementType::OutdoorUnit->value)
            ->keys()
            ->first();

        $repaired = [];
        foreach ($connections as $connection) {
            $connectionType = is_string($connection['type'] ?? null)
                ? $connection['type']
                : (string) ($connection['type'] ?? '');
            if ($connectionType !== AircoConnectionType::Power->value) {
                $repaired[] = $connection;

                continue;
            }

            $fromRef = is_string($connection['from_placement_reference'] ?? null)
                ? $connection['from_placement_reference']
                : null;
            $toRef = is_string($connection['to_placement_reference'] ?? null)
                ? $connection['to_placement_reference']
                : null;
            $from = $fromRef !== null ? $optionPlacements->get($fromRef) : null;
            $to = $toRef !== null ? $optionPlacements->get($toRef) : null;
            $fromType = is_array($from) ? ($from['type'] ?? null) : null;
            $toType = is_array($to) ? ($to['type'] ?? null) : null;

            if ($fromType === AircoPlacementType::OutdoorUnit->value
                && $toType === AircoPlacementType::OutdoorUnit->value) {
                // Prefer the connection's own outdoor endpoint when present.
                $targetOutdoor = is_string($toRef) ? $toRef : (is_string($fromRef) ? $fromRef : $outdoorRef);
                if (! is_string($targetOutdoor)) {
                    // Cannot repair without an outdoor endpoint — drop.
                    continue;
                }

                if (is_string($powerSourceRef)) {
                    $connection['from_placement_reference'] = $powerSourceRef;
                } else {
                    // No power_source in this option: keep power as open evidence.
                    $connection['from_placement_reference'] = null;
                }
                $connection['to_placement_reference'] = $targetOutdoor;
                $connection['status'] = AircoConnectionStatus::NeedsEvidence->value;
                $uncertainties = is_array($connection['uncertainties'] ?? null)
                    ? $connection['uncertainties']
                    : [];
                $uncertainties[] = is_string($powerSourceRef)
                    ? 'Stroomroute nog te bepalen (model koppelde buitenunit aan buitenunit).'
                    : 'Stroomroute nog te bepalen';
                $connection['uncertainties'] = array_values(array_unique(array_filter(
                    $uncertainties,
                    static fn (mixed $row): bool => is_string($row) && $row !== '',
                )));
            }

            $repaired[] = $connection;
        }

        return $repaired;
    }

    /**
     * Deterministically add missing refrigerant/condensate links per indoor unit.
     *
     * @param  list<array<string, mixed>>  $connections
     * @param  Collection<string, array<string, mixed>>  $optionPlacements
     * @param  list<string>  $optionReferences
     * @param  list<string>  $evidence
     * @return list<array<string, mixed>>
     */
    private function ensurePerIndoorConnections(
        array $connections,
        $optionPlacements,
        array $optionReferences,
        array $evidence,
    ): array {
        $outdoorRef = $optionPlacements
            ->filter(static fn (array $placement): bool => ($placement['type'] ?? null) === AircoPlacementType::OutdoorUnit->value)
            ->keys()
            ->first();
        $drainRef = $optionPlacements
            ->filter(static fn (array $placement): bool => ($placement['type'] ?? null) === AircoPlacementType::DrainPoint->value)
            ->keys()
            ->first();
        $indoorRefs = $optionPlacements
            ->filter(static fn (array $placement): bool => ($placement['type'] ?? null) === AircoPlacementType::IndoorUnit->value)
            ->keys()
            ->values();

        $fallbackEvidence = [];
        foreach ($optionReferences as $reference) {
            if (in_array($reference, $evidence, true)) {
                $fallbackEvidence[] = $reference;
            }
        }
        if ($fallbackEvidence === []) {
            foreach ($evidence as $reference) {
                if (str_starts_with($reference, 'dossier_image:')) {
                    $fallbackEvidence[] = $reference;
                    break;
                }
            }
        }
        if ($fallbackEvidence === [] && $evidence !== []) {
            $fallbackEvidence[] = $evidence[0];
        }

        foreach ([AircoConnectionType::Refrigerant, AircoConnectionType::Condensate] as $requiredType) {
            foreach ($indoorRefs as $indoorRef) {
                $covered = collect($connections)->contains(
                    static function (array $connection) use ($requiredType, $indoorRef): bool {
                        $type = is_string($connection['type'] ?? null)
                            ? $connection['type']
                            : (string) ($connection['type'] ?? '');
                        if ($type !== $requiredType->value) {
                            return false;
                        }

                        return in_array($indoorRef, [
                            $connection['from_placement_reference'] ?? null,
                            $connection['to_placement_reference'] ?? null,
                        ], true);
                    },
                );
                if ($covered) {
                    continue;
                }

                $to = $requiredType === AircoConnectionType::Refrigerant
                    ? (is_string($outdoorRef) ? $outdoorRef : null)
                    : (is_string($drainRef) ? $drainRef : (is_string($outdoorRef) ? $outdoorRef : null));

                if ($to === null || $fallbackEvidence === []) {
                    continue;
                }

                $label = $requiredType === AircoConnectionType::Refrigerant
                    ? 'Koelleiding (nog te bepalen)'
                    : 'Condensafvoer (nog te bepalen)';

                $connections[] = [
                    'type' => $requiredType->value,
                    'label' => $label,
                    'from_placement_reference' => $indoorRef,
                    'to_placement_reference' => $to,
                    'status' => AircoConnectionStatus::NeedsEvidence->value,
                    'length_class' => 'unknown',
                    'segments' => [],
                    'obstacles' => [],
                    'uncertainties' => ['Route en eindpunt nog te bepalen op basis van aanvullend bewijs.'],
                    'cost_impact' => 'unknown',
                    'confidence' => 0.35,
                    'evidence_references' => array_values(array_unique($fallbackEvidence)),
                ];
            }
        }

        return $connections;
    }

    /** @param list<string> $references */
    private function assertUniqueReferences(array $references): void
    {
        if (count($references) !== count(array_unique($references, SORT_STRING))) {
            throw ValidationException::withMessages([
                'evidence_references' => 'Eén voorstel mag dezelfde referentie niet dubbel opnemen.',
            ]);
        }
    }

    /**
     * @param  list<string>  $references
     * @param  list<string>  $available
     * @param  list<string>  $disallowed
     */
    private function assertEvidenceReferences(array $references, array $available, array $disallowed = []): void
    {
        foreach ($references as $reference) {
            if (in_array($reference, $disallowed, true)) {
                throw ValidationException::withMessages([
                    'evidence_references' => 'AI-bewijs gebruikt een wrong-subject foto die niet als bewijs mag tellen.',
                ]);
            }
            if (! in_array($reference, $available, true)) {
                throw ValidationException::withMessages([
                    'evidence_references' => 'AI-bewijs verwijst niet naar de verzonden dossiercontext.',
                ]);
            }
        }
    }

    private function claimsInventedCustomerWish(string $text): bool
    {
        $normalized = mb_strtolower($text);

        return (bool) preg_match(
            '/\b(de\s+klant\s+(wenst|wil|kiest|vraagt)|klant\s+wenst|wenst\s+een\s+multi[-\s]?split|wil\s+een\s+multi[-\s]?split)\b/u',
            $normalized,
        );
    }

    private function stripInventedCustomerWishes(string $summary): string
    {
        $cleaned = preg_replace(
            '/[^.]*\b(de\s+klant\s+(wenst|wil|kiest|vraagt)|klant\s+wenst|wenst\s+een\s+multi[-\s]?split)[^.]*\.?/iu',
            '',
            $summary,
        );

        return trim(preg_replace('/\s{2,}/', ' ', is_string($cleaned) ? $cleaned : $summary) ?? $summary);
    }

    /**
     * Hard factual claims about phase/electrical capacity that should stay hedged
     * unless the meterkast assessment already established them (handled separately).
     */
    private function claimsOverstatedPhotoFact(string $text): bool
    {
        return $this->claimGuard->claimsOverconfidentFact($text);
    }

    private function hedgeOverstatedPhotoFacts(string $summary): string
    {
        return $this->claimGuard->hedgeOverconfidentClaim($summary);
    }

    private function claimsFreeGroupAvailable(string $text): bool
    {
        $normalized = mb_strtolower($text);

        return (bool) preg_match(
            '/vrije\s+groep(en)?|free[_\s-]?group|met\s+vrije\s+groep/',
            $normalized,
        );
    }

    private function stripFreeGroupClaims(string $summary): string
    {
        $cleaned = preg_replace(
            '/[^.]*(\bvrije\s+groep|\b1[\s-]?fasen?|\b3[\s-]?fasen?|\bdriefasen?)[^.]*\.?/iu',
            '',
            $summary,
        );

        return trim(preg_replace('/\s{2,}/', ' ', is_string($cleaned) ? $cleaned : $summary) ?? $summary);
    }

    private function asksForRoomOrWallPhoto(string $text): bool
    {
        $normalized = mb_strtolower($text);

        return (bool) preg_match(
            '/\b(muur|wand|kamer|ruimte|plafond|indoor|binnenunitplek|plaatsingsplek)\b/',
            $normalized,
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function allReferences(array $input): array
    {
        $references = [];
        $remaining = [$input];

        while ($remaining !== []) {
            $value = array_pop($remaining);

            foreach ($value as $key => $item) {
                if (in_array($key, ['reference', 'subject_reference', 'room_reference'], true)
                    && is_string($item)
                    && $item !== '') {
                    $references[] = $item;
                }

                if (is_array($item)) {
                    $remaining[] = $item;
                }
            }
        }

        return array_values(array_unique($references));
    }

    /** @return list<array<string, mixed>> */
    private function arrayRows(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $rows = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return array{field: string, disposition: string, kind: string, reason: string|null}
     */
    private function outcome(string $field, string $disposition, string $kind, ?string $reason): array
    {
        return [
            'field' => $field,
            'disposition' => $disposition,
            'kind' => $kind,
            'reason' => $reason,
        ];
    }

    private function prefixedFailure(string $path, \Illuminate\Validation\Validator $validator): string
    {
        $parts = [];
        foreach ($validator->errors()->messages() as $attribute => $messages) {
            $attr = str_starts_with($attribute, 'item.')
                ? $path.substr($attribute, 4)
                : $path.'.'.$attribute;
            $rejected = data_get($validator->getData(), $attribute);
            $summary = is_array($rejected) ? 'array('.count($rejected).')' : (
                $rejected === null ? 'null' : (is_scalar($rejected) ? (string) $rejected : null)
            );
            foreach ($messages as $message) {
                $parts[] = $summary === null
                    ? "{$attr}: {$message}"
                    : "{$attr}: {$message} [got: {$summary}]";
            }
        }

        return implode(' | ', $parts);
    }

    /** @param  array<string, list<string>>  $validationErrors */
    private function summarizeErrors(array $validationErrors): ?string
    {
        if ($validationErrors === []) {
            return null;
        }

        $parts = [];
        foreach ($validationErrors as $attribute => $messages) {
            foreach ($messages as $message) {
                $parts[] = "{$attribute}: {$message}";
            }
        }

        return $parts === [] ? null : implode(' | ', $parts);
    }
}
