<?php

declare(strict_types=1);

namespace App\Enums;

enum ContributionTaskStatus: string
{
    case Proposed = 'proposed';
    case Open = 'open';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
