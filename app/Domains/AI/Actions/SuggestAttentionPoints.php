<?php

declare(strict_types=1);

namespace App\Domains\AI\Actions;

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiGateway;
use App\Domains\AI\Services\IntakeAttentionContextBuilder;
use App\Domains\AI\Services\PromptVersionRepository;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAttentionPoint;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\AttentionPointSource;
use App\Enums\AttentionPointStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Proposes non-binding attention points for an intake (BL-007). AI is supporting,
 * never source of truth (ADR-0005): points land as `proposed` and the installer
 * accepts or dismisses them. Soft-fail: any failure leaves existing points
 * untouched; an already-created run is marked failed.
 */
final class SuggestAttentionPoints
{
    public function __construct(
        private readonly AiGateway $aiGateway,
        private readonly PromptVersionRepository $promptVersions,
        private readonly IntakeAttentionContextBuilder $contextBuilder,
    ) {}

    public function handle(Intake $intake): ?AiRun
    {
        $run = null;

        try {
            $promptName = (string) config('ai.attention_points_prompt', 'attention_points');
            $promptVersion = $this->promptVersions->version($promptName);
            $promptBody = $this->promptVersions->body($promptName);
            $provider = (string) config('ai.provider', 'null');
            $payload = $this->contextBuilder->build($intake);
            $inputHash = $this->payloadHash($payload);

            $run = AiRun::query()->create([
                'intake_id' => $intake->id,
                'type' => AiRunType::AttentionPoints,
                'provider' => $provider,
                'model' => null,
                'prompt_version' => $promptVersion,
                'input_hash' => $inputHash,
                'output' => null,
                'status' => AiRunStatus::Pending,
                'started_at' => now(),
            ]);

            $result = $this->aiGateway->complete(
                prompt: $promptBody,
                input: $payload,
                promptVersion: $promptVersion,
            );

            $points = $this->validateOutput($result->output, $payload);
            $completionAttributes = $run->completionResultAttributes($result) + [
                'status' => AiRunStatus::Succeeded,
                'output' => ['points' => $points],
                'finished_at' => now(),
                'error_message' => null,
            ];
            $this->persistProposals($intake, $points, $inputHash, $run, $completionAttributes);

            return $run->fresh() ?? $run;
        } catch (\Throwable $e) {
            Log::warning('AI attention points failed', [
                'intake_id' => $intake->id,
                'ai_run_id' => $run?->id,
                'message' => $e->getMessage(),
            ]);

            if ($run !== null) {
                $run->update([
                    'status' => AiRunStatus::Failed,
                    'error_message' => Str::limit($e->getMessage(), 1000, ''),
                    'finished_at' => now(),
                ]);

                return $run->fresh() ?? $run;
            }

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $payload
     * @return list<array{code: string, label: string, confidence: string|null, evidence: list<array{source_type: string, reference: string}>}>
     */
    private function validateOutput(array $output, array $payload): array
    {
        $validator = Validator::make($output, [
            'points' => ['present', 'array', 'max:20'],
            'points.*.code' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'points.*.label' => ['required', 'string', 'max:500'],
            'points.*.confidence' => ['required', 'string', 'in:low,medium,high'],
            'points.*.evidence' => ['required', 'array', 'min:1', 'max:10'],
            'points.*.evidence.*.source_type' => ['required', 'string', 'in:answer,external_fact,upload,follow_up,installer_review,pipe_route,system_attention_point'],
            'points.*.evidence.*.reference' => ['required', 'string', 'max:100'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        /** @var array{points: list<array{code: string, label: string, confidence: string, evidence: list<array{source_type: string, reference: string}>}>} $validated */
        $validated = $validator->validated();
        $availableEvidence = $this->availableEvidenceReferences($payload);

        $points = [];
        $seen = [];
        foreach ($validated['points'] as $point) {
            $code = $point['code'];
            if (isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;

            foreach ($point['evidence'] as $evidence) {
                if (! in_array($evidence['reference'], $availableEvidence[$evidence['source_type']] ?? [], true)) {
                    throw ValidationException::withMessages([
                        'points' => 'AI-bewijs verwijst niet naar de verzonden dossiercontext.',
                    ]);
                }
            }

            $points[] = [
                'code' => $code,
                'label' => trim($point['label']),
                'confidence' => $point['confidence'],
                'evidence' => $point['evidence'],
            ];
        }

        return $points;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, list<string>>
     */
    private function availableEvidenceReferences(array $payload): array
    {
        $references = [
            'answer' => [],
            'external_fact' => [],
            'upload' => [],
            'follow_up' => [],
            'installer_review' => [],
            'pipe_route' => [],
            'system_attention_point' => [],
        ];

        foreach (is_array($payload['answer_context'] ?? null) ? $payload['answer_context'] : [] as $answer) {
            $this->addEvidenceReference($references['answer'], $answer, 'reference');
        }

        foreach (is_array($payload['external_fact_context'] ?? null) ? $payload['external_fact_context'] : [] as $fact) {
            $this->addEvidenceReference($references['external_fact'], $fact, 'reference');
        }

        foreach (is_array($payload['uploads'] ?? null) ? $payload['uploads'] : [] as $upload) {
            $this->addEvidenceReference($references['upload'], $upload, 'reference');
        }

        foreach (is_array($payload['follow_up'] ?? null) ? $payload['follow_up'] : [] as $round) {
            foreach (is_array($round) && is_array($round['items'] ?? null) ? $round['items'] : [] as $item) {
                $this->addEvidenceReference($references['follow_up'], $item, 'reference');
            }
        }

        if (is_array($payload['installer_review'] ?? null)) {
            $this->addEvidenceReference($references['installer_review'], $payload['installer_review'], 'reference');
        }

        foreach (is_array($payload['pipe_routes'] ?? null) ? $payload['pipe_routes'] : [] as $route) {
            $this->addEvidenceReference($references['pipe_route'], $route, 'reference');
        }

        foreach (is_array($payload['system_attention_points'] ?? null) ? $payload['system_attention_points'] : [] as $point) {
            $this->addEvidenceReference($references['system_attention_point'], $point, 'reference');
        }

        return array_map(
            static fn (array $values): array => array_values(array_unique($values)),
            $references,
        );
    }

    /** @param list<string> $references */
    private function addEvidenceReference(array &$references, mixed $context, string $key): void
    {
        if (is_array($context) && is_string($context[$key] ?? null) && $context[$key] !== '') {
            $references[] = $context[$key];
        }
    }

    /**
     * Idempotent on (intake, code): never duplicates, and respects a prior
     * accept/dismiss decision. Stale still-proposed points that no longer apply
     * are removed; accepted/dismissed ones are kept.
     *
     * @param  list<array{code: string, label: string, confidence: string|null, evidence: list<array{source_type: string, reference: string}>}>  $points
     * @param  array<string, mixed>  $completionAttributes
     */
    private function persistProposals(
        Intake $intake,
        array $points,
        string $inputHash,
        AiRun $run,
        array $completionAttributes,
    ): void {
        DB::transaction(function () use ($intake, $points, $inputHash, $run, $completionAttributes): void {
            $lockedIntake = Intake::query()->whereKey($intake->id)->lockForUpdate()->firstOrFail();

            if (! hash_equals($inputHash, $this->payloadHash($this->contextBuilder->build($lockedIntake)))) {
                throw new \RuntimeException('Intakecontext gewijzigd tijdens AI-analyse; resultaat niet toegepast.');
            }

            $codes = array_column($points, 'code');

            $intake->attentionPoints()
                ->where('source', AttentionPointSource::Ai)
                ->where('status', AttentionPointStatus::Proposed)
                ->when($codes !== [], fn ($query) => $query->whereNotIn('code', $codes))
                ->delete();

            foreach ($points as $point) {
                $existing = $intake->attentionPoints()
                    ->where('source', AttentionPointSource::Ai)
                    ->where('code', $point['code'])
                    ->lockForUpdate()
                    ->first();

                if ($existing === null) {
                    IntakeAttentionPoint::query()->create([
                        'intake_id' => $intake->id,
                        'source' => AttentionPointSource::Ai,
                        'code' => $point['code'],
                        'label' => $point['label'],
                        'status' => AttentionPointStatus::Proposed,
                        'ai_confidence' => $point['confidence'],
                        'evidence' => $point['evidence'],
                    ]);

                    continue;
                }

                // Keep an accepted/dismissed decision; refresh only a still-proposed point.
                if ($existing->status === AttentionPointStatus::Proposed) {
                    $existing->update([
                        'label' => $point['label'],
                        'ai_confidence' => $point['confidence'],
                        'evidence' => $point['evidence'],
                    ]);
                }
            }

            AiRun::query()->whereKey($run->id)->update($completionAttributes);
        }, 3);
    }

    /** @param array<string, mixed> $payload */
    private function payloadHash(array $payload): string
    {
        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
