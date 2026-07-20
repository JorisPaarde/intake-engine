<?php

declare(strict_types=1);

namespace App\Enums;

enum AiRunType: string
{
    case Summary = 'summary';
    case AttentionPoints = 'attention_points';
    case PhotoQuality = 'photo_quality';
    case PhotoAssessment = 'photo_assessment';
}
