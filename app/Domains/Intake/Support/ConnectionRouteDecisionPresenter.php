<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\Intake\Models\AircoConnection;
use App\Enums\AircoConnectionStatus;
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
                $approveBlockedReason = 'Er mist nog bewijs. Voeg een foto toe, vraag de klant of kies locatiebezoek.';
            } else {
                $approveBlockedReason = 'Deze route kun je nog niet goedkeuren.';
            }
        }

        $uncertainties = array_values(array_filter(
            array_map(
                static fn (string $item): string => trim($item),
                is_array($connection->uncertainties) ? $connection->uncertainties : [],
            ),
            static fn (string $item): bool => $item !== '',
        ));
        $approveWarning = ($canApprove && $uncertainties !== [])
            ? implode(' · ', $uncertainties)
            : null;

        $currentIndex = match (true) {
            $alreadyDone => 4,
            $collecting && $segmentCount > 0 => 2,
            $collecting => 1,
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
