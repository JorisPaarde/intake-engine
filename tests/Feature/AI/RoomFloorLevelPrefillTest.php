<?php

declare(strict_types=1);

use App\Domains\AI\DTOs\RequestPrefillCandidate;
use App\Domains\AI\Services\PromptVersionRepository;
use App\Domains\AI\Services\RequestPrefillOutcomeClassifier;

test('classifyLocalOutput past verdieping per ruimte toe zonder begane-grond-default', function () {
    $catalog = [
        'sections' => [[
            'key' => 'rooms',
            'is_repeatable' => true,
            'questions' => [
                [
                    'key' => 'room_type',
                    'type' => 'single_choice',
                    'label' => 'Type',
                    'options' => [
                        ['value' => 'living_room'],
                        ['value' => 'bedroom'],
                    ],
                ],
                [
                    'key' => 'floor_level',
                    'type' => 'single_choice',
                    'label' => 'Verdieping',
                    'options' => [
                        ['value' => 'ground'],
                        ['value' => '1'],
                        ['value' => 'attic'],
                    ],
                ],
                [
                    'key' => 'indoor_unit_count',
                    'type' => 'number',
                    'label' => 'Aantal',
                    'options' => [],
                ],
                [
                    'key' => 'cooling_heating',
                    'type' => 'single_choice',
                    'label' => 'Doel',
                    'options' => [
                        ['value' => 'cooling'],
                    ],
                ],
            ],
        ]],
    ];

    $classifier = app(RequestPrefillOutcomeClassifier::class);
    $candidates = $classifier->classifyLocalOutput([
        'cooling_heating' => 'cooling',
        'rooms' => ['living_room', 'bedroom'],
        'floor_level' => null,
        'room_floors' => ['ground', '1'],
        'confidence' => 'high',
        'evidence' => 'test',
    ], $catalog);

    $floors = collect($candidates)
        ->where('questionKey', 'floor_level')
        ->mapWithKeys(fn (RequestPrefillCandidate $c): array => [
            (string) $c->sectionInstanceKey => $c->value,
        ])
        ->all();

    expect($floors)->toBe([
        'room-1' => ['value' => 'ground'],
        'room-2' => ['value' => '1'],
    ]);

    $empty = $classifier->classifyLocalOutput([
        'cooling_heating' => 'cooling',
        'rooms' => ['living_room', 'bedroom'],
        'floor_level' => null,
        'room_floors' => [null, null],
        'confidence' => 'high',
        'evidence' => 'test',
    ], $catalog);

    expect(collect($empty)->where('questionKey', 'floor_level'))->toHaveCount(0);
});

test('catalogusclassifier corrigeert verkeerde globale verdieping per ruimte', function () {
    $catalog = [
        'sections' => [[
            'key' => 'rooms',
            'is_repeatable' => true,
            'questions' => [
                [
                    'key' => 'room_type',
                    'type' => 'single_choice',
                    'label' => 'Type',
                    'options' => [
                        ['value' => 'living_room'],
                        ['value' => 'bedroom'],
                    ],
                ],
                [
                    'key' => 'floor_level',
                    'type' => 'single_choice',
                    'label' => 'Verdieping',
                    'options' => [
                        ['value' => 'ground'],
                        ['value' => '1'],
                        ['value' => 'attic'],
                    ],
                ],
            ],
        ]],
    ];

    $text = 'woonkamer op de begane grond en de slaapkamer op de eerste verdieping';
    $result = app(RequestPrefillOutcomeClassifier::class)->classifyCatalogOutput([
        'evidence' => $text,
        'fills' => [
            ['question_key' => 'room_type', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'value' => ['value' => 'living_room'], 'evidence' => 'woonkamer', 'provenance' => 'stated'],
            ['question_key' => 'floor_level', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'value' => ['value' => '1'], 'evidence' => 'eerste verdieping', 'provenance' => 'stated'],
            ['question_key' => 'room_type', 'section_instance_key' => 'room-2', 'confidence' => 'high', 'value' => ['value' => 'bedroom'], 'evidence' => 'slaapkamer', 'provenance' => 'stated'],
            ['question_key' => 'floor_level', 'section_instance_key' => 'room-2', 'confidence' => 'high', 'value' => ['value' => '1'], 'evidence' => 'eerste verdieping', 'provenance' => 'stated'],
        ],
    ], $catalog, [], $text);

    $floors = collect($result['candidates'])
        ->where('questionKey', 'floor_level')
        ->where('disposition', RequestPrefillCandidate::DISPOSITION_FILL)
        ->mapWithKeys(fn (RequestPrefillCandidate $c): array => [
            (string) $c->sectionInstanceKey => $c->value['value'] ?? null,
        ])
        ->all();

    expect($floors)->toBe([
        'room-1' => 'ground',
        'room-2' => '1',
    ])->and(collect($result['normalizations'])->pluck('rule')->all())
        ->toContain('floor_level_per_room_link');
});

test('catalogusclassifier vult geen begane grond zonder tekstbewijs', function () {
    $catalog = [
        'sections' => [[
            'key' => 'rooms',
            'is_repeatable' => true,
            'questions' => [
                [
                    'key' => 'room_type',
                    'type' => 'single_choice',
                    'label' => 'Type',
                    'options' => [
                        ['value' => 'bedroom'],
                    ],
                ],
                [
                    'key' => 'floor_level',
                    'type' => 'single_choice',
                    'label' => 'Verdieping',
                    'options' => [
                        ['value' => 'ground'],
                        ['value' => '1'],
                    ],
                ],
            ],
        ]],
    ];

    $text = 'De slaapkamer wordt te warm in de zomer.';
    $result = app(RequestPrefillOutcomeClassifier::class)->classifyCatalogOutput([
        'evidence' => $text,
        'fills' => [
            ['question_key' => 'room_type', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'value' => ['value' => 'bedroom'], 'evidence' => 'slaapkamer', 'provenance' => 'stated'],
            ['question_key' => 'floor_level', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'value' => ['value' => 'ground'], 'evidence' => 'aanname', 'provenance' => 'inferred'],
        ],
    ], $catalog, [], $text);

    $floor = collect($result['candidates'])->first(
        static fn (RequestPrefillCandidate $c): bool => $c->questionKey === 'floor_level',
    );

    expect($floor)->not->toBeNull()
        ->and($floor->disposition)->toBe(RequestPrefillCandidate::DISPOSITION_REJECTED);
});

test('request-prefill prompt vereist per-ruimte verdieping zonder begane-grond-default', function () {
    $repo = app(PromptVersionRepository::class);
    $prompt = $repo->body('request_prefill');
    $version = $repo->version('request_prefill');

    expect($version)->toBe('request-prefill-v13')
        ->and($prompt)->toContain('woonkamer op de begane grond en de slaapkamer op de eerste verdieping')
        ->and($prompt)->toContain('slaapkamer boven, woonkamer beneden')
        ->and($prompt)->toContain('stilzwijgend')
        ->and($prompt)->toContain('ground')
        ->and($prompt)->toContain('default');
});
