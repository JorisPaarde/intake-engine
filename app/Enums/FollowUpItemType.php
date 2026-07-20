<?php

declare(strict_types=1);

namespace App\Enums;

enum FollowUpItemType: string
{
    case Text = 'text';
    case Photo = 'photo';
    case Document = 'document';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Tekstantwoord',
            self::Photo => 'Foto',
            self::Document => 'Document (PDF)',
        };
    }
}
