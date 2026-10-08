<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

/**
 * Bron van een geëxtraheerd/afgeleid feit voor drempel, known-summary en dossier.
 *
 * - klantantwoord: bevestigd of letterlijk door de klant aangeleverd
 * - aanvraag (installateur): openingszin/aanvraagtekst die de installateur typt
 * - foto: uit fotobeoordeling
 * - afgeleid: AI-/code-aanname — nooit als feit tonen of overslaan
 */
enum FactSource: string
{
    case CustomerAnswer = 'klantantwoord';
    case InstallerRequest = 'aanvraag (installateur)';
    case Photo = 'foto';
    case Derived = 'afgeleid';

    public function installerLabel(bool $unconfirmed = false): string
    {
        if ($this === self::Derived || $unconfirmed) {
            return $this === self::Derived
                ? 'afgeleid, niet bevestigd'
                : $this->value.', niet bevestigd';
        }

        return $this->value;
    }

    public function mayCountAsKnown(): bool
    {
        return $this !== self::Derived;
    }
}
