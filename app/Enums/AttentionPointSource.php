<?php

declare(strict_types=1);

namespace App\Enums;

enum AttentionPointSource: string
{
    case System = 'system';
    case Reviewer = 'reviewer';
    case Ai = 'ai';
}
