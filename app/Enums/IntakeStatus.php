<?php

declare(strict_types=1);

namespace App\Enums;

enum IntakeStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Reviewed = 'reviewed';
    case AwaitingCustomer = 'awaiting_customer';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Concept',
            self::Sent => 'Verstuurd',
            self::InProgress => 'Bezig',
            self::Completed => 'Afgerond',
            self::Reviewed => 'Beoordeeld',
            self::AwaitingCustomer => 'Aanvulling gevraagd',
            self::Cancelled => 'Geannuleerd',
        };
    }

    public function isCustomerAccessible(): bool
    {
        return match ($this) {
            self::Sent, self::InProgress, self::AwaitingCustomer => true,
            default => false,
        };
    }
}
