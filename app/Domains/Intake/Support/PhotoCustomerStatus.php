<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\PhotoAssessmentStatus;

/**
 * Per-foto statuslabels voor klantwizard en vervolgronde (UX 8 okt 2026).
 * Teksten zijn vast; override-knoppen blijven in de bestaande mismatch-panels.
 */
final class PhotoCustomerStatus
{
    public const UPLOADING = 'Foto uploaden…';

    public const LOOKING = 'We bekijken je foto…';

    public const GOOD = 'Goed te zien. Dank je.';

    public const RECEIVED = 'Foto ontvangen.';

    public const SOFT_TIMEOUT = 'Dit duurt langer dan normaal. Je kunt alvast verder.';

    /**
     * Statusregel direct onder een thumbnail.
     *
     * @param  list<int>  $pendingIds
     * @param  list<string>  $assessmentUiReleased
     */
    public static function forUpload(
        IntakeUpload $upload,
        string $composite,
        string $uploadPhase,
        string $uploadPhaseComposite,
        array $pendingIds,
        array $assessmentUiReleased,
    ): string {
        $softReleased = in_array($composite, $assessmentUiReleased, true);
        $isPending = in_array((int) $upload->id, array_map('intval', $pendingIds), true);
        $assessingHere = $uploadPhase === 'assessing' && $uploadPhaseComposite === $composite;

        $status = $upload->assessment_status;
        $terminal = $status instanceof PhotoAssessmentStatus && $status->isTerminal();

        if (! $terminal && ($isPending || $assessingHere) && ! $softReleased) {
            return self::LOOKING;
        }

        if (! $terminal) {
            // Soft-timeout / not yet judged → never block with "Nog te vervangen".
            return self::RECEIVED;
        }

        if (PhotoOverridePolicy::needsOverride($upload)) {
            $assessment = $upload->contentAssessment();
            $message = $assessment instanceof PhotoContentAssessment
                ? trim((string) ($assessment->customerMessage() ?? ''))
                : '';

            return $message !== '' ? $message : self::RECEIVED;
        }

        if ($status === PhotoAssessmentStatus::NotAssessed
            || $status === PhotoAssessmentStatus::HeuristicRejected) {
            return self::RECEIVED;
        }

        $assessment = $upload->contentAssessment();
        if ($assessment instanceof PhotoContentAssessment
            && $assessment->status() === PhotoContentAssessment::STATUS_NOT_ASSESSED) {
            return self::RECEIVED;
        }

        return self::GOOD;
    }
}
