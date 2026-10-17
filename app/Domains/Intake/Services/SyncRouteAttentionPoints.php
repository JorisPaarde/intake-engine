<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAttentionPoint;
use App\Domains\Intake\Support\AttentionProposalVisibility;
use App\Enums\AttentionPointSource;
use App\Enums\AttentionPointStatus;

class SyncRouteAttentionPoints
{
    public function handle(Intake $intake): void
    {
        $checker = app(CompletenessChecker::class);
        $labels = [
            'condensate_pump_open' => 'Open technisch punt: condenspomp of natuurlijk afschot nog te bepalen',
            'pipe_route_open' => 'Open technisch punt: leidingroute nog te beoordelen',
            'drillings_open' => 'Open technisch punt: doorboringen door muren/vloeren nog te beoordelen',
        ];

        foreach ($labels as $code => $label) {
            $current = $checker->routeDecisionAttention($intake, $code, $label);
            if ($current === null) {
                // No installation option yet — leave any existing point alone.
                continue;
            }

            $point = $intake->attentionPoints()
                ->where('source', AttentionPointSource::System)
                ->where('code', $code)
                ->first();

            if ($current === []) {
                if ($point !== null && ! $point->is_resolved) {
                    $point->update([
                        'is_resolved' => true,
                        'resolved_at' => $point->resolved_at ?? now(),
                        'resolved_by' => null,
                    ]);
                }

                $this->resolveAcceptedAiRestatements($intake, $code);

                continue;
            }

            $newLabel = $current[0]['label'];
            $intake->attentionPoints()->updateOrCreate(
                ['source' => AttentionPointSource::System, 'code' => $code],
                [
                    'label' => $newLabel,
                    'is_resolved' => false,
                    'resolved_at' => null,
                    'resolved_by' => null,
                ],
            );
        }

        $intake->unsetRelation('attentionPoints');
    }

    /**
     * N77: an accepted AI point that only restates this system route point (by code or
     * system-only evidence, never by text) follows the system point when the route is done.
     */
    private function resolveAcceptedAiRestatements(Intake $intake, string $code): void
    {
        $intake->attentionPoints()
            ->where('source', AttentionPointSource::Ai)
            ->where('status', AttentionPointStatus::Accepted)
            ->where('is_resolved', false)
            ->get()
            ->filter(static fn (IntakeAttentionPoint $point): bool => AttentionProposalVisibility::isRedundantProposal($point, [$code => true]))
            ->each(static fn (IntakeAttentionPoint $point) => $point->update([
                'is_resolved' => true,
                'resolved_at' => now(),
                'resolved_by' => null,
            ]));
    }
}
