<?php

declare(strict_types=1);

namespace App\Enums;

enum AiTraceCallType: string
{
    case TextExtraction = 'text_extraction';
    case PhotoAnalysis = 'photo_analysis';
    case Synthesis = 'synthesis';
}
