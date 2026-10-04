<?php

declare(strict_types=1);

namespace App\Enums;

enum AiTraceStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    /** Logged decision that no provider call was made (skip reason in error_message). */
    case Skipped = 'skipped';
}
