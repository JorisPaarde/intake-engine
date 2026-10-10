<?php

declare(strict_types=1);

namespace App\Domains\AI\Eval;

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

/**
 * Orchestreert de volledige evaluatieset (alleen meten).
 */
final class InterpretationEvalRunner
{
    private const PROMPT_NAMES = [
        'request_prefill',
        'room_assessment',
        'outdoor_assessment',
        'fusebox_assessment',
        'pipe_route_assessment',
        'dossier_synthesis',
    ];

    public function __construct(
        private readonly PromptFingerprint $promptFingerprint,
        private readonly RequestTextCaseRunner $requestTextRunner,
        private readonly CustomerAnswerCaseRunner $customerAnswerRunner,
        private readonly PhotoObservationCaseRunner $photoObservationRunner,
        private readonly EvalReportWriter $reportWriter,
        private readonly EvalRuntimeBootstrap $runtimeBootstrap,
    ) {}

    /**
     * @return array{report: array<string, mixed>, paths: array<string, string>}
     */
    public function run(int $repeats = 3, bool $forceFake = false): array
    {
        $mode = $this->resolveMode($forceFake);
        $isBaseline = $mode === 'openai';
        if (! $isBaseline) {
            $repeats = 1; // fake/heuristic is deterministisch
        }

        $fingerprint = $this->promptFingerprint->capture(self::PROMPT_NAMES);
        $commitSha = trim((string) shell_exec('git rev-parse HEAD 2>/dev/null')) ?: 'unknown';
        $model = (string) config('ai.model');
        $temperature = (float) config('ai.temperature', 0.2);

        $fixtures = $this->loadFixtures();
        $cases = [];
        $notRunnable = [];

        foreach ($fixtures as $fixture) {
            $kind = $fixture['kind'] ?? null;
            for ($i = 1; $i <= $repeats; $i++) {
                try {
                    $result = match ($kind) {
                        'request_text' => $this->requestTextRunner->run($fixture, $i),
                        'customer_answer' => $this->customerAnswerRunner->run($fixture, $i),
                        'photo_observation' => $this->photoObservationRunner->run($fixture, $i),
                        default => null,
                    };
                    if ($result === null) {
                        $notRunnable[] = [
                            'id' => $fixture['id'] ?? 'unknown',
                            'reason' => 'Onbekend fixture-kind: '.json_encode($kind),
                        ];

                        break;
                    }
                    $cases[] = $result;
                } catch (\Throwable $e) {
                    $notRunnable[] = [
                        'id' => $fixture['id'] ?? 'unknown',
                        'reason' => $e->getMessage(),
                    ];

                    break;
                }
            }
        }

        // Dossier-synthesis C5 samples where possible without photos
        $notRunnable[] = $this->tryDossierSynthesisNote();

        $aggregated = $this->aggregate($cases);
        $report = [
            'title' => 'P1 stap 1: evaluatieset tekstinterpretatie',
            'is_baseline' => $isBaseline,
            'mode' => $mode,
            'date' => $fingerprint['date'],
            'commit_sha' => $commitSha,
            'model' => $model,
            'vision_model' => config('ai.vision_model'),
            'dossier_model' => config('ai.dossier_model'),
            'temperature' => $temperature,
            'classification_temperature' => config('ai.classification_temperature'),
            'repeats' => $repeats,
            'prompt_fingerprint' => $fingerprint,
            'template_version_target' => 'airco v26 enums',
            'api_key_present' => $this->apiKeyPresent(),
            'blocker' => $isBaseline ? null : 'blokker: env var AI_API_KEY ontbreekt (of --fake); echte baseline vereist AI_API_KEY',
            'scores_by_component' => $aggregated['scores_by_component'],
            'scores_by_fact_type' => $aggregated['scores_by_fact_type'],
            'disputed_scores' => $aggregated['disputed_scores'],
            'errors' => $aggregated['errors'],
            'spread' => $aggregated['spread'],
            'not_runnable' => $notRunnable,
            'unfilled_fixtures' => $this->unfilledFixtures($fixtures),
            'normalizer_audit' => $this->normalizerAudit(),
            'cases' => $cases,
        ];

        $paths = $this->reportWriter->write($report);

        return ['report' => $report, 'paths' => $paths];
    }

    private function resolveMode(bool $forceFake): string
    {
        if ($forceFake) {
            return 'fake';
        }
        if (! $this->apiKeyPresent()) {
            return (string) (config('ai.provider') ?: 'fake');
        }

        return (string) config('ai.provider', 'openai');
    }

    private function apiKeyPresent(): bool
    {
        return $this->runtimeBootstrap->resolveApiKey() !== null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadFixtures(): array
    {
        $root = base_path('tests/Eval/fixtures');
        $finder = (new Finder)->files()->name('*.json')->in([
            $root.'/request_texts',
            $root.'/customer_answers',
            $root.'/photo_observations',
        ])->sortByName();

        $fixtures = [];
        foreach ($finder as $file) {
            $decoded = json_decode($file->getContents(), true);
            if (is_array($decoded) && isset($decoded['id'], $decoded['kind'])) {
                $fixtures[] = $decoded;
            }
        }

        return $fixtures;
    }

    /**
     * @param  list<array<string, mixed>>  $cases
     * @return array{
     *     scores_by_component: array<string, array<string, array{correct: int, wrong: int, missing: int, not_supported: int, total: int}>>,
     *     scores_by_fact_type: array<string, array<string, int>>,
     *     disputed_scores: array<string, mixed>,
     *     errors: list<array<string, mixed>>,
     *     spread: array<string, mixed>|null
     * }
     */
    private function aggregate(array $cases): array
    {
        $components = [];
        foreach (['C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'C8'] as $comp) {
            $components[$comp] = [
                'model_raw' => ['correct' => 0, 'wrong' => 0, 'missing' => 0, 'not_supported' => 0, 'total' => 0],
                'pipeline_final' => ['correct' => 0, 'wrong' => 0, 'missing' => 0, 'not_supported' => 0, 'total' => 0],
            ];
        }

        $factTypes = [];
        $disputed = [];
        $errors = [];
        $perCaseRepeatCorrect = [];

        foreach ($cases as $case) {
            $caseId = is_string($case['id'] ?? null) ? $case['id'] : 'unknown';
            $repeat = (int) ($case['repeat'] ?? 1);

            foreach (['model_raw', 'pipeline_final'] as $layer) {
                $scores = $case['scores'][$layer] ?? [];
                if (! is_array($scores)) {
                    continue;
                }
                $correctCount = 0;
                $scoredCount = 0;
                foreach ($scores as $factKey => $score) {
                    if (! is_array($score)) {
                        continue;
                    }
                    $status = (string) ($score['status'] ?? 'missing');
                    $isDisputed = (bool) ($score['disputed'] ?? false);
                    $comps = is_array($score['components'] ?? null) ? $score['components'] : [];

                    if ($isDisputed) {
                        $disputed[$caseId][$layer][$factKey] = $score;

                        continue;
                    }

                    foreach ($comps as $comp) {
                        if (! isset($components[$comp][$layer])) {
                            continue;
                        }
                        $components[$comp][$layer][$status] = ($components[$comp][$layer][$status] ?? 0) + 1;
                        $components[$comp][$layer]['total']++;
                    }

                    $factTypes[$factKey][$status] = ($factTypes[$factKey][$status] ?? 0) + 1;

                    if (in_array($status, ['wrong', 'missing'], true) && $layer === 'pipeline_final') {
                        $errors[] = [
                            'case_id' => $caseId,
                            'layer' => $layer,
                            'fact' => $factKey,
                            'status' => $status,
                            'expected' => $score['expected'] ?? null,
                            'got' => $score['got'] ?? null,
                        ];
                    }

                    if ($status !== 'not_supported') {
                        $scoredCount++;
                        if ($status === 'correct') {
                            $correctCount++;
                        }
                    }
                }

                if ($layer === 'pipeline_final') {
                    $perCaseRepeatCorrect[$caseId][$repeat] = $scoredCount === 0
                        ? null
                        : $correctCount / $scoredCount;
                }
            }
        }

        $spread = null;
        $multi = false;
        foreach ($perCaseRepeatCorrect as $repeats) {
            if (count(array_filter($repeats, static fn ($v) => $v !== null)) > 1) {
                $multi = true;
                break;
            }
        }
        if ($multi) {
            $spread = [];
            foreach ($perCaseRepeatCorrect as $caseId => $repeats) {
                $vals = array_values(array_filter($repeats, static fn ($v) => is_float($v) || is_int($v)));
                if ($vals === []) {
                    continue;
                }
                $spread[$caseId] = [
                    'min' => min($vals),
                    'max' => max($vals),
                    'mean' => array_sum($vals) / count($vals),
                    'repeats' => $repeats,
                ];
            }
        }

        return [
            'scores_by_component' => $components,
            'scores_by_fact_type' => $factTypes,
            'disputed_scores' => $disputed,
            'errors' => $errors,
            'spread' => $spread,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $fixtures
     * @return list<array{id: string, reason: string}>
     */
    private function unfilledFixtures(array $fixtures): array
    {
        $out = [];
        foreach ($fixtures as $fixture) {
            $kind = $fixture['source_kind'] ?? null;
            if ($kind === 'reconstructed') {
                $out[] = [
                    'id' => (string) ($fixture['id'] ?? ''),
                    'reason' => 'reconstructed — echte prod-tekst nog niet geëxporteerd; vervangen via eval:import-traces',
                ];
            }
        }

        return $out;
    }

    /**
     * @return array{id: string, reason: string}
     */
    private function tryDossierSynthesisNote(): array
    {
        $dir = base_path('tests/fixtures/dossier-synthesis');
        if (! File::isDirectory($dir)) {
            return [
                'id' => 'dossier-synthesis',
                'reason' => 'not_runnable: geen tests/fixtures/dossier-synthesis aanwezig',
            ];
        }

        // C5 kan via PhotoObservationCaseRunner (hedged fusebox). Volledige dossiersynthese
        // zonder echte foto's/vision blijft beperkt — noteer expliciet.
        return [
            'id' => 'dossier-synthesis-full',
            'reason' => 'not_runnable: volledige dossiersynthese (C5/C8 end-to-end) vereist vision-foto\'s; C5 hedge-gedrag wel gemeten via photo_observations + DerivedClaimConfidenceGuard. Snapshots in tests/fixtures/dossier-synthesis/ beschikbaar voor latere uitbreiding.',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function normalizerAudit(): array
    {
        return [
            'CustomerFacingTaskText' => 'Normaliseert/filtert klantgerichte taaktekst (audience safety); vult geen technische feiten in. Geen interpretatie van aanvraagtekst.',
            'PhotoObservationRelevance' => 'Interpreteert relevantie van foto-notities t.o.v. geplande binnenunit (keyword/display). Wel lichte betekenis, geen catalogus-prefill.',
            'AiEnumNormalizer' => 'Alleen synonym→catalogus-token normalisatie; inventariseert geen domeinfeiten.',
            'DossierSynthesisPartialAcceptor' => 'Valideert/herschrijft synthese (subject-refs, connections, hedge via DerivedClaimConfidenceGuard). Wél post-interpretatie op AI-output; niet aangepast in deze PR.',
        ];
    }
}
