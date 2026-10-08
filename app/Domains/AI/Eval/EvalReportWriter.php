<?php

declare(strict_types=1);

namespace App\Domains\AI\Eval;

use Illuminate\Support\Facades\File;

/**
 * Schrijft JSON+MD rapporten naar storage/app/eval én tests/Eval/results (+ HISTORY).
 */
final class EvalReportWriter
{
    /**
     * @param  array<string, mixed>  $report
     * @return array{json: string, md: string, results_json: string, results_md: string}
     */
    public function write(array $report): array
    {
        $date = is_string($report['date'] ?? null) ? $report['date'] : now()->format('Y-m-d');
        $sha = is_string($report['commit_sha'] ?? null) ? substr($report['commit_sha'], 0, 7) : 'unknown';
        $promptHash = is_string($report['prompt_fingerprint']['combined_hash'] ?? null)
            ? $report['prompt_fingerprint']['combined_hash']
            : 'noprompt';

        $storageDir = storage_path('app/eval');
        File::ensureDirectoryExists($storageDir);
        $storageBase = $storageDir.'/'.$date.'-'.$sha;
        $jsonPath = $storageBase.'.json';
        $mdPath = $storageBase.'.md';

        $resultsDir = base_path('tests/Eval/results');
        File::ensureDirectoryExists($resultsDir);
        $resultsBase = $resultsDir.'/'.$date.'-'.$promptHash;
        $resultsJson = $resultsBase.'.json';
        $resultsMd = $resultsBase.'.md';

        $baselineDir = base_path('tests/Eval/baseline');
        File::ensureDirectoryExists($baselineDir);
        $baselineJson = $baselineDir.'/'.$date.'-'.$sha.'.json';
        $baselineMd = $baselineDir.'/'.$date.'-'.$sha.'.md';

        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Kon eval-rapport niet serialiseren.');
        }

        $md = $this->toMarkdown($report);

        File::put($jsonPath, $json."\n");
        File::put($mdPath, $md);
        File::put($resultsJson, $json."\n");
        File::put($resultsMd, $md);
        File::put($baselineJson, $json."\n");
        File::put($baselineMd, $md);

        $this->appendHistory($report, $date, $promptHash, $sha);

        return [
            'json' => $jsonPath,
            'md' => $mdPath,
            'results_json' => $resultsJson,
            'results_md' => $resultsMd,
            'baseline_json' => $baselineJson,
            'baseline_md' => $baselineMd,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public function toMarkdown(array $report): string
    {
        $isBaseline = (bool) ($report['is_baseline'] ?? false);
        $lines = [];
        $lines[] = '# Evaluatieset tekstinterpretatie — P1 stap 1';
        $lines[] = '';
        if (! $isBaseline) {
            $lines[] = '> **GEEN baseline** — deze run gebruikte geen echt model (`'.($report['mode'] ?? '?').'`).';
            $lines[] = '';
        }
        $lines[] = '- Datum: `'.($report['date'] ?? '').'`';
        $lines[] = '- Commit: `'.($report['commit_sha'] ?? '').'`';
        $lines[] = '- Mode: `'.($report['mode'] ?? '').'`';
        $lines[] = '- Model: `'.($report['model'] ?? '').'`';
        $lines[] = '- Temperatuur: `'.($report['temperature'] ?? '').'`';
        $lines[] = '- Repeats: `'.($report['repeats'] ?? '').'`';
        $lines[] = '- Prompt-hash: `'.($report['prompt_fingerprint']['combined_hash'] ?? '').'`';
        $lines[] = '';

        $lines[] = '## Promptversies';
        $lines[] = '';
        foreach (($report['prompt_fingerprint']['prompts'] ?? []) as $prompt) {
            if (! is_array($prompt)) {
                continue;
            }
            $lines[] = sprintf(
                '- `%s` versie `%s` — `%s` (sha `%s`)',
                $prompt['name'] ?? '',
                $prompt['version'] ?? '?',
                $prompt['prompt_path'] ?? '',
                substr((string) ($prompt['content_sha256'] ?? ''), 0, 12),
            );
        }
        $lines[] = '';

        $lines[] = '## Score per component';
        $lines[] = '';
        $lines[] = '| Component | model_raw correct | model_raw total | pipeline_final correct | pipeline_final total |';
        $lines[] = '|-----------|-------------------|-----------------|------------------------|----------------------|';
        foreach (($report['scores_by_component'] ?? []) as $comp => $layers) {
            if (! is_array($layers)) {
                continue;
            }
            $raw = is_array($layers['model_raw'] ?? null) ? $layers['model_raw'] : [];
            $final = is_array($layers['pipeline_final'] ?? null) ? $layers['pipeline_final'] : [];
            $lines[] = sprintf(
                '| %s | %d | %d | %d | %d |',
                $comp,
                (int) ($raw['correct'] ?? 0),
                (int) ($raw['total'] ?? 0),
                (int) ($final['correct'] ?? 0),
                (int) ($final['total'] ?? 0),
            );
        }
        $lines[] = '';

        $lines[] = '## Disputed feiten (apart gescoord)';
        $lines[] = '';
        $disputed = is_array($report['disputed_scores'] ?? null) ? $report['disputed_scores'] : [];
        if ($disputed === []) {
            $lines[] = '_Geen disputed feiten in deze run._';
        } else {
            $lines[] = '```json';
            $lines[] = json_encode($disputed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}';
            $lines[] = '```';
        }
        $lines[] = '';

        $lines[] = '## Bevindingen normalizers (geen wijziging)';
        $lines[] = '';
        foreach (($report['normalizer_audit'] ?? []) as $name => $verdict) {
            $lines[] = '- **'.$name.'**: '.$verdict;
        }
        $lines[] = '';

        $lines[] = '## Niet draaibaar / niet gevuld';
        $lines[] = '';
        foreach (($report['not_runnable'] ?? []) as $item) {
            if (is_array($item)) {
                $lines[] = '- `'.($item['id'] ?? '?').'`: '.($item['reason'] ?? '');
            }
        }
        if (($report['not_runnable'] ?? []) === []) {
            $lines[] = '_Geen._';
        }
        $lines[] = '';

        $lines[] = '## Fouten (verwacht vs gekregen)';
        $lines[] = '';
        $errors = $report['errors'] ?? [];
        if (! is_array($errors) || $errors === []) {
            $lines[] = '_Geen fouten of run zonder bruikbare scores._';
        } else {
            foreach ($errors as $error) {
                if (! is_array($error)) {
                    continue;
                }
                $lines[] = sprintf(
                    '- `%s` / `%s` / `%s`: verwacht `%s`, kreeg `%s` (%s)',
                    $error['case_id'] ?? '',
                    $error['layer'] ?? '',
                    $error['fact'] ?? '',
                    json_encode($error['expected'] ?? null, JSON_UNESCAPED_UNICODE),
                    json_encode($error['got'] ?? null, JSON_UNESCAPED_UNICODE),
                    $error['status'] ?? '',
                );
            }
        }
        $lines[] = '';

        $lines[] = '## Spreiding over repeats';
        $lines[] = '';
        $spread = $report['spread'] ?? null;
        if ($spread === null) {
            $lines[] = '_Eén repeat (deterministisch of fake-modus)._';
        } else {
            $lines[] = '```json';
            $lines[] = json_encode($spread, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}';
            $lines[] = '```';
        }
        $lines[] = '';

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function appendHistory(array $report, string $date, string $promptHash, string $sha): void
    {
        $historyPath = base_path('tests/Eval/results/HISTORY.md');
        if (! File::exists($historyPath)) {
            File::put($historyPath, "# Eval resultaatgeschiedenis (prompt-versies)\n\n| Datum | Prompt-hash | Commit | Mode | Model | Opmerking |\n|-------|-------------|--------|------|-------|-----------|\n");
        }

        $compSummary = [];
        foreach (($report['scores_by_component'] ?? []) as $comp => $layers) {
            if (! is_array($layers)) {
                continue;
            }
            $final = is_array($layers['pipeline_final'] ?? null) ? $layers['pipeline_final'] : [];
            $compSummary[] = $comp.':'.(int) ($final['correct'] ?? 0).'/'.(int) ($final['total'] ?? 0);
        }

        $note = ((bool) ($report['is_baseline'] ?? false) ? 'baseline' : 'GEEN baseline')
            .' — '.implode(' ', $compSummary);
        $line = sprintf(
            '| %s | `%s` | `%s` | %s | `%s` | %s |',
            $date,
            $promptHash,
            $sha,
            $report['mode'] ?? '?',
            $report['model'] ?? '?',
            str_replace('|', '/', $note),
        );

        File::append($historyPath, $line."\n");

        // Componentdetailblok
        $detail = "\n### {$date} / {$promptHash}\n\n";
        $detail .= "| Component | model_raw | pipeline_final |\n|-----------|-----------|----------------|\n";
        foreach (($report['scores_by_component'] ?? []) as $comp => $layers) {
            if (! is_array($layers)) {
                continue;
            }
            $raw = is_array($layers['model_raw'] ?? null) ? $layers['model_raw'] : [];
            $final = is_array($layers['pipeline_final'] ?? null) ? $layers['pipeline_final'] : [];
            $detail .= sprintf(
                "| %s | %d/%d | %d/%d |\n",
                $comp,
                (int) ($raw['correct'] ?? 0),
                (int) ($raw['total'] ?? 0),
                (int) ($final['correct'] ?? 0),
                (int) ($final['total'] ?? 0),
            );
        }
        File::append($historyPath, $detail);
    }
}
