<?php

declare(strict_types=1);

use App\Domains\Intake\Support\InstallerDisplayLabels;

test('installer display labels translate every known enum and source value to Dutch', function () {
    foreach (InstallerDisplayLabels::maps() as $group => $map) {
        expect($map)->not->toBeEmpty("Label map {$group} must not be empty");

        foreach ($map as $raw => $label) {
            expect(InstallerDisplayLabels::isTranslated($group, $raw))->toBeTrue(
                "Untranslated or missing Dutch label for {$group}.{$raw}"
            );
            expect($label)->not->toBe($raw, "Raw key {$group}.{$raw} leaked as label");
            expect($label)->not->toContain('_', "Label for {$group}.{$raw} still looks internal");
        }
    }

    // Known English/internal tokens must never appear as display labels.
    $forbidden = ['short', 'medium', 'long', 'derived_lxw', 'high', 'low', 'unknown'];
    foreach (InstallerDisplayLabels::maps() as $map) {
        foreach ($map as $label) {
            expect(in_array($label, $forbidden, true))->toBeFalse(
                "Forbidden English/internal token leaked as label: {$label}"
            );
        }
    }
});

test('length class short medium long unknown are Dutch in installer UI map', function () {
    expect(InstallerDisplayLabels::lengthClass('short'))->toBe('Kort')
        ->and(InstallerDisplayLabels::lengthClass('medium'))->toBe('Middel')
        ->and(InstallerDisplayLabels::lengthClass('long'))->toBe('Lang')
        ->and(InstallerDisplayLabels::lengthClass('unknown'))->toBe('Onbekend');
});

test('derived_lxw and confidence bands have Dutch installer labels', function () {
    expect(InstallerDisplayLabels::source('derived_lxw'))->toBe('berekend uit L×B')
        ->and(InstallerDisplayLabels::source('installer'))->toBe('installateur')
        ->and(InstallerDisplayLabels::confidence('high'))->toBe('hoge')
        ->and(InstallerDisplayLabels::confidence('medium'))->toBe('middelmatige')
        ->and(InstallerDisplayLabels::confidence('low'))->toBe('lage');
});

test('unknown raw keys fail the translation guard', function () {
    expect(InstallerDisplayLabels::isTranslated('length_class', 'kort'))->toBeFalse()
        ->and(InstallerDisplayLabels::isTranslated('source', 'derived_lxw'))->toBeTrue()
        ->and(InstallerDisplayLabels::isTranslated('source', 'not_a_real_source'))->toBeFalse();
});
