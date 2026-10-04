<?php

declare(strict_types=1);

use App\Domains\AI\DTOs\RequestPrefillCandidate;
use App\Domains\AI\Services\RequestPrefillOutcomeClassifier;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Intake 84 fixture: balcony-only text must not produce neighbours_close/noise_sensitive as fact.
 * Intake 78 fixture: text without cooling must not produce cooling_heating as confirmed prefill.
 */
function quoteValidationCatalog(): array
{
    return [
        'sections' => [
            [
                'key' => 'request',
                'title' => 'Aanvraag',
                'is_repeatable' => false,
                'repeat_count_question_key' => null,
                'questions' => [
                    [
                        'key' => 'cooling_heating',
                        'type' => 'single_choice',
                        'label' => 'Koelen/verwarmen',
                        'options' => [
                            ['value' => 'cooling', 'label' => 'Koelen'],
                            ['value' => 'both', 'label' => 'Beide'],
                        ],
                    ],
                    [
                        'key' => 'noise_sensitive',
                        'type' => 'boolean',
                        'label' => 'Buren dichtbij',
                        'options' => [],
                    ],
                    [
                        'key' => 'ownership',
                        'type' => 'single_choice',
                        'label' => 'Eigendom',
                        'options' => [
                            ['value' => 'owned', 'label' => 'Koop'],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

test('intake 84 balkon text: fabricated buren evidence cannot stay stated fact for noise_sensitive', function () {
    config(['intake.fact_confidence_threshold' => 80]);

    $classifier = app(RequestPrefillOutcomeClassifier::class);
    $sourceText = 'Fictieve QA. We willen een airco voor de slaapkamer met balkon. Geen haast.';

    $result = $classifier->classifyCatalogOutput([
        'evidence' => 'Balkon genoemd in de aanvraag.',
        'fills' => [[
            'question_key' => 'noise_sensitive',
            'section_instance_key' => null,
            'confidence' => 'high',
            'value' => ['bool' => true],
            'evidence' => 'buren dichtbij',
            'provenance' => 'stated',
        ]],
    ], quoteValidationCatalog(), [], $sourceText);

    $candidate = $result['candidates'][0];

    expect($candidate->disposition)->toBe(RequestPrefillCandidate::DISPOSITION_SUGGESTION)
        ->and($candidate->provenance?->value)->toBe('inferred')
        ->and($candidate->confidencePercent)->toBeLessThan(80)
        ->and(collect($result['normalizations'])->pluck('rule')->all())
        ->toContain('stated_evidence_not_in_source')
        ->and($result['fills'])->toHaveCount(1)
        ->and($result['fills'][0]['provenance'])->toBe('inferred');
});

test('intake 78 text without cooling: fabricated koelen evidence cannot stay stated cooling prefill', function () {
    config(['intake.fact_confidence_threshold' => 80]);

    $classifier = app(RequestPrefillOutcomeClassifier::class);
    $sourceText = 'Nog geen airco in huis. Buitenunit mag aan de gevel. Installateur mag technische keuzes bepalen.';

    $result = $classifier->classifyCatalogOutput([
        'evidence' => 'Aanvraag noemt nog geen airco.',
        'fills' => [[
            'question_key' => 'cooling_heating',
            'section_instance_key' => null,
            'confidence' => 'high',
            'value' => ['value' => 'cooling'],
            'evidence' => 'koelen',
            'provenance' => 'stated',
        ]],
    ], quoteValidationCatalog(), [], $sourceText);

    $candidate = $result['candidates'][0];

    // Either rejected (absence rule) or suggestion/inferred — never confirmed fill as fact.
    expect($candidate->disposition)->not->toBe(RequestPrefillCandidate::DISPOSITION_FILL);

    if ($candidate->disposition === RequestPrefillCandidate::DISPOSITION_SUGGESTION) {
        expect($candidate->provenance?->value)->toBe('inferred')
            ->and($candidate->confidencePercent)->toBeLessThan(80);
    }
});

test('genuine stated quote in source text may remain a confirmed fill above threshold', function () {
    config(['intake.fact_confidence_threshold' => 80]);

    $classifier = app(RequestPrefillOutcomeClassifier::class);
    $sourceText = 'Ik wil de slaapkamer koelen. De buren zitten dichtbij.';

    $result = $classifier->classifyCatalogOutput([
        'evidence' => 'Letterlijke koelen en buren.',
        'fills' => [
            [
                'question_key' => 'cooling_heating',
                'section_instance_key' => null,
                'confidence' => 'high',
                'value' => ['value' => 'cooling'],
                'evidence' => 'koelen',
                'provenance' => 'stated',
            ],
            [
                'question_key' => 'noise_sensitive',
                'section_instance_key' => null,
                'confidence' => 'high',
                'value' => ['bool' => true],
                'evidence' => 'buren zitten dichtbij',
                'provenance' => 'stated',
            ],
        ],
    ], quoteValidationCatalog(), [], $sourceText);

    $byKey = collect($result['candidates'])->keyBy('questionKey');

    expect($byKey['cooling_heating']->disposition)->toBe(RequestPrefillCandidate::DISPOSITION_FILL)
        ->and($byKey['cooling_heating']->provenance?->value)->toBe('stated')
        ->and($byKey['cooling_heating']->confidencePercent)->toBe(90)
        ->and($byKey['noise_sensitive']->disposition)->toBe(RequestPrefillCandidate::DISPOSITION_FILL)
        ->and($byKey['noise_sensitive']->provenance?->value)->toBe('stated');
});
