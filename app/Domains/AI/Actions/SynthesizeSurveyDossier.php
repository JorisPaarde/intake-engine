<?php

declare(strict_types=1);

namespace App\Domains\AI\Actions;

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiGateway;
use App\Domains\AI\Services\AiImageResolver;
use App\Domains\AI\Services\PromptVersionRepository;
use App\Domains\AI\Services\SurveySynthesisContextBuilder;
use App\Domains\Intake\Models\AircoConnection;
use App\Domains\Intake\Models\AircoInstallationOption;
use App\Domains\Intake\Models\AircoPlacementOption;
use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\DossierSubject;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\DecisionReadinessService;
use App\Domains\Intake\Services\DossierManager;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoConnectionType;
use App\Enums\AircoOptionStatus;
use App\Enums\AircoPlacementType;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\ContributionAudience;
use App\Enums\ContributionTaskStatus;
use App\Enums\DossierRecordKind;
use App\Enums\DossierRecordStatus;
use App\Enums\FollowUpItemType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Produces non-binding, evidence-bound dossier proposals.
 *
 * Candidate options and customer tasks are stored as proposals only. No connection
 * becomes approved and no customer link is activated by this action.
 */
final class SynthesizeSurveyDossier
{
    public function __construct(
        private readonly AiGateway $aiGateway,
        private readonly AiImageResolver $aiImageResolver,
        private readonly PromptVersionRepository $promptVersions,
        private readonly SurveySynthesisContextBuilder $contextBuilder,
        private readonly DossierManager $dossierManager,
        private readonly DecisionReadinessService $decisionReadiness,
    ) {}

    public function handle(Intake $intake): ?AiRun
    {
        if (! (bool) config('ai.dossier.enabled', false)) {
            return null;
        }

        $run = null;

        try {
            $promptName = (string) config('ai.dossier.prompt', 'dossier_synthesis');
            $promptVersion = $this->promptVersions->version($promptName);
            $promptBody = $this->promptVersions->body($promptName);
            $model = (string) config('ai.dossier.model', 'gpt-5.6-terra');
            $input = $this->contextBuilder->build($intake);
            $imageUploads = $this->imageUploads($intake);
            $input['image_manifest'] = $this->imageManifest($imageUploads);
            $inputHash = $this->hash($input, $promptVersion, $model);

            $run = AiRun::query()->create([
                'intake_id' => $intake->id,
                'type' => AiRunType::DossierSynthesis,
                'provider' => (string) config('ai.provider', 'null'),
                'model' => $model,
                'prompt_version' => $promptVersion,
                'input_hash' => $inputHash,
                'output' => null,
                'status' => AiRunStatus::Pending,
                'started_at' => now(),
            ]);

            $result = $this->aiGateway->complete(
                prompt: $promptBody,
                input: $input,
                promptVersion: $promptVersion,
                images: $imageUploads
                    ->map(fn (IntakeUpload $upload) => $this->aiImageResolver->input($upload))
                    ->values()
                    ->all(),
                model: $model,
            );
            $output = $this->validateOutput($result->output, $input);

            DB::transaction(function () use (
                $intake,
                $run,
                $result,
                $output,
                $inputHash,
                $promptVersion,
                $model,
            ): void {
                $locked = Intake::query()->whereKey($intake->id)->lockForUpdate()->firstOrFail();
                $currentInput = $this->contextBuilder->build($locked);
                $currentInput['image_manifest'] = $this->imageManifest($this->imageUploads($locked));

                if (! hash_equals($inputHash, $this->hash($currentInput, $promptVersion, $model))) {
                    throw new RuntimeException('Opnamedossier gewijzigd tijdens AI-synthese; resultaat niet toegepast.');
                }

                $this->replaceProposals($locked, $run, $output);
                $this->decisionReadiness->recalculate($locked);

                $run->update($run->completionResultAttributes($result, $model) + [
                    'status' => AiRunStatus::Succeeded,
                    'output' => $output,
                    'error_message' => null,
                    'finished_at' => now(),
                ]);
            }, 3);

            return $run->fresh() ?? $run;
        } catch (Throwable $exception) {
            Log::warning('AI dossier synthesis failed', [
                'intake_id' => $intake->id,
                'ai_run_id' => $run?->id,
                'exception' => $exception::class,
            ]);

            if ($run !== null) {
                $run->update([
                    'status' => AiRunStatus::Failed,
                    'error_message' => Str::limit($exception->getMessage(), 1000, ''),
                    'finished_at' => now(),
                ]);

                return $run->fresh() ?? $run;
            }

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validateOutput(array $output, array $input): array
    {
        $validator = Validator::make($output, [
            'summary' => ['required', 'string', 'max:800'],
            'placement_proposals' => ['present', 'array', 'max:20'],
            'placement_proposals.*.key' => ['required', 'string', 'distinct', 'regex:/^proposal:[a-z0-9_]+$/'],
            'placement_proposals.*.type' => ['required', Rule::enum(AircoPlacementType::class)],
            'placement_proposals.*.label' => ['required', 'string', 'max:160'],
            'placement_proposals.*.description' => ['required', 'string', 'max:1500'],
            'placement_proposals.*.room_reference' => ['present', 'nullable', 'string', 'regex:/^room:\d+$/'],
            'placement_proposals.*.subject_reference' => ['required', 'string', 'regex:/^subject:\d+$/'],
            'placement_proposals.*.confidence' => ['required', 'numeric', 'between:0,1'],
            'placement_proposals.*.evidence_references' => ['required', 'array', 'min:1', 'max:20'],
            'placement_proposals.*.evidence_references.*' => ['required', 'string', 'max:160'],
            'option_proposals' => ['present', 'array', 'max:3'],
            'option_proposals.*.label' => ['required', 'string', 'max:160'],
            'option_proposals.*.configuration_type' => ['required', Rule::enum(AircoConfigurationType::class)],
            'option_proposals.*.summary' => ['required', 'string', 'max:2000'],
            'option_proposals.*.cost_impact' => ['required', 'in:low,medium,high,unknown'],
            'option_proposals.*.confidence' => ['required', 'numeric', 'between:0,1'],
            'option_proposals.*.placement_references' => ['required', 'array', 'min:2', 'max:20'],
            'option_proposals.*.placement_references.*' => ['required', 'string', 'regex:/^(placement:\d+|proposal:[a-z0-9_]+)$/'],
            'option_proposals.*.connections' => ['required', 'array', 'min:3', 'max:40'],
            'option_proposals.*.connections.*.type' => ['required', Rule::enum(AircoConnectionType::class)],
            'option_proposals.*.connections.*.label' => ['required', 'string', 'max:180'],
            'option_proposals.*.connections.*.from_placement_reference' => ['present', 'nullable', 'string', 'regex:/^(placement:\d+|proposal:[a-z0-9_]+)$/'],
            'option_proposals.*.connections.*.to_placement_reference' => ['present', 'nullable', 'string', 'regex:/^(placement:\d+|proposal:[a-z0-9_]+)$/'],
            'option_proposals.*.connections.*.status' => [
                'required',
                Rule::in([
                    AircoConnectionStatus::Proposed->value,
                    AircoConnectionStatus::NeedsEvidence->value,
                    AircoConnectionStatus::NotRemotelyResolvable->value,
                ]),
            ],
            'option_proposals.*.connections.*.length_class' => ['required', 'in:short,medium,long,unknown'],
            'option_proposals.*.connections.*.segments' => ['present', 'array', 'max:20'],
            'option_proposals.*.connections.*.segments.*' => ['string', 'max:200'],
            'option_proposals.*.connections.*.obstacles' => ['present', 'array', 'max:20'],
            'option_proposals.*.connections.*.obstacles.*' => ['string', 'max:200'],
            'option_proposals.*.connections.*.uncertainties' => ['present', 'array', 'max:20'],
            'option_proposals.*.connections.*.uncertainties.*' => ['string', 'max:200'],
            'option_proposals.*.connections.*.cost_impact' => ['required', 'in:low,medium,high,unknown'],
            'option_proposals.*.connections.*.confidence' => ['required', 'numeric', 'between:0,1'],
            'option_proposals.*.connections.*.evidence_references' => ['present', 'array', 'min:1', 'max:20'],
            'option_proposals.*.connections.*.evidence_references.*' => ['string', 'max:160'],
            'exceptions' => ['present', 'array', 'max:20'],
            'exceptions.*.code' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'exceptions.*.label' => ['required', 'string', 'max:500'],
            'exceptions.*.decision_area_key' => ['required', 'in:request,capacity,placement,refrigerant,condensate,power,cost_risks'],
            'exceptions.*.confidence' => ['required', 'in:low,medium,high'],
            'exceptions.*.evidence_references' => ['present', 'array', 'min:1', 'max:20'],
            'exceptions.*.evidence_references.*' => ['string', 'max:160'],
            'customer_tasks' => ['present', 'array', 'max:3'],
            'customer_tasks.*.type' => ['required', Rule::enum(FollowUpItemType::class)],
            'customer_tasks.*.prompt' => ['required', 'string', 'max:500'],
            'customer_tasks.*.decision_area_key' => ['required', 'in:request,capacity,placement,refrigerant,condensate,power,cost_risks'],
            'customer_tasks.*.subject_reference' => ['present', 'nullable', 'string', 'regex:/^subject:\d+$/'],
            'customer_tasks.*.reason' => ['required', 'string', 'max:500'],
            'customer_tasks.*.evidence_references' => ['present', 'array', 'max:20'],
            'customer_tasks.*.evidence_references.*' => ['string', 'max:160'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();
        $this->validateReferences($validated, $input);

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $input
     */
    private function validateReferences(array $output, array $input): void
    {
        $placements = collect($this->arrayRows($input['placements'] ?? null))
            ->keyBy('reference');
        $rooms = collect($this->arrayRows($input['rooms'] ?? null))
            ->keyBy('reference');
        $subjects = $placements
            ->pluck('subject_reference')
            ->merge(collect($this->arrayRows($input['subjects'] ?? null))->pluck('reference'))
            ->merge($rooms->pluck('subject_reference'))
            ->merge(collect($this->arrayRows($input['dossier_records'] ?? null))->pluck('subject_reference'))
            ->filter(static fn (mixed $reference): bool => is_string($reference))
            ->unique()
            ->values()
            ->all();
        $evidence = $this->allReferences($input);

        foreach ($output['placement_proposals'] as $proposal) {
            $room = $proposal['room_reference'] === null
                ? null
                : $rooms->get($proposal['room_reference']);

            if (! in_array($proposal['subject_reference'], $subjects, true)
                || ($proposal['room_reference'] !== null && ! is_array($room))
                || ($proposal['type'] === AircoPlacementType::IndoorUnit->value && ! is_array($room))
                || (is_array($room) && $room['subject_reference'] !== $proposal['subject_reference'])) {
                throw ValidationException::withMessages([
                    'placement_proposals' => 'Een AI-positie verwijst niet naar het bijbehorende dossieronderdeel of de gewenste ruimte.',
                ]);
            }

            $this->assertUniqueReferences($proposal['evidence_references']);
            $this->assertEvidenceReferences($proposal['evidence_references'], $evidence);
            $placements->put($proposal['key'], $proposal + [
                'reference' => $proposal['key'],
            ]);
        }

        foreach ($output['option_proposals'] as $option) {
            $optionReferences = $option['placement_references'];
            $this->assertUniqueReferences($optionReferences);
            $optionPlacements = $placements->only($optionReferences);
            $configuration = AircoConfigurationType::from($option['configuration_type']);
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
                throw ValidationException::withMessages([
                    'option_proposals' => 'Een AI-optie verwijst niet naar een geldige combinatie van onderbouwde binnen- en buitenposities.',
                ]);
            }

            $connections = $this->arrayRows($option['connections'] ?? null);
            $connectionTypes = collect($connections)->pluck('type');
            foreach (AircoConnectionType::cases() as $type) {
                if (! $connectionTypes->contains($type->value)) {
                    throw ValidationException::withMessages([
                        'option_proposals' => 'Iedere AI-optie moet koel-, condens- en stroomverbindingen bevatten.',
                    ]);
                }
            }

            foreach ([AircoConnectionType::Refrigerant, AircoConnectionType::Condensate] as $requiredType) {
                $connectionsForType = collect($connections)->where('type', $requiredType->value);
                $indoorReferences = $optionPlacements
                    ->where('type', AircoPlacementType::IndoorUnit->value)
                    ->keys();
                $coversEveryIndoorPlacement = $indoorReferences->every(
                    static fn (string $reference): bool => $connectionsForType->contains(
                        static fn (array $connection): bool => in_array(
                            $reference,
                            [
                                $connection['from_placement_reference'],
                                $connection['to_placement_reference'],
                            ],
                            true,
                        ),
                    ),
                );

                if (! $coversEveryIndoorPlacement) {
                    throw ValidationException::withMessages([
                        'option_proposals' => 'Iedere AI-binnenpositie moet een eigen koel- en condensverbinding hebben.',
                    ]);
                }
            }

            foreach ($connections as $connection) {
                foreach (['from_placement_reference', 'to_placement_reference'] as $key) {
                    $reference = $connection[$key];
                    if ($reference !== null && ! in_array($reference, $optionReferences, true)) {
                        throw ValidationException::withMessages([
                            'option_proposals' => 'Een AI-verbinding verwijst naar een positie buiten de voorgestelde optie.',
                        ]);
                    }
                }

                $this->assertUniqueReferences($connection['evidence_references']);
                $this->assertEvidenceReferences($connection['evidence_references'], $evidence);
            }
        }

        foreach ($output['exceptions'] as $exception) {
            $this->assertUniqueReferences($exception['evidence_references']);
            $this->assertEvidenceReferences($exception['evidence_references'], $evidence);
        }

        foreach ($output['customer_tasks'] as $task) {
            if ($task['subject_reference'] !== null
                && ! in_array($task['subject_reference'], $subjects, true)) {
                throw ValidationException::withMessages([
                    'customer_tasks' => 'Een AI-klanttaak verwijst naar een onbekend dossieronderdeel.',
                ]);
            }
            $this->assertUniqueReferences($task['evidence_references']);
            $this->assertEvidenceReferences($task['evidence_references'], $evidence);
        }
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

    /** @param array<string, mixed> $output */
    private function replaceProposals(Intake $intake, AiRun $run, array $output): void
    {
        $candidateOptionIds = AircoInstallationOption::query()
            ->where('intake_id', $intake->id)
            ->where('source_type', 'ai')
            ->where('status', AircoOptionStatus::Candidate)
            ->pluck('id');
        $obsoleteSubjectIds = AircoConnection::query()
            ->whereIn('airco_installation_option_id', $candidateOptionIds)
            ->pluck('dossier_subject_id');

        AircoInstallationOption::query()
            ->whereIn('id', $candidateOptionIds)
            ->delete();

        $obsoletePlacements = AircoPlacementOption::query()
            ->where('intake_id', $intake->id)
            ->where('source_type', 'ai')
            ->where('status', AircoOptionStatus::Candidate)
            ->whereDoesntHave('installationOptions')
            ->get(['id', 'dossier_subject_id']);
        $obsoleteSubjectIds = $obsoleteSubjectIds
            ->merge($obsoletePlacements->pluck('dossier_subject_id'))
            ->filter()
            ->unique()
            ->values();

        AircoPlacementOption::query()
            ->whereIn('id', $obsoletePlacements->pluck('id'))
            ->delete();
        DossierSubject::query()
            ->where('intake_id', $intake->id)
            ->whereIn('id', $obsoleteSubjectIds)
            ->delete();

        ContributionTask::query()
            ->where('intake_id', $intake->id)
            ->where('status', ContributionTaskStatus::Proposed)
            ->get()
            ->filter(static fn (ContributionTask $task): bool => ($task->meta['source_type'] ?? null) === 'ai')
            ->each(static fn (ContributionTask $task) => $task->update([
                'status' => ContributionTaskStatus::Cancelled,
            ]));

        $subjects = DossierSubject::query()
            ->where('intake_id', $intake->id)
            ->get()
            ->keyBy(static fn (DossierSubject $subject): string => 'subject:'.$subject->id);
        $rooms = AircoRoom::query()
            ->where('intake_id', $intake->id)
            ->get()
            ->keyBy(static fn (AircoRoom $room): string => 'room:'.$room->id);
        $root = $this->dossierManager->root($intake);
        $placements = AircoPlacementOption::query()
            ->where('intake_id', $intake->id)
            ->get()
            ->keyBy(static fn (AircoPlacementOption $placement): string => 'placement:'.$placement->id);

        foreach ($output['placement_proposals'] as $proposalIndex => $proposal) {
            /** @var DossierSubject $parent */
            $parent = $subjects->get($proposal['subject_reference']) ?? $root;
            /** @var AircoRoom|null $room */
            $room = $proposal['room_reference'] === null
                ? null
                : $rooms->get($proposal['room_reference']);
            $subject = $this->dossierManager->subject(
                $intake,
                'airco.placement.ai.'.$run->id.'.'.$proposalIndex,
                'airco_placement',
                trim($proposal['label']),
                $parent,
                [
                    'placement_type' => $proposal['type'],
                    'ai_run_id' => $run->id,
                    'evidence_references' => $proposal['evidence_references'],
                ],
            );
            $placement = AircoPlacementOption::query()->create([
                'intake_id' => $intake->id,
                'company_id' => $intake->company_id,
                'airco_room_id' => $room?->id,
                'dossier_subject_id' => $subject->id,
                'type' => $proposal['type'],
                'label' => trim($proposal['label']),
                'description' => trim($proposal['description']),
                'location_data' => [
                    'evidence_references' => $proposal['evidence_references'],
                ],
                'status' => AircoOptionStatus::Candidate,
                'source_type' => 'ai',
                'source_id' => $run->id,
                'confidence' => round((float) $proposal['confidence'], 3),
                'cost_risks' => null,
            ]);
            $placements->put($proposal['key'], $placement);
        }

        foreach ($output['option_proposals'] as $optionIndex => $proposal) {
            $option = AircoInstallationOption::query()->create([
                'intake_id' => $intake->id,
                'company_id' => $intake->company_id,
                'label' => trim($proposal['label']),
                'configuration_type' => $proposal['configuration_type'],
                'rank' => $optionIndex + 1,
                'status' => AircoOptionStatus::Candidate,
                'summary' => trim($proposal['summary']),
                'cost_impact' => $proposal['cost_impact'],
                'source_type' => 'ai',
                'source_id' => $run->id,
                'confidence' => round((float) $proposal['confidence'], 3),
                'created_by' => null,
            ]);

            foreach ($proposal['placement_references'] as $sortOrder => $reference) {
                /** @var AircoPlacementOption $placement */
                $placement = $placements->get($reference);
                $option->placements()->attach($placement->id, [
                    'role' => $placement->type->value,
                    'sort_order' => $sortOrder + 1,
                ]);
            }

            foreach ($proposal['connections'] as $connectionIndex => $proposalConnection) {
                $subject = $this->dossierManager->subject(
                    $intake,
                    'airco.connection.ai.'.$run->id.'.'.$optionIndex.'.'.$connectionIndex,
                    'airco_connection',
                    trim($proposalConnection['label']),
                    $root,
                    [
                        'connection_type' => $proposalConnection['type'],
                        'installation_option_id' => $option->id,
                        'ai_run_id' => $run->id,
                    ],
                );
                $from = $proposalConnection['from_placement_reference'] === null
                    ? null
                    : $placements->get($proposalConnection['from_placement_reference']);
                $to = $proposalConnection['to_placement_reference'] === null
                    ? null
                    : $placements->get($proposalConnection['to_placement_reference']);

                AircoConnection::query()->create([
                    'intake_id' => $intake->id,
                    'company_id' => $intake->company_id,
                    'airco_installation_option_id' => $option->id,
                    'from_placement_id' => $from?->id,
                    'to_placement_id' => $to?->id,
                    'dossier_subject_id' => $subject->id,
                    'type' => $proposalConnection['type'],
                    'label' => trim($proposalConnection['label']),
                    'status' => $proposalConnection['status'],
                    'length_class' => $proposalConnection['length_class'],
                    'segments' => $proposalConnection['segments'],
                    'obstacles' => $proposalConnection['obstacles'],
                    'uncertainties' => $proposalConnection['uncertainties'],
                    'cost_impact' => $proposalConnection['cost_impact'],
                    'confidence' => round((float) $proposalConnection['confidence'], 3),
                    'source_type' => 'ai',
                    'source_id' => $run->id,
                    'safety_check_required' => $proposalConnection['type'] === AircoConnectionType::Power->value,
                ]);
            }
        }

        foreach ($output['customer_tasks'] as $task) {
            /** @var DossierSubject|null $subject */
            $subject = $task['subject_reference'] === null ? null : $subjects->get($task['subject_reference']);
            ContributionTask::query()->create([
                'intake_id' => $intake->id,
                'company_id' => $intake->company_id,
                'dossier_subject_id' => $subject?->id,
                'intake_follow_up_item_id' => null,
                'audience' => ContributionAudience::Customer,
                'type' => $task['type'],
                'prompt' => trim($task['prompt']),
                'decision_area_key' => $task['decision_area_key'],
                'status' => ContributionTaskStatus::Proposed,
                'requested_by' => null,
                'meta' => [
                    'source_type' => 'ai',
                    'source_id' => $run->id,
                    'reason' => trim($task['reason']),
                    'evidence_references' => $task['evidence_references'],
                ],
            ]);
        }

        $this->dossierManager->record(
            intake: $intake,
            subject: $root,
            kind: DossierRecordKind::Conclusion,
            key: 'ai_dossier_synthesis',
            value: [
                'summary' => trim($output['summary']),
                'exceptions' => $output['exceptions'],
                'placement_count' => count($output['placement_proposals']),
                'option_count' => count($output['option_proposals']),
                'customer_task_count' => count($output['customer_tasks']),
            ],
            actorType: 'ai',
            actorId: null,
            sourceType: 'ai_run',
            sourceId: $run->id,
            method: 'evidence_synthesis',
            confidence: null,
            status: DossierRecordStatus::Proposed,
        );
    }

    /** @return Collection<int, IntakeUpload> */
    private function imageUploads(Intake $intake): Collection
    {
        $maximum = max(0, min(20, (int) config('ai.dossier.max_images', 12)));
        if ($maximum === 0) {
            return collect();
        }

        /** @var Collection<string, Collection<int, IntakeUpload>> $groups */
        $groups = IntakeUpload::query()
            ->where('intake_id', $intake->id)
            ->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/webp'])
            ->orderBy('id')
            ->get()
            ->groupBy(
                static fn (IntakeUpload $upload): string => $upload->question_key
                    .'|'.($upload->section_instance_key ?? 'survey'),
            );
        /** @var Collection<int, IntakeUpload> $selected */
        $selected = collect();

        // First take the newest image from every dossier part, then a second one.
        // This keeps a twelve-image budget representative across rooms and routes.
        for ($offset = 0; $offset < 2 && $selected->count() < $maximum; $offset++) {
            foreach ($groups as $uploads) {
                $candidate = $uploads->reverse()->values()->get($offset);

                if ($candidate instanceof IntakeUpload) {
                    $selected->push($candidate);
                }

                if ($selected->count() >= $maximum) {
                    break;
                }
            }
        }

        return $selected->values();
    }

    /**
     * @param  Collection<int, IntakeUpload>  $uploads
     * @return list<array<string, mixed>>
     */
    private function imageManifest(Collection $uploads): array
    {
        return $uploads
            ->map(fn (IntakeUpload $upload): array => [
                'reference' => 'dossier_image:'.$upload->id,
                'question_key' => $upload->question_key,
                'section_instance_key' => $upload->section_instance_key,
                'follow_up_item_reference' => $upload->intake_follow_up_item_id === null
                    ? null
                    : 'follow_up_item:'.$upload->intake_follow_up_item_id,
                'sort_order' => $upload->sort_order,
                'image_identity' => $this->aiImageResolver->identity($upload),
            ])
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $input */
    private function hash(array $input, string $promptVersion, string $model): string
    {
        return hash('sha256', (string) json_encode([
            'prompt_version' => $promptVersion,
            'model' => $model,
            'input' => $input,
        ], JSON_THROW_ON_ERROR));
    }
}
