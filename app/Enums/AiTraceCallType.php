<?php

declare(strict_types=1);

namespace App\Enums;

enum AiTraceCallType: string
{
    case TextExtraction = 'text_extraction';
    case RequestIntent = 'request_intent';
    case PhotoDerive = 'photo_derive';
    case PhotoAssess = 'photo_assess';
    case FollowUpPhotoSubject = 'follow_up_photo_subject';
    case Summary = 'summary';
    case AttentionPoints = 'attention_points';
    case DossierSynthesis = 'dossier_synthesis';
    case Route = 'route';
    case RouteReview = 'route_review';

    /** @deprecated Prefer PhotoDerive / PhotoAssess / FollowUpPhotoSubject / Route */
    case PhotoAnalysis = 'photo_analysis';

    /** @deprecated Prefer Summary / AttentionPoints / DossierSynthesis / Route / RouteReview */
    case Synthesis = 'synthesis';
}
