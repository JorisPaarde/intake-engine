<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Domains\Intake\Services\AnswerValueReader;
use Illuminate\Support\Collection;

/**
 * Eenvoudige klantopname (BL-172, airco v34): aanvraagtekst → foto's → wensen → versturen.
 *
 * Aan/uit per templateversie via meta `customer_flow: simple` op een vraag (request_reason);
 * oudere versies houden hun eigen flow (ADR-0001).
 */
final class SimpleCustomerFlow
{
    public const WISH_QUESTION_KEY = 'additional_comments';

    private const DEFAULT_PROMPT_MAX = 4;

    public static function isEnabled(?IntakeTemplateVersion $version): bool
    {
        if (! $version instanceof IntakeTemplateVersion) {
            return false;
        }

        $version->loadMissing('sections.questions');

        foreach ($version->sections as $section) {
            foreach ($section->questions as $question) {
                if (self::questionEnablesFlow($question)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function questionEnablesFlow(IntakeQuestion $question): bool
    {
        $meta = is_array($question->meta) ? $question->meta : [];

        return ($meta['customer_flow'] ?? null) === 'simple';
    }

    /**
     * Voorbeeldvragen bij het wensenveld, alleen voor onderwerpen die nog niet bekend zijn
     * uit de aanvraagtekst, foto's of andere bronnen. Volgorde = template; maximaal `wish_prompts_max`.
     *
     * @param  array<string, array<string, mixed>|null>  $answers  composite => value
     * @param  array<string, string|null>  $answerSources  composite => prefill_source
     * @param  Collection<string, IntakeQuestion>  $allQuestions
     * @return list<string>
     */
    public static function wishPrompts(
        IntakeQuestion $wishQuestion,
        array $answers,
        array $answerSources,
        Collection $allQuestions,
        AnswerValueReader $reader,
    ): array {
        $meta = is_array($wishQuestion->meta) ? $wishQuestion->meta : [];
        $definitions = is_array($meta['wish_prompts'] ?? null) ? $meta['wish_prompts'] : [];
        $max = is_int($meta['wish_prompts_max'] ?? null) ? max(1, $meta['wish_prompts_max']) : self::DEFAULT_PROMPT_MAX;

        $prompts = [];
        foreach ($definitions as $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $text = $definition['text'] ?? null;
            if (! is_string($text) || trim($text) === '') {
                continue;
            }

            $key = $definition['when_missing'] ?? null;
            if (is_string($key) && $key !== '') {
                $question = $allQuestions->get($key);
                if ($question instanceof IntakeQuestion
                    && self::isKnown($question, $answers, $answerSources, $reader)) {
                    continue;
                }
            }

            $prompts[] = trim($text);
            if (count($prompts) >= $max) {
                break;
            }
        }

        return $prompts;
    }

    /**
     * Bekend = in minstens één instantie ingevuld, niet “weet ik niet” en geen onbevestigde AI-suggestie.
     *
     * @param  array<string, array<string, mixed>|null>  $answers
     * @param  array<string, string|null>  $answerSources
     */
    private static function isKnown(
        IntakeQuestion $question,
        array $answers,
        array $answerSources,
        AnswerValueReader $reader,
    ): bool {
        foreach ($answers as $composite => $value) {
            if (! self::compositeIsFor((string) $composite, $question->key)) {
                continue;
            }

            if (! is_array($value) || ! $reader->isFilled($value, $question->type)) {
                continue;
            }

            if (($value['value'] ?? null) === 'unknown') {
                continue;
            }

            if (PrefillSources::isSuggestion($answerSources[$composite] ?? null)) {
                continue;
            }

            return true;
        }

        return false;
    }

    private static function compositeIsFor(string $composite, string $questionKey): bool
    {
        return $composite === $questionKey || str_ends_with($composite, '__'.$questionKey);
    }
}
