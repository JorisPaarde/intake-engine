<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\AI\Support\PhotoSubject;

/**
 * Filters installer-internal notes out of customer-facing task drafts (BL-129 / BL-145 / BL-158).
 */
final class CustomerFacingTaskText
{
    /** Eenduidige tip voor buitenunit-/gevelfoto’s (mobiel = desktop, BL-158). */
    public const OUTDOOR_PHOTO_TIP = 'Maak een duidelijke foto van de gevel of buitenmuur waar de buitenunit kan komen. Neem genoeg omgeving mee zodat de plek herkenbaar is.';

    /**
     * Neutral meterkast ask: readable groupenkast, no technical 1-/3-fase choice for the customer.
     */
    public static function fuseboxPhotoPrompt(): string
    {
        return 'Maak een duidelijke foto van de meterkast; maak de groepenkast volledig leesbaar. De installateur beoordeelt de aansluiting.';
    }

    /**
     * Soft meterkast retake without DIN-rail / phase jargon (BL-158).
     */
    public static function fuseboxRetakePrompt(): string
    {
        return 'Maak een scherpere foto van de meterkast, recht van voren, zodat groepen en een eventuele vrije plek voor een extra groep goed zichtbaar zijn.';
    }

    public static function outdoorPhotoTip(): string
    {
        return self::OUTDOOR_PHOTO_TIP;
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
     * Hardware jargon (DIN-rail etc.) that must not reach the customer wizard.
     */
    public static function containsHardwareJargon(string $text): bool
    {
        $lower = mb_strtolower($text);

        foreach ([
            'din-rail',
            'din rail',
            'din-sleuf',
            'din sleuf',
            'modulepositie',
            'modulesleuf',
            'automaatruimte',
            'lege module',
            'empty_module',
        ] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
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
        $mentionsOutdoor = str_contains($lower, 'gevel')
            || str_contains($lower, 'buitenunit')
            || str_contains($lower, 'buitenmuur')
            || str_contains($lower, 'tuin');

        if (self::isInstallerInternal($trimmed)) {
            return $mentionsFusebox
                ? PhotoSubject::Fusebox->customerRetakePrompt()
                : ($mentionsOutdoor
                    ? self::outdoorPhotoTip()
                    : PhotoSubject::Other->customerRetakePrompt());
        }

        if (self::containsHardwareJargon($trimmed)) {
            return $mentionsFusebox || self::looksLikeFuseboxJargonOnly($lower)
                ? self::fuseboxRetakePrompt()
                : ($mentionsOutdoor ? self::outdoorPhotoTip() : PhotoSubject::Other->customerRetakePrompt());
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

    /**
     * Sanitize AI retake_instruction before it becomes customer_message (BL-158).
     */
    public static function sanitizePhotoRetake(PhotoSubject $expected, string $message): string
    {
        $trimmed = trim($message);
        if ($trimmed === '') {
            return $expected->customerRetakePrompt();
        }

        if ($expected === PhotoSubject::OutdoorLocation || $expected === PhotoSubject::OutdoorUnit) {
            if (self::isInstallerInternal($trimmed) || self::containsHardwareJargon($trimmed) || self::isContradictoryOutdoorTip($trimmed)) {
                return self::outdoorPhotoTip();
            }

            return self::ensureCustomerFacing($trimmed);
        }

        if ($expected === PhotoSubject::Fusebox) {
            if (self::isInstallerInternal($trimmed) || self::containsHardwareJargon($trimmed)) {
                return self::fuseboxRetakePrompt();
            }

            return self::ensureCustomerFacing($trimmed);
        }

        return self::ensureCustomerFacing($trimmed);
    }

    private static function looksLikeFuseboxJargonOnly(string $lower): bool
    {
        return str_contains($lower, 'din')
            || str_contains($lower, 'module')
            || str_contains($lower, 'automaat')
            || str_contains($lower, 'fase');
    }

    /**
     * Detect the mobile/desktop tip contradiction from free-form AI retakes.
     */
    private static function isContradictoryOutdoorTip(string $text): bool
    {
        $lower = mb_strtolower($text);

        $asksWholeFacade = str_contains($lower, 'hele gevel')
            || str_contains($lower, 'volledige gevel')
            || str_contains($lower, 'hele voorgevel');
        $asksSingleWall = str_contains($lower, 'één specifieke')
            || str_contains($lower, 'een specifieke muur')
            || str_contains($lower, 'specifieke muur')
            || str_contains($lower, 'één muur');

        return $asksWholeFacade || $asksSingleWall;
    }
}
