<?php

declare(strict_types=1);

namespace App\Enums;

enum InstallationProposalDelta: string
{
    case Configuration = 'configuration';
    case IndoorPlacement = 'indoor_placement';
    case OutdoorPlacement = 'outdoor_placement';
    case RefrigerantRoute = 'refrigerant_route';
    case CondensateRoute = 'condensate_route';
    case PowerRoute = 'power_route';
    case Cost = 'cost';

    public function label(): string
    {
        return match ($this) {
            self::Configuration => 'Configuratie',
            self::IndoorPlacement => 'Positie binnenunit',
            self::OutdoorPlacement => 'Positie buitenunit',
            self::RefrigerantRoute => 'Koelleiding',
            self::CondensateRoute => 'Condensafvoer',
            self::PowerRoute => 'Stroomtoevoer',
            self::Cost => 'Kosteninschatting',
        };
    }
}
