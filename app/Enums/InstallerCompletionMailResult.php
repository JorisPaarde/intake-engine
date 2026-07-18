<?php

declare(strict_types=1);

namespace App\Enums;

enum InstallerCompletionMailResult: string
{
    case Sent = 'sent';
    case SkippedDemo = 'skipped_demo';
    case SkippedInvalid = 'skipped_invalid';
    case SkippedLogMailer = 'skipped_log_mailer';
    case Failed = 'failed';
}
