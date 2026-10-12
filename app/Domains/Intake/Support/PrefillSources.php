<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

/**
 * Prefill-bronnen voor intake_answers.prefill_source (BL-116).
 *
 * Sterk: `request_text`, `ai_text`, `ai_photo`, legacy `ai`.
 * Suggestie: `ai_text_suggestion`, `ai_photo_suggestion`; legacy `ai_suggestion`
 * telt als foto-suggestie (historisch één string voor medium foto-fills; zo wist
 * foto-invalidatie ze mee en overschrijft foto nooit stil een tekstsuggestie).
 * Afgeleid: `derived_lxw` (m²/grootte uit L×B).
 */
final class PrefillSources
{
    public const REQUEST_TEXT = 'request_text';

    public const AI_TEXT = 'ai_text';

    public const AI_PHOTO = 'ai_photo';

    /** @deprecated Legacy strong fill (tekst of foto). */
    public const AI_LEGACY = 'ai';

    public const AI_TEXT_SUGGESTION = 'ai_text_suggestion';

    public const AI_PHOTO_SUGGESTION = 'ai_photo_suggestion';

    /**
     * @deprecated Legacy medium fill — behandeld als foto-suggestie.
     */
    public const AI_SUGGESTION_LEGACY = 'ai_suggestion';

    /** Oppervlak/grootte berekend uit bekende L×B. */
    public const DERIVED_LXW = 'derived_lxw';

    /**
     * Sterke AI-fills (nooit Established in het dossier).
     */
    public static function isStrongAi(?string $source): bool
    {
        return is_string($source) && in_array($source, [
            self::AI_LEGACY,
            self::AI_TEXT,
            self::AI_PHOTO,
        ], true);
    }

    public static function isTextDerived(?string $source): bool
    {
        return is_string($source) && in_array($source, [
            self::REQUEST_TEXT,
            self::AI_TEXT,
            self::AI_LEGACY,
            self::DERIVED_LXW,
        ], true);
    }

    public static function isTextSuggestion(?string $source): bool
    {
        return $source === self::AI_TEXT_SUGGESTION;
    }

    /**
     * Tekstzijde die een foto niet stil mag overschrijven (sterk of suggestie).
     */
    public static function isTextSide(?string $source): bool
    {
        return self::isTextDerived($source) || self::isTextSuggestion($source);
    }

    public static function isPhotoSuggestion(?string $source): bool
    {
        return is_string($source) && in_array($source, [
            self::AI_PHOTO_SUGGESTION,
            self::AI_SUGGESTION_LEGACY,
        ], true);
    }

    public static function isSuggestion(?string $source): bool
    {
        return self::isTextSuggestion($source) || self::isPhotoSuggestion($source);
    }

    /**
     * AI of suggestie → dossier Proposed (excl. derived_lxw: zie L/W).
     */
    public static function isProposedAi(?string $source): bool
    {
        return self::isStrongAi($source) || self::isSuggestion($source);
    }

    /**
     * Bronnen die foto-invalidatie mag wissen (excl. legacy `ai` — zie fact-check).
     *
     * @return list<string>
     */
    public static function photoInvalidationSources(): array
    {
        return [
            self::AI_PHOTO,
            self::AI_PHOTO_SUGGESTION,
            self::AI_SUGGESTION_LEGACY,
        ];
    }

    /**
     * Mag foto-AI dit bestaande antwoord overschrijven?
     */
    public static function photoMayOverwrite(?string $existingSource): bool
    {
        if ($existingSource === null) {
            return false;
        }

        if (self::isTextSide($existingSource)) {
            return false;
        }

        if (in_array($existingSource, ['installer', 'pdok', 'epo'], true)) {
            return false;
        }

        return $existingSource === self::AI_PHOTO || self::isPhotoSuggestion($existingSource);
    }

    /**
     * Match answer-bron tegen template skip_when_prefilled_by.
     * Legacy-lijsten met alleen `ai` matchen ook `ai_text` en `ai_photo`.
     *
     * @param  list<mixed>  $skipSources
     */
    public static function matchesSkipSource(?string $answerSource, array $skipSources): bool
    {
        if ($answerSource === null) {
            return false;
        }

        $normalized = array_values(array_filter($skipSources, 'is_string'));

        if (in_array($answerSource, $normalized, true)) {
            return true;
        }

        if (in_array(self::AI_LEGACY, $normalized, true)
            && in_array($answerSource, [self::AI_TEXT, self::AI_PHOTO], true)) {
            return true;
        }

        return false;
    }

    /**
     * Eén skip-beslissing voor stepbuilder en known-summary.
     * request_text en derived_lxw vallen altijd weg; verder template-skiplijst (+ legacy ai).
     *
     * @param  list<mixed>  $skipSources
     */
    public static function shouldSkipPrefill(?string $answerSource, array $skipSources): bool
    {
        if ($answerSource === self::REQUEST_TEXT || $answerSource === self::DERIVED_LXW) {
            return true;
        }

        return self::matchesSkipSource($answerSource, $skipSources);
    }

    /**
     * Mag deze prefill de klantvraag stil overslaan? Gedeeld door step-builder en
     * ProgressCalculator/CompletenessChecker (zelfde definitie van overslaanbaar).
     *
     * AI-waarde `unknown` mag nooit een verplichte vraag laten vervallen.
     *
     * @param  list<mixed>  $skipSources  template meta skip_when_prefilled_by
     * @param  array{provenance: ?string, confidence: ?int, fact_source: ?string}|null  $answerFact
     * @param  array<string, mixed>|null  $answerValue
     */
    public static function maySkipAsPrefill(
        ?string $answerSource,
        array $skipSources,
        ?string $questionKey = null,
        ?array $answerFact = null,
        ?array $answerValue = null,
    ): bool {
        if (! self::shouldSkipPrefill($answerSource, $skipSources)) {
            return false;
        }

        // AI-unknown is geen antwoord: vraag blijft staan.
        if (self::isAiUnknownChoice($answerSource, $answerValue)) {
            return false;
        }

        $provenance = FactProvenance::tryFromMixed($answerFact['provenance'] ?? null);
        $factSource = FactSource::tryFrom((string) ($answerFact['fact_source'] ?? ''))
            ?? FactAcceptance::sourceFrom($answerSource, $provenance);
        $confidence = is_int($answerFact['confidence'] ?? null)
            ? $answerFact['confidence']
            : null;

        if ($answerSource === self::DERIVED_LXW) {
            return $confidence === null || $confidence >= FactAcceptance::threshold($questionKey);
        }

        // Legacy fills zonder confidence/provenance: blijf op oude skipgedrag voor request_text.
        if ($answerSource === self::REQUEST_TEXT && $answerFact === null) {
            return true;
        }

        if ($provenance === null && $confidence === null && ($answerFact['fact_source'] ?? null) === null) {
            // Oude antwoorden zonder fact-meta: alleen sterke niet-suggestion bronnen skippen.
            return ! self::isSuggestion($answerSource)
                && $answerSource !== self::AI_TEXT_SUGGESTION;
        }

        // Null provenance = legacy fill zonder meta: niet als inferred behandelen.
        return FactAcceptance::countsAsKnown(
            $confidence ?? ($provenance === FactProvenance::Stated ? FactAcceptance::LEVEL_HIGH : null),
            $factSource,
            $provenance,
            $questionKey,
        );
    }

    /**
     * AI-/suggestiebron met single-choice `unknown` — geen bruikbaar antwoord.
     *
     * @param  array<string, mixed>|null  $answerValue
     */
    public static function isAiUnknownChoice(?string $answerSource, ?array $answerValue): bool
    {
        if ($answerSource === null || $answerValue === null) {
            return false;
        }

        if (! self::isStrongAi($answerSource) && ! self::isSuggestion($answerSource)) {
            return false;
        }

        $choice = $answerValue['value'] ?? null;

        return is_string($choice) && $choice === 'unknown';
    }

    /**
     * AI-aanname / voorzet: niet bevestigd door de klant (wizard mag om bevestiging vragen).
     */
    public static function isAssumption(?string $source): bool
    {
        return self::isSuggestion($source);
    }

    /**
     * Wizard-hook: moet de klant dit antwoord nog bevestigen?
     * Suggestie, afgeleide bron, of confidence onder drempel → ja.
     */
    public static function needsCustomerConfirmation(
        ?string $prefillSource,
        ?FactProvenance $provenance = null,
        ?string $questionKey = null,
        ?int $confidencePercent = null,
        ?FactSource $factSource = null,
    ): bool {
        if ($prefillSource === null) {
            return false;
        }

        if (self::isAssumption($prefillSource)) {
            return true;
        }

        if ($questionKey !== null
            && $provenance instanceof FactProvenance
            && RiskRelevantPrefillKeys::requiresConfirmation($questionKey, $provenance)) {
            return true;
        }

        $source = $factSource ?? FactAcceptance::sourceFrom($prefillSource, $provenance);
        if ($confidencePercent !== null) {
            return FactAcceptance::needsConfirmation(
                $confidencePercent,
                $source,
                $provenance,
                $questionKey,
            );
        }

        if ($provenance === FactProvenance::Inferred || $provenance === FactProvenance::Unknown) {
            return true;
        }

        return $source === FactSource::Derived;
    }

    /**
     * Installateurslabel voor dossierweergave.
     */
    public static function installerSourceLabel(?string $prefillSource, ?FactProvenance $provenance = null): string
    {
        $source = FactAcceptance::sourceFrom($prefillSource, $provenance);

        if (self::isAssumption($prefillSource) || $source === FactSource::Derived) {
            return FactSource::Derived->installerLabel(true);
        }

        return $source->installerLabel();
    }
}
