<?php

declare(strict_types=1);

namespace App\Domains\AI\Support;

use App\Enums\IntakeStatus;

/**
 * Wanneer achtergrond-dossiersynthese mag draaien / beloofd mag worden.
 * Completed is toegestaan (1A na klantafronding); Reviewed/AwaitingCustomer/Cancelled niet.
 */
final class DossierSynthesisEligibility
{
    /** @var list<IntakeStatus> */
    public const SKIP_STATUSES = [
        IntakeStatus::Reviewed,
        IntakeStatus::AwaitingCustomer,
        IntakeStatus::Cancelled,
    ];

    public static function allowsStatus(?IntakeStatus $status): bool
    {
        if ($status === null) {
            return false;
        }

        return ! in_array($status, self::SKIP_STATUSES, true);
    }

    /**
     * Workspace-tekst mag automatische update beloven alleen bij AI aan + toegestane status.
     */
    public static function promisesAutoUpdate(?IntakeStatus $status): bool
    {
        return (bool) config('ai.dossier.enabled', false)
            && self::allowsStatus($status);
    }
}
