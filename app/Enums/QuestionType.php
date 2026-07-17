<?php

declare(strict_types=1);

namespace App\Enums;

enum QuestionType: string
{
    case ShortText = 'short_text';
    case LongText = 'long_text';
    case Number = 'number';
    case SingleChoice = 'single_choice';
    case MultiChoice = 'multi_choice';
    case Boolean = 'boolean';
    case Photo = 'photo';
}
