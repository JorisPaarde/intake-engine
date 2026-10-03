<?php

declare(strict_types=1);

namespace App\Domains\AI\Actions;

use App\Domains\AI\DTOs\RequestPrefillCandidate;
use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiGateway;
use App\Domains\AI\Services\AiTraceRecorder;
use App\Domains\AI\Services\AiTraceSnapshotService;
use App\Domains\AI\Services\PromptVersionRepository;
use App\Domains\AI\Services\RequestPrefillContextBuilder;
use App\Domains\AI\Services\RequestPrefillOutcomeClassifier;
use App\Domains\AI\Services\TemplateQuestionCatalogBuilder;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Support\PrefillSources;
use App\Domains\Intake\Support\RoomAreaAcceptance;
use App\Domains\Intake\Support\TechnicalDecisionKeys;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\AiTraceCallType;
use App\Enums\IntakeStatus;
use App\Enums\QuestionType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Vult templatevragen vanuit bekende context via AI die de volledige vraagenset kent (ADR-0013).
 */
final class PrefillAnswersFromKnownContext
{
    public const SOURCE_DERIVED = PrefillSources::AI_TEXT;

    public const SOURCE_SUGGESTED = PrefillSources::AI_TEXT_SUGGESTION;

    public function __construct(
        private readonly AiGateway $aiGateway,
        private readonly PromptVersionRepository $promptVersions,
        private readonly TemplateQuestionCatalogBuilder $catalogBuilder,
        private readonly RequestPrefillContextBuilder $contextBuilder,
        private readonly RequestPrefillOutcomeClassifier $classifier,
        private readonly SaveIntakeAnswer $saveIntakeAnswer,
        private readonly AiTraceRecorder $traceRecorder,
        private readonly AiTraceSnapshotService $traceSnapshots,
    ) {}

    public function handle(Intake $intake): ?AiRun
    {
        if (! in_array($intake->status, [
            IntakeStatus::Draft,
            IntakeStatus::Sent,
            IntakeStatus::InProgress,
        ], true)) {
            return null;
        }

        if (! (bool) config('ai.text_inference.enabled', false)) {
            return null;
        }

        $catalog = $this->catalogBuilder->build($intake);
        $context = $this->contextBuilder->build($intake);
        $reason = $context['request_reason'];

        if (! is_string($reason) || mb_strlen(trim($reason)) < 10) {
            return null;
        }

        $promptName = (string) config('ai.request_prefill_prompt', 'request_prefill');
        $promptVersion = $this->promptVersions->version($promptName);
        $promptBody = $this->promptVersions->body($promptName);

        $input = [
            'task' => 'prefill_from_known_context',
            'known_context' => $context,
            'question_catalog' => $catalog,
        ];
        $inputHash = hash('sha256', (string) json_encode([
            'prompt_version' => $promptVersion,
            'input' => $input,
        ], JSON_THROW_ON_ERROR));

        $existing = AiRun::query()
            ->where('intake_id', $intake->id)
            ->where('type', AiRunType::RequestIntent)
            ->where('input_hash', $inputHash)
            ->where('status', AiRunStatus::Succeeded)
            ->latest('id')
            ->first();

        if ($existing instanceof AiRun) {
            return $existing;
        }

        $run = AiRun::query()->create([
            'intake_id' => $intake->id,
            'type' => AiRunType::RequestIntent,
            'provider' => (string) config('ai.provider', 'null'),
            'model' => null,
            'prompt_version' => $promptVersion,
            'input_hash' => $inputHash,
            'output' => null,
            'status' => AiRunStatus::Pending,
            'started_at' => now(),
        ]);

        $trace = $this->traceRecorder->start($intake, AiTraceCallType::TextExtraction, [
            'ai_run_id' => $run->id,
            'provider' => (string) config('ai.provider', 'null'),
            'prompt_version' => $promptVersion,
        ]);
        $dossierBefore = [];
        $questionsBefore = [];
        if (! $trace->isNoop()) {
            $dossierBefore = $this->traceSnapshots->answers($intake);
            $questionsBefore = $this->traceSnapshots->remainingQuestions($intake);
        }

        try {
            $trace->recordRequest(
                systemAndUser: [
                    'system' => $promptBody,
                    'user' => $input,
                    'prompt_version' => $promptVersion,
                ],
                promptVersion: $promptVersion,
            );

            $result = $this->aiGateway->complete(
                prompt: $promptBody,
                input: $input,
                promptVersion: $promptVersion,
            );
            $trace->recordProviderResult($result);

            $classified = $this->classifier->classifyCatalogOutput(
                $result->output,
                $catalog,
                $this->photoKeys($intake),
            );
            $output = [
                'evidence' => $classified['evidence'],
                'fills' => $classified['fills'],
            ];
            $trace->recordParsed($output, [], $classified['normalizations']);
            $trace->step('normalize', [
                'candidate_count' => count($classified['candidates']),
                'normalization_count' => count($classified['normalizations']),
            ]);

            try {
                $applied = DB::transaction(function () use ($intake, $output, $classified, $trace): array {
                    $trace->beginBuffer();
                    Intake::query()->whereKey($intake->id)->lockForUpdate()->firstOrFail();
                    $applied = $this->apply($intake, $output, $classified['candidates']);
                    $trace->step('apply', ['applied_question_keys' => $applied]);
                    $trace->recordFieldOutcomes(array_map(
                        static fn (RequestPrefillCandidate $candidate): array => [
                            'question_key' => $candidate->questionKey,
                            'section_instance_key' => $candidate->sectionInstanceKey,
                            'disposition' => $candidate->disposition,
                            'confidence' => $candidate->confidence,
                            'source' => $candidate->source,
                            'reason' => $candidate->reason,
                            'has_value' => $candidate->value !== null,
                        ],
                        $classified['candidates'],
                    ));

                    return $applied;
                }, 3);
                $trace->flushBuffer();
            } catch (Throwable $transactionException) {
                $trace->discardBuffer();
                throw $transactionException;
            }

            $run->update($run->completionResultAttributes($result) + [
                'status' => AiRunStatus::Succeeded,
                'output' => $output + ['applied_question_keys' => $applied],
                'error_message' => null,
                'finished_at' => now(),
            ]);

            $run = $run->fresh() ?? $run;
            $trace->linkAiRun($run);
            $trace->stopProcessTimer();
            if (! $trace->isNoop()) {
                $freshIntake = $intake->fresh() ?? $intake;
                $dossierAfter = $this->traceSnapshots->answers($freshIntake);
                $trace->recordDossierSnapshots(
                    $dossierBefore,
                    $dossierAfter,
                    $this->traceSnapshots->changedFields($dossierBefore, $dossierAfter),
                );
                $trace->recordRemainingQuestions(
                    $questionsBefore,
                    $this->traceSnapshots->remainingQuestions($freshIntake),
                );
            }
            $trace->succeed();

            IntakeActivityEvent::query()->create([
                'intake_id' => $intake->id,
                'actor_type' => 'system',
                'actor_id' => null,
                'event' => 'request_prefill_derived',
                'properties' => [
                    'ai_run_id' => $run->id,
                    'ai_trace_id' => $trace->traceId(),
                    'provider' => $run->provider,
                    'prompt_version' => $promptVersion,
                    'question_keys' => $applied,
                ],
                'created_at' => now(),
            ]);

            return $run;
        } catch (Throwable $exception) {
            $run->update([
                'status' => AiRunStatus::Failed,
                'error_message' => Str::limit($exception->getMessage(), 1000, ''),
                'finished_at' => now(),
            ]);
            $trace->linkAiRun($run->fresh() ?? $run);
            $trace->discardBuffer();
            $trace->fail($exception->getMessage(), $exception);

            return $run->fresh() ?? $run;
        }
    }

    /**
     * @param  array{evidence: string, fills: list<array<string, mixed>>}  $output
     * @param  list<RequestPrefillCandidate>  $candidates
     * @return list<string>
     */
    private function apply(Intake $intake, array $output, array $candidates): array
    {
        $applied = [];

        foreach ($candidates as $candidate) {
            if (! in_array($candidate->disposition, [
                RequestPrefillCandidate::DISPOSITION_FILL,
                RequestPrefillCandidate::DISPOSITION_SUGGESTION,
            ], true)) {
                continue;
            }

            if ($candidate->value === null) {
                continue;
            }

            if (TechnicalDecisionKeys::contains($candidate->questionKey)) {
                continue;
            }

            if (! $this->mayWrite($intake, $candidate->questionKey, $candidate->sectionInstanceKey)) {
                continue;
            }

            $source = $candidate->disposition === RequestPrefillCandidate::DISPOSITION_FILL
                ? self::SOURCE_DERIVED
                : self::SOURCE_SUGGESTED;

            if ($candidate->questionKey === 'room_area_m2') {
                $number = $candidate->value['number'] ?? null;
                $area = is_numeric($number) ? (float) $number : null;
                $evidence = is_string($candidate->evidence) && trim($candidate->evidence) !== ''
                    ? trim($candidate->evidence)
                    : null;
                $runEvidence = trim($output['evidence']);
                $effectiveEvidence = $evidence ?? ($runEvidence !== '' ? $runEvidence : null);

                if (! RoomAreaAcceptance::acceptsAiExactArea($candidate->confidence, $effectiveEvidence, $area)) {
                    // Keep as reviewable suggestion; never invent L×B and never trust weak m².
                    $source = self::SOURCE_SUGGESTED;
                }
            }

            $this->saveIntakeAnswer->handle(
                $intake,
                $candidate->questionKey,
                $candidate->sectionInstanceKey,
                $candidate->value,
                $source,
            );

            $applied[] = $candidate->compositeKey();
        }

        $this->deriveAreaFromLengthWidth($intake);
        $this->pruneExtraPrefillRooms($intake, $applied);

        return $applied;
    }

    /**
     * Herberekent derived_lxw wanneer L of W wijzigt (ook buiten catalogus-prefill).
     */
    public function recalculateDerivedDimensions(Intake $intake): void
    {
        $this->deriveAreaFromLengthWidth($intake);
    }

    /**
     * Bekende L×B → sla afgeleid m² op met bron `derived_lxw`; herberekent bij L/W-wijziging.
     */
    private function deriveAreaFromLengthWidth(Intake $intake): void
    {
        $intake->loadMissing('answers');
        $byInstance = [];

        foreach ($intake->answers as $answer) {
            if (! in_array($answer->question_key, ['room_length_m', 'room_width_m'], true)) {
                continue;
            }
            // Tekst-/AI-L×B, suggesties, of menselijke L×B (herberekening).
            $dimSource = $answer->prefill_source;
            $usableDim = $dimSource === null
                || $dimSource === 'installer'
                || PrefillSources::isTextDerived($dimSource)
                || PrefillSources::isTextSuggestion($dimSource);
            if (! $usableDim) {
                continue;
            }
            $instance = $answer->section_instance_key;
            if (! is_string($instance) || $instance === '') {
                continue;
            }
            $number = is_array($answer->value) ? ($answer->value['number'] ?? null) : null;
            if (! is_numeric($number)) {
                continue;
            }
            $byInstance[$instance][$answer->question_key] = [
                'number' => (float) $number,
                'source' => $dimSource,
            ];
        }

        foreach ($byInstance as $instanceKey => $dims) {
            if (! isset($dims['room_length_m'], $dims['room_width_m'])) {
                continue;
            }

            $area = round($dims['room_length_m']['number'] * $dims['room_width_m']['number'], 2);
            if (! RoomAreaAcceptance::isPlausibleArea($area)) {
                continue;
            }

            // Suggestie-L/W → suggestie-afleiding; anders derived_lxw.
            $fromSuggestion = PrefillSources::isTextSuggestion($dims['room_length_m']['source'])
                || PrefillSources::isTextSuggestion($dims['room_width_m']['source']);
            $source = $fromSuggestion
                ? PrefillSources::AI_TEXT_SUGGESTION
                : PrefillSources::DERIVED_LXW;

            $existingArea = $intake->answers->first(
                static fn ($answer): bool => $answer->question_key === 'room_area_m2'
                    && $answer->section_instance_key === $instanceKey,
            );

            // Alleen derived_lxw / suggestie herberekeken; exacte m² (klant/AI/installer) behouden.
            if ($existingArea !== null
                && ! in_array($existingArea->prefill_source, [
                    PrefillSources::DERIVED_LXW,
                    PrefillSources::AI_TEXT_SUGGESTION,
                    PrefillSources::AI_SUGGESTION_LEGACY,
                ], true)) {
                continue;
            }

            $this->saveIntakeAnswer->handle(
                $intake,
                'room_area_m2',
                $instanceKey,
                ['number' => $area],
                $source,
            );

            if ($this->mayWrite($intake, 'room_size_indication', $instanceKey)
                || $this->mayOverwriteDerivedSize($intake, $instanceKey)) {
                $size = RoomAreaAcceptance::sizeIndicationFromArea($area);
                $this->saveIntakeAnswer->handle(
                    $intake,
                    'room_size_indication',
                    $instanceKey,
                    ['value' => $size],
                    $source,
                );
            }
        }
    }

    private function mayOverwriteDerivedSize(Intake $intake, string $instanceKey): bool
    {
        $existing = IntakeAnswer::query()
            ->where('intake_id', $intake->id)
            ->where('question_key', 'room_size_indication')
            ->where('section_instance_key', $instanceKey)
            ->first();

        if (! $existing instanceof IntakeAnswer) {
            return true;
        }

        return in_array($existing->prefill_source, [
            PrefillSources::DERIVED_LXW,
            PrefillSources::AI_TEXT_SUGGESTION,
            PrefillSources::AI_SUGGESTION_LEGACY,
            PrefillSources::AI_TEXT,
            PrefillSources::AI_LEGACY,
        ], true);
    }

    /**
     * @return list<string>
     */
    private function photoKeys(Intake $intake): array
    {
        $intake->loadMissing('templateVersion.sections.questions');
        $keys = [];

        foreach ($intake->templateVersion->sections as $section) {
            foreach ($section->questions as $question) {
                if ($question->type === QuestionType::Photo) {
                    $keys[] = $question->key;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  list<string>  $appliedComposites
     */
    private function pruneExtraPrefillRooms(Intake $intake, array $appliedComposites): void
    {
        // Alleen snoeien wanneer indoor_unit_count echt is toegepast.
        $countApplied = false;
        $roomCount = null;
        foreach ($appliedComposites as $composite) {
            if ($composite === 'indoor_unit_count' || str_starts_with($composite, 'indoor_unit_count__')) {
                $countApplied = true;
                break;
            }
        }

        if (! $countApplied) {
            return;
        }

        $countAnswer = IntakeAnswer::query()
            ->where('intake_id', $intake->id)
            ->where('question_key', 'indoor_unit_count')
            ->whereNull('section_instance_key')
            ->first();
        $number = is_array($countAnswer?->value) ? ($countAnswer->value['number'] ?? null) : null;
        if (! is_numeric($number) || (int) $number < 1) {
            return;
        }

        $roomCount = (int) $number;

        $answers = IntakeAnswer::query()
            ->where('intake_id', $intake->id)
            ->whereIn('prefill_source', [
                PrefillSources::AI_TEXT,
                PrefillSources::AI_TEXT_SUGGESTION,
                PrefillSources::REQUEST_TEXT,
            ])
            ->whereNotNull('section_instance_key')
            ->get();

        foreach ($answers as $answer) {
            $instanceKey = $answer->section_instance_key;

            if (! is_string($instanceKey) || preg_match('/^room-(\d+)$/', $instanceKey, $matches) !== 1) {
                continue;
            }

            if ((int) $matches[1] <= $roomCount) {
                continue;
            }

            $answer->delete();
        }
    }

    private function mayWrite(Intake $intake, string $questionKey, ?string $sectionInstanceKey): bool
    {
        $existing = IntakeAnswer::query()
            ->where('intake_id', $intake->id)
            ->where('question_key', $questionKey)
            ->when(
                $sectionInstanceKey === null,
                static fn ($query) => $query->whereNull('section_instance_key'),
                static fn ($query) => $query->where('section_instance_key', $sectionInstanceKey),
            )
            ->first();

        if (! $existing instanceof IntakeAnswer) {
            return true;
        }

        return in_array($existing->prefill_source, [
            self::SOURCE_DERIVED,
            self::SOURCE_SUGGESTED,
            PrefillSources::AI_LEGACY,
            PrefillSources::AI_SUGGESTION_LEGACY,
            PrefillSources::DERIVED_LXW,
            DeriveIntentFromRequest::SOURCE_REQUEST_TEXT,
        ], true);
    }
}
