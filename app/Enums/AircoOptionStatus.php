<?php

declare(strict_types=1);

namespace App\Enums;

enum AircoOptionStatus: string
{
    case Candidate = 'candidate';
    case Selected = 'selected';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Candidate => 'Kandidaat',
            self::Selected => 'Geselecteerd',
            self::Rejected => 'Afgewezen',
        };
    }
}
