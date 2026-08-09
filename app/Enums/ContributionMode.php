<?php

declare(strict_types=1);

namespace App\Enums;

enum ContributionMode: string
{
    case Customer = 'customer';
    case Installer = 'installer';
    case Hybrid = 'hybrid';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Klant laten opnemen',
            self::Installer => 'Zelf de opname uitvoeren',
            self::Hybrid => 'Samen met de klant',
        };
    }
}
