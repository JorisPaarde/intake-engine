<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Enums\QuestionType;

/**
 * N73: een vervolgfoto binnen hetzelfde foto-onderwerp (bijv. `fusebox_photo_extra`
 * naast `fusebox_photo`) is niet verplicht zodra de klant op de hoofdfoto
 * “Toch doorgaan” koos: doorgaan zonder extra foto. Structureel: onderwerp via
 * `meta.photo_analysis`, hoofdvraag = laagste sort_order; geen tekstvergelijking.
 */
final class PhotoFollowUpWaiver
{
    public static function waives(
        Intake $intake,
        IntakeTemplateVersion $version,
        IntakeQuestion $question,
        ?string $sectionInstanceKey,
    ): bool {
        if ($question->type !== QuestionType::Photo) {
            return false;
        }

        $keys = PhotoContinueAnywayAttention::questionKeysForTopic($version, $question->key);
        $primaryKey = $keys[0] ?? $question->key;
        if ($primaryKey === $question->key) {
            return false;
        }

        $primaryUploads = collect(PhotoContinueAnywayAttention::currentWizardUploadsAt($intake, $primaryKey, $sectionInstanceKey));

        return PhotoOverridePolicy::hasAcceptedOverride($primaryUploads);
    }
}
