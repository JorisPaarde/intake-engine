<?php

declare(strict_types=1);

namespace App\Domains\AI\Eval;

use App\Domains\AI\Actions\SuggestAttentionPoints;
use App\Domains\AI\Actions\SummarizeIntake;
use App\Domains\AI\Services\AiGateway;
use App\Domains\AI\Services\PromptVersionRepository;
use App\Domains\AI\Support\DerivedClaimConfidenceGuard;
use Throwable;

/**
 * Runs report_claims fixtures through the real summary + attention_points prompts.
 *
 * Scoring choice (brief P1-5 / IE uncertain photo facts): option (a) —
 * fixture-local `must_not_assert` / `must_hedge_phase_claims` lists plus
 * structured confidence/evidence checks in {@see ReportClaimsScorer}.
 * Measurement code only; never used on product paths.
 *
 * Layers:
 * - model_raw: gateway output before DerivedClaimConfidenceGuard
 * - pipeline_final: same applyPipeline path as SummarizeIntake / SuggestAttentionPoints
 */
final class ReportClaimsCaseRunner
{
    public function __construct(
        private readonly AiGateway $aiGateway,
        private readonly PromptVersionRepository $promptVersions,
        private readonly SummarizeIntake $summarizeIntake,
        private readonly SuggestAttentionPoints $suggestAttentionPoints,
        private readonly DerivedClaimConfidenceGuard $claimGuard,
        private readonly ReportClaimsScorer $scorer,
    ) {}

    /**
     * @param  array<string, mixed>  $fixture
     * @return array<string, mixed>
     */
    public function run(array $fixture, int $repeatIndex): array
    {
        $summaryInput = is_array($fixture['summary_input'] ?? null) ? $fixture['summary_input'] : [];
        $attentionInput = is_array($fixture['attention_input'] ?? null) ? $fixture['attention_input'] : [];
        $expected = is_array($fixture['expected'] ?? null) ? $fixture['expected'] : [];
        $disputed = is_array($fixture['disputed_facts'] ?? null) ? $fixture['disputed_facts'] : [];
        $components = is_array($fixture['components'] ?? null)
            ? array_values(array_filter(
                $fixture['components'],
                static fn (mixed $v): bool => is_string($v) && $v !== '',
            ))
            : ['C5', 'C7', 'C8'];

        $summaryPromptName = (string) config('ai.summary_prompt', 'summary');
        $attentionPromptName = (string) config('ai.attention_points_prompt', 'attention_points');

        $summaryRaw = null;
        $summaryFinal = null;
        $summaryError = null;
        $summaryProvider = null;
        $summaryModel = null;
        $summaryCeiling = $this->claimGuard->ceilingFromSummaryPayload($summaryInput);

        try {
            $summaryResult = $this->aiGateway->complete(
                prompt: $this->promptVersions->body($summaryPromptName),
                input: $summaryInput,
                promptVersion: $this->promptVersions->version($summaryPromptName),
            );
            $summaryRaw = $summaryResult->output;
            $summaryProvider = $summaryResult->provider;
            $summaryModel = $summaryResult->model;
            $summaryFinal = $this->summarizeIntake->applyPipelineToOutput($summaryRaw, $summaryInput);
        } catch (Throwable $e) {
            $summaryError = $e->getMessage();
        }

        $attentionRaw = null;
        $attentionFinal = null;
        $attentionError = null;
        $attentionProvider = null;
        $attentionModel = null;

        try {
            $attentionResult = $this->aiGateway->complete(
                prompt: $this->promptVersions->body($attentionPromptName),
                input: $attentionInput,
                promptVersion: $this->promptVersions->version($attentionPromptName),
            );
            $attentionRaw = $attentionResult->output;
            $attentionProvider = $attentionResult->provider;
            $attentionModel = $attentionResult->model;
            $attentionFinal = $this->suggestAttentionPoints->applyPipelineToOutput($attentionRaw, $attentionInput);
        } catch (Throwable $e) {
            $attentionError = $e->getMessage();
        }

        $rawSummaryNormalized = $this->normalizeSummaryShape($summaryRaw);
        $finalSummaryNormalized = is_array($summaryFinal) ? $summaryFinal : null;
        $rawPoints = $this->normalizePointsShape($attentionRaw);
        $finalPoints = is_array($attentionFinal) ? $attentionFinal : null;

        $scores = [
            'model_raw' => $this->scorer->scoreLayer(
                $expected,
                $rawSummaryNormalized,
                $rawPoints,
                $disputed,
                $components,
            ),
            'pipeline_final' => $this->scorer->scoreLayer(
                $expected,
                $finalSummaryNormalized,
                $finalPoints,
                $disputed,
                $components,
            ),
        ];

        $pipelineDiffers = json_encode($rawSummaryNormalized) !== json_encode($finalSummaryNormalized)
            || json_encode($rawPoints) !== json_encode($finalPoints);

        return [
            'id' => $fixture['id'] ?? null,
            'kind' => 'report_claims',
            'repeat' => $repeatIndex,
            'source_kind' => $fixture['source_kind'] ?? null,
            'origin' => $fixture['origin'] ?? null,
            'status' => ($summaryError !== null && $summaryRaw === null) || ($attentionError !== null && $attentionRaw === null)
                ? 'error'
                : 'ok',
            'error' => $this->combineErrors($summaryError, $attentionError),
            'provider' => $summaryProvider ?? $attentionProvider,
            'model' => $summaryModel ?? $attentionModel,
            'runnable' => true,
            'runnable_note' => 'Text-only structured fusebox/photo assessments; real summary + attention_points prompts; no images.',
            'model_raw' => [
                'summary' => $summaryRaw,
                'attention_points' => $attentionRaw,
            ],
            'pipeline_final' => [
                'summary' => $summaryFinal,
                'attention_points' => $attentionFinal,
            ],
            'pipeline_differs_from_model_raw' => $pipelineDiffers,
            'guard_meta' => [
                'summary_ceiling' => $summaryCeiling,
                'summary_hedged_flags' => $this->summaryHedgeFlags($rawSummaryNormalized, $summaryCeiling),
            ],
            'facts' => [
                'model_raw' => [
                    'summary' => $rawSummaryNormalized,
                    'attention_points' => $rawPoints,
                ],
                'pipeline_final' => [
                    'summary' => $finalSummaryNormalized,
                    'attention_points' => $finalPoints,
                ],
            ],
            'scores' => $scores,
            'note' => $fixture['note'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $raw
     * @return array{summary: string, highlights: list<string>}|null
     */
    private function normalizeSummaryShape(?array $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        if (! is_string($raw['summary'] ?? null)) {
            return null;
        }
        $highlights = is_array($raw['highlights'] ?? null) ? $raw['highlights'] : [];
        $clean = [];
        foreach ($highlights as $item) {
            if (is_string($item)) {
                $clean[] = $item;
            }
        }

        return [
            'summary' => (string) $raw['summary'],
            'highlights' => $clean,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $raw
     * @return list<array{code: string, label: string, confidence: string|null, evidence: list<array{source_type: string, reference: string}>}>|null
     */
    private function normalizePointsShape(?array $raw): ?array
    {
        if ($raw === null || ! is_array($raw['points'] ?? null)) {
            return null;
        }

        $points = [];
        foreach ($raw['points'] as $point) {
            if (! is_array($point)) {
                continue;
            }
            $evidence = [];
            foreach (is_array($point['evidence'] ?? null) ? $point['evidence'] : [] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                if (! is_string($item['source_type'] ?? null) || ! is_string($item['reference'] ?? null)) {
                    continue;
                }
                $evidence[] = [
                    'source_type' => (string) $item['source_type'],
                    'reference' => (string) $item['reference'],
                ];
            }
            $points[] = [
                'code' => is_string($point['code'] ?? null) ? (string) $point['code'] : '',
                'label' => is_string($point['label'] ?? null) ? (string) $point['label'] : '',
                'confidence' => is_string($point['confidence'] ?? null) ? (string) $point['confidence'] : null,
                'evidence' => $evidence,
            ];
        }

        return $points;
    }

    /**
     * @param  array{summary: string, highlights: list<string>}|null  $summary
     * @param  'low'|'medium'|'high'|null  $ceiling
     * @return array{summary: bool, highlights: list<bool>}
     */
    private function summaryHedgeFlags(?array $summary, ?string $ceiling): array
    {
        if ($summary === null) {
            return ['summary' => false, 'highlights' => []];
        }

        $highlightFlags = [];
        foreach ($summary['highlights'] as $highlight) {
            $highlightFlags[] = $this->claimGuard->normalizeDerivedText($highlight, $ceiling)['hedged'];
        }

        return [
            'summary' => $this->claimGuard->normalizeDerivedText($summary['summary'], $ceiling)['hedged'],
            'highlights' => $highlightFlags,
        ];
    }

    private function combineErrors(?string $summaryError, ?string $attentionError): ?string
    {
        $parts = array_values(array_filter([$summaryError, $attentionError]));

        return $parts === [] ? null : implode('; ', $parts);
    }
}
