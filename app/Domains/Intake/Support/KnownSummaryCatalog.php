<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Enums\QuestionType;

/**
 * Welke prefill-antwoorden mogen in het klantoverzicht “Dit hebben we al uit je aanvraag”.
 * Alleen stated/confirmed feiten boven de confidence-drempel (geen afgeleide aannames).
 */
final class KnownSummaryCatalog
{
    public static function allows(IntakeQuestion $question): bool
    {
        if ($question->type === QuestionType::Photo || $question->key === 'request_reason') {
            return false;
        }

        if (TechnicalDecisionKeys::contains($question->key)) {
            return false;
        }

        if (InternalCustomerQuestions::hidesFromCustomer($question)) {
            return false;
        }

        $skipSources = $question->meta['skip_when_prefilled_by'] ?? null;
        if ($skipSources === null) {
            return false;
        }

        $skipSources = is_array($skipSources) ? $skipSources : [$skipSources];

        return PrefillSources::matchesSkipSource(PrefillSources::AI_TEXT, $skipSources)
            || PrefillSources::matchesSkipSource(PrefillSources::REQUEST_TEXT, $skipSources);
    }

    /**
     * Tekst-/foto-/afgeleide bronnen die in het overzicht mogen (vóór confidence/provenance-check).
     * Foto-prefill (ai_photo) mag mee voor zon/glas/buitenlocatie zodat rich-text
     * én foto-afgeleide bekende feiten in hetzelfde overzicht landen.
     */
    public static function allowsSource(?string $source): bool
    {
        return in_array($source, [
            PrefillSources::REQUEST_TEXT,
            PrefillSources::AI_TEXT,
            PrefillSources::AI_PHOTO,
            PrefillSources::DERIVED_LXW,
        ], true);
    }

    public static function isSkipped(?string $source, IntakeQuestion $question): bool
    {
        $skipSources = $question->meta['skip_when_prefilled_by'] ?? null;
        $skipSources = is_array($skipSources) ? $skipSources : ($skipSources !== null ? [$skipSources] : []);

        return PrefillSources::shouldSkipPrefill($source, $skipSources);
    }

    /**
     * Mag dit antwoord als “al bekend” in de known-summary?
     */
    public static function allowsAnswer(IntakeAnswer $answer, IntakeQuestion $question): bool
    {
        if (! self::allows($question)
            || ! self::allowsSource($answer->prefill_source)
            || ! self::isSkipped($answer->prefill_source, $question)) {
            return false;
        }

        // Null provenance = legacy fill zonder meta — niet als inferred behandelen.
        $provenance = FactProvenance::tryFromMixed($answer->fact_provenance);
        $source = FactSource::tryFrom((string) ($answer->fact_source ?? ''))
            ?? FactAcceptance::sourceFrom($answer->prefill_source, $provenance);
        $confidence = is_int($answer->fact_confidence)
            ? $answer->fact_confidence
            : FactAcceptance::normalizeConfidence($answer->fact_confidence);

        // derived_lxw is berekend uit L×B die al stated/confirmed zijn — mag in summary.
        if ($answer->prefill_source === PrefillSources::DERIVED_LXW) {
            return $confidence === null || $confidence >= FactAcceptance::threshold($answer->question_key);
        }

        return FactAcceptance::countsAsKnown(
            $confidence,
            $source,
            $provenance,
            $answer->question_key,
        );
    }
}
