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
     *     show_panel: bool,
     *     title: string,
     *     guidance: string,
     *     steps: list<array{label: string, state: 'done'|'current'|'todo'}>,
     *     can_approve: bool,
     *     approve_blocked_reason: string|null,
     *     can_site_visit: bool,
     *     show_summarize: bool,
     *     photo_label: string
     * }
     */
    public function present(AircoConnection $connection): array
    {
        $session = $connection->routeSession;
        $status = $connection->status;
        $hasSegments = is_array($connection->segments) && $connection->segments !== [];
        $sessionStatus = $session?->status;
        $collecting = $sessionStatus === PipeRouteStatus::Collecting;
        $proposedSession = $sessionStatus === PipeRouteStatus::Proposed;
        $alreadyDone = in_array($status, [
            AircoConnectionStatus::Approved,
            AircoConnectionStatus::NotRemotelyResolvable,
        ], true);

        $canApprove = ! $alreadyDone
            && in_array($status, [
                AircoConnectionStatus::Proposed,
                AircoConnectionStatus::Plausible,
            ], true)
            && ($session === null || $proposedSession);

        $approveBlockedReason = null;
        if (! $canApprove && ! $alreadyDone) {
            if ($collecting) {
                $approveBlockedReason = 'Vat de routesegmenten eerst samen met “Route samenvatten”.';
            } elseif (in_array($status, [
                AircoConnectionStatus::Unknown,
                AircoConnectionStatus::NeedsEvidence,
            ], true)) {
                $approveBlockedReason = 'Er mist nog bewijs. Voeg een eigen installateursfoto toe, vraag de klant, of kies locatiebezoek.';
            } else {
                $approveBlockedReason = 'Deze route is nog niet klaar om goed te keuren.';
            }
        }

        $steps = [];
        if ($hasSegments || in_array($status, [
            AircoConnectionStatus::Proposed,
            AircoConnectionStatus::Plausible,
        ], true)) {
            $steps[] = [
                'label' => 'Klantfoto’s en AI-route zijn al beschikbaar',
                'state' => 'done',
            ];
        } else {
            $steps[] = [
                'label' => 'Bekijk klantfoto’s en de huidige route',
                'state' => 'current',
            ];
        }

        if ($collecting) {
            $steps[] = [
                'label' => 'Eigen installateursfoto’s verzamelen',
                'state' => 'current',
            ];
            $steps[] = [
                'label' => 'Route samenvatten',
                'state' => 'todo',
            ];
        } elseif ($proposedSession) {
            $steps[] = [
                'label' => 'Route is samengevat — controleer hem als geheel',
                'state' => 'current',
            ];
        } else {
            $steps[] = [
                'label' => 'Eigen installateursfoto is optioneel',
                'state' => $canApprove ? 'todo' : 'current',
            ];
        }

        $steps[] = [
            'label' => 'Beslis: goedkeuren, locatiebezoek, of meer foto',
            'state' => $alreadyDone ? 'done' : ($canApprove || $approveBlockedReason !== null ? 'current' : 'todo'),
        ];

        return [
            'show_panel' => true,
            'title' => 'Beoordelen in stappen',
            'guidance' => 'Klantfoto’s staan bij aanvullingen of in de fotogalerij. Een eigen foto hieronder is van jou als installateur — niet hetzelfde als een klantfoto.',
            'steps' => $steps,
            'can_approve' => $canApprove,
            'approve_blocked_reason' => $approveBlockedReason,
            'can_site_visit' => ! $alreadyDone,
            'show_summarize' => $session !== null && ! $alreadyDone,
            'photo_label' => 'Eigen foto toevoegen',
        ];
    }
}
