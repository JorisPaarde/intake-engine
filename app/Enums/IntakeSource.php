<?php

declare(strict_types=1);

namespace App\Enums;

enum IntakeSource: string
{
    case Installer = 'installer';
    case Embed = 'embed';

    public function label(): string
    {
        return match ($this) {
            self::Installer => 'Installateur',
            self::Embed => 'Website',
        };
    }
}
