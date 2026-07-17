<?php

declare(strict_types=1);

namespace App\Enums;

enum RuleEffect: string
{
    case Show = 'show';
    case Require = 'require';
}
