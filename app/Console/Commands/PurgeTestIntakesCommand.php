<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Intake\Actions\HardDeleteIntake;
use App\Domains\Intake\Models\Intake;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Safe cleanup of non-demo test intakes by customer e-mail domain (staging/local only).
 * Dry-run by default; --confirm hard-deletes via HardDeleteIntake (incl. media).
 */
final class PurgeTestIntakesCommand extends Command
{
    protected $signature = 'intakes:purge-test
                            {--email-domain=example.com : Customer e-mail domain to match (without @)}
                            {--confirm : Actually hard-delete matching intakes (default is dry-run)}';

    protected $description = 'Dry-run of hard-purge test intakes by customer e-mail domain (refuse on production; use --confirm to delete)';

    public function handle(HardDeleteIntake $hardDeleteIntake): int
    {
        if (app()->environment('production')) {
            $this->error('intakes:purge-test geweigerd: niet beschikbaar in production.');

            return self::FAILURE;
        }

        $domain = $this->normalizedDomain((string) $this->option('email-domain'));
        if ($domain === null) {
            $this->error('Ongeldig --email-domain. Gebruik bijvoorbeeld example.com (zonder @, geen wildcard).');

            return self::FAILURE;
        }

        $ids = $this->matchingQuery($domain)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $count = count($ids);

        if ($count === 0) {
            $this->info("Geen intakes gevonden voor domein @{$domain}.");

            return self::SUCCESS;
        }

        $this->line("Gevonden: {$count} intake(s) met customer_email op @{$domain}.");
        $this->line('IDs: '.implode(', ', $ids));

        if (! $this->option('confirm')) {
            $this->warn('Dry-run — niets verwijderd. Voeg --confirm toe om hard te wissen (incl. foto\'s/bestanden).');

            return self::SUCCESS;
        }

        $deleted = 0;
        $this->matchingQuery($domain)
            ->orderBy('id')
            ->chunkById(50, function (Collection $intakes) use ($hardDeleteIntake, &$deleted): void {
                foreach ($intakes as $intake) {
                    $hardDeleteIntake->handle($intake);
                    $deleted++;
                }
            });

        $this->info("Hard verwijderd: {$deleted} intake(s) (IDs eerder: ".implode(', ', $ids).').');

        return self::SUCCESS;
    }

    private function normalizedDomain(string $raw): ?string
    {
        $domain = strtolower(trim($raw));
        $domain = ltrim($domain, '@');
        $domain = rtrim($domain, '.');

        if ($domain === '' || str_contains($domain, '/') || str_contains($domain, ' ') || str_contains($domain, '*')) {
            return null;
        }

        if (preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i', $domain) !== 1) {
            return null;
        }

        if (! str_contains($domain, '.')) {
            return null;
        }

        return $domain;
    }

    /**
     * @return Builder<Intake>
     */
    private function matchingQuery(string $domain): Builder
    {
        $suffix = '@'.$domain;

        return Intake::query()
            ->withTrashed()
            ->where('is_demo', false)
            ->whereRaw('lower(customer_email) like ?', ['%'.$suffix]);
    }
}
