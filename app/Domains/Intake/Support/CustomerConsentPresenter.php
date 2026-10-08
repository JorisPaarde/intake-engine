<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use Carbon\CarbonInterface;

/**
 * Installer-facing line: “Toestemming klant: gegeven op 8 okt, 10:12” or “Niet gegeven”.
 */
final class CustomerConsentPresenter
{
    /**
     * @return array{given: bool, label: string, answered_at: CarbonInterface|null}
     */
    public function present(Intake $intake): array
    {
        $intake->loadMissing('answers');

        $answer = $intake->answers->first(
            static fn (IntakeAnswer $row): bool => $row->question_key === 'privacy_consent'
                && $row->section_instance_key === null,
        );

        if (! $answer instanceof IntakeAnswer || ! MustAcceptQuestions::isAccepted(
            is_array($answer->value) ? $answer->value : null,
        )) {
            return [
                'given' => false,
                'label' => 'Toestemming klant: niet gegeven',
                'answered_at' => null,
            ];
        }

        $at = $answer->answered_at ?? $answer->updated_at;

        if (! $at instanceof CarbonInterface) {
            return [
                'given' => true,
                'label' => 'Toestemming klant: gegeven',
                'answered_at' => null,
            ];
        }

        $formatted = $at->timezone(config('app.timezone'))->translatedFormat('j M, H:i');

        return [
            'given' => true,
            'label' => 'Toestemming klant: gegeven op '.$formatted,
            'answered_at' => $at,
        ];
    }
}
