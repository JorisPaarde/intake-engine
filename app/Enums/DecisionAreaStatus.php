<?php

declare(strict_types=1);

namespace App\Enums;

enum DecisionAreaStatus: string
{
    case Unknown = 'unknown';
    case Ready = 'ready';
    case Review = 'review';
    case Blocked = 'blocked';
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Nog niet beoordeeld',
            self::Ready => 'Klaar',
            self::Review => 'Controle nodig',
            self::Blocked => 'Aanvulling nodig',
            self::NotApplicable => 'Niet van toepassing',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Ready => 'emerald',
            self::Review => 'amber',
            self::Blocked => 'red',
            self::Unknown => 'gray',
            self::NotApplicable => 'slate',
        };
    }
}
