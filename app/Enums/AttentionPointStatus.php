<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of an AI-proposed attention point (BL-007). System/reviewer points
 * carry no status (null): they are authoritative and always shown.
 */
enum AttentionPointStatus: string
{
    case Proposed = 'proposed';
    case Accepted = 'accepted';
    case Dismissed = 'dismissed';
}
