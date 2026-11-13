<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Services\VisibilityResolver;

/**
 * Vragen die meestal voor alle ruimtes gelijk zijn: één keer stellen,
 * met escape “Verschilt per ruimte”. Antwoorden worden per ruimte-instantie
 * opgeslagen zodat rapport/export ongewijzigd per ruimte tonen.
 */
final class SharedRoomAnswers
{
    /** Synthetische instance-key voor het gedeelde wizardscherm. */
    public const SCOPE_INSTANCE = '_shared';

    /** Keuze-waarde: klant wil per ruimte antwoorden. */
    public const DIFFERS_VALUE = '__differs_per_instance__';

    public const DIFFERS_LABEL = 'Verschilt per ruimte';

    public const SECTION_TITLE = 'Geldt voor alle ruimtes';

    /**
     * @return list<string>
     */
    public static function shareableQuestionKeys(): array
    {
        return [
            'ceiling_height_m',
            'floor_level',
            'sun_exposure',
            'glass_amount',
            'glazing_type',
            // room_outlet_status blijft intern (InternalCustomerQuestions); niet delen met de klant (BL-166).
        ];
    }

    public static function isShareable(IntakeQuestion|string $question): bool
    {
        if ($question instanceof IntakeQuestion) {
            if (($question->meta['share_across_instances'] ?? false) === true) {
                return true;
            }

            return in_array($question->key, self::shareableQuestionKeys(), true);
        }

        return in_array($question, self::shareableQuestionKeys(), true);
    }

    public static function isScopeInstance(?string $sectionInstanceKey): bool
    {
        return $sectionInstanceKey === self::SCOPE_INSTANCE;
    }

    public static function differsMarkerKey(string $questionKey): string
    {
        return '_share_differs_'.$questionKey;
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $answers
     */
    public static function customerChoseDiffers(array $answers, string $questionKey): bool
    {
        $shared = $answers[VisibilityResolver::compositeKey($questionKey, self::SCOPE_INSTANCE)] ?? null;
        if (is_array($shared) && ($shared['value'] ?? null) === self::DIFFERS_VALUE) {
            return true;
        }

        $marker = $answers[VisibilityResolver::compositeKey(self::differsMarkerKey($questionKey), null)] ?? null;
        if (! is_array($marker)) {
            return false;
        }

        return ($marker['bool'] ?? null) === true
            || ($marker['value'] ?? null) === self::DIFFERS_VALUE;
    }

    /**
     * @param  list<string>  $instanceKeys
     * @param  array<string, array<string, mixed>|null>  $answers
     */
    public static function allInstancesAnswered(
        array $instanceKeys,
        array $answers,
        string $questionKey,
    ): bool {
        if ($instanceKeys === []) {
            return false;
        }

        foreach ($instanceKeys as $instanceKey) {
            $value = $answers[VisibilityResolver::compositeKey($questionKey, $instanceKey)] ?? null;
            if (! self::answerLooksFilled($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>|null  $value
     */
    public static function answerLooksFilled(?array $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (array_key_exists('value', $value) && is_string($value['value']) && $value['value'] !== '') {
            return $value['value'] !== self::DIFFERS_VALUE;
        }

        if (array_key_exists('bool', $value) && is_bool($value['bool'])) {
            return true;
        }

        if (array_key_exists('number', $value) && is_numeric($value['number'])) {
            return true;
        }

        if (array_key_exists('text', $value) && is_string($value['text']) && trim($value['text']) !== '') {
            return true;
        }

        if (array_key_exists('values', $value) && is_array($value['values']) && $value['values'] !== []) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public static function isDiffersPayload(?array $payload): bool
    {
        if ($payload === null) {
            return false;
        }

        return ($payload['value'] ?? null) === self::DIFFERS_VALUE;
    }

    /**
     * Sectietitel: “Geldt voor alle slaapkamers” als alle doelruimtes hetzelfde type zijn.
     *
     * @param  list<string>  $instanceKeys
     * @param  array<string, array<string, mixed>|null>  $answers
     */
    public static function sectionTitleForTargets(array $instanceKeys, array $answers): string
    {
        $types = [];
        foreach ($instanceKeys as $instanceKey) {
            $raw = $answers[VisibilityResolver::compositeKey('room_type', $instanceKey)] ?? null;
            $type = is_array($raw) ? ($raw['value'] ?? null) : null;
            if (! is_string($type) || $type === '') {
                return self::SECTION_TITLE;
            }
            $types[$type] = true;
        }

        if (count($types) !== 1) {
            return self::SECTION_TITLE;
        }

        $only = array_key_first($types);

        return match ($only) {
            'bedroom' => 'Geldt voor alle slaapkamers',
            'living_room' => 'Geldt voor alle woonkamers',
            'office' => 'Geldt voor alle werkkamers',
            default => self::SECTION_TITLE,
        };
    }

    public static function differsLabel(IntakeQuestion $question): string
    {
        $label = $question->meta['share_differs_label'] ?? null;

        return is_string($label) && trim($label) !== ''
            ? trim($label)
            : self::DIFFERS_LABEL;
    }

    /**
     * Welke section_instance_key hoort bij een werkende wizardstap voor een
     * ontbrekende shareable vraag? Completeness telt per room-*, maar de wizard
     * toont óf `_shared` óf per-kamer — nooit beide. Zonder remap ontstaan dode
     * “Nog niet alles is klaar”-links naar room-N terwijl alleen `_shared` bestaat.
     *
     * @param  list<array{question_key: string, section_instance_key: string|null, shared_scope?: bool}>  $steps
     * @param  array<string, array<string, mixed>|null>  $answers
     */
    public static function navigableInstanceKey(
        string $questionKey,
        ?string $requestedInstanceKey,
        array $steps,
        array $answers,
    ): ?string {
        if (! self::isShareable($questionKey)) {
            return $requestedInstanceKey;
        }

        foreach ($steps as $step) {
            if ($step['question_key'] !== $questionKey) {
                continue;
            }
            if (($step['section_instance_key'] ?? null) === $requestedInstanceKey) {
                return $requestedInstanceKey;
            }
        }

        if (self::customerChoseDiffers($answers, $questionKey)) {
            if (is_string($requestedInstanceKey) && str_starts_with($requestedInstanceKey, 'room-')) {
                return $requestedInstanceKey;
            }

            foreach ($steps as $step) {
                if ($step['question_key'] !== $questionKey) {
                    continue;
                }
                $instance = $step['section_instance_key'] ?? null;
                if (is_string($instance) && str_starts_with($instance, 'room-')) {
                    return $instance;
                }
            }

            return $requestedInstanceKey;
        }

        foreach ($steps as $step) {
            if ($step['question_key'] !== $questionKey) {
                continue;
            }
            if (($step['shared_scope'] ?? false) === true
                || ($step['section_instance_key'] ?? null) === self::SCOPE_INSTANCE) {
                return self::SCOPE_INSTANCE;
            }
        }

        return $requestedInstanceKey;
    }
}
