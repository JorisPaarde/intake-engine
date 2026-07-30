<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Models\AircoConnection;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\PipeRouteSession;
use App\Enums\AircoConnectionStatus;
use App\Enums\PipeRouteStatus;
use Illuminate\Support\Facades\DB;

/**
 * Start (of hervat) een begeleide leidingroute-sessie voor een intake. Een nog niet
 * goedgekeurde/afgekeurde sessie wordt hergebruikt zodat er geen dubbele lopen ontstaan.
 */
final class StartPipeRouteSession
{
    public function handle(Intake $intake, ?AircoConnection $connection = null): PipeRouteSession
    {
        return DB::transaction(function () use ($intake, $connection): PipeRouteSession {
            $intake = Intake::query()->whereKey($intake->id)->lockForUpdate()->firstOrFail();

            if ($connection !== null && $connection->intake_id !== $intake->id) {
                throw new \InvalidArgumentException('Verbinding hoort niet bij deze opname.');
            }

            if ($connection !== null) {
                $connection = AircoConnection::query()
                    ->where('intake_id', $intake->id)
                    ->lockForUpdate()
                    ->findOrFail($connection->id);
                $existing = $intake->pipeRouteSessions()
                    ->where('airco_connection_id', $connection->id)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof PipeRouteSession) {
                    if (in_array($existing->status, [PipeRouteStatus::Approved, PipeRouteStatus::Rejected], true)) {
                        $existing->update([
                            'status' => PipeRouteStatus::Collecting,
                            'confidence' => null,
                            'proposed_route' => null,
                            'alternative_route' => null,
                            'uncertainties' => null,
                            'missing_checks' => null,
                            'next_photo_instruction' => null,
                            'approved_by' => null,
                            'approved_at' => null,
                        ]);
                        $connection->update([
                            'status' => AircoConnectionStatus::NeedsEvidence,
                            'approved_by' => null,
                            'approved_at' => null,
                        ]);
                    }

                    return $existing;
                }
            }

            $query = $intake->pipeRouteSessions()
                ->whereIn('status', [PipeRouteStatus::Collecting, PipeRouteStatus::Proposed])
                ->when(
                    $connection === null,
                    static fn ($query) => $query->whereNull('airco_connection_id'),
                    static fn ($query) => $query->where('airco_connection_id', $connection->id),
                );
            $open = $query->first();

            if ($open instanceof PipeRouteSession) {
                return $open;
            }

            return $intake->pipeRouteSessions()->create([
                'airco_connection_id' => $connection?->id,
                'status' => PipeRouteStatus::Collecting,
            ]);
        }, 3);
    }
}
