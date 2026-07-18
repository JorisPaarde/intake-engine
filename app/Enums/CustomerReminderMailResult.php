<?php

declare(strict_types=1);

namespace App\Enums;

enum CustomerReminderMailResult: string
{
    case Sent = 'sent';
    case SkippedDemo = 'skipped_demo';
    case SkippedInvalid = 'skipped_invalid';
    case SkippedAlreadySent = 'skipped_already_sent';
    case SkippedNotDue = 'skipped_not_due';
    case SkippedLogMailer = 'skipped_log_mailer';
    case Failed = 'failed';
}
