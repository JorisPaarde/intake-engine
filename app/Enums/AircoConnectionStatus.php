<?php

declare(strict_types=1);

namespace App\Enums;

enum AircoConnectionStatus: string
{
    case Unknown = 'unknown';
    case Proposed = 'proposed';
    case Plausible = 'plausible';
    case NeedsEvidence = 'needs_evidence';
    case NotRemotelyResolvable = 'not_remotely_resolvable';
    case Approved = 'approved';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Nog onbekend',
            self::Proposed => 'Voorgesteld',
            self::Plausible => 'Lijkt te kloppen',
            self::NeedsEvidence => 'Aanvulling nodig',
            self::NotRemotelyResolvable => 'Alleen te zien op locatie',
            self::Approved => 'Goedgekeurd',
        };
    }
}
