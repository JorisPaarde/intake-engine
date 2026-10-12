<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\AI\Services\AiFailureAlertService;
use App\Domains\AI\Services\OpenRouterBalanceChecker;
use App\Support\Logging\AppErrorLogger;
use Illuminate\Console\Command;
use Throwable;

final class AiBalanceCheckCommand extends Command
{
    protected $signature = 'ai:balance-check
                            {--force : Stuur waarschuwing ook binnen de dagelijkse throttle}
                            {--threshold= : Drempel in USD (default uit config)}';

    protected $description = 'Controleer OpenRouter-saldo en mail bij laag krediet';

    public function handle(
        OpenRouterBalanceChecker $checker,
        AiFailureAlertService $alerts,
        AppErrorLogger $logger,
    ): int {
        $threshold = $this->option('threshold');
        $warnBelow = is_numeric($threshold)
            ? (float) $threshold
            : (float) config('ai.alerts.balance_warn_usd', 5);

        try {
            $result = $checker->check();
        } catch (Throwable $exception) {
            $logger->warning('AI saldo-check mislukt', [], $exception);
            $this->error('Saldo-check mislukt: '.$exception->getMessage());

            return self::FAILURE;
        }

        $remaining = $result['remaining_usd'];
        $this->info(sprintf(
            'OpenRouter-saldo: $%s (bron: %s, drempel: $%s)',
            number_format($remaining, 2, '.', ''),
            $result['source'],
            number_format($warnBelow, 2, '.', ''),
        ));

        if ($remaining >= $warnBelow) {
            $this->line('Saldo boven drempel; geen alert.');

            return self::SUCCESS;
        }

        $sent = $alerts->alertBalanceLow(
            remainingUsd: $remaining,
            thresholdUsd: $warnBelow,
            force: (bool) $this->option('force'),
        );

        if ($sent) {
            $this->warn('Waarschuwingsmail verstuurd (laag saldo).');
        } else {
            $this->line('Geen mail verstuurd (throttle, uitgeschakeld, of mailfout).');
        }

        return self::SUCCESS;
    }
}
