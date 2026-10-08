<?php

declare(strict_types=1);

use App\Domains\Intake\Support\FactAcceptance;
use App\Domains\Intake\Support\FactProvenance;
use App\Domains\Intake\Support\FactSource;
use App\Domains\Intake\Support\PrefillSources;
use Tests\TestCase;

uses(TestCase::class);

test('normalizes high medium low and float confidence to 0-100 integers', function () {
    expect(FactAcceptance::normalizeConfidence('high'))->toBe(90)
        ->and(FactAcceptance::normalizeConfidence('medium'))->toBe(70)
        ->and(FactAcceptance::normalizeConfidence('low'))->toBe(40)
        ->and(FactAcceptance::normalizeConfidence(0.85))->toBe(85)
        ->and(FactAcceptance::normalizeConfidence(85))->toBe(85)
        ->and(FactAcceptance::normalizeConfidence(1.0))->toBe(100)
        ->and(FactAcceptance::normalizeConfidence(null))->toBeNull();
});

test('default fact confidence threshold comes from config and can be overridden', function () {
    config(['intake.fact_confidence_threshold' => 80]);
    expect(FactAcceptance::threshold())->toBe(80);

    config(['intake.fact_confidence_threshold' => 80, 'intake.fact_confidence_thresholds.noise_sensitive' => 95]);
    expect(FactAcceptance::threshold('noise_sensitive'))->toBe(95)
        ->and(FactAcceptance::threshold('cooling_heating'))->toBe(80);
});

test('derived or below-threshold values never count as known facts', function () {
    config(['intake.fact_confidence_threshold' => 80]);

    expect(FactAcceptance::countsAsKnown(
        95,
        FactSource::Derived,
        FactProvenance::Inferred,
    ))->toBeFalse()
        ->and(FactAcceptance::countsAsKnown(
            70,
            FactSource::CustomerAnswer,
            FactProvenance::Stated,
        ))->toBeFalse()
        ->and(FactAcceptance::countsAsKnown(
            90,
            FactSource::CustomerAnswer,
            FactProvenance::Stated,
        ))->toBeTrue()
        ->and(FactAcceptance::countsAsKnown(
            90,
            FactSource::Photo,
            FactProvenance::Stated,
        ))->toBeTrue();
});

test('maps prefill sources to aanvraag foto or afgeleid', function () {
    expect(FactAcceptance::sourceFrom(PrefillSources::AI_TEXT, FactProvenance::Stated)->value)->toBe('aanvraag (installateur)')
        ->and(FactAcceptance::sourceFrom(PrefillSources::REQUEST_TEXT, FactProvenance::Stated)->value)->toBe('aanvraag (installateur)')
        ->and(FactAcceptance::sourceFrom('installer', FactProvenance::Stated)->value)->toBe('aanvraag (installateur)')
        ->and(FactAcceptance::sourceFrom(PrefillSources::AI_PHOTO, FactProvenance::Stated)->value)->toBe('foto')
        ->and(FactAcceptance::sourceFrom(PrefillSources::AI_TEXT_SUGGESTION, FactProvenance::Inferred)->value)->toBe('afgeleid')
        ->and(FactAcceptance::sourceFrom(PrefillSources::AI_TEXT, FactProvenance::Inferred)->value)->toBe('afgeleid')
        ->and(FactAcceptance::sourceFrom(null, FactProvenance::Stated)->value)->toBe('klantantwoord')
        ->and(FactAcceptance::sourceFrom('pdok', FactProvenance::Stated)->value)->toBe('klantantwoord');
});

test('stated evidence quote must appear as normalized substring of source text', function () {
    $source = 'Wij willen airco op het balkon van de slaapkamer.';

    expect(FactAcceptance::evidenceAppearsInSource('balkon', $source))->toBeTrue()
        ->and(FactAcceptance::evidenceAppearsInSource('Buren dichtbij', $source))->toBeFalse()
        ->and(FactAcceptance::evidenceAppearsInSource('koelen', $source))->toBeFalse()
        ->and(FactAcceptance::evidenceAppearsInSource(null, $source))->toBeFalse()
        ->and(FactAcceptance::evidenceAppearsInSource('  balkon  ', $source))->toBeTrue();
});
