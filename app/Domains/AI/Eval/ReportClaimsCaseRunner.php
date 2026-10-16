<?php

declare(strict_types=1);

namespace App\Domains\AI\Eval;

use App\Domains\AI\Actions\SuggestAttentionPoints;
use App\Domains\AI\Actions\SummarizeIntake;
use App\Domains\AI\DTOs\AiCompletionResult;
use App\Domains\AI\Services\AiGateway;
use App\Domains\AI\Services\PromptVersionRepository;
use App\Domains\AI\Support\DerivedClaimConfidenceGuard;
use App\Domains\AI\Support\SchemaEnumLabelGuard;
use Throwable;

/**
 * Runs report_claims fixtures through the real summary + attention_points prompts.
 *
 * Scoring: claim-level concepts via {@see ReportClaimsScorer} (eval-only).
 * Layers:
 * - model_raw: gateway output before DerivedClaimConfidenceGuard
 * - pipeline_final: same applyPipeline path as SummarizeIntake / SuggestAttentionPoints
 *
 * FakeAiClient is not specialized for attention_points; fixtures may supply
 * `fake_summary_output` / `fake_attention_output` used only when provider=fake
 * and the gateway shape is unusable for that prompt.
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
        private readonly SchemaEnumLabelGuard $schemaEnumLabelGuard,
        private readonly JudgeClaimEvaluator $judgeClaims,
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
        $summaryPromptVersion = $this->promptVersions->version($summaryPromptName);
        $attentionPromptVersion = $this->promptVersions->version($attentionPromptName);
        $isFake = strtolower((string) config('ai.provider', '')) === 'fake';

        $summaryRaw = null;
        $summaryFinal = null;
        $summaryError = null;
        $summaryGatewayRejection = null;
        $summaryPipelineRejection = null;
        $summaryProvider = null;
        $summaryModel = null;
        $summaryUsage = null;
        $summaryCeiling = $this->claimGuard->ceilingFromSummaryPayload($summaryInput);

        try {
            $summaryResult = $this->aiGateway->complete(
                prompt: $this->promptVersions->body($summaryPromptName),
                input: $summaryInput,
                promptVersion: $summaryPromptVersion,
            );
            $summaryUsage = $this->usageFromResult($summaryResult);
            $summaryRaw = $summaryResult->output;
            $summaryProvider = $summaryResult->provider;
            $summaryModel = $summaryResult->model;

            if ($isFake && ! $this->looksLikeSummary($summaryRaw)) {
                $override = is_array($fixture['fake_summary_output'] ?? null)
                    ? $fixture['fake_summary_output']
                    : $this->defaultFakeSummaryOutput();
                $summaryRaw = $override;
            }

            if (! $this->looksLikeSummary($summaryRaw)) {
                $summaryGatewayRejection = 'gateway_output_not_summary_shape';
            } else {
                try {
                    $summaryFinal = $this->summarizeIntake->applyPipelineToOutput($summaryRaw, $summaryInput);
                } catch (Throwable $e) {
                    $summaryPipelineRejection = 'pipeline_rejected: '.$e->getMessage();
                    $summaryError = $e->getMessage();
                }
            }
        } catch (Throwable $e) {
            $summaryError = $e->getMessage();
            $summaryGatewayRejection = 'gateway_error: '.$e->getMessage();
        }

        $attentionRaw = null;
        $attentionFinal = null;
        $attentionError = null;
        $attentionGatewayRejection = null;
        $attentionPipelineRejection = null;
        $attentionProvider = null;
        $attentionModel = null;
        $attentionUsage = null;

        try {
            $attentionResult = $this->aiGateway->complete(
                prompt: $this->promptVersions->body($attentionPromptName),
                input: $attentionInput,
                promptVersion: $attentionPromptVersion,
            );
            $attentionUsage = $this->usageFromResult($attentionResult);
            $attentionRaw = $attentionResult->output;
            $attentionProvider = $attentionResult->provider;
            $attentionModel = $attentionResult->model;

            if ($isFake && ! $this->looksLikeAttentionPoints($attentionRaw)) {
                $override = is_array($fixture['fake_attention_output'] ?? null)
                    ? $fixture['fake_attention_output']
                    : $this->defaultFakeAttentionOutput();
                $attentionRaw = $override;
            }

            if (! $this->looksLikeAttentionPoints($attentionRaw)) {
                $attentionGatewayRejection = 'gateway_output_not_attention_points_shape';
            } else {
                try {
                    $attentionFinal = $this->suggestAttentionPoints->applyPipelineToOutput($attentionRaw, $attentionInput);
                } catch (Throwable $e) {
                    $attentionPipelineRejection = 'pipeline_rejected: '.$e->getMessage();
                    $attentionError = $e->getMessage();
                }
            }
        } catch (Throwable $e) {
            $attentionError = $e->getMessage();
            $attentionGatewayRejection = 'gateway_error: '.$e->getMessage();
        }

        $rawSummaryNormalized = $summaryGatewayRejection === null
            ? $this->normalizeSummaryShape($summaryRaw)
            : null;
        $finalSummaryNormalized = is_array($summaryFinal) ? $summaryFinal : null;
        $rawPoints = $attentionGatewayRejection === null
            ? $this->normalizePointsShape($attentionRaw)
            : null;
        $finalPoints = is_array($attentionFinal) ? $attentionFinal : null;

        $inputReferences = $this->collectInputReferences($summaryInput, $attentionInput);

        $rawLayerMeta = [
            'summary_rejection' => ($rawSummaryNormalized === null)
                ? ($summaryGatewayRejection ?? 'missing_or_invalid_summary')
                : null,
            'attention_rejection' => ($rawPoints === null)
                ? ($attentionGatewayRejection ?? 'missing_or_rejected_attention_points')
                : null,
            'input_references' => $inputReferences,
        ];
        $finalLayerMeta = [
            'summary_rejection' => ($finalSummaryNormalized === null)
                ? ($summaryPipelineRejection ?? $summaryGatewayRejection ?? 'pipeline_summary_unavailable')
                : null,
            'attention_rejection' => ($finalPoints === null)
                ? ($attentionPipelineRejection ?? $attentionGatewayRejection ?? 'pipeline_attention_unavailable')
                : null,
            'input_references' => $inputReferences,
        ];

        $scores = [
            'model_raw' => $this->scorer->scoreLayer(
                $expected,
                $rawSummaryNormalized,
                $rawPoints,
                $disputed,
                $components,
                $rawLayerMeta,
            ),
            'pipeline_final' => $this->scorer->scoreLayer(
                $expected,
                $finalSummaryNormalized,
                $finalPoints,
                $disputed,
                $components,
                $finalLayerMeta,
            ),
        ];

        $rawJudgeTexts = $this->judgeTexts($rawSummaryNormalized, $rawPoints);
        $finalJudgeTexts = $this->judgeTexts($finalSummaryNormalized, $finalPoints);
        $judgedRaw = $this->judgeClaims->evaluate($expected, $rawJudgeTexts, $components);
        $scores['model_raw'] += $judgedRaw['scores'];
        // Same texts in both layers (within this repeat): reuse the verdict.
        $judged = $finalJudgeTexts === $rawJudgeTexts
            ? $judgedRaw
            : $this->judgeClaims->evaluate($expected, $finalJudgeTexts, $components);
        $scores['pipeline_final'] += $judged['scores'];

        $pipelineDiffers = json_encode($rawSummaryNormalized) !== json_encode($finalSummaryNormalized)
            || json_encode($rawPoints) !== json_encode($finalPoints);

        return [
            'id' => $fixture['id'] ?? null,
            'kind' => 'report_claims',
            'repeat' => $repeatIndex,
            'source_kind' => $fixture['source_kind'] ?? null,
            'origin' => $fixture['origin'] ?? null,
            'status' => ($summaryGatewayRejection !== null && $rawSummaryNormalized === null)
                || ($attentionGatewayRejection !== null && $rawPoints === null)
                || ($summaryPipelineRejection !== null && $finalSummaryNormalized === null)
                || ($attentionPipelineRejection !== null && $finalPoints === null)
                ? 'error'
                : 'ok',
            'error' => $this->combineErrors($summaryError, $attentionError),
            'provider' => $summaryProvider ?? $attentionProvider,
            'model' => $summaryModel ?? $attentionModel,
            'prompt_versions' => [
                'summary' => $summaryPromptVersion,
                'attention_points' => $attentionPromptVersion,
            ],
            'token_usage' => [
                'summary' => $summaryUsage,
                'attention_points' => $attentionUsage,
                'total_tokens' => $this->sumNullableInt(
                    is_array($summaryUsage) ? ($summaryUsage['total_tokens'] ?? null) : null,
                    is_array($attentionUsage) ? ($attentionUsage['total_tokens'] ?? null) : null,
                ),
            ],
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
            'rejections' => [
                'summary_gateway' => $summaryGatewayRejection,
                'summary_pipeline' => $summaryPipelineRejection,
                'attention_gateway' => $attentionGatewayRejection,
                'attention_pipeline' => $attentionPipelineRejection,
            ],
            'guard_meta' => [
                'summary_ceiling' => $summaryCeiling,
                'summary_hedged_flags' => $this->summaryHedgeFlags($rawSummaryNormalized, $summaryCeiling),
                'schema_enum_guard' => $this->schemaEnumGuardMeta(
                    $rawSummaryNormalized,
                    $finalSummaryNormalized,
                    $rawPoints,
                    $finalPoints,
                ),
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
            'judge' => ['model_raw' => $judgedRaw['log'], 'pipeline_final' => $judged['log']],
            'note' => $fixture['note'] ?? null,
        ];
    }

    /**
     * @param  array{summary: string, highlights: list<string>}|null  $summary
     * @param  list<array<string, mixed>>|null  $points
     * @return list<string>
     */
    private function judgeTexts(?array $summary, ?array $points): array
    {
        $texts = [];
        if ($summary !== null) {
            $texts[] = $summary['summary'];
            foreach ($summary['highlights'] as $highlight) {
                $texts[] = $highlight;
            }
        }
        foreach ($points ?? [] as $point) {
            if (is_string($point['label'] ?? null)) {
                $texts[] = $point['label'];
            }
        }

        return $texts;
    }

    /**
     * @return array{input_tokens: int|null, output_tokens: int|null, total_tokens: int|null, estimated_cost: string|null, estimated_cost_cents: int|null}
     */
    private function usageFromResult(AiCompletionResult $result): array
    {
        return [
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'total_tokens' => $result->totalTokens,
            'estimated_cost' => $result->estimatedCost,
            'estimated_cost_cents' => $result->estimatedCostCents,
        ];
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function looksLikeSummary(array $output): bool
    {
        return is_string($output['summary'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function looksLikeAttentionPoints(array $output): bool
    {
        return is_array($output['points'] ?? null);
    }

    /**
     * @return array{summary: string, highlights: list<string>}
     */
    private function defaultFakeSummaryOutput(): array
    {
        return [
            'summary' => 'Fictieve AI-samenvatting van de intake voor testgebruik zonder fase- of capaciteitsclaims.',
            'highlights' => [
                'Controleer foto’s en aandachtspunten handmatig.',
            ],
        ];
    }

    /**
     * Neutral empty points — does not invent phase/capacity (eval fake only).
     *
     * @return array{points: list<array<string, mixed>>}
     */
    private function defaultFakeAttentionOutput(): array
    {
        return ['points' => []];
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

    /**
     * Eval-only measurement: raw schema-enum hits in model_raw vs pipeline_final (N20).
     * Not used in product paths; logs hits for the report_claims harness only.
     *
     * @param  array{summary: string, highlights: list<string>}|null  $rawSummary
     * @param  array{summary: string, highlights: list<string>}|null  $finalSummary
     * @param  list<array{code: string, label: string, confidence: string|null, evidence?: mixed}>|null  $rawPoints
     * @param  list<array{code: string, label: string, confidence: string|null, evidence?: mixed}>|null  $finalPoints
     * @return array{model_raw_hits: list<string>, pipeline_final_hits: list<string>}
     */
    private function schemaEnumGuardMeta(
        ?array $rawSummary,
        ?array $finalSummary,
        ?array $rawPoints,
        ?array $finalPoints,
    ): array {
        return [
            'model_raw_hits' => $this->collectSchemaEnumHits($rawSummary, $rawPoints),
            'pipeline_final_hits' => $this->collectSchemaEnumHits($finalSummary, $finalPoints),
        ];
    }

    /**
     * @param  array{summary: string, highlights: list<string>}|null  $summary
     * @param  list<array{code: string, label: string, confidence: string|null, evidence?: mixed}>|null  $points
     * @return list<string>
     */
    private function collectSchemaEnumHits(?array $summary, ?array $points): array
    {
        $texts = [];
        if ($summary !== null) {
            $texts[] = $summary['summary'];
            foreach ($summary['highlights'] as $highlight) {
                $texts[] = $highlight;
            }
        }
        if ($points !== null) {
            foreach ($points as $point) {
                $texts[] = $point['label'];
            }
        }

        $hits = [];
        foreach ($texts as $text) {
            foreach ($this->schemaEnumLabelGuard->findRawHits($text) as $token) {
                $hits[] = $token;
            }
        }

        $hits = array_values(array_unique($hits));
        sort($hits);

        return $hits;
    }

    private function combineErrors(?string $summaryError, ?string $attentionError): ?string
    {
        $parts = array_values(array_filter([$summaryError, $attentionError]));

        return $parts === [] ? null : implode('; ', $parts);
    }

    /**
     * Exact machine `reference` values from fixture inputs (for no_internal_upload_refs).
     *
     * @param  array<string, mixed>  $summaryInput
     * @param  array<string, mixed>  $attentionInput
     * @return list<string>
     */
    private function collectInputReferences(array $summaryInput, array $attentionInput): array
    {
        $refs = [];
        foreach ([$summaryInput, $attentionInput] as $input) {
            foreach (['uploads', 'answer_context', 'external_fact_context', 'system_attention_points', 'pipe_routes'] as $listKey) {
                foreach (is_array($input[$listKey] ?? null) ? $input[$listKey] : [] as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    if (is_string($row['reference'] ?? null) && trim((string) $row['reference']) !== '') {
                        $refs[] = trim((string) $row['reference']);
                    }
                }
            }
            foreach (is_array($input['follow_up'] ?? null) ? $input['follow_up'] : [] as $round) {
                if (! is_array($round)) {
                    continue;
                }
                foreach (is_array($round['items'] ?? null) ? $round['items'] : [] as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    if (is_string($item['reference'] ?? null) && trim((string) $item['reference']) !== '') {
                        $refs[] = trim((string) $item['reference']);
                    }
                    foreach (is_array($item['uploads'] ?? null) ? $item['uploads'] : [] as $upload) {
                        if (! is_array($upload)) {
                            continue;
                        }
                        if (is_string($upload['reference'] ?? null) && trim((string) $upload['reference']) !== '') {
                            $refs[] = trim((string) $upload['reference']);
                        }
                    }
                }
            }
        }

        return array_values(array_unique($refs));
    }

    private function sumNullableInt(mixed $a, mixed $b): ?int
    {
        $vals = array_values(array_filter(
            [$a, $b],
            static fn (mixed $v): bool => is_int($v),
        ));

        return $vals === [] ? null : array_sum($vals);
    }
}
