<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\PipeRouteSession;
use App\Enums\PipeRouteStatus;

/**
 * Start (of hervat) een begeleide leidingroute-sessie voor een intake. Een nog niet
 * goedgekeurde/afgekeurde sessie wordt hergebruikt zodat er geen dubbele lopen ontstaan.
 */
final class StartPipeRouteSession
{
    public function handle(Intake $intake): PipeRouteSession
    {
        $open = $intake->pipeRouteSessions()
            ->whereIn('status', [PipeRouteStatus::Collecting, PipeRouteStatus::Proposed])
            ->first();

        if ($open instanceof PipeRouteSession) {
            return $open;
        }

        return $intake->pipeRouteSessions()->create([
            'status' => PipeRouteStatus::Collecting,
        ]);
    }
}
