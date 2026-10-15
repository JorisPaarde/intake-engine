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
        foreach ($intake->attentionPoints()->where('source', AttentionPointSource::System)->whereIn('code', array_keys($labels))->get() as $point) {
            $current = $checker->routeDecisionAttention($intake, $point->code, $labels[$point->code]);
            if ($current === null) {
                // No option: restore the original unresolved decision.
                $current = [['label' => $labels[$point->code]]];
            }
            $resolved = $current === [];
            $point->update([
                'label' => $current[0]['label'] ?? $point->label,
                'is_resolved' => $resolved,
                'resolved_at' => $resolved ? ($point->resolved_at ?? now()) : null,
                'resolved_by' => null,
            ]);
        }
        $intake->unsetRelation('attentionPoints');
    }
}
