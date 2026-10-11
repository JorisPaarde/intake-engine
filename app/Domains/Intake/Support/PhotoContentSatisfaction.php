<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeUpload;
use Illuminate\Support\Collection;

/**
 * Shared rule: a photo does not satisfy a question while it still needs an
 * explicit override (wrong subject, unusable, …) unless the customer chose
 * “Toch doorgaan”. not_assessed counts as received (no choice). A received
 * (pending) photo does satisfy the required question so Volgende is not
 * blocked while assessment runs asynchronously — except the UI soft-timeout
 * gate in the wizard (max 15 s).
 */
final class PhotoContentSatisfaction
{
    public static function isSatisfied(
        Intake $intake,
        string $questionKey,
        ?string $sectionInstanceKey,
    ): bool {
        $intake->loadMissing('uploads');

        return self::uploadsSatisfy(
            $intake->uploads->filter(static function (IntakeUpload $upload) use ($questionKey, $sectionInstanceKey): bool {
                if ($upload->question_key !== $questionKey) {
                    return false;
                }

                return $sectionInstanceKey === null
                    ? $upload->section_instance_key === null
                    : $upload->section_instance_key === $sectionInstanceKey;
            }),
        );
    }

    /**
     * @param  Collection<int, IntakeUpload>  $uploads
     */
    public static function uploadsSatisfy(Collection $uploads): bool
    {
        if ($uploads->isEmpty()) {
            return false;
        }

        foreach ($uploads as $upload) {
            if (PhotoOverridePolicy::needsOverride($upload)) {
                continue;
            }

            // Received photo: customer may continue while assessment runs async
            // (staging intake 82 — pending must not block Volgende indefinitely).
            return true;
        }

        return false;
    }

    /**
     * @param  Collection<int, IntakeUpload>  $uploads
     */
    public static function unresolvedWrongSubject(Collection $uploads): ?PhotoContentAssessment
    {
        foreach ($uploads as $upload) {
            $assessment = $upload->contentAssessment();

            if ($assessment instanceof PhotoContentAssessment
                && $assessment->status() === PhotoContentAssessment::STATUS_WRONG_SUBJECT
                && ! $assessment->customerAcceptedOverride()) {
                return $assessment;
            }
        }

        return null;
    }

    /**
     * Any unresolved photo issue that requires replace / “Toch versturen”.
     *
     * @param  Collection<int, IntakeUpload>  $uploads
     */
    public static function unresolvedOverride(Collection $uploads): ?IntakeUpload
    {
        return PhotoOverridePolicy::unresolvedOverride($uploads);
    }
}
