<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\AI\Services\AiFailureAlertService;
use Illuminate\Console\Command;

final class AiAlertTestCommand extends Command
{
    protected $signature = 'ai:alert-test {--force : Bypass throttle (altijd aan voor testalerts)}';

    protected $description = 'Stuur een duidelijk gemarkeerde testalert naar het AI-alertadres';

    public function handle(AiFailureAlertService $alerts): int
    {
        // Testalerts bypass throttle by default; --force is explicit and documented.
        $sent = $alerts->sendTestAlert(force: true);

        if ($sent) {
            $this->info('Testalert verstuurd naar '.config('ai.alerts.mail_to').'.');

            return self::SUCCESS;
        }

        $this->error('Testalert niet verstuurd (mailfout of leeg ontvangeradres).');

        return self::FAILURE;
    }
}
