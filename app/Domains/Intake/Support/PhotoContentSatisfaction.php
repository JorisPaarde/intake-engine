<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeUpload;
use Illuminate\Support\Collection;

/**
 * Shared rule: a wrong-subject upload does not satisfy a photo question unless the
 * customer explicitly chose “Toch doorgaan”. Quality retakes (needs_clearer) and
 * not_assessed still count as a present photo.
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
            $assessment = $upload->contentAssessment();

            if ($assessment instanceof PhotoContentAssessment
                && $assessment->status() === PhotoContentAssessment::STATUS_WRONG_SUBJECT
                && ! $assessment->customerAcceptedMismatch()) {
                continue;
            }

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
                && ! $assessment->customerAcceptedMismatch()) {
                return $assessment;
            }
        }

        return null;
    }
}
