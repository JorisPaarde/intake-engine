<?php

declare(strict_types=1);

namespace App\Domains\AI\Eval;

use App\Domains\AI\Services\AiGateway;
use App\Domains\AI\Services\DossierSynthesisJsonSchema;
use App\Domains\AI\Services\PromptVersionRepository;
use App\Enums\AircoConnectionType;
use Throwable;

/**
 * N90: runs the dossier_synthesis prompt (text-only, recorded synthesis input)
 * and judges the option summaries / connection texts on meaning.
 */
final class OptionSummaryCaseRunner
{
    public function __construct(
        private readonly AiGateway $aiGateway,
        private readonly PromptVersionRepository $promptVersions,
        private readonly DossierSynthesisJsonSchema $jsonSchema,
        private readonly JudgeClaimEvaluator $judgeClaims,
        private readonly FactScorer $factScorer,
    ) {}

    /**
     * @param  array<string, mixed>  $fixture
     * @return array<string, mixed>
     */
    public function run(array $fixture, int $repeatIndex): array
    {
        $components = ['dossier_synthesis'];
        $expected = is_array($fixture['expected'] ?? null) ? $fixture['expected'] : [];
        $input = is_array($fixture['synthesis_input'] ?? null) ? $fixture['synthesis_input'] : [];
        $promptName = 'dossier_synthesis';
        $usage = null;
        $output = null;
        $error = null;

        try {
            $result = $this->aiGateway->complete(
                prompt: $this->promptVersions->body($promptName),
                input: $input,
                promptVersion: $this->promptVersions->version($promptName),
                model: (string) config('ai.dossier.model'),
                responseSchema: ['name' => 'dossier_synthesis', 'schema' => $this->jsonSchema->schema()],
                timeoutSeconds: max(1, (int) config('ai.dossier.timeout_seconds', 45)),
            );
            $output = $result->output;
            $usage = [
                'input_tokens' => $result->inputTokens,
                'output_tokens' => $result->outputTokens,
                'total_tokens' => $result->totalTokens,
                'estimated_cost' => $result->estimatedCost,
            ];
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        $texts = $output !== null ? $this->texts($output) : [];
        $optionTexts = $output !== null && $this->hasOptionWithCondensateConnection($output) ? $this->optionTexts($output) : [];
        $scores = [
            // Option + connection texts must exist; a top-level summary alone does not exercise the case.
            'synthesis_output' => $this->factScorer->score(
                expected: true,
                got: $optionTexts !== [],
                supported: true,
                components: $components,
            ),
        ];
        $judged = $optionTexts !== []
            ? $this->judgeClaims->evaluate($expected, $texts, $components)
            : ['scores' => [], 'log' => []];

        return [
            'id' => $fixture['id'] ?? null,
            'kind' => 'option_summary',
            'repeat' => $repeatIndex,
            'components' => $components,
            'error' => $error,
            'texts' => $texts,
            'token_usage' => ['dossier_synthesis' => $usage],
            'judge' => $judged['log'],
            'scores' => ['model_raw' => $scores + $judged['scores'], 'pipeline_final' => $scores + $judged['scores']],
            'note' => $fixture['note'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $output
     * @return list<string>
     */
    private function texts(array $output): array
    {
        $texts = is_string($output['summary'] ?? null) && trim($output['summary']) !== ''
            ? [$output['summary']]
            : [];

        return array_merge($texts, $this->optionTexts($output));
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function hasOptionWithCondensateConnection(array $output): bool
    {
        foreach ((array) ($output['option_proposals'] ?? []) as $option) {
            if (! is_array($option) || ! is_string($option['summary'] ?? null) || trim($option['summary']) === '') {
                continue;
            }
            foreach ((array) ($option['connections'] ?? []) as $connection) {
                // N90 target: the option must carry a condensate connection.
                if (is_array($connection) && ($connection['type'] ?? null) === AircoConnectionType::Condensate->value) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $output
     * @return list<string>
     */
    private function optionTexts(array $output): array
    {
        $texts = [];
        foreach ((array) ($output['option_proposals'] ?? []) as $option) {
            if (! is_array($option)) {
                continue;
            }
            foreach (['label', 'summary'] as $key) {
                if (is_string($option[$key] ?? null)) {
                    $texts[] = $option[$key];
                }
            }
            foreach ((array) ($option['connections'] ?? []) as $connection) {
                if (! is_array($connection)) {
                    continue;
                }
                if (is_string($connection['label'] ?? null)) {
                    $texts[] = $connection['label'];
                }
                foreach (['segments', 'obstacles', 'uncertainties'] as $list) {
                    foreach ((array) ($connection[$list] ?? []) as $item) {
                        if (is_string($item)) {
                            $texts[] = $item;
                        }
                    }
                }
            }
        }

        return array_values(array_filter($texts, static fn (string $t): bool => trim($t) !== ''));
    }
}
