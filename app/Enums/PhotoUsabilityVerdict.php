<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Local, deterministic photo-usability verdict (BL-007). A *voorstel* — never blocks
 * the flow; the applicant may keep any photo. AI/heuristic is supporting only (ADR-0005).
 */
enum PhotoUsabilityVerdict: string
{
    case Ok = 'ok';
    case TooDark = 'too_dark';
    case TooSmall = 'too_small';

    public function isUsable(): bool
    {
        return $this === self::Ok;
    }

    /** Friendly, non-blocking hint for the applicant. */
    public function customerHint(): ?string
    {
        return match ($this) {
            self::Ok => null,
            self::TooDark => 'Deze foto lijkt erg donker. Maak een nieuwe foto met meer licht.',
            self::TooSmall => 'Deze foto heeft een lage resolutie. Maak een nieuwe, scherpere foto van dichterbij.',
        };
    }

    /** Short label for the installer gallery. */
    public function installerLabel(): ?string
    {
        return match ($this) {
            self::Ok => null,
            self::TooDark => 'mogelijk te donker',
            self::TooSmall => 'lage resolutie',
        };
    }
}
