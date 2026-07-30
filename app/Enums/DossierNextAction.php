<?php

declare(strict_types=1);

namespace App\Enums;

enum DossierNextAction: string
{
    case PrepareQuote = 'prepare_quote';
    case SendEstimate = 'send_estimate';
    case RequestContribution = 'request_contribution';
    case PlanSiteVisit = 'plan_site_visit';
    case Reject = 'reject';

    public function label(): string
    {
        return match ($this) {
            self::PrepareQuote => 'Offerte voorbereiden',
            self::SendEstimate => 'Prijsindicatie sturen',
            self::RequestContribution => 'Gerichte aanvulling vragen',
            self::PlanSiteVisit => 'Locatiebezoek plannen',
            self::Reject => 'Aanvraag afwijzen',
        };
    }
}
