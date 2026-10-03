<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

/**
 * Filters installer-internal notes out of customer-facing task drafts (BL-127).
 */
final class CustomerFacingTaskText
{
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

        if (str_contains($normalized, 'ontvangen foto lijkt') && str_contains($normalized, 'controleer')) {
            return true;
        }

        return false;
    }
}
