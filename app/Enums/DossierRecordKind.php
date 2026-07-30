<?php

declare(strict_types=1);

namespace App\Enums;

enum DossierRecordKind: string
{
    case Observation = 'observation';
    case Conclusion = 'conclusion';
}
