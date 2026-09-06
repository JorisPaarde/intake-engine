<?php

declare(strict_types=1);

namespace App\Domains\AI\Actions;

use App\Domains\AI\DTOs\RequestPrefillCandidate;
use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiGateway;
use App\Domains\AI\Services\PromptVersionRepository;
use App\Domains\AI\Services\RequestPrefillContextBuilder;
use App\Domains\AI\Services\RequestPrefillOutcomeClassifier;
use App\Domains\AI\Services\TemplateQuestionCatalogBuilder;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Support\RoomAreaAcceptance;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\IntakeStatus;
use App\Enums\QuestionType;
use Illuminate\Support\Str;
use Throwable;

/**
 * Vult templatevragen vanuit bekende context via AI die de volledige vraagenset kent (ADR-0013).
 */
final class PrefillAnswersFromKnownContext
{
    public const SOURCE_DERIVED = 'ai';

    public const SOURCE_SUGGESTED = 'ai_suggestion';

    public function __construct(
        private readonly AiGateway $aiGateway,
        private readonly PromptVersionRepository $promptVersions,
        private readonly TemplateQuestionCatalogBuilder $catalogBuilder,
        private readonly RequestPrefillContextBuilder $contextBuilder,
        private readonly RequestPrefillOutcomeClassifier $classifier,
        private readonly SaveIntakeAnswer $saveIntakeAnswer,
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

        try {
            $result = $this->aiGateway->complete(
                prompt: $promptBody,
                input: $input,
                promptVersion: $promptVersion,
            );

            $classified = $this->classifier->classifyCatalogOutput(
                $result->output,
                $catalog,
                $this->photoKeys($intake),
            );
            $output = [
                'evidence' => $classified['evidence'],
                'fills' => $classified['fills'],
            ];
            $applied = $this->apply($intake, $output, $classified['candidates']);

            $run->update($run->completionResultAttributes($result) + [
                'status' => AiRunStatus::Succeeded,
                'output' => $output + ['applied_question_keys' => $applied],
                'error_message' => null,
                'finished_at' => now(),
            ]);

            $run = $run->fresh() ?? $run;

            IntakeActivityEvent::query()->create([
                'intake_id' => $intake->id,
                'actor_type' => 'system',
                'actor_id' => null,
                'event' => 'request_prefill_derived',
                'properties' => [
                    'ai_run_id' => $run->id,
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

        $this->pruneExtraPrefillRooms($intake, $output['fills']);

        return $applied;
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
     * @param  list<array<string, mixed>>  $fills
     */
    private function pruneExtraPrefillRooms(Intake $intake, array $fills): void
    {
        $roomCount = null;

        foreach ($fills as $fill) {
            if (($fill['question_key'] ?? null) !== 'indoor_unit_count') {
                continue;
            }

            $value = $fill['value'] ?? null;
            $number = is_array($value) ? ($value['number'] ?? null) : null;

            if (is_numeric($number) && (int) $number >= 1) {
                $roomCount = (int) $number;
            }
        }

        if ($roomCount === null) {
            return;
        }

        $answers = IntakeAnswer::query()
            ->where('intake_id', $intake->id)
            ->whereIn('prefill_source', [
                self::SOURCE_DERIVED,
                self::SOURCE_SUGGESTED,
                DeriveIntentFromRequest::SOURCE_REQUEST_TEXT,
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
            DeriveIntentFromRequest::SOURCE_REQUEST_TEXT,
        ], true);
    }
}
