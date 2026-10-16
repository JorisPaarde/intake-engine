<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\AI\Eval\EvalComparer;
use App\Domains\AI\Eval\EvalCostMeter;
use App\Domains\AI\Eval\EvalFixturePrivacyScan;
use App\Domains\AI\Eval\EvalReportWriter;
use App\Domains\AI\Eval\EvalRuntimeBootstrap;
use App\Domains\AI\Eval\InterpretationEvalRunner;
use App\Domains\Intake\Models\IntakeTemplate;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Throwable;

/**
 * Meet tekstinterpretatie (model_raw vs pipeline_final).
 *
 * Echte baseline: AI_API_KEY (+ optioneel AI_MODEL/AI_BASE_URL/budget uit lokale
 * throwaway-.env). Nooit op production/staging (geen AI-runs/traces op live DB,
 * geen shared daily budget). migrate:fresh alleen met --migrate op sqlite.
 */
final class EvalInterpretationCommand extends Command
{
    protected $signature = 'eval:interpretation
        {--repeats=3 : Aantal herhalingen per case (echte model-run); fake=1}
        {--fake : Forceer FakeAiClient (GEEN baseline)}
        {--kind= : Alleen dit fixture-kind (request_text,customer_answer,photo_observation,report_claims,option_summary,judge_calibration); leeg=alles}
        {--live-vision : Stuur photo_observation-fixturefoto\'s door echte vision-prompt (default uit; CI blijft fake)}
        {--prompt-version= : live of v10; held-out proposed-vN indien aanwezig; relevant met --live-vision}
        {--compare= : Pad of bestandsstempel van een vorige run (JSON)}
        {--migrate : Draai migrate:fresh --seed (ALLEEN lokale sqlite; nooit prod/staging)}
        {--output= : Root voor results/baseline/HISTORY (default: tests/Eval of AI_EVAL_OUTPUT_DIR)}';

    protected $description = 'Evalueer tekstinterpretatie (request prefill, follow-up hoogte, foto-observaties, report claims)';

    public function handle(EvalRuntimeBootstrap $runtime): int
    {
        $liveBlock = $this->liveEnvBlockReason();
        if ($liveBlock !== null) {
            $this->error($liveBlock);
            $this->line('Draai alleen lokaal of in CI (APP_ENV=local/testing) op een throwaway sqlite-DB. Nooit op production/staging: eval schrijft AI-runs/traces en deelt het daily budget.');

            return self::FAILURE;
        }

        $outputOpt = $this->option('output');
        if (is_string($outputOpt) && trim($outputOpt) !== '') {
            config(['ai.eval.output_dir' => trim($outputOpt)]);
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
        // N90: meter real provider cost of this run at the HTTP layer (incl. retries).
        $costMeter = new EvalCostMeter;
        $this->laravel->instance(EvalCostMeter::class, $costMeter);
        Event::listen(ResponseReceived::class, [$costMeter, 'onResponse']);

        /** @var InterpretationEvalRunner $runner */
        $runner = $this->laravel->make(InterpretationEvalRunner::class);
        /** @var EvalComparer $comparer */
        $comparer = $this->laravel->make(EvalComparer::class);
        /** @var EvalFixturePrivacyScan $privacy */
        $privacy = $this->laravel->make(EvalFixturePrivacyScan::class);

        if ((bool) $this->option('migrate')) {
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
        } elseif ($this->needsTemplates()) {
            $this->error('Geweigerd: airco-template ontbreekt. Draai met --migrate op een lokale sqlite-DB (migrate:fresh + seed).');

            return self::FAILURE;
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
        $kinds = $this->parseKindsOption($this->option('kind'));
        $liveVision = (bool) $this->option('live-vision');
        $promptVersionOpt = $this->option('prompt-version');
        $promptVersion = is_string($promptVersionOpt) && trim($promptVersionOpt) !== ''
            ? trim($promptVersionOpt)
            : null;

        if ($liveVision) {
            $runtime->enableLiveVision();
            $this->info('Live vision AAN — photo_observation fixtures met image + supported prompt gaan via AiGateway.');
            if ($promptVersion !== null) {
                $this->line('  prompt-version selector: '.$promptVersion);
            }
        }

        try {
            $result = $runner->run(
                repeats: $repeats,
                forceFake: $forceFake,
                kinds: $kinds,
                liveVision: $liveVision,
                promptVersion: $promptVersion,
            );
        } catch (Throwable $e) {
            $this->error('Eval-run mislukt: '.$e->getMessage());

            return self::FAILURE;
        }

        $report = $result['report'];
        $paths = $result['paths'];

        $this->newLine();
        $this->info('Rapport geschreven:');
        foreach ($paths as $label => $path) {
            if ($path === null || $path === '') {
                continue;
            }
            $this->line("  [{$label}] {$path}");
        }

        $promptHash = is_string($report['prompt_fingerprint']['combined_hash'] ?? null)
            ? $report['prompt_fingerprint']['combined_hash']
            : '?';
        $promptVersionsByName = [];
        foreach (($report['prompt_fingerprint']['prompts'] ?? []) as $prompt) {
            if (! is_array($prompt) || ! is_string($prompt['name'] ?? null)) {
                continue;
            }
            $promptVersionsByName[$prompt['name']] = is_string($prompt['version'] ?? null)
                ? $prompt['version']
                : '?';
        }
        $prefillVersion = $promptVersionsByName['request_prefill'] ?? '?';
        $outputRoot = app(EvalReportWriter::class)->outputRoot();
        $this->line("Prompt: {$prefillVersion} — hash {$promptHash} → {$outputRoot}/results/<datum>-{$promptHash}.{json,md}");

        $kindOpt = $this->option('kind');
        $kindFocusesReportClaims = is_string($kindOpt)
            && str_contains(strtolower($kindOpt), 'report_claims');
        if ($kindFocusesReportClaims) {
            $summaryVer = $promptVersionsByName['summary'] ?? '?';
            $attentionVer = $promptVersionsByName['attention_points'] ?? '?';
            $this->line("report_claims prompts: summary={$summaryVer} · attention_points={$attentionVer}");

            $totalTokens = 0;
            $tokenCases = 0;
            foreach (is_array($report['cases'] ?? null) ? $report['cases'] : [] as $case) {
                if (! is_array($case) || ($case['kind'] ?? null) !== 'report_claims') {
                    continue;
                }
                $usage = is_array($case['token_usage'] ?? null) ? $case['token_usage'] : [];
                if (is_int($usage['total_tokens'] ?? null)) {
                    $totalTokens += $usage['total_tokens'];
                    $tokenCases++;
                }
            }
            if ($tokenCases > 0) {
                $this->line("report_claims token_usage: {$totalTokens} total_tokens across {$tokenCases} case-runs");
            }
        }

        if (is_array($report['eval_cost'] ?? null)) {
            $this->line(sprintf(
                'eval_cost: USD %s over %d AI calls (%d with provider cost)',
                number_format((float) ($report['eval_cost']['total_usd'] ?? 0), 4, '.', ''),
                (int) ($report['eval_cost']['calls'] ?? 0),
                (int) ($report['eval_cost']['calls_with_cost'] ?? 0),
            ));
        }

        if ($liveVision) {
            $this->summarizeLiveVision($report, $promptVersionsByName);
        }

        if (! ($report['is_baseline'] ?? false)) {
            $this->warn('GEEN baseline — mode='.($report['mode'] ?? '?').' (geen baseline/ of HISTORY-append)');
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
        if (in_array($appEnv, ['production', 'staging'], true)) {
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

    public function needsTemplates(): bool
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
    private function scanFixtures(EvalFixturePrivacyScan $privacy): array
    {
        $hits = [];
        $root = base_path('tests/Eval/fixtures');
        foreach (['request_texts', 'customer_answers', 'photo_observations', 'report_claims', 'option_summaries', 'judge_calibration'] as $dir) {
            foreach (glob($root.'/'.$dir.'/*.json') ?: [] as $file) {
                $data = json_decode((string) file_get_contents($file), true);
                if (! is_array($data)) {
                    continue;
                }
                $text = is_string($data['text'] ?? null)
                    ? $data['text']
                    : (string) json_encode($data, JSON_UNESCAPED_UNICODE);
                foreach ($privacy->hits($text) as $hit) {
                    $hits[] = basename($file).': '.$hit;
                }
            }
        }

        return $hits;
    }

    /**
     * @return list<string>|null
     */
    private function parseKindsOption(mixed $raw): ?array
    {
        if (! is_string($raw)) {
            return null;
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        $kinds = [];
        foreach (explode(',', $trimmed) as $part) {
            $kind = trim($part);
            if ($kind !== '') {
                $kinds[] = $kind;
            }
        }

        return $kinds === [] ? null : $kinds;
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, string>  $promptVersionsByName
     */
    private function summarizeLiveVision(array $report, array $promptVersionsByName): void
    {
        $roomVer = $promptVersionsByName['room_assessment'] ?? '?';
        $this->line("live_vision room_assessment fingerprint (live meta): {$roomVer}");

        $totalTokens = 0;
        $tokenCases = 0;
        $costCents = 0;
        $costKnown = false;
        $executed = [];
        $skipped = [];

        foreach (is_array($report['cases'] ?? null) ? $report['cases'] : [] as $case) {
            if (! is_array($case) || ($case['kind'] ?? null) !== 'photo_observation') {
                continue;
            }
            $id = is_string($case['id'] ?? null) ? $case['id'] : '?';
            $live = is_array($case['live_vision'] ?? null) ? $case['live_vision'] : [];
            if (($live['executed'] ?? false) === true) {
                $executed[] = $id;
                $usage = is_array($case['token_usage'] ?? null) ? $case['token_usage'] : [];
                if (is_int($usage['total_tokens'] ?? null)) {
                    $totalTokens += $usage['total_tokens'];
                    $tokenCases++;
                }
                if (is_int($usage['estimated_cost_cents'] ?? null)) {
                    $costCents += $usage['estimated_cost_cents'];
                    $costKnown = true;
                }
                $pv = is_array($case['prompt_versions'] ?? null) ? $case['prompt_versions'] : [];
                $used = is_string($pv['room_assessment'] ?? null) ? $pv['room_assessment'] : null;
                if ($used !== null) {
                    $this->line("  [{$id}] prompt={$used} source=".($case['prompt_source'] ?? '?'));
                }
            } elseif (($live['requested'] ?? false) === true) {
                $skipped[] = $id.' ('.(is_string($live['reason'] ?? null) ? $live['reason'] : 'stub').')';
            }
        }

        $this->line('live_vision executed: '.count($executed).' ('.implode(', ', $executed).')');
        if ($skipped !== []) {
            $this->line('live_vision stub/skip: '.implode(', ', $skipped));
        }
        if ($tokenCases > 0) {
            $costPart = $costKnown ? ", ~{$costCents} cents" : '';
            $this->line("live_vision token_usage: {$totalTokens} total_tokens across {$tokenCases} case-runs{$costPart}");
        }
    }
}
