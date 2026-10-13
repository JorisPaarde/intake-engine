<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Models\AircoConnection;
use App\Domains\Intake\Models\Intake;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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

    public const MESSAGE_PROPOSAL_REFRESHED = 'Het voorstel is ververst, bekijk het opnieuw.';

    /**
     * Content identity of a proposal: option type + placements, connection type,
     * endpoints and the proposal fields the installer judges on.
     */
    public static function fingerprint(AircoConnection $connection): string
    {
        $connection->loadMissing('installationOption.placements');
        $option = $connection->installationOption;
        $clean = static fn (mixed $list): array => array_values(array_filter(
            is_array($list) ? $list : [],
            static fn (mixed $v): bool => is_string($v) && trim($v) !== '',
        ));

        return hash('sha256', (string) json_encode([
            $option->configuration_type->value,
            $option->placements->pluck('id')->map(static fn (mixed $id): int => (int) $id)->sort()->values()->all(),
            $connection->type->value,
            $connection->from_placement_id,
            $connection->to_placement_id,
            $clean($connection->segments),
            $clean($connection->obstacles),
            $clean($connection->uncertainties),
            $connection->length_class,
            $connection->cost_impact,
        ]));
    }

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
            if (! $this->isSameProposal($successor, $connectionId)) {
                throw ValidationException::withMessages([
                    'decision' => self::MESSAGE_PROPOSAL_REFRESHED,
                ]);
            }

            return $successor;
        }

        // An id that exists but belongs to another intake is never ours: keep the 404.
        if (AircoConnection::query()->whereKey($connectionId)->exists()) {
            throw new NotFoundHttpException;
        }

        throw ValidationException::withMessages([
            'decision' => self::MESSAGE_STALE_AFTER_REBUILD,
        ]);
    }

    private function isSameProposal(AircoConnection $successor, int $oldConnectionId): bool
    {
        $meta = is_array($successor->subject->meta) ? $successor->subject->meta : [];
        $fingerprints = is_array($meta['supersedes_fingerprints'] ?? null) ? $meta['supersedes_fingerprints'] : [];
        $old = $fingerprints[(string) $oldConnectionId] ?? null;

        return is_string($old) && hash_equals($old, self::fingerprint($successor));
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
