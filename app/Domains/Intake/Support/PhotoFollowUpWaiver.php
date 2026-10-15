<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Enums\QuestionType;

/**
 * N73: de extra meterkastfoto (`fusebox_photo_extra`) is niet verplicht zodra de
 * klant op de hoofdfoto (`fusebox_photo`) “Toch doorgaan” koos.
 */
final class PhotoFollowUpWaiver
{
    /** @var array<string, string> follow-up key => primary key */
    private const FOLLOW_UPS = ['fusebox_photo_extra' => 'fusebox_photo'];

    public static function waives(
        Intake $intake,
        IntakeTemplateVersion $version,
        IntakeQuestion $question,
        ?string $sectionInstanceKey,
    ): bool {
        if ($question->type !== QuestionType::Photo) {
            return false;
        }

        $primaryKey = self::FOLLOW_UPS[$question->key] ?? null;
        if ($primaryKey === null) {
            return false;
        }

        $primaryUploads = collect(PhotoContinueAnywayAttention::currentWizardUploadsAt($intake, $primaryKey, $sectionInstanceKey));

        return PhotoOverridePolicy::hasAcceptedOverride($primaryUploads);
    }
}
