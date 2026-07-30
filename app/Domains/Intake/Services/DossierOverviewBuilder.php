<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\DossierDecisionArea;
use App\Domains\Intake\Models\Intake;
use App\Enums\DecisionAreaStatus;
use Illuminate\Support\Collection;

final class DossierOverviewBuilder
{
    public function __construct(
        private readonly DecisionReadinessService $decisionReadiness,
    ) {}

    /**
     * @return array{
     *     areas: Collection<int, DossierDecisionArea>,
     *     blockers: Collection<int, DossierDecisionArea>,
     *     quote: DossierDecisionArea|null,
     *     ready_count: int,
     *     total_count: int
     * }
     */
    public function build(Intake $intake): array
    {
        $areas = $this->decisionReadiness->recalculate($intake);
        $quote = $areas->firstWhere('key', 'quote');

        return [
            'areas' => $areas,
            'blockers' => $areas->filter(
                static fn (DossierDecisionArea $area): bool => in_array(
                    $area->status,
                    [DecisionAreaStatus::Blocked, DecisionAreaStatus::Review],
                    true,
                ),
            )->values(),
            'quote' => $quote instanceof DossierDecisionArea ? $quote : null,
            'ready_count' => $areas->where('status', DecisionAreaStatus::Ready)->count(),
            'total_count' => $areas->count(),
        ];
    }
}
