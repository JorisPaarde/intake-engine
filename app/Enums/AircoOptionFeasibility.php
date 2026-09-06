<?php

declare(strict_types=1);

namespace App\Enums;

enum AircoOptionFeasibility: string
{
    case Pending = 'pending';
    case Feasible = 'feasible';
    case Infeasible = 'infeasible';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Nog beoordelen',
            self::Feasible => 'Haalbaar',
            self::Infeasible => 'Niet haalbaar',
        };
    }
}
