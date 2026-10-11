<?php

declare(strict_types=1);

namespace App\Domains\AI\Eval;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Vergelijkt twee eval-runs: scoreverschil per feit/component + better/worse cases.
 */
final class EvalComparer
{
    /**
     * @return array<string, mixed>
     */
    public function compare(string $currentJsonPath, string $previousRef): array
    {
        $current = $this->loadReport($currentJsonPath);
        $previousPath = $this->resolvePrevious($previousRef);
        $previous = $this->loadReport($previousPath);

        $componentDiff = [];
        foreach (['C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'C8'] as $comp) {
            foreach (['model_raw', 'pipeline_final'] as $layer) {
                $cur = $current['scores_by_component'][$comp][$layer] ?? ['correct' => 0, 'total' => 0];
                $prev = $previous['scores_by_component'][$comp][$layer] ?? ['correct' => 0, 'total' => 0];
                $componentDiff[$comp][$layer] = [
                    'previous_correct' => (int) ($prev['correct'] ?? 0),
                    'previous_total' => (int) ($prev['total'] ?? 0),
                    'current_correct' => (int) ($cur['correct'] ?? 0),
                    'current_total' => (int) ($cur['total'] ?? 0),
                    'delta_correct' => (int) ($cur['correct'] ?? 0) - (int) ($prev['correct'] ?? 0),
                ];
            }
        }

        $prevCaseScores = $this->caseAccuracy($previous);
        $curCaseScores = $this->caseAccuracy($current);
        $better = [];
        $worse = [];
        $unchanged = [];
        $allIds = array_unique(array_merge(array_keys($prevCaseScores), array_keys($curCaseScores)));
        sort($allIds);
        foreach ($allIds as $id) {
            $p = $prevCaseScores[$id] ?? 0.0;
            $c = $curCaseScores[$id] ?? 0.0;
            $delta = $c - $p;
            $row = ['id' => $id, 'previous' => $p, 'current' => $c, 'delta' => $delta];
            if ($delta > 0.001) {
                $better[] = $row;
            } elseif ($delta < -0.001) {
                $worse[] = $row;
            } else {
                $unchanged[] = $row;
            }
        }

        $factDiffs = $this->factDiffs($previous, $current);

        return [
            'previous_path' => $previousPath,
            'current_path' => $currentJsonPath,
            'previous_prompt_hash' => $previous['prompt_fingerprint']['combined_hash'] ?? null,
            'current_prompt_hash' => $current['prompt_fingerprint']['combined_hash'] ?? null,
            'component_diff' => $componentDiff,
            'better_cases' => $better,
            'worse_cases' => $worse,
            'unchanged_cases' => $unchanged,
            'fact_diffs' => $factDiffs,
        ];
    }

    /**
     * @param  array<string, mixed>  $diff
     */
    public function toMarkdown(array $diff): string
    {
        $lines = [];
        $lines[] = '# Eval-vergelijking';
        $lines[] = '';
        $lines[] = '- Vorige: `'.($diff['previous_path'] ?? '').'` (prompt `'.($diff['previous_prompt_hash'] ?? '').'`)';
        $lines[] = '- Huidig: `'.($diff['current_path'] ?? '').'` (prompt `'.($diff['current_prompt_hash'] ?? '').'`)';
        $lines[] = '';
        $lines[] = '## Component-delta (pipeline_final correct)';
        $lines[] = '';
        $lines[] = '| Component | Was | Nu | Δ |';
        $lines[] = '|-----------|-----|----|---|';
        foreach (($diff['component_diff'] ?? []) as $comp => $layers) {
            $final = $layers['pipeline_final'] ?? [];
            $lines[] = sprintf(
                '| %s | %d/%d | %d/%d | %+d |',
                $comp,
                (int) ($final['previous_correct'] ?? 0),
                (int) ($final['previous_total'] ?? 0),
                (int) ($final['current_correct'] ?? 0),
                (int) ($final['current_total'] ?? 0),
                (int) ($final['delta_correct'] ?? 0),
            );
        }
        $lines[] = '';
        $lines[] = '## Cases beter';
        $lines[] = '';
        foreach (($diff['better_cases'] ?? []) as $row) {
            $lines[] = sprintf('- `%s`: %.2f → %.2f (Δ %+.2f)', $row['id'], $row['previous'], $row['current'], $row['delta']);
        }
        if (($diff['better_cases'] ?? []) === []) {
            $lines[] = '_Geen._';
        }
        $lines[] = '';
        $lines[] = '## Cases slechter';
        $lines[] = '';
        foreach (($diff['worse_cases'] ?? []) as $row) {
            $lines[] = sprintf('- `%s`: %.2f → %.2f (Δ %+.2f)', $row['id'], $row['previous'], $row['current'], $row['delta']);
        }
        if (($diff['worse_cases'] ?? []) === []) {
            $lines[] = '_Geen._';
        }
        $lines[] = '';
        $lines[] = '## Feiten die van status wisselden (pipeline_final)';
        $lines[] = '';
        foreach (($diff['fact_diffs'] ?? []) as $row) {
            $lines[] = sprintf(
                '- `%s` / `%s`: %s → %s',
                $row['case_id'] ?? '',
                $row['fact'] ?? '',
                $row['previous'] ?? '',
                $row['current'] ?? '',
            );
        }
        if (($diff['fact_diffs'] ?? []) === []) {
            $lines[] = '_Geen._';
        }
        $lines[] = '';

        return implode("\n", $lines)."\n";
    }

    /**
     * @return array<string, mixed>
     */
    private function loadReport(string $path): array
    {
        if (! File::isFile($path)) {
            throw new RuntimeException("Eval-rapport niet gevonden: {$path}");
        }
        $decoded = json_decode((string) File::get($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException("Ongeldig eval-rapport: {$path}");
        }

        return $decoded;
    }

    private function resolvePrevious(string $ref): string
    {
        if (File::isFile($ref)) {
            return $ref;
        }
        $candidates = [
            base_path('tests/Eval/results/'.$ref),
            base_path('tests/Eval/results/'.$ref.'.json'),
            base_path('tests/Eval/baseline/'.$ref),
            base_path('tests/Eval/baseline/'.$ref.'.json'),
            storage_path('app/eval/'.$ref),
            storage_path('app/eval/'.$ref.'.json'),
        ];
        foreach ($candidates as $candidate) {
            if (File::isFile($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException("Vorige run niet gevonden: {$ref}");
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, float>
     */
    private function caseAccuracy(array $report): array
    {
        $out = [];
        foreach (($report['cases'] ?? []) as $case) {
            if (! is_array($case)) {
                continue;
            }
            $id = is_string($case['id'] ?? null) ? $case['id'] : null;
            if ($id === null) {
                continue;
            }
            $scores = $case['scores']['pipeline_final'] ?? [];
            if (! is_array($scores) || $scores === []) {
                $out[$id] = $out[$id] ?? 0.0;

                continue;
            }
            $correct = 0;
            $total = 0;
            foreach ($scores as $fact) {
                if (! is_array($fact) || ($fact['disputed'] ?? false)) {
                    continue;
                }
                $total++;
                if (($fact['status'] ?? null) === 'correct') {
                    $correct++;
                }
            }
            $acc = $total === 0 ? 0.0 : $correct / $total;
            // Gemiddelde over repeats
            if (! isset($out[$id])) {
                $out[$id] = $acc;
            } else {
                $out[$id] = ($out[$id] + $acc) / 2;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $current
     * @return list<array<string, mixed>>
     */
    private function factDiffs(array $previous, array $current): array
    {
        $prevMap = $this->factStatusMap($previous);
        $curMap = $this->factStatusMap($current);
        $diffs = [];
        foreach ($curMap as $key => $status) {
            $prev = $prevMap[$key] ?? null;
            if ($prev !== null && $prev !== $status) {
                [$caseId, $fact] = array_pad(explode('::', $key, 2), 2, '');
                $diffs[] = [
                    'case_id' => $caseId,
                    'fact' => $fact,
                    'previous' => $prev,
                    'current' => $status,
                ];
            }
        }

        return $diffs;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, string>
     */
    private function factStatusMap(array $report): array
    {
        $map = [];
        foreach (($report['cases'] ?? []) as $case) {
            if (! is_array($case)) {
                continue;
            }
            $id = is_string($case['id'] ?? null) ? $case['id'] : null;
            if ($id === null) {
                continue;
            }
            foreach (($case['scores']['pipeline_final'] ?? []) as $fact => $score) {
                if (! is_array($score)) {
                    continue;
                }
                $map[$id.'::'.$fact] = (string) ($score['status'] ?? '');
            }
        }

        return $map;
    }
}
