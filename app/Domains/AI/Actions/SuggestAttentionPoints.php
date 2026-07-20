<?php

declare(strict_types=1);

namespace App\Domains\AI\Actions;

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiGateway;
use App\Domains\AI\Services\PromptVersionRepository;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAttentionPoint;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\AttentionPointSource;
use App\Enums\AttentionPointStatus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Proposes non-binding attention points for an intake (BL-007). AI is supporting,
 * never source of truth (ADR-0005): points land as `proposed` and the installer
 * accepts or dismisses them. Soft-fail: any failure records a failed AiRun and
 * leaves existing points untouched.
 */
final class SuggestAttentionPoints
{
    public function __construct(
        private readonly AiGateway $aiGateway,
        private readonly PromptVersionRepository $promptVersions,
    ) {}

    public function handle(Intake $intake): AiRun
    {
        $intake->loadMissing(['answers', 'externalFacts', 'followUpRounds.items.uploads', 'templateVersion.template']);

        $promptName = (string) config('ai.attention_points_prompt', 'attention_points');
        $promptVersion = $this->promptVersions->version($promptName);
        $promptBody = $this->promptVersions->body($promptName);
        $provider = (string) config('ai.provider', 'null');

        $payload = $this->buildPayload($intake);
        $inputHash = hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR));

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

        try {
            $result = $this->aiGateway->complete(
                prompt: $promptBody,
                input: $payload,
                promptVersion: $promptVersion,
            );

            $points = $this->validateOutput($result->output);
            $this->persistProposals($intake, $points);

            $run->update([
                'status' => AiRunStatus::Succeeded,
                'provider' => $result->provider,
                'model' => $result->model,
                'output' => ['points' => $points],
                'finished_at' => now(),
                'error_message' => null,
            ]);

            return $run->fresh() ?? $run;
        } catch (\Throwable $e) {
            Log::warning('AI attention points failed', [
                'intake_id' => $intake->id,
                'ai_run_id' => $run->id,
                'message' => $e->getMessage(),
            ]);

            $run->update([
                'status' => AiRunStatus::Failed,
                'error_message' => Str::limit($e->getMessage(), 1000, ''),
                'finished_at' => now(),
            ]);

            return $run->fresh() ?? $run;
        }
    }

    /**
     * @return array{answers: array<string, mixed>, external_facts: array<string, array{value: array<string, mixed>, source: string, confidence: string}>, follow_up: list<array{round_number: int, items: list<array{type: string, prompt: string, response_text: string|null, upload_count: int}>}>, template_key: string|null, template_version: int|null}
     */
    private function buildPayload(Intake $intake): array
    {
        $answers = [];

        foreach ($intake->answers as $answer) {
            $key = $answer->section_instance_key
                ? $answer->section_instance_key.'__'.$answer->question_key
                : $answer->question_key;

            $answers[$key] = $answer->value;
        }

        $externalFacts = [];
        foreach ($intake->externalFacts as $fact) {
            $externalFacts[$fact->fact_key] = [
                'value' => $fact->value,
                'source' => $fact->source,
                'confidence' => $fact->confidence,
            ];
        }

        return [
            'answers' => $answers,
            'external_facts' => $externalFacts,
            'follow_up' => $this->followUpPayload($intake),
            'template_key' => $intake->templateVersion?->template?->key,
            'template_version' => $intake->templateVersion?->version,
        ];
    }

    /** @return list<array{round_number: int, items: list<array{type: string, prompt: string, response_text: string|null, upload_count: int}>}> */
    private function followUpPayload(Intake $intake): array
    {
        $payload = [];

        foreach ($intake->followUpRounds as $round) {
            $items = [];

            foreach ($round->items as $item) {
                $items[] = [
                    'type' => $item->type->value,
                    'prompt' => $item->prompt,
                    'response_text' => $item->response_text,
                    'upload_count' => $item->uploads->count(),
                ];
            }

            $payload[] = [
                'round_number' => $round->round_number,
                'items' => $items,
            ];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $output
     * @return list<array{code: string, label: string}>
     */
    private function validateOutput(array $output): array
    {
        $validator = Validator::make($output, [
            'points' => ['present', 'array', 'max:20'],
            'points.*.code' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'points.*.label' => ['required', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        /** @var array{points: list<array{code: string, label: string}>} $validated */
        $validated = $validator->validated();

        $points = [];
        $seen = [];
        foreach ($validated['points'] as $point) {
            $code = $point['code'];
            if (isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $points[] = ['code' => $code, 'label' => trim($point['label'])];
        }

        return $points;
    }

    /**
     * Idempotent on (intake, code): never duplicates, and respects a prior
     * accept/dismiss decision. Stale still-proposed points that no longer apply
     * are removed; accepted/dismissed ones are kept.
     *
     * @param  list<array{code: string, label: string}>  $points
     */
    private function persistProposals(Intake $intake, array $points): void
    {
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
                ->first();

            if ($existing === null) {
                IntakeAttentionPoint::query()->create([
                    'intake_id' => $intake->id,
                    'source' => AttentionPointSource::Ai,
                    'code' => $point['code'],
                    'label' => $point['label'],
                    'status' => AttentionPointStatus::Proposed,
                ]);

                continue;
            }

            // Keep an accepted/dismissed decision; only refresh a still-proposed label.
            if ($existing->status === AttentionPointStatus::Proposed && $existing->label !== $point['label']) {
                $existing->update(['label' => $point['label']]);
            }
        }
    }
}
