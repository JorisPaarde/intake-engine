<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\PhotoAssessmentStatus;
use App\Enums\PhotoUsabilityVerdict;
use Illuminate\Support\Collection;

/**
 * Centrale regel: een foto die niet als goed is beoordeeld (verkeerde categorie,
 * te lage resolutie, onbruikbaar, not_assessed) blokkeert afronden tot de klant
 * expliciet vervangt of “Toch versturen / Toch doorgaan” kiest.
 *
 * Gedeeld door klantwizard en gerichte bijdrage-/follow-up-taak.
 */
final class PhotoOverridePolicy
{
    public const OVERRIDE_MESSAGE = 'Vervang de foto of kies expliciet “Toch versturen”.';

    public const OVERRIDE_MESSAGE_WIZARD = 'Vervang de foto of kies expliciet “Toch doorgaan”.';

    /**
     * @param  Collection<int, IntakeUpload>  $uploads
     */
    public static function unresolvedOverride(Collection $uploads): ?IntakeUpload
    {
        foreach ($uploads as $upload) {
            if (self::needsOverride($upload)) {
                return $upload;
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, IntakeUpload>  $uploads
     */
    public static function hasUnresolvedOverride(Collection $uploads): bool
    {
        return self::unresolvedOverride($uploads) instanceof IntakeUpload;
    }

    /**
     * @param  Collection<int, IntakeUpload>  $uploads
     */
    public static function hasAcceptedOverride(Collection $uploads): bool
    {
        foreach ($uploads as $upload) {
            if (self::isAcceptedOverride($upload) && self::hasQualityOrContentIssue($upload)) {
                return true;
            }
        }

        return false;
    }

    public static function needsOverride(IntakeUpload $upload): bool
    {
        if (self::isAcceptedOverride($upload)) {
            return false;
        }

        return self::hasQualityOrContentIssue($upload);
    }

    public static function isAcceptedOverride(IntakeUpload $upload): bool
    {
        $assessment = $upload->contentAssessment();

        return $assessment instanceof PhotoContentAssessment
            && $assessment->customerAcceptedOverride();
    }

    public static function hasQualityOrContentIssue(IntakeUpload $upload): bool
    {
        $status = $upload->assessment_status;
        if ($status instanceof PhotoAssessmentStatus && ! $status->isTerminal()) {
            // Nog bezig: geen override-gate (wachtbericht elders).
            return false;
        }

        $verdict = $upload->usability_verdict;
        if ($verdict instanceof PhotoUsabilityVerdict && ! $verdict->isUsable()) {
            return true;
        }

        if ($status === PhotoAssessmentStatus::HeuristicRejected) {
            return true;
        }

        $assessment = $upload->contentAssessment();
        if (! $assessment instanceof PhotoContentAssessment) {
            return false;
        }

        return in_array($assessment->status(), [
            PhotoContentAssessment::STATUS_WRONG_SUBJECT,
            PhotoContentAssessment::STATUS_NEEDS_CLEARER,
            PhotoContentAssessment::STATUS_NOT_ASSESSED,
        ], true);
    }

    /**
     * Markeer alle uploads die een override nodig hebben als expliciet geaccepteerd.
     *
     * @param  Collection<int, IntakeUpload>  $uploads
     * @return int Aantal geaccepteerde uploads
     */
    public static function acceptOverrides(Collection $uploads): int
    {
        $accepted = 0;

        foreach ($uploads as $upload) {
            if (! self::needsOverride($upload)) {
                continue;
            }

            self::acceptOverride($upload);
            $accepted++;
        }

        return $accepted;
    }

    public static function acceptOverride(IntakeUpload $upload): void
    {
        $assessment = $upload->contentAssessment();

        if ($assessment instanceof PhotoContentAssessment) {
            $upload->storeContentAssessment($assessment->withCustomerAcceptedOverride());

            return;
        }

        // Alleen usability-probleem (te klein/donker) zonder content_assessment:
        // leg een needs_clearer vast zodat de acceptatie persistent is.
        $verdict = $upload->usability_verdict;
        $message = $verdict instanceof PhotoUsabilityVerdict
            ? ($verdict->customerHint() ?? 'Deze foto is nog niet goed genoeg voor een automatische beoordeling.')
            : 'Deze foto is nog niet goed genoeg voor een automatische beoordeling.';

        $synthetic = PhotoContentAssessment::needsClearer(
            PhotoSubject::Other,
            $message,
        )->withCustomerAcceptedOverride();

        $upload->storeContentAssessment($synthetic, PhotoAssessmentStatus::HeuristicRejected);
    }

    public static function customerFeedback(IntakeUpload $upload): ?string
    {
        $assessment = $upload->contentAssessment();
        if ($assessment instanceof PhotoContentAssessment) {
            $message = $assessment->customerMessage();
            if ($message !== null
                && $assessment->status() !== PhotoContentAssessment::STATUS_OK) {
                return $message;
            }
        }

        $verdict = $upload->usability_verdict;
        if ($verdict instanceof PhotoUsabilityVerdict) {
            return $verdict->customerHint();
        }

        return null;
    }

    /**
     * Dedupliceerde klanthinzen voor een set uploads (één keer per unieke tekst).
     *
     * @param  Collection<int, IntakeUpload>  $uploads
     * @return list<string>
     */
    public static function uniqueCustomerFeedback(Collection $uploads): array
    {
        $hints = [];

        foreach ($uploads as $upload) {
            if (self::isAcceptedOverride($upload)) {
                continue;
            }

            $feedback = self::customerFeedback($upload);
            if ($feedback !== null) {
                $hints[$feedback] = $feedback;
            }
        }

        return array_values($hints);
    }

    public static function thankYouNeedsReviewCopy(bool $hadAcceptedOverride): string
    {
        if ($hadAcceptedOverride) {
            return 'Bedankt. Je aanvulling is ontvangen; de installateur beoordeelt de foto’s nog voordat het dossier rond is.';
        }

        return 'Bedankt. Je installateur kijkt nu of er nog iets openstaat.';
    }
}
