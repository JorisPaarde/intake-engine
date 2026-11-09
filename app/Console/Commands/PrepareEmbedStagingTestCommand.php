<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Intake\Actions\PrepareEmbedStagingTest;
use App\Domains\Intake\Support\EmbedStagingTest;
use Illuminate\Console\Command;

/**
 * Zet een fictieve embed-testcompany klaar (local/staging). Geen echte klantdata.
 */
final class PrepareEmbedStagingTestCommand extends Command
{
    protected $signature = 'embed:prepare-staging-test
        {--host= : Optionele hostname in de allowlist (default: host van APP_URL)}';

    protected $description = 'Maak/update de embed staging-testcompany + toon de testpagina-URL (local/staging)';

    public function handle(PrepareEmbedStagingTest $prepare): int
    {
        if (! $this->environmentAllowed()) {
            $this->error('Dit commando is alleen bedoeld voor local of staging (APP_ENV).');

            return self::FAILURE;
        }

        $hostOpt = $this->option('host');
        $host = is_string($hostOpt) && trim($hostOpt) !== '' ? trim($hostOpt) : null;

        $result = $prepare->handle($host);
        $company = $result['company'];

        $testUrl = url('/embed-test');
        $formUrl = url('/embed/'.EmbedStagingTest::PUBLIC_KEY);

        $this->info($result['created'] ? 'Embed staging-testcompany aangemaakt.' : 'Embed staging-testcompany bijgewerkt.');
        $this->line('Company: '.$company->name.' (#'.$company->id.')');
        $this->line('Public key: '.EmbedStagingTest::PUBLIC_KEY);
        $origins = $company->embedAllowedOrigins();
        $this->line('Allowlist: '.($origins === [] ? '(leeg; frame-ancestors bevat nog steeds \'self\')' : implode(', ', $origins)));
        $this->newLine();
        $this->line('Testpagina (iframe): '.$testUrl);
        $this->line('Formulier direct: '.$formUrl);
        $this->line('Dashboard-login: '.EmbedStagingTest::USER_EMAIL.' / '.EmbedStagingTest::USER_PASSWORD);

        return self::SUCCESS;
    }

    private function environmentAllowed(): bool
    {
        $env = (string) app()->environment();

        return in_array($env, ['local', 'staging', 'testing'], true);
    }
}
