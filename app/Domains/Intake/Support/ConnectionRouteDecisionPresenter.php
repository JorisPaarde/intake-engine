<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\Intake\Models\AircoConnection;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoConnectionType;
use App\Enums\PipeRouteStatus;

/**
 * UI-state for assessing a koel-/condens-/stroomroute: visible steps and which
 * decisions are safe now, without hiding guards.
 */
final class ConnectionRouteDecisionPresenter
{
    /**
     * @return array{
     *     title: string,
     *     steps: list<array{label: string, state: 'done'|'current'|'todo'}>,
     *     can_approve: bool,
     *     approve_blocked_reason: string|null,
     *     approve_warning: string|null,
     *     can_site_visit: bool,
     *     show_summarize: bool,
     *     photo_label: string
     * }
     */
    public function present(AircoConnection $connection): array
    {
        $session = $connection->routeSession;
        $status = $connection->status;
        $sessionStatus = $session?->status;
        $collecting = $sessionStatus === PipeRouteStatus::Collecting;
        $proposedSession = $sessionStatus === PipeRouteStatus::Proposed;
        $segmentCount = $session?->segments->count() ?? 0;
        $alreadyDone = in_array($status, [
            AircoConnectionStatus::Approved,
            AircoConnectionStatus::NotRemotelyResolvable,
        ], true);

        // Same gate as ApprovePipeRoute: a Proposed session may be approved even when
        // synthesis left the connection on NeedsEvidence (route_continuous=false).
        $canApprove = ! $alreadyDone && (
            $proposedSession
            || (
                $session === null
                && in_array($status, [
                    AircoConnectionStatus::Proposed,
                    AircoConnectionStatus::Plausible,
                ], true)
            )
        );

        $approveBlockedReason = null;
        if (! $canApprove && ! $alreadyDone) {
            if ($collecting) {
                $approveBlockedReason = 'Vat eerst de route samen.';
            } elseif (in_array($status, [
                AircoConnectionStatus::Unknown,
                AircoConnectionStatus::NeedsEvidence,
            ], true)) {
                $approveBlockedReason = $connection->type === AircoConnectionType::Condensate
                    ? 'Er mist nog bewijs. Voeg een foto toe, kies locatiebezoek of stel de klant één vraag voor alle condensafvoeren via het overzicht.'
                    : 'Er mist nog bewijs. Voeg een foto toe, vraag de klant of kies locatiebezoek.';
            } else {
                $approveBlockedReason = 'Deze route kun je nog niet goedkeuren.';
            }
        }

        $hasUncertainties = collect($connection->uncertainties ?? [])
            ->contains(static fn (string $item): bool => trim($item) !== '');
        $approveWarning = ($canApprove && $hasUncertainties)
            ? 'Let op: er staan nog onzekerheden bij deze route.'
            : null;

        // Collecting (e.g. new photo after approve/site_visit) beats alreadyDone so
        // steps keep pointing at summarize instead of all showing done.
        $currentIndex = match (true) {
            $collecting && $segmentCount > 0 => 2,
            $collecting => 1,
            $alreadyDone => 4,
            $proposedSession, $canApprove => 3,
            in_array($status, [
                AircoConnectionStatus::Unknown,
                AircoConnectionStatus::NeedsEvidence,
            ], true) => 1,
            default => 0,
        };

        $labels = [
            'Bekijk de route en de foto’s',
            'Maak je eigen foto’s',
            'Vat de route samen',
            'Keur goed of kies locatiebezoek',
        ];

        $steps = [];
        foreach ($labels as $index => $label) {
            $steps[] = [
                'label' => $label,
                'state' => match (true) {
                    $index < $currentIndex => 'done',
                    $index === $currentIndex => 'current',
                    default => 'todo',
                },
            ];
        }

        return [
            'title' => 'Route beoordelen',
            'steps' => $steps,
            'can_approve' => $canApprove,
            'approve_blocked_reason' => $approveBlockedReason,
            'approve_warning' => $approveWarning,
            'can_site_visit' => ! $alreadyDone,
            'show_summarize' => $collecting || ($session !== null && ! $alreadyDone),
            'photo_label' => 'Eigen foto toevoegen',
        ];
    }
}
