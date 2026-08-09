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
            self::PowerUncertain => 'Stroomvoorziening onduidelijk',
            self::CondensateUncertain => 'Condensafvoer onduidelijk',
            self::RouteUncertain => 'Leidingroute niet zichtbaar',
            self::AccessUncertain => 'Bereikbaarheid onduidelijk',
            self::ConstructionUncertain => 'Muur of constructie onduidelijk',
            self::CustomerPreference => 'Voorkeur of verzoek van de klant',
            self::Other => 'Andere reden',
        };
    }
}
