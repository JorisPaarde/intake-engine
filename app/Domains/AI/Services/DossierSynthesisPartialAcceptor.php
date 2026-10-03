<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

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

        $acceptedPlacements = [];
        foreach ($this->arrayRows($output['placement_proposals'] ?? null) as $index => $proposal) {
            $path = 'placement_proposals.'.$index;
            $result = $this->acceptPlacement($proposal, $path, $context, $placements);
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
            $result = $this->acceptOption($option, $path, $placements, $context['evidence']);
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
            $result = $this->acceptException($exception, $path, $context['evidence']);
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
            $result = $this->acceptCustomerTask($task, $path, $context['subjects'], $context['evidence']);
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
     * @param  Collection<string, array<string, mixed>>  $placements
     * @param  array{rooms: Collection<string, array<string, mixed>>, subjects: list<string>, evidence: list<string>, placements: Collection<string, array<string, mixed>>}  $context
     * @return array{accepted: array<string, mixed>|null, reason: string|null}
     */
    private function acceptPlacement(array $proposal, string $path, array $context, $placements): array
    {
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

        try {
            $this->assertUniqueReferences($item['evidence_references']);
            $this->assertEvidenceReferences($item['evidence_references'], $context['evidence']);
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
     * @return array{accepted: array<string, mixed>|null, reason: string|null}
     */
    private function acceptOption(array $option, string $path, $placements, array $evidence): array
    {
        $option = $this->remapSubjectRefsToPlacements($option, $placements);

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
                'item.connections' => ['required', 'array', 'min:3', 'max:40'],
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
            return ['accepted' => null, 'reason' => $this->prefixedFailure($path, $validator)];
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
        $connectionTypes = collect($connections)->map(
            static fn (array $connection): string => is_string($connection['type'] ?? null)
                ? $connection['type']
                : (string) ($connection['type'] ?? ''),
        );

        foreach (AircoConnectionType::cases() as $type) {
            if (! $connectionTypes->contains($type->value)) {
                return [
                    'accepted' => null,
                    'reason' => 'Iedere AI-optie moet koel-, condens- en stroomverbindingen bevatten.',
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

            try {
                $this->assertUniqueReferences($connection['evidence_references']);
                $this->assertEvidenceReferences($connection['evidence_references'], $evidence);
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
     * @param  array<string, mixed>  $exception
     * @param  list<string>  $evidence
     * @return array{accepted: array<string, mixed>|null, reason: string|null}
     */
    private function acceptException(array $exception, string $path, array $evidence): array
    {
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

        try {
            $this->assertUniqueReferences($item['evidence_references']);
            $this->assertEvidenceReferences($item['evidence_references'], $evidence);
        } catch (ValidationException $e) {
            return ['accepted' => null, 'reason' => $this->failureFormatter->fromException($e)];
        }

        return ['accepted' => $item, 'reason' => null];
    }

    /**
     * @param  array<string, mixed>  $task
     * @param  list<string>  $subjects
     * @param  list<string>  $evidence
     * @return array{accepted: array<string, mixed>|null, reason: string|null}
     */
    private function acceptCustomerTask(array $task, string $path, array $subjects, array $evidence): array
    {
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

        try {
            $this->assertUniqueReferences($item['evidence_references']);
            $this->assertEvidenceReferences($item['evidence_references'], $evidence);
        } catch (ValidationException $e) {
            return ['accepted' => null, 'reason' => $this->failureFormatter->fromException($e)];
        }

        return ['accepted' => $item, 'reason' => null];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     rooms: Collection<string, array<string, mixed>>,
     *     subjects: list<string>,
     *     evidence: list<string>,
     *     placements: Collection<string, array<string, mixed>>
     * }
     */
    private function buildReferenceContext(array $input): array
    {
        $placements = collect($this->arrayRows($input['placements'] ?? null))->keyBy('reference');
        $rooms = collect($this->arrayRows($input['rooms'] ?? null))->keyBy('reference');
        $subjects = $placements
            ->pluck('subject_reference')
            ->merge(collect($this->arrayRows($input['subjects'] ?? null))->pluck('reference'))
            ->merge($rooms->pluck('subject_reference'))
            ->merge(collect($this->arrayRows($input['dossier_records'] ?? null))->pluck('subject_reference'))
            ->filter(static fn (mixed $reference): bool => is_string($reference))
            ->unique()
            ->values()
            ->all();

        return [
            'rooms' => $rooms,
            'subjects' => $subjects,
            'evidence' => $this->allReferences($input),
            'placements' => $placements,
        ];
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
     */
    private function assertEvidenceReferences(array $references, array $available): void
    {
        foreach ($references as $reference) {
            if (! in_array($reference, $available, true)) {
                throw ValidationException::withMessages([
                    'evidence_references' => 'AI-bewijs verwijst niet naar de verzonden dossiercontext.',
                ]);
            }
        }
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
