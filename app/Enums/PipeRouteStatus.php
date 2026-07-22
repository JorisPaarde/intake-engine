<?php

declare(strict_types=1);

namespace App\Enums;

enum PipeRouteStatus: string
{
    /** Foto's worden verzameld en per stuk beoordeeld. */
    case Collecting = 'collecting';

    /** De segmenten zijn tot een route samengevat; wacht op installateur. */
    case Proposed = 'proposed';

    /** De installateur heeft de voorgestelde route goedgekeurd. */
    case Approved = 'approved';

    /** De installateur heeft de voorgestelde route afgekeurd. */
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Collecting => 'Foto\'s verzamelen',
            self::Proposed => 'Route voorgesteld',
            self::Approved => 'Goedgekeurd',
            self::Rejected => 'Afgekeurd',
        };
    }
}
