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
 * te lage resolutie, onbruikbaar) blokkeert afronden tot de klant
 * expliciet vervangt of “Toch doorgaan” kiest.
 *
 * not_assessed telt als ontvangen zonder keuze (UX voorlopige keuze 10 okt 2026).
 *
 * Gedeeld door klantwizard en gerichte bijdrage-/follow-up-taak.
 */
final class PhotoOverridePolicy
{
    /**
     * Interne/installateurstekst bij override; klantknop is PRIMARY_RETRY / SECONDARY_KEEP
     * (UX-review 13 nov). Sticky rode footerregel is weg.
     */
    public const OVERRIDE_MESSAGE = 'Niet alle foto’s zijn goed gelukt. Maak ze opnieuw of ga toch door.';

    /** Korte bevestiging na “Toch deze foto gebruiken” vóór auto-advance. */
    public const OVERRIDE_ACCEPTED_FLASH = 'Foto bewaard — de installateur kijkt mee.';

    /** Eén kop + één zin op het bedankscherm van een vervolgronde (BL-147, UX #16.1). */
    public const THANK_YOU_HEADING = 'Bedankt, je aanvulling is binnen';

    public const THANK_YOU_COPY = 'Je installateur bekijkt je antwoorden en neemt contact met je op als er nog iets nodig is.';

    public const PANEL_HEADING_ONE = 'Deze foto laat de kamer nog niet goed zien';

    public const PRIMARY_RETRY = 'Nieuwe foto maken';

    public const SECONDARY_KEEP = 'Toch deze foto gebruiken';

    /** @deprecated Uitlegregel is weg uit het afkeurkader (UX-review 13 nov). */
    public const PANEL_EXPLAIN_ONE = 'Je installateur krijgt de foto dan wel en kijkt er zelf naar.';

    /** @deprecated Uitlegregel is weg uit het afkeurkader (UX-review 13 nov). */
    public const PANEL_EXPLAIN_MANY = 'Je installateur krijgt de foto’s dan wel en kijkt er zelf naar.';

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
    public static function unresolvedOverrideCount(Collection $uploads): int
    {
        $count = 0;

        foreach ($uploads as $upload) {
            if (self::needsOverride($upload)) {
                $count++;
            }
        }

        return $count;
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

        // Bruikbaarheidsproblemen (te donker/klein) vragen altijd een keuze,
        // ook als de AI-status not_assessed is.
        $verdict = $upload->usability_verdict;
        if ($verdict instanceof PhotoUsabilityVerdict && ! $verdict->isUsable()) {
            return true;
        }

        if ($status === PhotoAssessmentStatus::HeuristicRejected) {
            return true;
        }

        // Alleen AI-soft-fail zonder bruikbaarheidsfout: ontvangen, geen keuze.
        if ($status === PhotoAssessmentStatus::NotAssessed) {
            return false;
        }

        $assessment = $upload->contentAssessment();
        if (! $assessment instanceof PhotoContentAssessment) {
            return false;
        }

        if ($assessment->status() === PhotoContentAssessment::STATUS_NOT_ASSESSED) {
            return false;
        }

        return in_array($assessment->status(), [
            PhotoContentAssessment::STATUS_WRONG_SUBJECT,
            PhotoContentAssessment::STATUS_NEEDS_CLEARER,
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
                && $assessment->status() !== PhotoContentAssessment::STATUS_OK
                && $assessment->status() !== PhotoContentAssessment::STATUS_NOT_ASSESSED) {
                $expected = $assessment->expectedSubject() ?? PhotoSubject::Other;

                return CustomerFacingTaskText::sanitizePhotoRetake($expected, $message);
            }
        }

        $verdict = $upload->usability_verdict;
        if ($verdict instanceof PhotoUsabilityVerdict) {
            return $verdict->customerHint();
        }

        return null;
    }

    /**
     * Gestructureerde groepsleutel voor klanthinzen (geen tekstvergelijking).
     */
    public static function feedbackGroupKey(IntakeUpload $upload): ?string
    {
        $assessment = $upload->contentAssessment();
        if ($assessment instanceof PhotoContentAssessment) {
            $status = $assessment->status();
            if (in_array($status, [
                PhotoContentAssessment::STATUS_WRONG_SUBJECT,
                PhotoContentAssessment::STATUS_NEEDS_CLEARER,
            ], true)) {
                $expected = $assessment->expectedSubject();

                return 'content:'.$status.':'.($expected instanceof PhotoSubject ? $expected->value : '');
            }
        }

        $verdict = $upload->usability_verdict;
        if ($verdict instanceof PhotoUsabilityVerdict && ! $verdict->isUsable()) {
            return 'usability:'.$verdict->value;
        }

        if ($upload->assessment_status === PhotoAssessmentStatus::HeuristicRejected) {
            return 'heuristic_rejected';
        }

        return null;
    }

    /**
     * Dedupliceerde klanthinzen gegroepeerd op status/enum/onderwerp (niet op tekst).
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

            if (! self::hasQualityOrContentIssue($upload)) {
                continue;
            }

            $groupKey = self::feedbackGroupKey($upload);
            if ($groupKey === null || isset($hints[$groupKey])) {
                continue;
            }

            $feedback = self::customerFeedback($upload);
            if ($feedback !== null) {
                $hints[$groupKey] = $feedback;
            }
        }

        return array_values($hints);
    }

    /**
     * Afkeurkader-titel, afgestemd op fototype (kamer / meterkast / buitenunit / …).
     * Bij gemengde of onbekende onderwerpen: neutrale meervoudskop.
     *
     * @param  Collection<int, IntakeUpload>  $uploads
     */
    public static function panelHeadingFor(Collection $uploads): string
    {
        $unresolved = $uploads->filter(static fn (IntakeUpload $u): bool => self::needsOverride($u));
        $count = $unresolved->count();
        if ($count === 0) {
            return self::PANEL_HEADING_ONE;
        }

        $subjects = [];
        foreach ($unresolved as $upload) {
            $subject = self::expectedSubjectFor($upload);
            $subjects[$subject instanceof PhotoSubject ? $subject->value : 'other'] = $subject;
        }

        if (count($subjects) === 1) {
            /** @var PhotoSubject|null $only */
            $only = array_values($subjects)[0];

            return self::panelHeadingForSubject($only, $count > 1);
        }

        return $count === 1
            ? 'Deze foto is nog niet goed genoeg'
            : 'Deze foto’s zijn nog niet goed genoeg';
    }

    /**
     * Tiptekst(en) onder de afkeurtitel. AI-/bruikbaarheidshint eerst; anders default per type.
     *
     * @param  Collection<int, IntakeUpload>  $uploads
     * @return list<string>
     */
    public static function panelTipsFor(Collection $uploads): array
    {
        $tips = self::uniqueCustomerFeedback($uploads);
        if ($tips !== []) {
            return $tips;
        }

        foreach ($uploads as $upload) {
            if (! self::needsOverride($upload)) {
                continue;
            }

            $fallback = self::defaultTipFor(self::expectedSubjectFor($upload));
            if ($fallback !== null) {
                return [$fallback];
            }
        }

        return ['Ga in de deuropening staan en laat muren en ramen zien.'];
    }

    /**
     * @deprecated Afkeurkader toont geen uitlegregel meer; behouden voor oudere tests.
     *
     * @param  Collection<int, IntakeUpload>  $uploads
     */
    public static function panelExplanationFor(Collection $uploads): string
    {
        $count = count(self::unresolvedPositions($uploads));

        if ($count === 0 || $count === $uploads->count()) {
            return self::panelExplanation($count);
        }

        $fine = $uploads->count() - $count;
        $received = $fine === 1
            ? 'Je andere foto is goed ontvangen.'
            : 'Je andere foto’s zijn goed ontvangen.';
        $choice = $count === 1
            ? 'Je installateur krijgt deze foto dan wel en kijkt er zelf naar.'
            : 'Je installateur krijgt deze foto’s dan wel en kijkt er zelf naar.';

        return $received.' '.$choice;
    }

    public static function panelHeadingForSubject(?PhotoSubject $subject, bool $plural = false): string
    {
        $noun = match ($subject) {
            PhotoSubject::Room => 'de kamer',
            PhotoSubject::Fusebox => 'de meterkast',
            PhotoSubject::OutdoorUnit => 'de buitenunit',
            PhotoSubject::OutdoorLocation => 'de buitenplek',
            PhotoSubject::PipeRoute => 'de leidingroute',
            PhotoSubject::IndoorUnit => 'de binnenunit',
            default => null,
        };

        if ($noun === null) {
            return $plural
                ? 'Deze foto’s zijn nog niet goed genoeg'
                : 'Deze foto is nog niet goed genoeg';
        }

        return $plural
            ? 'Deze foto’s laten '.$noun.' nog niet goed zien'
            : 'Deze foto laat '.$noun.' nog niet goed zien';
    }

    public static function defaultTipFor(?PhotoSubject $subject): ?string
    {
        return match ($subject) {
            PhotoSubject::Room => 'Ga in de deuropening staan en laat muren en ramen zien.',
            PhotoSubject::Fusebox => 'Open de meterkastdeur helemaal en fotografeer recht van voren.',
            PhotoSubject::OutdoorUnit => 'Fotografeer de buitenunit van dichterbij, met genoeg omgeving eromheen.',
            PhotoSubject::OutdoorLocation => 'Laat de gevel of tuin zien waar de buitenunit kan komen, met genoeg omgeving.',
            PhotoSubject::PipeRoute => 'Laat de hele route zien van binnen naar buiten, zonder dicht op één detail te zoomen.',
            PhotoSubject::IndoorUnit => 'Laat de wand of plek voor de binnenunit zien, met deuren of ramen in beeld.',
            default => null,
        };
    }

    private static function expectedSubjectFor(IntakeUpload $upload): ?PhotoSubject
    {
        $assessment = $upload->contentAssessment();
        if ($assessment instanceof PhotoContentAssessment) {
            $expected = $assessment->expectedSubject();
            if ($expected instanceof PhotoSubject) {
                return $expected;
            }
        }

        return PhotoSubject::expectedForPhotoQuestion((string) $upload->question_key);
    }

    /**
     * 1-based positions (list order) of uploads that still need a choice.
     *
     * @param  Collection<int, IntakeUpload>  $uploads
     * @return list<int>
     */
    private static function unresolvedPositions(Collection $uploads): array
    {
        $positions = [];
        $position = 0;

        foreach ($uploads as $upload) {
            $position++;
            if (self::needsOverride($upload)) {
                $positions[] = $position;
            }
        }

        return $positions;
    }

    public static function panelHeading(int $unresolvedCount): string
    {
        if ($unresolvedCount <= 1) {
            return self::PANEL_HEADING_ONE;
        }

        return $unresolvedCount.' foto’s zijn nog niet goed.';
    }

    public static function panelExplanation(int $unresolvedCount): string
    {
        return $unresolvedCount <= 1
            ? self::PANEL_EXPLAIN_ONE
            : self::PANEL_EXPLAIN_MANY;
    }
}
