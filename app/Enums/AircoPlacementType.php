<?php

declare(strict_types=1);

namespace App\Enums;

enum AircoPlacementType: string
{
    case IndoorUnit = 'indoor_unit';
    case OutdoorUnit = 'outdoor_unit';
    case PowerSource = 'power_source';
    case DrainPoint = 'drain_point';

    public function label(): string
    {
        return match ($this) {
            self::IndoorUnit => 'Binnenunit',
            self::OutdoorUnit => 'Buitenunit',
            self::PowerSource => 'Voedingspunt',
            self::DrainPoint => 'Afvoerpunt',
        };
    }
}
