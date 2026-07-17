<?php

declare(strict_types=1);

namespace App\Enums;

enum ReviewDecision: string
{
    case Pending = 'pending';
    case PrepareQuote = 'prepare_quote';
    case NeedMoreInfo = 'need_more_info';
    case SiteVisitNeeded = 'site_visit_needed';
    case NotSuitable = 'not_suitable';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Nog beoordelen',
            self::PrepareQuote => 'Offerte voorbereiden',
            self::NeedMoreInfo => 'Aanvullende informatie nodig',
            self::SiteVisitNeeded => 'Locatiebezoek nodig',
            self::NotSuitable => 'Aanvraag niet passend',
        };
    }
}
