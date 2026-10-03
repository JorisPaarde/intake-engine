<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\Intake\Models\IntakeQuestion;

/**
 * Technische beslisvragen die de klant nooit mag/moet beantwoorden
 * (klanttest 2026-10-02 P0/P1 · BL-116/ADR-0015 · BL-119).
 *
 * Enige bron voor klantwizard-filter, zichtbaarheidsbypass in klantmodus,
 * open-puntenlogica, known-summary-uitsluiting, tekst-prefill-blokkade (BL-118)
 * én routevoorstellen die nooit als klant-intake_answer landen (BL-119).
 */
final class TechnicalDecisionKeys
{
    /** @var list<string> */
    public const KEYS = [
        'natural_fall_possible',
        'pipe_route_description',
        'pipe_distance_indication',
        'drillings_needed',
        'free_group_known',
    ];

    /** Route-/doorboringconclusies die nooit als klant-intake_answer landen. */
    /** @var list<string> */
    public const ROUTE_PROPOSAL_KEYS = [
        'pipe_route_description',
        'pipe_distance_indication',
        'drillings_needed',
    ];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::KEYS;
    }

    public static function contains(string $questionKey): bool
    {
        return in_array($questionKey, self::KEYS, true);
    }

    public static function isRouteProposal(string $questionKey): bool
    {
        return in_array($questionKey, self::ROUTE_PROPOSAL_KEYS, true);
    }

    public static function hidesFromCustomer(IntakeQuestion $question): bool
    {
        return self::contains($question->key);
    }

    /**
     * Prefill-bronnen die een AI-voorstel zijn, geen installateursbesluit.
     *
     * @return list<string>
     */
    public static function aiPrefillSources(): array
    {
        return ['ai', 'ai_suggestion'];
    }

    public static function isAiPrefillSource(?string $prefillSource): bool
    {
        return $prefillSource !== null
            && in_array($prefillSource, self::aiPrefillSources(), true);
    }

    public static function isInstallerPrefillSource(?string $prefillSource): bool
    {
        return $prefillSource === 'installer';
    }
}
