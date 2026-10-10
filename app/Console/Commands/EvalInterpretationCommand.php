<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Eval\EvalComparer;
use App\Domains\AI\Eval\InterpretationEvalRunner;
use App\Domains\AI\Eval\TraceFixtureImporter;
use App\Domains\Intake\Models\IntakeTemplate;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * P1 stap 1: meet tekstinterpretatie (model_raw vs pipeline_final). Wijzigt geen prompts/regex.
 */
final class EvalInterpretationCommand extends Command
{
    protected $signature = 'eval:interpretation
        {--repeats=3 : Aantal herhalingen per case (echte model-run); fake=1}
        {--fake : Forceer FakeAiClient (GEEN baseline)}
        {--compare= : Pad of bestandsstempel van een vorige run (JSON)}
        {--migrate : Draai migrate:fresh --seed voor templates (sqlite/local)}';

    protected $description = 'Evalueer tekstinterpretatie (request prefill, follow-up hoogte, foto-observaties)';

    public function handle(): int
    {
        $forceFake = (bool) $this->option('fake');
        $keyPresent = $this->apiKeyPresent();

        if (! $keyPresent || $forceFake) {
            $forceFake = true;
            if (! $keyPresent) {
                $this->warn('blokker: env var AI_API_KEY ontbreekt in de cloud-agent-omgeving');
                $this->warn('Draai tegen FakeAiClient — rapport wordt gemarkeerd als GEEN baseline.');
            }
            // Config vóór container-resolve van AiGateway/AiClientInterface, anders blijft NullAiClient hangen.
            config([
                'ai.provider' => 'fake',
                'ai.text_inference.enabled' => true,
                'ai.photo_inference.enabled' => true,
                'ai.dossier_synthesis.enabled' => true,
            ]);
            FakeAiClient::reset();
        }

        /** @var InterpretationEvalRunner $runner */
        $runner = $this->laravel->make(InterpretationEvalRunner::class);
        /** @var EvalComparer $comparer */
        $comparer = $this->laravel->make(EvalComparer::class);
        /** @var TraceFixtureImporter $privacy */
        $privacy = $this->laravel->make(TraceFixtureImporter::class);

        if ((bool) $this->option('migrate') || $this->needsTemplates()) {
            $this->info('Database voorbereiden (migrate + IntakeTemplateSeeder)…');
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

    private function apiKeyPresent(): bool
    {
        $configKey = config('ai.api_key');
        if (is_string($configKey) && trim($configKey) !== '') {
            return true;
        }

        // OpenRouter-alias zonder env()-helper (config-cache veilig).
        foreach (['AI_API_KEY', 'OPENROUTER_API_KEY'] as $name) {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
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
