<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\Intake\Models\IntakeQuestion;
use App\Enums\QuestionType;

/**
 * Welke prefill-antwoorden mogen in het klantoverzicht “Dit hebben we al uit je aanvraag”.
 * Klantgericht = template skip_when_prefilled_by (tekst-AI) minus technische blocklist.
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
     * Tekst-/afgeleide bronnen die in het overzicht mogen.
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
}
