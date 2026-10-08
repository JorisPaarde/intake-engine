<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\AI\Eval\TraceFixtureImporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Importeer een lokale ai:traces:export JSONL naar tests/Eval/fixtures (met anonimisering).
 * Niet tegen staging/prod draaien.
 */
final class ImportEvalTracesCommand extends Command
{
    protected $signature = 'eval:import-traces
        {jsonl : Pad naar lokaal JSONL-exportbestand}
        {--dry-run : Alleen scannen/tellen, niets schrijven}';

    protected $description = 'Importeer geanonimiseerde AI-trace JSONL naar de evaluatieset-fixtures';

    public function handle(TraceFixtureImporter $importer): int
    {
        $path = (string) $this->argument('jsonl');
        $dryRun = (bool) $this->option('dry-run');

        try {
            $result = $importer->import($path, $dryRun);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(($dryRun ? '[dry-run] ' : '').'Geïmporteerd: '.$result['imported'].', overgeslagen: '.$result['skipped']);
        if ($result['privacy_hits'] !== []) {
            $this->warn('Privacy-treffers (geanonimiseerd):');
            foreach ($result['privacy_hits'] as $hit) {
                $this->line('  - '.$hit);
            }
        }
        foreach ($result['written'] as $file) {
            $this->line('  '.$file);
        }

        $this->comment('Vul daarna per fixture de expected-feiten handmatig in vóór een baseline-run.');

        return self::SUCCESS;
    }
}
