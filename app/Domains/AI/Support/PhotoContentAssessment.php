<?php

declare(strict_types=1);

namespace App\Domains\AI\Support;

use App\Domains\Intake\Support\PhotoContinueAnywayAttention;

/**
 * Structured content verdict stored on `intake_uploads.content_assessment`.
 *
 * @phpstan-type AssessmentArray array{
 *     status: string,
 *     expected_subject: string|null,
 *     detected_subject: string|null,
 *     customer_message: string|null,
 *     customer_accepted_mismatch?: bool,
 *     customer_accepted_override?: bool
 * }
 */
final class PhotoContentAssessment
{
    public const STATUS_OK = 'ok';

    public const STATUS_WRONG_SUBJECT = 'wrong_subject';

    public const STATUS_NEEDS_CLEARER = 'needs_clearer';

    public const STATUS_NOT_ASSESSED = 'not_assessed';

    /**
     * @param  AssessmentArray  $value
     */
    private function __construct(private readonly array $value) {}

    public static function ok(PhotoSubject $expected, ?PhotoSubject $detected = null): self
    {
        return new self([
            'status' => self::STATUS_OK,
            'expected_subject' => $expected->value,
            'detected_subject' => ($detected ?? $expected)->value,
            'customer_message' => null,
        ]);
    }

    public static function wrongSubject(
        PhotoSubject $expected,
        PhotoSubject $detected,
        ?string $questionKey = null,
    ): self {
        return new self([
            'status' => self::STATUS_WRONG_SUBJECT,
            'expected_subject' => $expected->value,
            'detected_subject' => $detected->value,
            'customer_message' => $expected->mismatchMessage($detected, $questionKey),
        ]);
    }

    public static function needsClearer(PhotoSubject $expected, string $message): self
    {
        return new self([
            'status' => self::STATUS_NEEDS_CLEARER,
            'expected_subject' => $expected->value,
            'detected_subject' => $expected->value,
            'customer_message' => $message,
        ]);
    }

    public static function notAssessed(?PhotoSubject $expected = null): self
    {
        return new self([
            'status' => self::STATUS_NOT_ASSESSED,
            'expected_subject' => $expected?->value,
            'detected_subject' => null,
            // Soft-fail: klant mag door; installateur ziet het ook als badge.
            'customer_message' => 'We konden je foto nu niet automatisch beoordelen; de installateur kijkt mee.',
        ]);
    }

    /** Definitief oordeel (geen herbeoordeling nodig), of nog open voor retry. */
    public function needsReassessment(): bool
    {
        return $this->value['status'] === self::STATUS_NOT_ASSESSED;
    }

    /**
     * Eén fabriek voor Derive / Fusebox / Follow-up.
     *
     * Derive/Fusebox (geen accepted-set): subject_match=no → altijd wrong_subject,
     * behalve route: een herkende `pipe_route` blokkeert nooit.
     * Accepted-set: detected in de set geldt als match (ongeacht subject_match),
     * behalve op het derive-pad (`$questionKey !== null`) voor outdoor_location /
     * around_house / drain (`expected === OutdoorLocation`): subject_match=no →
     * wrong_subject. Follow-up (geen questionKey) blijft accepted-set-wint.
     * Bruikbare match + retake_instruction → needs_clearer
     * (behalve route-foto’s: die blijven ok / nooit blokkeren).
     *
     * @param  array<string, mixed>  $output
     * @param  list<PhotoSubject>|null  $acceptedSubjects  Follow-up/route: geaccepteerde onderwerpen; null = niet filteren
     */
    public static function fromModelOutput(
        PhotoSubject $expected,
        array $output,
        ?array $acceptedSubjects = null,
        ?string $questionKey = null,
    ): self {
        // Ontbrekend detected_subject ≠ "other": legacy outdoor/room-fixtures
        // leveren alleen subject_match; val dan terug op expected bij match=yes.
        $detected = PhotoSubject::tryFromMixed($output['detected_subject'] ?? null);
        if ($detected === null) {
            if (($output['subject_match'] ?? 'yes') !== 'yes') {
                return self::wrongSubject($expected, PhotoSubject::Other, $questionKey);
            }
            $detected = $expected;
        }

        // Een expliciete leidingroutefoto mag de klant nooit blokkeren.
        if ($expected === PhotoSubject::PipeRoute && $detected === PhotoSubject::PipeRoute) {
            return self::ok($expected, $detected);
        }

        if ($acceptedSubjects !== null) {
            $accepted = false;
            foreach ($acceptedSubjects as $subject) {
                if ($detected === $subject) {
                    $accepted = true;
                    break;
                }
            }

            if (! $accepted) {
                return self::wrongSubject($expected, $detected, $questionKey);
            }

            // Derive-pad alleen: outdoor/around_house/drain + subject_match=no → mismatch.
            // Follow-up (questionKey null) houdt accepted-set-wint.
            if ($questionKey !== null
                && $expected === PhotoSubject::OutdoorLocation
                && ($output['subject_match'] ?? 'yes') !== 'yes') {
                return self::wrongSubject(
                    $expected,
                    $detected === $expected ? PhotoSubject::Other : $detected,
                    $questionKey,
                );
            }
        } elseif (($output['subject_match'] ?? 'yes') !== 'yes') {
            return self::wrongSubject($expected, $detected, $questionKey);
        }

        $retake = is_string($output['retake_instruction'] ?? null)
            ? trim((string) $output['retake_instruction'])
            : '';

        // Routevraag: geaccepteerde categorie → nooit needs_clearer/wrong_subject-blokkade.
        if ($expected === PhotoSubject::PipeRoute && $retake !== '') {
            return self::ok($expected, $detected === PhotoSubject::Other ? $expected : $detected);
        }

        if ($retake !== '') {
            return self::needsClearer($expected, $retake);
        }

        return self::ok($expected, $detected === PhotoSubject::Other ? $expected : $detected);
    }

    /** Behoud klant-acceptatie wanneer een herbeoordeling opnieuw een niet-ok oordeel oplevert. */
    public function preservingCustomerAcceptance(?self $previous): self
    {
        if ($previous?->customerAcceptedOverride()
            && $this->status() !== self::STATUS_OK) {
            return $this->withCustomerAcceptedOverride();
        }

        return $this;
    }

    /**
     * @param  array<string, mixed>|null  $raw
     */
    public static function fromArray(?array $raw): ?self
    {
        if ($raw === null || ! is_string($raw['status'] ?? null)) {
            return null;
        }

        return new self([
            'status' => (string) $raw['status'],
            'expected_subject' => is_string($raw['expected_subject'] ?? null) ? $raw['expected_subject'] : null,
            'detected_subject' => is_string($raw['detected_subject'] ?? null) ? $raw['detected_subject'] : null,
            'customer_message' => is_string($raw['customer_message'] ?? null) ? $raw['customer_message'] : null,
            'customer_accepted_mismatch' => (bool) ($raw['customer_accepted_mismatch'] ?? false),
            'customer_accepted_override' => (bool) ($raw['customer_accepted_override'] ?? false),
        ]);
    }

    public function status(): string
    {
        return $this->value['status'];
    }

    public function solvesContent(): bool
    {
        return $this->value['status'] === self::STATUS_OK
            || $this->customerAcceptedOverride();
    }

    /**
     * Legacy naam: alleen wrong_subject + oude flag.
     * Nieuwe code gebruikt {@see customerAcceptedOverride()}.
     */
    public function customerAcceptedMismatch(): bool
    {
        return ($this->value['status'] === self::STATUS_WRONG_SUBJECT)
            && $this->customerAcceptedOverride();
    }

    /**
     * Expliciete klantkeuze “Toch versturen/doorgaan” bij elke niet-goede foto.
     */
    public function customerAcceptedOverride(): bool
    {
        return (bool) ($this->value['customer_accepted_override'] ?? false)
            || (bool) ($this->value['customer_accepted_mismatch'] ?? false);
    }

    public function withCustomerAcceptedMismatch(): self
    {
        return $this->withCustomerAcceptedOverride();
    }

    public function withCustomerAcceptedOverride(): self
    {
        return new self([
            ...$this->value,
            'customer_accepted_mismatch' => true,
            'customer_accepted_override' => true,
        ]);
    }

    public function customerMessage(): ?string
    {
        $message = $this->value['customer_message'] ?? null;

        return is_string($message) && trim($message) !== '' ? trim($message) : null;
    }

    public function detectedSubject(): ?PhotoSubject
    {
        return PhotoSubject::tryFromMixed($this->value['detected_subject'] ?? null);
    }

    /**
     * Visible installer marker for follow-up / dossier photo review.
     */
    public function installerLabel(): ?string
    {
        return match ($this->value['status']) {
            self::STATUS_WRONG_SUBJECT => 'AI: lijkt '.$this->detectedLabel().', controleer',
            self::STATUS_NOT_ASSESSED => 'AI: nog niet automatisch beoordeeld; controleer',
            self::STATUS_NEEDS_CLEARER => 'AI: scherpere foto gewenst; controleer',
            default => null,
        };
    }

    /**
     * Installer-facing reason when a follow-up photo has the wrong subject
     * (e.g. outdoor unit uploaded for a meterkast task).
     */
    public function followUpMismatchReason(?PhotoSubject $expected = null): ?string
    {
        if ($this->value['status'] !== self::STATUS_WRONG_SUBJECT) {
            return null;
        }

        $expectedLabel = $expected instanceof PhotoSubject
            ? $expected->dutchShortLabel()
            : (($expectedSubject = $this->expectedSubject()) instanceof PhotoSubject
                ? $expectedSubject->dutchShortLabel()
                : 'verwacht onderwerp');

        return 'Ontvangen foto lijkt een '.$this->detectedLabel()
            .', geen '.$expectedLabel
            .' — handmatig controleren';
    }

    /**
     * Customer-facing retake instruction for a wrong-subject follow-up photo.
     * Never expose the installer diagnosis (“handmatig controleren”) to the customer.
     */
    public function customerRetakePrompt(): ?string
    {
        if ($this->value['status'] !== self::STATUS_WRONG_SUBJECT) {
            return null;
        }

        $expected = $this->expectedSubject();

        return $expected instanceof PhotoSubject
            ? $expected->customerRetakePrompt()
            : 'Maak een nieuwe, duidelijke foto van wat we vroegen';
    }

    public function expectedSubject(): ?PhotoSubject
    {
        return PhotoSubject::tryFromMixed($this->value['expected_subject'] ?? null);
    }

    /**
     * Legacy per-upload label (ouder dan groepering op vraag+plek).
     * Nieuwe systeempunten gebruiken {@see PhotoContinueAnywayAttention}.
     */
    public function continueAnywayAttentionLabel(): string
    {
        return 'Foto lijkt '.$this->detectedLabel().', controleer';
    }

    private function detectedLabel(): string
    {
        $detected = $this->detectedSubject();

        if ($detected instanceof PhotoSubject) {
            return $detected->dutchShortLabel();
        }

        return 'andere categorie';
    }

    /**
     * @return AssessmentArray
     */
    public function toArray(): array
    {
        return $this->value;
    }
}
