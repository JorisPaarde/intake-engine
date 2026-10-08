<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Services\AnswerValueReader;
use App\Enums\QuestionType;

/**
 * Boolean questions that must be accepted (true), not merely answered.
 *
 * Keys come from config/intake.php; templates may also set meta.must_accept.
 * No template version bump required — applies to all pinned versions.
 */
final class MustAcceptQuestions
{
    public static function requiresAcceptance(IntakeQuestion $question): bool
    {
        if ($question->type !== QuestionType::Boolean) {
            return false;
        }

        if (($question->meta['must_accept'] ?? false) === true) {
            return true;
        }

        /** @var list<string> $keys */
        $keys = config('intake.must_accept_question_keys', []);

        return in_array($question->key, $keys, true);
    }

    public static function isKey(string $questionKey): bool
    {
        /** @var list<string> $keys */
        $keys = config('intake.must_accept_question_keys', []);

        return in_array($questionKey, $keys, true);
    }

    /**
     * @param  array<string, mixed>|null  $value
     */
    public static function isAccepted(?array $value): bool
    {
        if ($value === null || ! array_key_exists('bool', $value)) {
            return false;
        }

        $comparable = app(AnswerValueReader::class)->readComparable($value, QuestionType::Boolean);

        return $comparable === true;
    }

    public static function refusalMessage(): string
    {
        return 'Zonder je toestemming kunnen we je gegevens niet gebruiken. Neem contact op met je installateur.';
    }

    public static function checkboxLabel(IntakeQuestion $question): string
    {
        return match ($question->key) {
            'privacy_consent' => 'Ik geef toestemming',
            'truth_confirmation' => 'Ik bevestig dit',
            default => 'Ik ga akkoord',
        };
    }
}
