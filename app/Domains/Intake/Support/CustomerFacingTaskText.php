<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\AI\Support\PhotoSubject;

/**
 * Filters installer-internal notes out of customer-facing task drafts (BL-129 / BL-145).
 */
final class CustomerFacingTaskText
{
    /**
     * Neutral meterkast ask: readable groupenkast, no technical 1-/3-fase choice for the customer.
     */
    public static function fuseboxPhotoPrompt(): string
    {
        return 'Maak een duidelijke foto van de meterkast; maak de groepenkast volledig leesbaar. De installateur beoordeelt de aansluiting.';
    }

    /**
     * Texts meant for the installer (dossier/readiness), never for the customer.
     */
    public static function isInstallerInternal(string $text): bool
    {
        $normalized = mb_strtolower(trim($text));

        if ($normalized === '') {
            return false;
        }

        if (str_contains($normalized, 'handmatig controleren')) {
            return true;
        }

        if (str_starts_with($normalized, 'ai:')) {
            return true;
        }

        if (str_contains($normalized, 'ontvangen foto lijkt')
            && (str_contains($normalized, 'controleer') || str_contains($normalized, 'geen meterkast'))) {
            return true;
        }

        if (PhotoSubject::isInstallerMismatchReason($normalized)) {
            return true;
        }

        return false;
    }

    /**
     * Ensure a prompt is safe to show the customer. Never pass installer diagnosis through.
     */
    public static function ensureCustomerFacing(string $text): string
    {
        $trimmed = trim($text);

        if ($trimmed === '') {
            return $trimmed;
        }

        $lower = mb_strtolower($trimmed);
        $mentionsFusebox = str_contains($lower, 'meterkast')
            || str_contains($lower, 'groepenkast')
            || str_contains($lower, 'fusebox');

        if (self::isInstallerInternal($trimmed)) {
            return $mentionsFusebox
                ? PhotoSubject::Fusebox->customerRetakePrompt()
                : PhotoSubject::Other->customerRetakePrompt();
        }

        // Technical phase language is an installer decision, not a customer instruction.
        if ($mentionsFusebox && (
            str_contains($lower, '1- of 3-fase')
            || str_contains($lower, '1- of 3 fase')
            || str_contains($lower, '1 of 3-fase')
            || str_contains($lower, 'daaruit volgt')
        )) {
            return self::fuseboxPhotoPrompt();
        }

        return $trimmed;
    }
}
