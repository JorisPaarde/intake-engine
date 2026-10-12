<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Models\AircoConnection;
use App\Domains\Intake\Models\Intake;
use Illuminate\Validation\ValidationException;

/**
 * Resolves a workspace connection id after AI rebuilds replace Candidates.
 *
 * Prefer an exact id; otherwise map via subject meta supersedes_connection_ids
 * (stable identity written during InstallerCandidateDecisionPreserver reapply).
 * When no successor exists, surface a refresh message instead of a hard 404.
 */
final class ResolveAircoConnection
{
    public const MESSAGE_STALE_AFTER_REBUILD = 'Dit voorstel is net bijgewerkt. Ververs de pagina en probeer het opnieuw.';

    public function handle(Intake $intake, int $connectionId): AircoConnection
    {
        $connection = AircoConnection::query()
            ->where('intake_id', $intake->id)
            ->whereKey($connectionId)
            ->first();

        if ($connection instanceof AircoConnection) {
            return $connection;
        }

        $successor = $this->successorFor($intake, $connectionId);
        if ($successor instanceof AircoConnection) {
            return $successor;
        }

        throw ValidationException::withMessages([
            'decision' => self::MESSAGE_STALE_AFTER_REBUILD,
        ]);
    }

    private function successorFor(Intake $intake, int $oldConnectionId): ?AircoConnection
    {
        $intake->loadMissing(['aircoConnections.subject']);

        foreach ($intake->aircoConnections as $connection) {
            $subject = $connection->subject;
            $meta = is_array($subject->meta) ? $subject->meta : [];
            $supersedes = $meta['supersedes_connection_ids'] ?? null;
            if (! is_array($supersedes)) {
                continue;
            }

            foreach ($supersedes as $id) {
                if ((int) $id === $oldConnectionId) {
                    return $connection;
                }
            }
        }

        return null;
    }
}
