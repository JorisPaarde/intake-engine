<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\AI\Support\E2eAiScenario;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

final class E2ePrepareCommand extends Command
{
    protected $signature = 'e2e:prepare {--fresh : Drop all tables and re-run migrations}';

    protected $description = 'Prepare database and seed for Playwright E2E (templates + demo org)';

    public function handle(): int
    {
        if (! (bool) config('ai.e2e_helpers_enabled', false) && ! app()->environment(['local', 'testing'])) {
            $this->error('E2E prepare refused: set E2E_HELPERS=true (or APP_ENV=local/testing).');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            Artisan::call('migrate:fresh', ['--force' => true]);
            $this->line(Artisan::output());
        } else {
            Artisan::call('migrate', ['--force' => true]);
            $this->line(Artisan::output());
        }

        Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);
        $this->line(Artisan::output());

        E2eAiScenario::set(E2eAiScenario::GOOD_PHOTO);
        $this->info('E2E database ready (AI_PROVIDER should be fake).');

        return self::SUCCESS;
    }
}
