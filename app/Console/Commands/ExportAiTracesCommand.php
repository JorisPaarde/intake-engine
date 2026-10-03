<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\AI\Models\AiTrace;
use App\Domains\AI\Services\AiTraceExporter;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class ExportAiTracesCommand extends Command
{
    protected $signature = 'ai:traces:export
        {--intake=* : Intake-ID(s); comma-separated and/or repeated}
        {--since= : Alleen traces vanaf (Y-m-d of ISO-8601)}
        {--until= : Alleen traces tot (Y-m-d of ISO-8601)}
        {--demo-only : Alleen demo-traces (is_demo)}
        {--format= : jsonl|md; leeg = beide}
        {--output= : Doelmap/prefix t.o.v. storage/app/exports (of absoluut); exports/ voorvoegsel wordt niet verdubbeld}';

    protected $description = 'Exporteer AI-traces (gemaskeerd) als JSONL en/of Markdown, met auto-split';

    public function handle(AiTraceExporter $exporter): int
    {
        $intakeIds = $this->parseIntakeIds();
        $formats = $this->parseFormats();
        $outputBase = $this->resolveOutputBase();

        $query = AiTrace::query()->orderBy('intake_ref_id')->orderBy('id');

        if ($intakeIds !== []) {
            $query->where(function ($builder) use ($intakeIds): void {
                $builder->whereIn('intake_ref_id', $intakeIds)
                    ->orWhereIn('intake_id', $intakeIds);
            });
        }

        if ($this->option('demo-only')) {
            $query->where('is_demo', true);
        }

        $since = $this->parseDateOption('since');
        if ($since !== null) {
            $query->where('created_at', '>=', $since);
        }

        $until = $this->parseDateOption('until');
        if ($until !== null) {
            $query->where('created_at', '<=', $until);
        }

        $traces = $query->get();

        if ($traces->isEmpty()) {
            $this->warn('Geen AI-traces gevonden voor de gegeven filters.');

            return self::SUCCESS;
        }

        // Trailing slash or existing directory → stamped files inside that directory.
        // Otherwise treat --output as a file prefix (parent dir is created).
        $prefix = $outputBase;
        if (str_ends_with($outputBase, DIRECTORY_SEPARATOR)
            || (is_dir($outputBase) && ! is_file($outputBase))) {
            File::ensureDirectoryExists(rtrim($outputBase, DIRECTORY_SEPARATOR));
            $prefix = rtrim($outputBase, DIRECTORY_SEPARATOR)
                .DIRECTORY_SEPARATOR
                .'ai-traces-'
                .now()->format('Ymd-His');
        } else {
            File::ensureDirectoryExists(dirname($prefix));
        }

        $parts = $exporter->splitByBudget($traces);
        $partCount = count($parts);
        $totalCost = (int) $traces->sum(fn (AiTrace $trace): int => (int) ($trace->estimated_cost_cents ?? 0));
        $totalIntakes = $traces
            ->map(fn (AiTrace $trace): int => (int) ($trace->intake_ref_id ?? $trace->intake_id ?? 0))
            ->unique()
            ->count();
        $manifestParts = [];
        /** @var list<string> $writtenAbsolutePaths */
        $writtenAbsolutePaths = [];

        foreach ($parts as $index => $part) {
            $partNumber = $index + 1;
            $suffix = $partCount > 1 ? '-part'.$partNumber.'-of-'.$partCount : '';
            $written = [];

            $meta = [
                'part' => $partNumber,
                'parts' => $partCount,
                'intake_ids_in_part' => $part['intake_ids'],
                'total_intakes' => $totalIntakes,
                'total_calls' => $traces->count(),
                'total_cost_cents' => $totalCost,
            ];

            foreach ($formats as $format) {
                $path = $prefix.$suffix.'.'.$format;
                $body = $format === 'jsonl'
                    ? $exporter->renderJsonl($part['traces'])
                    : $exporter->renderMarkdown($part['traces'], $meta);
                File::put($path, $body);
                $absolute = $this->absolutePath($path);
                $writtenAbsolutePaths[] = $absolute;
                $written[$format] = [
                    'path' => $absolute,
                    'bytes' => strlen($body),
                ];
                $this->info("Schreef {$format}: {$absolute} (".strlen($body).' bytes)');
            }

            $manifestParts[] = [
                'part' => $partNumber,
                'parts' => $partCount,
                'intake_ids' => $part['intake_ids'],
                'call_count' => count($part['traces']),
                'approx_bytes' => $part['approx_bytes'],
                'files' => $written,
            ];
        }

        $manifestPath = $prefix.'-manifest.json';
        $manifest = [
            'generated_at' => now()->toIso8601String(),
            'filters' => [
                'intake' => $intakeIds,
                'since' => $this->option('since'),
                'until' => $this->option('until'),
                'demo_only' => (bool) $this->option('demo-only'),
                'format' => $formats,
            ],
            'totals' => [
                'intakes' => $totalIntakes,
                'calls' => $traces->count(),
                'estimated_cost_cents' => $totalCost,
                'parts' => $partCount,
            ],
            'parts' => $manifestParts,
        ];
        File::put(
            $manifestPath,
            (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
        );
        $manifestAbsolute = $this->absolutePath($manifestPath);
        $writtenAbsolutePaths[] = $manifestAbsolute;

        $this->newLine();
        $this->info("Export klaar: {$traces->count()} call(s) over {$totalIntakes} intake(s) in {$partCount} part(s).");
        $this->line('Totale geschatte kosten: '.$totalCost.' cent.');
        foreach ($manifestParts as $part) {
            $this->line(sprintf(
                '  part %d/%d — intakes [%s] — %d calls — ~%d bytes',
                $part['part'],
                $part['parts'],
                implode(',', $part['intake_ids']),
                $part['call_count'],
                $part['approx_bytes'],
            ));
        }

        $this->newLine();
        $this->info('Bestanden (absolute paden):');
        foreach ($writtenAbsolutePaths as $absolute) {
            $this->line('  '.$absolute);
        }

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function parseIntakeIds(): array
    {
        $ids = [];

        foreach ((array) $this->option('intake') as $value) {
            if ($value === null || $value === '') {
                continue;
            }

            foreach (preg_split('/\s*,\s*/', trim((string) $value)) ?: [] as $piece) {
                if ($piece !== '' && ctype_digit($piece)) {
                    $ids[] = (int) $piece;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<'jsonl'|'md'>
     */
    private function parseFormats(): array
    {
        $format = $this->option('format');
        if (! is_string($format) || trim($format) === '') {
            return ['jsonl', 'md'];
        }

        $normalized = strtolower(trim($format));
        if ($normalized === 'jsonl') {
            return ['jsonl'];
        }
        if (in_array($normalized, ['md', 'markdown'], true)) {
            return ['md'];
        }

        $this->error('Ongeldig --format; gebruik jsonl of md.');

        return ['jsonl', 'md'];
    }

    private function resolveOutputBase(): string
    {
        $exportsRoot = storage_path('app/exports');
        File::ensureDirectoryExists($exportsRoot);

        $output = $this->option('output');
        if (! is_string($output) || trim($output) === '') {
            return $exportsRoot.DIRECTORY_SEPARATOR.'ai-traces-'.now()->format('Ymd-His');
        }

        $path = str_replace('\\', '/', trim($output));

        // Absolute paths (Unix or Windows) stay as given.
        if (str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return str_replace('/', DIRECTORY_SEPARATOR, $path);
        }

        // Relative → under storage/app/exports. Strip a leading exports/ or
        // storage/app/exports/ so --output=exports/foo does not become
        // storage/app/exports/exports/foo.
        $relative = ltrim($path, '/');
        if ($relative === 'storage/app/exports' || $relative === 'exports') {
            $relative = '';
        } elseif (str_starts_with($relative, 'storage/app/exports/')) {
            $relative = substr($relative, strlen('storage/app/exports/'));
        } elseif (str_starts_with($relative, 'exports/')) {
            $relative = substr($relative, strlen('exports/'));
        }

        $relative = ltrim(str_replace('\\', '/', (string) $relative), '/');
        if ($relative === '' || $relative === '.') {
            return $exportsRoot.DIRECTORY_SEPARATOR;
        }

        return $exportsRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    private function absolutePath(string $path): string
    {
        $real = realpath($path);
        if (is_string($real)) {
            return $real;
        }

        if (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }

    private function parseDateOption(string $name): ?Carbon
    {
        $value = $this->option($name);
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            $this->warn("Kon --{$name}={$value} niet parsen; filter genegeerd.");

            return null;
        }
    }
}
