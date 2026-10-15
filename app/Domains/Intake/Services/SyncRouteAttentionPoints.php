<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\Intake;
use App\Enums\AttentionPointSource;

final class SyncRouteAttentionPoints
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
}
