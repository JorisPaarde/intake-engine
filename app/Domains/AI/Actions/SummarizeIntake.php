<?php

declare(strict_types=1);

namespace App\Domains\AI\Actions;

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiGateway;
use App\Domains\AI\Services\PromptVersionRepository;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\GenerateIntakeReportHtml;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\AttentionPointStatus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SummarizeIntake
{
    public function __construct(
        private readonly AiGateway $aiGateway,
        private readonly PromptVersionRepository $promptVersions,
        private readonly GenerateIntakeReportHtml $generateIntakeReportHtml,
    ) {}

    public function handle(Intake $intake): AiRun
    {
        $intake->loadMissing(['answers', 'uploads', 'attentionPoints', 'externalFacts', 'followUpRounds.items.uploads', 'report', 'templateVersion.template']);

        $promptName = (string) config('ai.summary_prompt', 'summary');
        $promptVersion = $this->promptVersions->version($promptName);
        $promptBody = $this->promptVersions->body($promptName);
        $provider = (string) config('ai.provider', 'null');

        $payload = $this->buildPayload($intake);
        $inputHash = hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR));

        $run = AiRun::query()->create([
            'intake_id' => $intake->id,
            'type' => AiRunType::Summary,
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

            $validated = $this->validateOutput($result->output);

            $run->update([
                'status' => AiRunStatus::Succeeded,
                'provider' => $result->provider,
                'model' => $result->model,
                'output' => $validated,
                'finished_at' => now(),
                'error_message' => null,
            ]);

            $this->attachSummaryToReport($intake, $validated, $run->fresh() ?? $run);

            return $run->fresh() ?? $run;
        } catch (\Throwable $e) {
            Log::warning('AI summarize failed', [
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
     * @return array{answers: array<string, mixed>, external_facts: array<string, array{value: array<string, mixed>, source: string, confidence: string}>, follow_up: list<array{round_number: int, items: list<array{type: string, prompt: string, response_text: string|null, upload_count: int}>}>, attention_point_codes: list<string>, template_key: string|null, template_version: int|null}
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
            'attention_point_codes' => $intake->attentionPoints
                ->pluck('code')
                ->filter()
                ->values()
                ->all(),
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
     * @return array{summary: string, highlights: list<string>}
     */
    private function validateOutput(array $output): array
    {
        $validator = Validator::make($output, [
            'summary' => ['required', 'string', 'min:10', 'max:4000'],
            'highlights' => ['required', 'array', 'min:1', 'max:12'],
            'highlights.*' => ['required', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        /** @var array{summary: string, highlights: list<string>} $validated */
        $validated = $validator->validated();

        $highlights = [];
        foreach ($validated['highlights'] as $item) {
            $highlights[] = trim($item);
        }

        return [
            'summary' => trim($validated['summary']),
            'highlights' => $highlights,
        ];
    }

    /**
     * @param  array{summary: string, highlights: list<string>}  $summary
     */
    private function attachSummaryToReport(Intake $intake, array $summary, AiRun $run): void
    {
        $report = $intake->report;

        if ($report === null) {
            return;
        }

        $version = $intake->templateVersion()
            ->with(['sections.questions.options', 'sections.questions.rules', 'template'])
            ->firstOrFail();

        // Only authoritative points in the report — never still-proposed/dismissed AI ones (BL-007).
        $attentionPoints = $intake->attentionPoints
            ->filter(static fn ($point): bool => $point->status === null
                || $point->status === AttentionPointStatus::Accepted)
            ->map(static fn ($point): array => [
                'code' => (string) ($point->code ?? ''),
                'label' => $point->label,
            ])
            ->values()
            ->all();

        $html = $this->generateIntakeReportHtml->handle(
            $intake,
            $version,
            $attentionPoints,
            $summary,
        );

        $rawMeta = $report->getAttribute('meta');
        $meta = is_array($rawMeta) ? $rawMeta : [];
        $meta['ai_summary'] = $summary;
        $meta['ai_run_id'] = $run->id;
        $meta['ai_provider'] = $run->provider;
        $meta['ai_prompt_version'] = $run->prompt_version;

        $report->update([
            'html' => $html,
            'meta' => $meta,
            'generated_at' => now(),
        ]);
    }
}
