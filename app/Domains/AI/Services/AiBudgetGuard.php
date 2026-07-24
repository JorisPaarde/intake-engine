<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Exceptions\AiClientException;
use App\Domains\AI\Models\AiRun;
use App\Enums\AiRunStatus;
use Illuminate\Support\Carbon;

final class AiBudgetGuard
{
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

        $reserve = $this->reserveCents();

        if ($dailyCap !== null && $this->spentCentsSince(now()->startOfDay()) + $reserve > $dailyCap) {
            throw new AiClientException('AI-budgetlimiet bereikt voor vandaag.');
        }

        if ($monthlyCap !== null && $this->spentCentsSince(now()->startOfMonth()) + $reserve > $monthlyCap) {
            throw new AiClientException('AI-budgetlimiet bereikt voor deze maand.');
        }
    }

    public function estimateCostCents(?int $inputTokens, ?int $outputTokens, int $imageCount = 0): int
    {
        $inputCost = $inputTokens === null
            ? 0.0
            : ($inputTokens / 1000) * $this->rateCents('input_cents_per_1k_tokens');
        $outputCost = $outputTokens === null
            ? 0.0
            : ($outputTokens / 1000) * $this->rateCents('output_cents_per_1k_tokens');
        $imageCost = max(0, $imageCount) * $this->rateCents('image_cents_per_image');

        return max($this->reserveCents(), (int) ceil($inputCost + $outputCost + $imageCost));
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

    private function capCents(string $key): ?int
    {
        $value = config('ai.budget.'.$key);

        if ($value === null || $value === '') {
            return null;
        }

        return max(0, (int) $value);
    }

    private function spentCentsSince(Carbon $since): int
    {
        $spent = AiRun::query()
            ->where('provider', 'openai')
            ->where('status', AiRunStatus::Succeeded)
            ->where('started_at', '>=', $since)
            ->selectRaw(
                'COALESCE(SUM(COALESCE(estimated_cost_cents, ?)), 0) as spent_cents',
                [$this->reserveCents()],
            )
            ->value('spent_cents');

        return (int) $spent;
    }
}
