<?php

declare(strict_types=1);

namespace App\Enums;

enum AiRunStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
