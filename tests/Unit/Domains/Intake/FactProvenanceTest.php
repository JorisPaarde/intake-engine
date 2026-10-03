<?php

declare(strict_types=1);

use App\Domains\Intake\Support\FactProvenance;
use App\Domains\Intake\Support\PrefillSources;
use App\Domains\Intake\Support\RiskRelevantPrefillKeys;

describe('FactProvenance and risk confirmation', function () {
    it('labels inferred as aanname for the installer dossier', function () {
        expect(FactProvenance::Inferred->installerLabel())->toBe('aanname')
            ->and(FactProvenance::Stated->installerLabel())->toBe('gezegd')
            ->and(FactProvenance::Unknown->installerLabel())->toBe('onbekend');
    });

    it('requires confirmation for inferred risk keys', function () {
        expect(RiskRelevantPrefillKeys::requiresConfirmation('noise_sensitive', FactProvenance::Inferred))->toBeTrue()
            ->and(RiskRelevantPrefillKeys::requiresConfirmation('ownership', FactProvenance::Inferred))->toBeTrue()
            ->and(RiskRelevantPrefillKeys::requiresConfirmation('ownership', FactProvenance::Stated))->toBeFalse()
            ->and(RiskRelevantPrefillKeys::requiresConfirmation('cooling_heating', FactProvenance::Inferred))->toBeFalse();
    });

    it('exposes wizard confirmation hook for assumptions', function () {
        expect(PrefillSources::needsCustomerConfirmation(PrefillSources::AI_TEXT_SUGGESTION))->toBeTrue()
            ->and(PrefillSources::needsCustomerConfirmation(
                PrefillSources::AI_TEXT,
                FactProvenance::Inferred,
                'noise_sensitive',
            ))->toBeTrue()
            ->and(PrefillSources::needsCustomerConfirmation(
                PrefillSources::AI_TEXT,
                FactProvenance::Stated,
                'ownership',
            ))->toBeFalse()
            ->and(PrefillSources::needsCustomerConfirmation(null))->toBeFalse()
            ->and(PrefillSources::installerSourceLabel(PrefillSources::AI_TEXT_SUGGESTION))->toBe('aanname')
            ->and(PrefillSources::isAssumption(PrefillSources::AI_TEXT_SUGGESTION))->toBeTrue();
    });
});
