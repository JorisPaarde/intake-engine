<?php

declare(strict_types=1);

namespace App\Domains\AI\Support;

use App\Domains\AI\Models\AiRun;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;

/**
 * Recente Pending AiRuns (wizard-wacht + workspace "AI is bezig").
 */
final class AiRunPendingQuery
{
    /** Vaste pending-window; geen aparte env-knop meer. */
    public const WINDOW_SECONDS = 300;

    public static function hasRecent(int $intakeId, AiRunType $type, ?int $windowSeconds = null): bool
    {
        $window = max(30, $windowSeconds ?? self::WINDOW_SECONDS);

        return AiRun::query()
            ->where('intake_id', $intakeId)
            ->where('type', $type)
            ->where('status', AiRunStatus::Pending)
            ->where(function ($query) use ($window): void {
                $query->where('started_at', '>=', now()->subSeconds($window))
                    ->orWhere(function ($inner) use ($window): void {
                        $inner->whereNull('started_at')
                            ->where('created_at', '>=', now()->subSeconds($window));
                    });
            })
            ->exists();
    }
}
