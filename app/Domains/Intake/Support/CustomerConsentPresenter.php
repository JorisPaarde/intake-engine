<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Enums\ContributionMode;
use Carbon\CarbonInterface;

/**
 * Installer-facing consent line (three states):
 * - gegeven op …
 * - niet gegeven (klant zag de vraag en weigerde/liet open)
 * - niet gevraagd (installateursopname)
 */
final class CustomerConsentPresenter
{
    /** @var array<int, string> */
    private const SHORT_MONTHS = [
        1 => 'jan',
        2 => 'feb',
        3 => 'mrt',
        4 => 'apr',
        5 => 'mei',
        6 => 'jun',
        7 => 'jul',
        8 => 'aug',
        9 => 'sep',
        10 => 'okt',
        11 => 'nov',
        12 => 'dec',
    ];

    /**
     * @return array{given: bool, asked: bool, label: string, detail: string, answered_at: CarbonInterface|null}
     */
    public function present(Intake $intake): array
    {
        $intake->loadMissing('answers');

        $customerAnswer = $intake->answers->first(
            static fn (IntakeAnswer $row): bool => $row->question_key === 'privacy_consent'
                && $row->section_instance_key === null
                && ($row->prefill_source === null || $row->prefill_source === ''),
        );

        if ($customerAnswer instanceof IntakeAnswer && MustAcceptQuestions::isAccepted(
            is_array($customerAnswer->value) ? $customerAnswer->value : null,
            $customerAnswer->prefill_source,
        )) {
            $at = $customerAnswer->answered_at ?? $customerAnswer->updated_at;

            if (! $at instanceof CarbonInterface) {
                return [
                    'given' => true,
                    'asked' => true,
                    'label' => 'Toestemming klant: gegeven',
                    'detail' => 'gegeven',
                    'answered_at' => null,
                ];
            }

            $formatted = $this->formatConsentTimestamp($at);

            return [
                'given' => true,
                'asked' => true,
                'label' => 'Toestemming klant: gegeven op '.$formatted,
                'detail' => 'gegeven op '.$formatted,
                'answered_at' => $at,
            ];
        }

        if (! $this->customerWasAsked($intake, $customerAnswer)) {
            return [
                'given' => false,
                'asked' => false,
                'label' => 'Toestemming klant: niet gevraagd (installateursopname)',
                'detail' => 'niet gevraagd (installateursopname)',
                'answered_at' => null,
            ];
        }

        return [
            'given' => false,
            'asked' => true,
            'label' => 'Toestemming klant: niet gegeven',
            'detail' => 'niet gegeven',
            'answered_at' => null,
        ];
    }

    private function formatConsentTimestamp(CarbonInterface $at): string
    {
        $local = $at->timezone(config('app.timezone'));
        $month = self::SHORT_MONTHS[(int) $local->month] ?? rtrim($local->translatedFormat('M'), '.');

        return $local->day.' '.$month.', '.$local->format('H:i');
    }

    private function customerWasAsked(Intake $intake, ?IntakeAnswer $customerAnswer): bool
    {
        return match ($intake->workflow_mode) {
            ContributionMode::Installer => false,
            ContributionMode::Customer => true,
            // Hybrid: asked when consent row exists OR any customer-sourced answer.
            ContributionMode::Hybrid => $customerAnswer instanceof IntakeAnswer
                || $this->customerHasActed($intake),
        };
    }

    private function customerHasActed(Intake $intake): bool
    {
        return $intake->answers->contains(
            static function (IntakeAnswer $answer): bool {
                if ($answer->prefill_source !== null && $answer->prefill_source !== '') {
                    return false;
                }

                $value = is_array($answer->value) ? $answer->value : null;

                return $value !== null && $value !== [];
            },
        );
    }
}
