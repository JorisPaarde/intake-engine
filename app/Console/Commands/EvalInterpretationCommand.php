<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\AI\Eval\EvalComparer;
use App\Domains\AI\Eval\EvalRuntimeBootstrap;
use App\Domains\AI\Eval\InterpretationEvalRunner;
use App\Domains\AI\Eval\TraceFixtureImporter;
use App\Domains\Intake\Models\IntakeTemplate;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Meet tekstinterpretatie (model_raw vs pipeline_final).
 *
 * Echte baseline: AI_API_KEY (+ optioneel AI_MODEL/AI_BASE_URL/budget uit lokale
 * throwaway-.env). Nooit op production/staging (geen AI-runs/traces op live DB,
 * geen shared daily budget). migrate:fresh alleen op sqlite.
 */
final class EvalInterpretationCommand extends Command
{
    protected $signature = 'eval:interpretation
        {--repeats=3 : Aantal herhalingen per case (echte model-run); fake=1}
        {--fake : Forceer FakeAiClient (GEEN baseline)}
        {--compare= : Pad of bestandsstempel van een vorige run (JSON)}
        {--migrate : Draai migrate:fresh --seed (ALLEEN lokale sqlite; nooit prod/staging)}';

    protected $description = 'Evalueer tekstinterpretatie (request prefill, follow-up hoogte, foto-observaties)';

    public function handle(EvalRuntimeBootstrap $runtime): int
    {
        $liveBlock = $this->liveEnvBlockReason();
        if ($liveBlock !== null) {
            $this->error($liveBlock);
            $this->line('Draai alleen lokaal of in CI (APP_ENV=local/testing) op een throwaway sqlite-DB. Nooit op production/staging: eval schrijft AI-runs/traces en deelt het daily budget.');

            return self::FAILURE;
        }

        $forceFake = (bool) $this->option('fake');
        $activation = $runtime->activate($forceFake);
        $forceFake = $activation['mode'] === 'fake';

        if ($forceFake) {
            if (! $activation['api_key_present']) {
                $this->warn('blokker: env var AI_API_KEY ontbreekt');
                $this->warn('Draai tegen FakeAiClient — rapport wordt gemarkeerd als GEEN baseline.');
            } elseif ((bool) $this->option('fake')) {
                $this->warn('AI_API_KEY aanwezig maar --fake gezet — GEEN baseline.');
            }
        } else {
            $this->info('Echte eval (openai): '.implode(', ', $activation['applied']));
            $this->line(sprintf(
                '  model=%s  base_url=%s',
                (string) config('ai.model'),
                (string) config('ai.base_url'),
            ));
            foreach ($activation['warnings'] as $warning) {
                $this->warn($warning);
            }
        }

        // Resolve ná runtime-config, anders blijft NullAiClient/FakeAiClient verkeerd hangen.
        /** @var InterpretationEvalRunner $runner */
        $runner = $this->laravel->make(InterpretationEvalRunner::class);
        /** @var EvalComparer $comparer */
        $comparer = $this->laravel->make(EvalComparer::class);
        /** @var TraceFixtureImporter $privacy */
        $privacy = $this->laravel->make(TraceFixtureImporter::class);

        $wantsFresh = (bool) $this->option('migrate') || $this->needsTemplates();
        if ($wantsFresh) {
            $block = $this->freshMigrateBlockReason();
            if ($block !== null) {
                $this->error($block);
                $this->line('Gebruik een lokale throwaway sqlite-DB (DB_CONNECTION=sqlite). Kopieer hooguit AI_MODEL / AI_BASE_URL / budget uit prod — nooit de prod/staging-database.');

                return self::FAILURE;
            }

            $this->info('Database voorbereiden (migrate:fresh + IntakeTemplateSeeder op sqlite)…');
            Artisan::call('migrate:fresh', ['--force' => true]);
            $this->output->write(Artisan::output());
            $this->call('db:seed', ['--class' => IntakeTemplateSeeder::class, '--force' => true]);
        }

        $scanHits = $this->scanFixtures($privacy);
        if ($scanHits !== []) {
            $this->error('Privacy-scan vond treffers in fixtures:');
            foreach ($scanHits as $hit) {
                $this->line('  - '.$hit);
            }

            return self::FAILURE;
        }
        $this->info('Privacy-scan fixtures: schoon (geen postcode/e-mail/telefoon/straat+huisnr).');

        $repeats = max(1, (int) $this->option('repeats'));

        try {
            $result = $runner->run(repeats: $repeats, forceFake: $forceFake);
        } catch (Throwable $e) {
            $this->error('Eval-run mislukt: '.$e->getMessage());

            return self::FAILURE;
        }

        $report = $result['report'];
        $paths = $result['paths'];

        $this->newLine();
        $this->info('Rapport geschreven:');
        foreach ($paths as $label => $path) {
            $this->line("  [{$label}] {$path}");
        }

        $promptHash = is_string($report['prompt_fingerprint']['combined_hash'] ?? null)
            ? $report['prompt_fingerprint']['combined_hash']
            : '?';
        $prefillVersion = '?';
        foreach (($report['prompt_fingerprint']['prompts'] ?? []) as $prompt) {
            if (is_array($prompt) && ($prompt['name'] ?? null) === 'request_prefill') {
                $prefillVersion = is_string($prompt['version'] ?? null) ? $prompt['version'] : '?';
                break;
            }
        }
        $this->line("Prompt: {$prefillVersion} — hash {$promptHash} → tests/Eval/results/<datum>-{$promptHash}.{json,md} + HISTORY.md");

        if (! ($report['is_baseline'] ?? false)) {
            $this->warn('GEEN baseline — mode='.($report['mode'] ?? '?'));
            if (is_string($report['blocker'] ?? null)) {
                $this->warn($report['blocker']);
            }
        }

        $this->newLine();
        $rows = [];
        $byComponent = is_array($report['scores_by_component'] ?? null) ? $report['scores_by_component'] : [];
        foreach ($byComponent as $comp => $layers) {
            if (! is_array($layers)) {
                continue;
            }
            $raw = is_array($layers['model_raw'] ?? null) ? $layers['model_raw'] : [];
            $final = is_array($layers['pipeline_final'] ?? null) ? $layers['pipeline_final'] : [];
            $rows[] = [
                (string) $comp,
                ($raw['correct'] ?? 0).'/'.($raw['total'] ?? 0),
                ($final['correct'] ?? 0).'/'.($final['total'] ?? 0),
            ];
        }
        $this->table(['Component', 'raw correct/total', 'final correct/total'], $rows);

        $compareRef = $this->option('compare');
        if (is_string($compareRef) && $compareRef !== '') {
            $diff = $comparer->compare($paths['results_json'], $compareRef);
            $md = $comparer->toMarkdown($diff);
            $diffPath = storage_path('app/eval/compare-'.now()->format('Ymd-His').'.md');
            file_put_contents($diffPath, $md);
            $this->newLine();
            $this->info('Vergelijking: '.$diffPath);
            $this->line($md);
        }

        return self::SUCCESS;
    }

    /**
     * Weiger de hele eval op production/staging (live DB + shared budget).
     */
    public function liveEnvBlockReason(): ?string
    {
        $appEnv = strtolower(trim((string) config('app.env', '')));
        if (in_array($appEnv, ['production', 'staging', 'prod'], true)) {
            return 'Geweigerd: eval:interpretation mag niet op APP_ENV='.$appEnv.' (production/staging).';
        }

        return null;
    }

    /**
     * Weiger migrate:fresh op niet-sqlite (dataverlies). Live-env is al geblokkeerd.
     */
    public function freshMigrateBlockReason(): ?string
    {
        $connection = strtolower(trim((string) config('database.default', '')));
        if ($connection !== 'sqlite') {
            return 'Geweigerd: migrate:fresh alleen op DB_CONNECTION=sqlite (nu: '
                .($connection !== '' ? $connection : '(leeg)').').';
        }

        return null;
    }

    private function needsTemplates(): bool
    {
        try {
            return IntakeTemplate::query()->where('key', 'airco')->doesntExist();
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * @return list<string>
     */
    private function scanFixtures(TraceFixtureImporter $privacy): array
    {
        $hits = [];
        $root = base_path('tests/Eval/fixtures');
        foreach (['request_texts', 'customer_answers', 'photo_observations'] as $dir) {
            foreach (glob($root.'/'.$dir.'/*.json') ?: [] as $file) {
                $data = json_decode((string) file_get_contents($file), true);
                if (! is_array($data)) {
                    continue;
                }
                $text = is_string($data['text'] ?? null) ? $data['text'] : '';
                foreach ($privacy->privacyScan($text) as $hit) {
                    $hits[] = basename($file).': '.$hit;
                }
            }
        }

        return $hits;
    }
}
