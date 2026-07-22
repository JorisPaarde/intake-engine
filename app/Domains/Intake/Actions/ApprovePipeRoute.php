<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\PipeRouteSession;
use App\Enums\PipeRouteStatus;
use App\Models\User;

/**
 * De installateur keurt de voorgestelde leidingroute goed of af. Dit is bewust een
 * expliciete menselijke stap: AI levert alleen een voorzet (vast ontwerpprincipe).
 */
final class ApprovePipeRoute
{
    public function handle(PipeRouteSession $session, User $installer, bool $approved = true): PipeRouteSession
    {
        $status = $approved ? PipeRouteStatus::Approved : PipeRouteStatus::Rejected;

        $session->update([
            'status' => $status,
            'approved_by' => $installer->id,
            'approved_at' => now(),
        ]);

        IntakeActivityEvent::query()->create([
            'intake_id' => $session->intake_id,
            'actor_type' => 'user',
            'actor_id' => $installer->id,
            'event' => 'pipe_route_reviewed',
            // Alleen keys/status — nooit route-inhoud in de log (ADR-0002).
            'properties' => [
                'pipe_route_session_id' => $session->id,
                'decision' => $status->value,
                'segment_count' => $session->segments()->count(),
            ],
            'created_at' => now(),
        ]);

        return $session->fresh() ?? $session;
    }
}
