<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Models\AircoConnection;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\PipeRouteSession;
use App\Domains\Intake\Services\RefreshStoredIntakeReport;
use App\Enums\AircoConnectionStatus;
use App\Enums\PipeRouteStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Installer decision on one technical connection: approve remotely or mark as
 * site-visit only. Keeps the collecting→summarize guard for guided sessions.
 */
final class DecideAircoConnection
{
    public function __construct(
        private readonly RefreshStoredIntakeReport $refreshStoredIntakeReport,
    ) {}

    public function handle(
        Intake $intake,
        AircoConnection|int $connection,
        User $installer,
        string $decision,
    ): AircoConnection {
        if (is_int($connection)) {
            $connection = app(ResolveAircoConnection::class)->handle($intake, $connection);
        }

        if ($connection->intake_id !== $intake->id) {
            throw ValidationException::withMessages([
                'decision' => ResolveAircoConnection::MESSAGE_STALE_AFTER_REBUILD,
            ]);
        }

        if ($installer->company_id !== $intake->company_id) {
            throw ValidationException::withMessages([
                'decision' => 'Je mag deze verbinding niet beoordelen.',
            ]);
        }

        if (! in_array($decision, ['approve', 'site_visit'], true)) {
            throw ValidationException::withMessages([
                'decision' => 'Kies goedkeuren of alleen te zien op locatie.',
            ]);
        }

        $connection = DB::transaction(function () use ($intake, $connection, $installer, $decision): AircoConnection {
            Intake::query()->whereKey($intake->id)->lockForUpdate()->firstOrFail();
            $connection = AircoConnection::query()
                ->whereKey($connection->id)
                ->lockForUpdate()
                ->firstOrFail();

            $session = $connection->routeSession !== null
                ? PipeRouteSession::query()->whereKey($connection->routeSession->id)->lockForUpdate()->first()
                : null;

            if ($decision === 'site_visit') {
                if (in_array($connection->status, [
                    AircoConnectionStatus::Approved,
                    AircoConnectionStatus::NotRemotelyResolvable,
                ], true)) {
                    throw ValidationException::withMessages([
                        'decision' => 'Deze route is al afgerond.',
                    ]);
                }

                $connection->update([
                    'status' => AircoConnectionStatus::NotRemotelyResolvable,
                    'approved_by' => null,
                    'approved_at' => null,
                ]);

                IntakeActivityEvent::query()->create([
                    'intake_id' => $intake->id,
                    'actor_type' => 'user',
                    'actor_id' => $installer->id,
                    'event' => 'connection_marked_site_visit',
                    'properties' => [
                        'airco_connection_id' => $connection->id,
                        'decision' => 'site_visit',
                    ],
                    'created_at' => now(),
                ]);

                return $connection->fresh(['routeSession', 'installationOption']) ?? $connection;
            }

            if ($session?->status === PipeRouteStatus::Collecting) {
                throw ValidationException::withMessages([
                    'decision' => 'Vat eerst de route samen.',
                ]);
            }

            if ($session !== null) {
                if ($session->status !== PipeRouteStatus::Proposed) {
                    throw ValidationException::withMessages([
                        'decision' => 'Deze leidingroute kan niet worden beoordeeld.',
                    ]);
                }
            } elseif (! in_array($connection->status, [
                AircoConnectionStatus::Proposed,
                AircoConnectionStatus::Plausible,
            ], true)) {
                throw ValidationException::withMessages([
                    'decision' => 'Er mist nog bewijs. Voeg een foto toe, vraag de klant of kies locatiebezoek.',
                ]);
            }

            $connection->update([
                'status' => AircoConnectionStatus::Approved,
                'segments' => $session !== null
                    ? ($session->proposed_route ?? $connection->segments)
                    : $connection->segments,
                'uncertainties' => $session !== null
                    ? ($session->uncertainties ?? [])
                    : ($connection->uncertainties ?? []),
                'confidence' => $session !== null
                    ? ($session->confidence ?? $connection->confidence)
                    : $connection->confidence,
                'approved_by' => $installer->id,
                'approved_at' => now(),
            ]);

            if ($session !== null) {
                $session->update([
                    'status' => PipeRouteStatus::Approved,
                    'approved_by' => $installer->id,
                    'approved_at' => now(),
                ]);
            }

            IntakeActivityEvent::query()->create([
                'intake_id' => $intake->id,
                'actor_type' => 'user',
                'actor_id' => $installer->id,
                'event' => 'connection_approved',
                'properties' => [
                    'airco_connection_id' => $connection->id,
                    'decision' => 'approve',
                    'pipe_route_session_id' => $session?->id,
                ],
                'created_at' => now(),
            ]);

            return $connection->fresh(['routeSession', 'installationOption']) ?? $connection;
        }, 3);

        // Refresh whenever a stored report exists — also for Candidate options
        // so route status appears before "Deze keuze" (N35 rest).
        $freshIntake = Intake::query()->find($intake->id);
        if ($freshIntake !== null) {
            $this->refreshStoredIntakeReport->handle($freshIntake);
        }

        return $connection;
    }
}
