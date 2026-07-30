<?php

declare(strict_types=1);

namespace App\Enums;

enum InstallationSiteVisitReason: string
{
    case PowerUncertain = 'power_uncertain';
    case CondensateUncertain = 'condensate_uncertain';
    case RouteUncertain = 'route_uncertain';
    case AccessUncertain = 'access_uncertain';
    case ConstructionUncertain = 'construction_uncertain';
    case CustomerPreference = 'customer_preference';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::PowerUncertain => 'Stroomvoorziening niet zeker',
            self::CondensateUncertain => 'Condensafvoer niet zeker',
            self::RouteUncertain => 'Leidingroute niet zichtbaar',
            self::AccessUncertain => 'Bereikbaarheid niet zeker',
            self::ConstructionUncertain => 'Constructie of wandopbouw niet zeker',
            self::CustomerPreference => 'Voorkeur of verzoek van de klant',
            self::Other => 'Andere reden',
        };
    }
}
