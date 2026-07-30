<?php

declare(strict_types=1);

namespace App\Enums;

enum AircoConfigurationType: string
{
    case SingleSplit = 'single_split';
    case MultiSplit = 'multi_split';
    case MultipleSingleSplits = 'multiple_single_splits';

    public function label(): string
    {
        return match ($this) {
            self::SingleSplit => 'Single-split',
            self::MultiSplit => 'Multi-split',
            self::MultipleSingleSplits => 'Meerdere single-splits',
        };
    }
}
