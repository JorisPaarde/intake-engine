<?php

declare(strict_types=1);

namespace App\Enums;

enum ContributionAudience: string
{
    case Customer = 'customer';
    case Installer = 'installer';
}
