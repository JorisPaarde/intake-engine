<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\PhotoAssessmentStatus;
use App\Enums\PhotoUsabilityVerdict;

/**
 * Per-foto statuslabels voor klantwizard en vervolgronde (UX scout brief 10 okt 2026).
 * Lange AI-adviezen horen in het vraagmeldingsvak, niet op de tegel.
 */
final class PhotoCustomerStatus
{
    public const UPLOADING = 'Foto uploaden…';

    public const LOOKING = 'We bekijken je foto…';

    public const GOOD = 'Goed te zien. Dank je.';

    public const RECEIVED = 'Foto ontvangen.';

    public const SOFT_TIMEOUT = 'Dit duurt langer dan normaal. Je kunt alvast verder.';

    public const UNCLEAR = 'Niet goed te zien.';

    public const WRONG_SUBJECT = 'Niet de gevraagde foto.';

    public const OVERRIDE_ACCEPTED = 'Je installateur kijkt hier zelf naar.';

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

        // Terminal DB-status always wins over stale Livewire assessing state
        // (Vorige/Volgende/goToMissing must never revive "We bekijken je foto…").
        if (! $terminal && ($isPending || $assessingHere) && ! $softReleased) {
            return self::LOOKING;
        }

        if (! $terminal) {
            return self::RECEIVED;
        }

        if (PhotoOverridePolicy::isAcceptedOverride($upload)) {
            return self::OVERRIDE_ACCEPTED;
        }

        $assessment = $upload->contentAssessment();

        if ($assessment instanceof PhotoContentAssessment
            && $assessment->status() === PhotoContentAssessment::STATUS_WRONG_SUBJECT) {
            return self::WRONG_SUBJECT;
        }

        if (self::isUnclearIssue($upload, $assessment)) {
            return self::UNCLEAR;
        }

        if ($assessment instanceof PhotoContentAssessment
            && $assessment->status() === PhotoContentAssessment::STATUS_NOT_ASSESSED) {
            return self::RECEIVED;
        }

        if ($status === PhotoAssessmentStatus::NotAssessed) {
            return self::RECEIVED;
        }

        return self::GOOD;
    }

    private static function isUnclearIssue(
        IntakeUpload $upload,
        ?PhotoContentAssessment $assessment,
    ): bool {
        $verdict = $upload->usability_verdict;
        if ($verdict instanceof PhotoUsabilityVerdict && ! $verdict->isUsable()) {
            return true;
        }

        if ($upload->assessment_status === PhotoAssessmentStatus::HeuristicRejected) {
            return true;
        }

        return $assessment instanceof PhotoContentAssessment
            && $assessment->status() === PhotoContentAssessment::STATUS_NEEDS_CLEARER;
    }
}
