<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Exceptions\AiClientException;
use App\Domains\AI\Models\AiRun;
use App\Enums\AiRunStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Pre-call budget reservation + post-call fractional cost reconciliation.
 *
 * Costs are stored as microcents (1 cent = 10_000 microcents). When token/image
 * rate env vars are empty, each call still books the reserve (legacy behaviour)
 * and a one-time warning is logged.
 */
final class AiBudgetGuard
{
    public const int MICROCENTS_PER_CENT = 10_000;

    private static bool $missingRatesWarned = false;

    public function ensureOpenAiBudgetAvailable(): void
    {
        if (! $this->enforced()) {
            return;
        }

        $dailyCap = $this->capCents('daily_cents');
        $monthlyCap = $this->capCents('monthly_cents');

        if ($dailyCap === null && $monthlyCap === null) {
            throw new AiClientException('AI-budgetcap ontbreekt: stel AI_BUDGET_DAILY_CENTS of AI_BUDGET_MONTHLY_CENTS in.');
        }

        $reserve = (float) $this->reserveCents();

        if ($dailyCap !== null && $this->spentCentsSince(now()->startOfDay()) + $reserve > $dailyCap) {
            throw new AiClientException('AI-budgetlimiet bereikt voor vandaag.');
        }

        if ($monthlyCap !== null && $this->spentCentsSince(now()->startOfMonth()) + $reserve > $monthlyCap) {
            throw new AiClientException('AI-budgetlimiet bereikt voor deze maand.');
        }
    }

    /**
     * Estimated cost in fractional cents. Reserve is only used when rates are unset
     * (legacy) or as the pre-call budget reservation — never as a floor on real cost.
     */
    public function estimateCostCents(?int $inputTokens, ?int $outputTokens, int $imageCount = 0): float
    {
        if (! $this->hasConfiguredRates()) {
            $this->warnMissingRatesOnce();

            return (float) $this->reserveCents();
        }

        $inputCost = $inputTokens === null
            ? 0.0
            : ($inputTokens / 1000) * $this->rateCents('input_cents_per_1k_tokens');
        $outputCost = $outputTokens === null
            ? 0.0
            : ($outputTokens / 1000) * $this->rateCents('output_cents_per_1k_tokens');
        $imageCost = max(0, $imageCount) * $this->rateCents('image_cents_per_image');

        return max(0.0, $inputCost + $outputCost + $imageCost);
    }

    public function toMicrocents(?float $costCents): ?int
    {
        if ($costCents === null) {
            return null;
        }

        return (int) max(0, (int) round($costCents * self::MICROCENTS_PER_CENT));
    }

    /**
     * Convert an unrounded currency-unit cost (e.g. OpenRouter usage.cost) to microcents.
     * 1 currency unit = 100 cents = {@see self::MICROCENTS_PER_CENT} × 100 microcents.
     */
    public function toMicrocentsFromCurrency(?string $costCurrency): ?int
    {
        if ($costCurrency === null || $costCurrency === '' || ! is_numeric($costCurrency)) {
            return null;
        }

        $currency = (float) $costCurrency;
        if ($currency < 0) {
            return null;
        }

        return (int) max(0, (int) round($currency * 100 * self::MICROCENTS_PER_CENT));
    }

    public function ceilCents(?float $costCents): ?int
    {
        if ($costCents === null) {
            return null;
        }

        return (int) max(0, (int) ceil($costCents));
    }

    public function microcentsToCents(int $microcents): float
    {
        return $microcents / self::MICROCENTS_PER_CENT;
    }

    /** @internal for tests */
    public static function resetMissingRatesWarning(): void
    {
        self::$missingRatesWarned = false;
    }

    private function enforced(): bool
    {
        return (bool) config('ai.budget.enforced', true);
    }

    private function reserveCents(): int
    {
        return max(0, (int) config('ai.budget.reserve_cents_per_call', 1));
    }

    private function rateCents(string $key): float
    {
        return max(0.0, (float) config('ai.budget.'.$key, 0.0));
    }

    private function hasConfiguredRates(): bool
    {
        return $this->rateCents('input_cents_per_1k_tokens') > 0.0
            || $this->rateCents('output_cents_per_1k_tokens') > 0.0
            || $this->rateCents('image_cents_per_image') > 0.0;
    }

    private function warnMissingRatesOnce(): void
    {
        if (self::$missingRatesWarned) {
            return;
        }

        self::$missingRatesWarned = true;
        Log::warning('AI budget rates empty; booking reserve_cents_per_call per external call.', [
            'reserve_cents_per_call' => $this->reserveCents(),
        ]);
    }

    private function capCents(string $key): ?float
    {
        $value = config('ai.budget.'.$key);

        if ($value === null || $value === '') {
            return null;
        }

        return max(0.0, (float) $value);
    }

    private function spentCentsSince(Carbon $since): float
    {
        $rows = AiRun::query()
            ->where('provider', 'openai')
            ->whereIn('status', [AiRunStatus::Succeeded, AiRunStatus::Partial])
            ->where('started_at', '>=', $since)
            ->get(['estimated_cost_microcents', 'estimated_cost_cents']);

        $total = 0.0;
        $fallbackReserve = (float) $this->reserveCents();

        foreach ($rows as $row) {
            if ($row->estimated_cost_microcents !== null) {
                $total += $this->microcentsToCents((int) $row->estimated_cost_microcents);
            } elseif ($row->estimated_cost_cents !== null) {
                $total += (float) $row->estimated_cost_cents;
            } else {
                $total += $fallbackReserve;
            }
        }

        return $total;
    }
}
