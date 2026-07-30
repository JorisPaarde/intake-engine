<?php

declare(strict_types=1);

namespace App\Enums;

enum DossierRecordStatus: string
{
    case Proposed = 'proposed';
    case Established = 'established';
    case Conflicted = 'conflicted';
    case Superseded = 'superseded';
}
