<?php

declare(strict_types=1);

namespace App\Domains\AI\Support;

/**
 * Structured content verdict stored on `intake_uploads.content_assessment`.
 *
 * @phpstan-type AssessmentArray array{
 *     status: string,
 *     expected_subject: string|null,
 *     detected_subject: string|null,
 *     customer_message: string|null,
 *     customer_accepted_mismatch?: bool
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

    public static function wrongSubject(PhotoSubject $expected, PhotoSubject $detected): self
    {
        return new self([
            'status' => self::STATUS_WRONG_SUBJECT,
            'expected_subject' => $expected->value,
            'detected_subject' => $detected->value,
            'customer_message' => $expected->mismatchMessage($detected),
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
            // Alleen voor de installateur; klant ziet geen hint/error.
            'customer_message' => null,
        ]);
    }

    /**
     * Eén fabriek voor Derive / Fusebox / Follow-up.
     *
     * Derive/Fusebox (geen accepted-set): subject_match=no → altijd wrong_subject.
     * Follow-up (accepted-set): detected in de set geldt als match (ongeacht subject_match).
     * Bruikbare match + retake_instruction → needs_clearer (ongeacht confidence).
     *
     * @param  array<string, mixed>  $output
     * @param  list<PhotoSubject>|null  $acceptedSubjects  Follow-up: geaccepteerde onderwerpen; null = niet filteren
     */
    public static function fromModelOutput(
        PhotoSubject $expected,
        array $output,
        ?array $acceptedSubjects = null,
    ): self {
        $detected = PhotoSubject::tryFromMixed($output['detected_subject'] ?? null) ?? PhotoSubject::Other;

        if ($acceptedSubjects !== null) {
            $accepted = false;
            foreach ($acceptedSubjects as $subject) {
                if ($detected === $subject) {
                    $accepted = true;
                    break;
                }
            }

            if (! $accepted) {
                return self::wrongSubject($expected, $detected);
            }
        } elseif (($output['subject_match'] ?? 'yes') !== 'yes') {
            return self::wrongSubject($expected, $detected);
        }

        $retake = is_string($output['retake_instruction'] ?? null)
            ? trim((string) $output['retake_instruction'])
            : '';

        if ($retake !== '') {
            return self::needsClearer($expected, $retake);
        }

        return self::ok($expected, $detected === PhotoSubject::Other ? $expected : $detected);
    }

    /** Behoud klant-acceptatie wanneer een herbeoordeling opnieuw wrong_subject oplevert. */
    public function preservingCustomerAcceptance(?self $previous): self
    {
        if ($previous?->customerAcceptedMismatch()
            && $this->status() === self::STATUS_WRONG_SUBJECT) {
            return $this->withCustomerAcceptedMismatch();
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
        ]);
    }

    public function status(): string
    {
        return $this->value['status'];
    }

    public function solvesContent(): bool
    {
        return $this->value['status'] === self::STATUS_OK
            || $this->customerAcceptedMismatch();
    }

    public function customerAcceptedMismatch(): bool
    {
        return ($this->value['status'] === self::STATUS_WRONG_SUBJECT)
            && (bool) ($this->value['customer_accepted_mismatch'] ?? false);
    }

    public function withCustomerAcceptedMismatch(): self
    {
        return new self([
            ...$this->value,
            'customer_accepted_mismatch' => true,
        ]);
    }

    public function customerMessage(): ?string
    {
        if ($this->value['status'] === self::STATUS_NOT_ASSESSED) {
            return null;
        }

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

    public function expectedSubject(): ?PhotoSubject
    {
        return PhotoSubject::tryFromMixed($this->value['expected_subject'] ?? null);
    }

    /** System attention-point label after the customer continues despite a mismatch. */
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
