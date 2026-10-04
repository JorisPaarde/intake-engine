<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\AI\Support\E2eAiScenario;
use App\Support\E2e\E2eScenarioFactory;
use Illuminate\Console\Command;

final class E2eScenarioCommand extends Command
{
    protected $signature = 'e2e:scenario
        {scenario : wizard-happy-path|fusebox-upload|follow-up-mismatch|progress-empty|drain-facade|feedback}
        {--ai= : Optional FakeAiClient scenario (good_photo|wrong_subject|…)}';

    protected $description = 'Create a deterministic intake for Playwright and print JSON';

    public function handle(E2eScenarioFactory $factory): int
    {
        if (! (bool) config('ai.e2e_helpers_enabled', false) && ! app()->environment(['local', 'testing'])) {
            $this->error('E2E scenario refused: set E2E_HELPERS=true.');

            return self::FAILURE;
        }

        $ai = $this->option('ai');
        if (is_string($ai) && $ai !== '') {
            E2eAiScenario::set($ai);
        }

        $payload = $factory->create((string) $this->argument('scenario'));
        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
