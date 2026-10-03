<?php

declare(strict_types=1);

namespace App\Enums;

enum AiTraceStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
