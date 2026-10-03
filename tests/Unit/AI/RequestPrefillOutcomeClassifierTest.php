<?php

declare(strict_types=1);

use App\Domains\AI\DTOs\RequestPrefillCandidate;
use App\Domains\AI\Services\RequestPrefillOutcomeClassifier;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

function classifierCatalog(): array
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
                        'key' => 'indoor_unit_count',
                        'type' => 'number',
                        'label' => 'Aantal',
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
            [
                'key' => 'rooms',
                'title' => 'Ruimtes',
                'is_repeatable' => true,
                'repeat_count_question_key' => 'indoor_unit_count',
                'questions' => [
                    [
                        'key' => 'room_name',
                        'type' => 'short_text',
                        'label' => 'Naam',
                        'options' => [],
                    ],
                    [
                        'key' => 'room_type',
                        'type' => 'single_choice',
                        'label' => 'Type',
                        'options' => [
                            ['value' => 'bedroom', 'label' => 'Slaapkamer'],
                            ['value' => 'living_room', 'label' => 'Woonkamer'],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

test('lange top-level evidence wordt ingekort i.p.v. de hele extractie te verwerpen', function () {
    $classifier = app(RequestPrefillOutcomeClassifier::class);
    $longEvidence = str_repeat('Feit uit de openingszin. ', 40); // > 500 chars

    expect(mb_strlen($longEvidence))->toBeGreaterThan(500);

    $result = $classifier->classifyCatalogOutput([
        'evidence' => $longEvidence,
        'fills' => [
            [
                'question_key' => 'cooling_heating',
                'section_instance_key' => null,
                'confidence' => 'high',
                'value' => ['value' => 'both'],
                'evidence' => null,
            ],
            [
                'question_key' => 'indoor_unit_count',
                'section_instance_key' => null,
                'confidence' => 'high',
                'value' => ['number' => 2],
                'evidence' => null,
            ],
        ],
    ], classifierCatalog());

    expect(mb_strlen($result['evidence']))->toBe(500)
        ->and($result['fills'])->toHaveCount(2)
        ->and($result['candidates'])->toHaveCount(2)
        ->and($result['candidates'][0]->disposition)->toBe(RequestPrefillCandidate::DISPOSITION_FILL)
        ->and($result['validation_errors'])->toHaveKey('evidence')
        ->and(collect($result['normalizations'])->pluck('rule')->all())->toContain('truncate_evidence');
});

test('één kapotte fill verwerpt niet de andere geldige fills', function () {
    $classifier = app(RequestPrefillOutcomeClassifier::class);

    $result = $classifier->classifyCatalogOutput([
        'evidence' => 'Twee slaapkamers koelen en verwarmen.',
        'fills' => [
            [
                'question_key' => 'cooling_heating',
                'section_instance_key' => null,
                'confidence' => 'high',
                'value' => ['value' => 'both'],
                'evidence' => null,
            ],
            [
                'question_key' => 'ownership',
                'section_instance_key' => null,
                'confidence' => 'high',
                // Plausibele modelglitch: scalar i.p.v. object.
                'value' => 'owned',
                'evidence' => null,
            ],
            [
                'question_key' => 'room_name',
                'section_instance_key' => 'room-1',
                'confidence' => 'high',
                'value' => ['text' => 'Slaapkamer ouders'],
                'evidence' => null,
            ],
            [
                'question_key' => 'room_type',
                'section_instance_key' => 'room-1',
                'confidence' => 'typo-high',
                'value' => ['value' => 'bedroom'],
                'evidence' => null,
            ],
            [
                'question_key' => 'room_name',
                'section_instance_key' => 'room-2',
                'confidence' => 'high',
                'value' => ['text' => 'Kinderkamer'],
                'evidence' => null,
            ],
        ],
    ], classifierCatalog());

    $accepted = collect($result['candidates'])
        ->where('disposition', RequestPrefillCandidate::DISPOSITION_FILL)
        ->values();
    $rejected = collect($result['candidates'])
        ->where('disposition', RequestPrefillCandidate::DISPOSITION_REJECTED)
        ->values();

    expect($accepted)->toHaveCount(3)
        ->and($accepted->pluck('questionKey')->all())->toBe([
            'cooling_heating',
            'room_name',
            'room_name',
        ])
        ->and($accepted[1]->value)->toBe(['text' => 'Slaapkamer ouders'])
        ->and($accepted[2]->value)->toBe(['text' => 'Kinderkamer'])
        ->and($rejected)->toHaveCount(2)
        ->and($rejected->pluck('questionKey')->all())->toContain('ownership')
        ->and($rejected->pluck('questionKey')->all())->toContain('room_type');
});

test('ontbrekende fills-array blijft een harde validatiefout', function () {
    $classifier = app(RequestPrefillOutcomeClassifier::class);

    try {
        $classifier->classifyCatalogOutput([
            'evidence' => 'Iets',
        ], classifierCatalog());
        expect(false)->toBeTrue('Expected ValidationException');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('fills');
    }
});

test('inferred risk key becomes suggestion not confirmed fill', function () {
    $classifier = app(RequestPrefillOutcomeClassifier::class);

    $result = $classifier->classifyCatalogOutput([
        'evidence' => 'Balkon aan de straatkant.',
        'fills' => [
            [
                'question_key' => 'ownership',
                'section_instance_key' => null,
                'confidence' => 'high',
                'value' => ['value' => 'owned'],
                'evidence' => 'koopwoning',
                'provenance' => 'inferred',
            ],
            [
                'question_key' => 'cooling_heating',
                'section_instance_key' => null,
                'confidence' => 'high',
                'value' => ['value' => 'both'],
                'evidence' => 'koelen en verwarmen',
                'provenance' => 'stated',
            ],
        ],
    ], classifierCatalog());

    $byKey = collect($result['candidates'])->keyBy('questionKey');

    expect($byKey['ownership']->disposition)->toBe(RequestPrefillCandidate::DISPOSITION_SUGGESTION)
        ->and($byKey['ownership']->provenance?->value)->toBe('inferred')
        ->and($byKey['cooling_heating']->disposition)->toBe(RequestPrefillCandidate::DISPOSITION_FILL)
        ->and($byKey['cooling_heating']->provenance?->value)->toBe('stated');
});

test('missing provenance on risk key defaults to inferred suggestion', function () {
    $classifier = app(RequestPrefillOutcomeClassifier::class);

    $result = $classifier->classifyCatalogOutput([
        'evidence' => 'Balkon.',
        'fills' => [[
            'question_key' => 'ownership',
            'section_instance_key' => null,
            'confidence' => 'high',
            'value' => ['value' => 'owned'],
            'evidence' => null,
        ]],
    ], classifierCatalog());

    expect($result['candidates'])->toHaveCount(1)
        ->and($result['candidates'][0]->disposition)->toBe(RequestPrefillCandidate::DISPOSITION_SUGGESTION)
        ->and($result['candidates'][0]->provenance?->value)->toBe('inferred')
        ->and(collect($result['normalizations'])->pluck('rule')->all())
        ->toContain('provenance_default_inferred_risk');
});
