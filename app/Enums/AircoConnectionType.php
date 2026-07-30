<?php

declare(strict_types=1);

namespace App\Enums;

enum AircoConnectionType: string
{
    case Refrigerant = 'refrigerant';
    case Condensate = 'condensate';
    case Power = 'power';

    public function label(): string
    {
        return match ($this) {
            self::Refrigerant => 'Koelleiding',
            self::Condensate => 'Condensafvoer',
            self::Power => 'Stroomtoevoer',
        };
    }
}
