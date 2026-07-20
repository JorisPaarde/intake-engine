<?php

declare(strict_types=1);

namespace App\Enums;

enum FollowUpRoundStatus: string
{
    case Open = 'open';
    case Completed = 'completed';
}
