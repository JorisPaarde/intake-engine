<?php

declare(strict_types=1);

use App\Domains\AI\Services\AiBudgetGuard;
use App\Domains\AI\Services\DossierSynthesisJsonSchema;
use App\Domains\AI\Services\DossierSynthesisOutputNormalizer;
use App\Domains\AI\Services\DossierSynthesisPartialAcceptor;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoPlacementType;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    AiBudgetGuard::resetMissingRatesWarning();
});

/** @return array<string, mixed> */
function partialAcceptorInput(): array
{
    return [
        'rooms' => [[
            'reference' => 'room:12',
            'subject_reference' => 'subject:40',
        ]],
        'subjects' => [
            ['reference' => 'subject:40'],
            ['reference' => 'subject:41'],
            ['reference' => 'subject:42'],
        ],
        'placements' => [
            [
                'reference' => 'placement:81',
                'type' => AircoPlacementType::IndoorUnit->value,
                'subject_reference' => 'subject:40',
            ],
            [
                'reference' => 'placement:82',
                'type' => AircoPlacementType::OutdoorUnit->value,
                'subject_reference' => 'subject:41',
            ],
            [
                'reference' => 'placement:83',
                'type' => AircoPlacementType::PowerSource->value,
                'subject_reference' => 'subject:42',
            ],
            [
                'reference' => 'placement:84',
                'type' => AircoPlacementType::DrainPoint->value,
                'subject_reference' => 'subject:42',
            ],
        ],
        'image_manifest' => [
            ['reference' => 'dossier_image:101'],
            ['reference' => 'dossier_image:102'],
        ],
    ];
}

/** @return array<string, mixed> */
function validConnection(string $type, string $from, string $to, string $evidence): array
{
    return [
        'type' => $type,
        'label' => ucfirst($type).' route',
        'from_placement_reference' => $from,
        'to_placement_reference' => $to,
        'status' => 'proposed',
        'length_class' => 'short',
        'segments' => [],
        'obstacles' => [],
        'uncertainties' => [],
        'cost_impact' => 'low',
        'confidence' => 0.8,
        'evidence_references' => [$evidence],
    ];
}

test('prod run-336 two connections drops option but keeps placements (partial accept)', function () {
    // Prod intake 94 / ai_run ~336 (also staging intake 77): valid JSON with
    // option_proposals.0.connections = array(2). Hard min:3 used to reject the
    // entire synthesis; partial accept must keep placements and only drop the option.
    $output = [
        'summary' => 'Run-336: twee connections, geldige placements.',
        'placement_proposals' => [
            [
                'key' => 'proposal:indoor_woonkamer',
                'type' => AircoPlacementType::IndoorUnit->value,
                'label' => 'Binnenunit woonkamer',
                'description' => 'Vrije muur zichtbaar op kamerfoto.',
                'room_reference' => 'room:12',
                'subject_reference' => 'subject:40',
                'confidence' => 0.8,
                'evidence_references' => ['dossier_image:101'],
            ],
            [
                'key' => 'proposal:outdoor_gevel',
                'type' => AircoPlacementType::OutdoorUnit->value,
                'label' => 'Buitenunit gevel',
                'description' => 'Gevelruimte zichtbaar.',
                'room_reference' => null,
                'subject_reference' => 'subject:41',
                'confidence' => 0.75,
                'evidence_references' => ['dossier_image:102'],
            ],
        ],
        'option_proposals' => [[
            'label' => 'Single-split incompleet',
            'configuration_type' => AircoConfigurationType::SingleSplit->value,
            'summary' => 'Koel en condens aanwezig; stroom ontbreekt (array van 2).',
            'cost_impact' => 'medium',
            'confidence' => 0.7,
            'placement_references' => ['proposal:indoor_woonkamer', 'proposal:outdoor_gevel', 'placement:83', 'placement:84'],
            'connections' => [
                validConnection('refrigerant', 'proposal:indoor_woonkamer', 'proposal:outdoor_gevel', 'dossier_image:101'),
                validConnection('condensate', 'proposal:indoor_woonkamer', 'placement:84', 'dossier_image:101'),
                // power missing → type set incomplete (got: array(2))
            ],
        ]],
        'exceptions' => [],
        'customer_tasks' => [],
    ];

    $result = app(DossierSynthesisPartialAcceptor::class)->accept($output, partialAcceptorInput());

    expect($result['has_accepted_proposals'])->toBeTrue()
        ->and($result['had_rejections'])->toBeTrue()
        ->and($result['accepted']['placement_proposals'])->toHaveCount(2)
        ->and($result['accepted']['option_proposals'])->toHaveCount(0)
        ->and($result['validation_errors'])->toHaveKey('option_proposals.0')
        ->and($result['validation_errors']['option_proposals.0'][0] ?? '')
        ->toContain('koel-, condens- en stroomverbindingen')
        ->and($result['summary_message'] ?? '')->not->toContain('must have at least 3 items');
});

test('partial acceptor keeps valid placements when option has only two connections (staging gemini error)', function () {
    $output = [
        'summary' => 'Gedeeltelijk geldig voorstel.',
        'placement_proposals' => [[
            'key' => 'proposal:indoor_extra',
            'type' => AircoPlacementType::IndoorUnit->value,
            'label' => 'Extra binnenpositie',
            'description' => 'Zichtbaar op foto.',
            'room_reference' => 'room:12',
            'subject_reference' => 'subject:40',
            'confidence' => 0.7,
            'evidence_references' => ['dossier_image:101'],
        ]],
        'option_proposals' => [[
            'label' => 'Kapotte optie',
            'configuration_type' => AircoConfigurationType::SingleSplit->value,
            'summary' => 'Mist één verbinding.',
            'cost_impact' => 'medium',
            'confidence' => 0.7,
            'placement_references' => ['placement:81', 'placement:82'],
            'connections' => [
                validConnection('refrigerant', 'placement:81', 'placement:82', 'dossier_image:101'),
                validConnection('condensate', 'placement:81', 'placement:84', 'dossier_image:101'),
                // power missing → min 3 fails / type set incomplete
            ],
        ]],
        'exceptions' => [],
        'customer_tasks' => [],
    ];

    $result = app(DossierSynthesisPartialAcceptor::class)->accept($output, partialAcceptorInput());

    expect($result['has_accepted_proposals'])->toBeTrue()
        ->and($result['had_rejections'])->toBeTrue()
        ->and($result['accepted']['placement_proposals'])->toHaveCount(1)
        ->and($result['accepted']['option_proposals'])->toHaveCount(0)
        ->and($result['validation_errors'])->toHaveKey('option_proposals.0');
});

test('partial acceptor drops option with room:ID connection refs and short placement_references (staging gemini error)', function () {
    $output = [
        'summary' => 'Optie met foute refs.',
        'placement_proposals' => [],
        'option_proposals' => [[
            'label' => 'Foute refs',
            'configuration_type' => AircoConfigurationType::SingleSplit->value,
            'summary' => 'to_placement_reference = room:81',
            'cost_impact' => 'medium',
            'confidence' => 0.6,
            'placement_references' => ['placement:81'], // min 2 missing
            'connections' => [
                [
                    'type' => 'refrigerant',
                    'label' => 'Koel',
                    'from_placement_reference' => 'placement:81',
                    'to_placement_reference' => 'room:81',
                    'status' => 'proposed',
                    'length_class' => 'short',
                    'segments' => [],
                    'obstacles' => [],
                    'uncertainties' => [],
                    'cost_impact' => 'low',
                    'confidence' => 0.5,
                    'evidence_references' => ['dossier_image:101'],
                ],
            ],
        ]],
        'exceptions' => [[
            'code' => 'route_unclear',
            'label' => 'Route nog onduidelijk.',
            'decision_area_key' => 'refrigerant',
            'confidence' => 'medium',
            'evidence_references' => ['dossier_image:101'],
        ]],
        'customer_tasks' => [],
    ];

    $result = app(DossierSynthesisPartialAcceptor::class)->accept($output, partialAcceptorInput());

    expect($result['has_accepted_proposals'])->toBeFalse()
        ->and($result['accepted']['option_proposals'])->toBe([])
        ->and($result['accepted']['exceptions'])->toBe([])
        ->and($result['validation_errors']['option_proposals.0'][0] ?? '')->toContain('placement_references');
});

test('partial acceptor drops invalid placement subject_reference but keeps valid option (gpt-4o-mini prod errors)', function () {
    $output = [
        'summary' => 'Eén geldige optie, één kapotte positie.',
        'placement_proposals' => [[
            'key' => 'proposal:broken',
            'type' => AircoPlacementType::OutdoorUnit->value,
            'label' => 'Kapot',
            'description' => 'Mist subject.',
            'room_reference' => null,
            'subject_reference' => null,
            'confidence' => 0.5,
            'evidence_references' => ['dossier_image:101'],
        ]],
        'option_proposals' => [[
            'label' => 'Geldige single-split',
            'configuration_type' => AircoConfigurationType::SingleSplit->value,
            'summary' => 'Bestaande posities.',
            'cost_impact' => 'medium',
            'confidence' => 0.88,
            'placement_references' => ['placement:81', 'placement:82', 'placement:83', 'placement:84'],
            'connections' => [
                validConnection('refrigerant', 'placement:81', 'placement:82', 'dossier_image:101'),
                validConnection('condensate', 'placement:81', 'placement:84', 'dossier_image:102'),
                validConnection('power', 'placement:83', 'placement:82', 'dossier_image:101'),
            ],
        ]],
        'exceptions' => [],
        'customer_tasks' => [],
    ];

    // Second option-like error: empty evidence on a connection → whole option dropped when alone.
    $brokenOptionOnly = $output;
    $brokenOptionOnly['option_proposals'][0]['connections'][0]['evidence_references'] = [];
    $brokenOnly = app(DossierSynthesisPartialAcceptor::class)->accept($brokenOptionOnly, partialAcceptorInput());
    expect($brokenOnly['accepted']['option_proposals'])->toBe([])
        ->and($brokenOnly['has_accepted_proposals'])->toBeFalse();

    $result = app(DossierSynthesisPartialAcceptor::class)->accept($output, partialAcceptorInput());

    expect($result['has_accepted_proposals'])->toBeTrue()
        ->and($result['had_rejections'])->toBeTrue()
        ->and($result['accepted']['placement_proposals'])->toBe([])
        ->and($result['accepted']['option_proposals'])->toHaveCount(1)
        ->and($result['validation_errors'])->toHaveKey('placement_proposals.0');
});

test('partial acceptor remaps unambiguous subject refs to placements (prod run-243)', function () {
    $input = partialAcceptorInput();
    // Mimic prod: placement:298 lives under subject:298.
    $input['placements'][] = [
        'reference' => 'placement:298',
        'type' => AircoPlacementType::IndoorUnit->value,
        'subject_reference' => 'subject:298',
    ];
    $input['subjects'][] = ['reference' => 'subject:298'];
    $input['rooms'][] = [
        'reference' => 'room:98',
        'subject_reference' => 'subject:298',
    ];

    $output = [
        'summary' => 'Model gebruikte subject:298 waar placement:298 hoorde.',
        'placement_proposals' => [],
        'option_proposals' => [[
            'label' => 'Single-split met subject-refs',
            'configuration_type' => AircoConfigurationType::SingleSplit->value,
            'summary' => 'Binnen- en buitenpositie met koel-, condens- en stroomverbinding.',
            'cost_impact' => 'medium',
            'confidence' => 0.7,
            // Prod quirk: subject:298 i.p.v. placement:298; overige refs correct.
            'placement_references' => ['subject:298', 'placement:82', 'placement:83', 'placement:84'],
            'connections' => [
                validConnection('refrigerant', 'subject:298', 'placement:82', 'dossier_image:101'),
                validConnection('condensate', 'subject:298', 'placement:84', 'dossier_image:101'),
                validConnection('power', 'placement:83', 'placement:82', 'dossier_image:102'),
            ],
        ]],
        'exceptions' => [],
        'customer_tasks' => [],
    ];

    $result = app(DossierSynthesisPartialAcceptor::class)->accept($output, $input);

    expect($result['has_accepted_proposals'])->toBeTrue()
        ->and($result['had_rejections'])->toBeFalse()
        ->and($result['accepted']['option_proposals'])->toHaveCount(1)
        ->and($result['accepted']['option_proposals'][0]['placement_references'])
        ->toBe(['placement:298', 'placement:82', 'placement:83', 'placement:84'])
        ->and($result['accepted']['option_proposals'][0]['connections'][0]['from_placement_reference'])->toBe('placement:298');
});

test('exact prod run-243 response shape is dropped per proposal (not whole synthesis)', function () {
    // Exact ai_runs error string from google/gemini-3.1-flash-lite via OpenRouter:
    // option_proposals.0.placement_references: must have at least 2 items [got array(1)]
    // | option_proposals.0.connections: must have at least 3 items [got array(1)]
    // | option_proposals.0.connections.0.to_placement_reference: format is invalid [got: subject:298]
    $input = partialAcceptorInput();
    $input['placements'][] = [
        'reference' => 'placement:298',
        'type' => AircoPlacementType::IndoorUnit->value,
        'subject_reference' => 'subject:298',
    ];
    $input['subjects'][] = ['reference' => 'subject:298'];
    $input['rooms'][] = [
        'reference' => 'room:98',
        'subject_reference' => 'subject:298',
    ];

    $run243Shape = [
        'summary' => 'Prod run-243 exacte foutvorm.',
        'placement_proposals' => [[
            'key' => 'proposal:indoor_extra',
            'type' => AircoPlacementType::IndoorUnit->value,
            'label' => 'Extra binnenpositie',
            'description' => 'Zichtbaar op foto.',
            'room_reference' => 'room:12',
            'subject_reference' => 'subject:40',
            'confidence' => 0.7,
            'evidence_references' => ['dossier_image:101'],
        ]],
        'option_proposals' => [[
            'label' => 'Incomplete gemini-optie',
            'configuration_type' => AircoConfigurationType::SingleSplit->value,
            'summary' => 'Eén placement-ref en één connection met subject:298 als to.',
            'cost_impact' => 'medium',
            'confidence' => 0.55,
            'placement_references' => ['placement:81'], // array(1) — min 2 ontbreekt
            'connections' => [[
                'type' => 'refrigerant',
                'label' => 'Koel',
                'from_placement_reference' => 'placement:81',
                'to_placement_reference' => 'subject:298', // exact prod invalid format
                'status' => 'proposed',
                'length_class' => 'short',
                'segments' => [],
                'obstacles' => [],
                'uncertainties' => [],
                'cost_impact' => 'low',
                'confidence' => 0.5,
                'evidence_references' => ['dossier_image:101'],
            ]], // array(1) — min 3 ontbreekt
        ]],
        'exceptions' => [],
        'customer_tasks' => [],
    ];

    $result = app(DossierSynthesisPartialAcceptor::class)->accept($run243Shape, $input);

    // subject:298 remaps to placement:298 on the single connection, but cardinality
    // still fails → option dropped; valid placement proposal remains (partial).
    expect($result['has_accepted_proposals'])->toBeTrue()
        ->and($result['had_rejections'])->toBeTrue()
        ->and($result['accepted']['placement_proposals'])->toHaveCount(1)
        ->and($result['accepted']['option_proposals'])->toBe([])
        ->and($result['validation_errors'])->toHaveKey('option_proposals.0')
        ->and($result['validation_errors']['option_proposals.0'][0] ?? '')->toContain('placement_references');
});

test('ambiguous subject refs drop only that connection then fail option on cardinality', function () {
    $input = partialAcceptorInput();
    // Two placements under subject:40 → remap is ambiguous.
    $input['placements'][1]['subject_reference'] = 'subject:40';

    $output = [
        'summary' => 'Ambiguous subject-ref op één connection.',
        'placement_proposals' => [],
        'option_proposals' => [[
            'label' => 'Anders geldige optie',
            'configuration_type' => AircoConfigurationType::SingleSplit->value,
            'summary' => 'Drie connections waarvan één subject:40.',
            'cost_impact' => 'medium',
            'confidence' => 0.8,
            'placement_references' => ['placement:81', 'placement:82', 'placement:83', 'placement:84'],
            'connections' => [
                validConnection('refrigerant', 'placement:81', 'placement:82', 'dossier_image:101'),
                validConnection('condensate', 'placement:81', 'placement:84', 'dossier_image:101'),
                validConnection('power', 'placement:83', 'subject:40', 'dossier_image:102'),
            ],
        ]],
        'exceptions' => [],
        'customer_tasks' => [],
    ];

    $result = app(DossierSynthesisPartialAcceptor::class)->accept($output, $input);

    expect($result['has_accepted_proposals'])->toBeFalse()
        ->and($result['accepted']['option_proposals'])->toBe([])
        ->and($result['validation_errors']['option_proposals.0'][0] ?? '')->toContain('koel-, condens- en stroomverbindingen')
        ->and($result['validation_errors']['option_proposals.0'][0] ?? '')->toContain('subject-ref');
});

test('partial acceptor drops run-243 option that still has too few placements or connections after remap', function () {
    $input = partialAcceptorInput();
    $input['placements'][] = [
        'reference' => 'placement:298',
        'type' => AircoPlacementType::IndoorUnit->value,
        'subject_reference' => 'subject:298',
    ];
    $input['subjects'][] = ['reference' => 'subject:298'];

    $tooFew = [
        'summary' => 'Te weinig placements en connections (prod run-243).',
        'placement_proposals' => [],
        'option_proposals' => [[
            'label' => 'Incomplete optie',
            'configuration_type' => AircoConfigurationType::SingleSplit->value,
            'summary' => 'Alleen één subject-ref en twee connections.',
            'cost_impact' => 'medium',
            'confidence' => 0.6,
            'placement_references' => ['subject:298'], // min 2 ontbreekt ook na remap
            'connections' => [
                validConnection('refrigerant', 'subject:298', 'placement:82', 'dossier_image:101'),
                validConnection('condensate', 'subject:298', 'placement:84', 'dossier_image:101'),
                // power missing
            ],
        ]],
        'exceptions' => [],
        'customer_tasks' => [],
    ];

    $result = app(DossierSynthesisPartialAcceptor::class)->accept($tooFew, $input);

    expect($result['has_accepted_proposals'])->toBeFalse()
        ->and($result['had_rejections'])->toBeTrue()
        ->and($result['accepted']['option_proposals'])->toBe([])
        ->and($result['validation_errors'])->toHaveKey('option_proposals.0')
        ->and($result['validation_errors']['option_proposals.0'][0] ?? '')->toContain('placement_references');
});

test('staging intake 76 shape: room-ref + short cardinality keeps placements, drops option', function () {
    // Exact staging errors: placement_references min 2, connections min 3, to=room:81
    $output = [
        'summary' => '3-fase aansluiting met vrije groepen lijkt mogelijk.',
        'placement_proposals' => [[
            'key' => 'proposal:indoor_extra',
            'type' => AircoPlacementType::IndoorUnit->value,
            'label' => 'Extra binnenpositie',
            'description' => 'Zichtbaar op kamerfoto.',
            'room_reference' => 'room:12',
            'subject_reference' => 'subject:40',
            'confidence' => 0.9,
            'evidence_references' => ['dossier_image:101'],
        ]],
        'option_proposals' => [[
            'label' => 'Incomplete optie intake 76',
            'configuration_type' => AircoConfigurationType::SingleSplit->value,
            'summary' => '3-fase aansluiting met vrije groepen.',
            'cost_impact' => 'medium',
            'confidence' => 0.7,
            'placement_references' => ['placement:81'],
            'connections' => [[
                'type' => 'refrigerant',
                'label' => 'Koel',
                'from_placement_reference' => 'placement:81',
                'to_placement_reference' => 'room:81',
                'status' => 'proposed',
                'length_class' => 'short',
                'segments' => [],
                'obstacles' => [],
                'uncertainties' => [],
                'cost_impact' => 'low',
                'confidence' => 0.5,
                'evidence_references' => ['dossier_image:101'],
            ]],
        ]],
        'exceptions' => [],
        'customer_tasks' => [],
    ];

    $input = partialAcceptorInput();
    $input['synthesis_policy'] = ['free_group' => 'no', 'subjects_with_room_photo' => []];
    $input['image_manifest'] = [
        ['reference' => 'dossier_image:101', 'evidence_eligible' => true],
        ['reference' => 'dossier_image:102', 'evidence_eligible' => true],
    ];

    $result = app(DossierSynthesisPartialAcceptor::class)->accept($output, $input);

    expect($result['has_accepted_proposals'])->toBeTrue()
        ->and($result['had_rejections'])->toBeTrue()
        ->and($result['accepted']['placement_proposals'])->toHaveCount(1)
        ->and($result['accepted']['option_proposals'])->toBe([])
        ->and($result['accepted']['summary'])->not->toContain('vrije groep')
        ->and($result['validation_errors'])->toHaveKey('option_proposals.0');
});

test('staging intake 76: wrong-subject outdoor photo cannot be used as evidence', function () {
    $input = partialAcceptorInput();
    $input['image_manifest'] = [
        ['reference' => 'dossier_image:101', 'evidence_eligible' => true],
        // Fusebox slot filled with outdoor unit photo (wrong_subject).
        ['reference' => 'dossier_image:199', 'evidence_eligible' => false, 'question_key' => 'fusebox_photo'],
    ];
    $input['synthesis_policy'] = ['free_group' => 'no', 'subjects_with_room_photo' => []];

    $output = [
        'summary' => 'Technische voorzet op basis van beschikbare foto’s.',
        'placement_proposals' => [[
            'key' => 'proposal:power_claim',
            'type' => AircoPlacementType::PowerSource->value,
            'label' => 'Meterkastpositie',
            'description' => 'Afgelezen vanaf de geüploade meterkastfoto.',
            'room_reference' => null,
            'subject_reference' => 'subject:42',
            'confidence' => 0.9,
            'evidence_references' => ['dossier_image:199'],
        ]],
        'option_proposals' => [],
        'exceptions' => [],
        'customer_tasks' => [],
    ];

    $result = app(DossierSynthesisPartialAcceptor::class)->accept($output, $input);

    expect($result['has_accepted_proposals'])->toBeFalse()
        ->and($result['accepted']['placement_proposals'])->toBe([])
        ->and($result['validation_errors']['placement_proposals.0'][0] ?? '')->toContain('wrong-subject');
});

test('staging intake 77 shape: two connections drops option; wall-photo task skipped when already uploaded', function () {
    $input = partialAcceptorInput();
    $input['image_manifest'] = [
        ['reference' => 'dossier_image:101', 'evidence_eligible' => true],
        ['reference' => 'dossier_image:102', 'evidence_eligible' => true],
    ];
    $input['synthesis_policy'] = [
        'free_group' => null,
        'subjects_with_room_photo' => ['subject:40'],
    ];

    $output = [
        'summary' => 'Single-split met gedeeltelijke verbindingen.',
        'placement_proposals' => [[
            'key' => 'proposal:indoor_extra',
            'type' => AircoPlacementType::IndoorUnit->value,
            'label' => 'Extra binnenpositie',
            'description' => 'Zichtbaar op foto.',
            'room_reference' => 'room:12',
            'subject_reference' => 'subject:40',
            'confidence' => 0.7,
            'evidence_references' => ['dossier_image:101'],
        ]],
        'option_proposals' => [[
            'label' => 'Twee connections intake 77',
            'configuration_type' => AircoConfigurationType::SingleSplit->value,
            'summary' => 'Mist power-connection.',
            'cost_impact' => 'medium',
            'confidence' => 0.6,
            'placement_references' => ['placement:81', 'placement:82'],
            'connections' => [
                validConnection('refrigerant', 'placement:81', 'placement:82', 'dossier_image:101'),
                validConnection('condensate', 'placement:81', 'placement:84', 'dossier_image:101'),
                // power missing → min 3 / type set incomplete
            ],
        ]],
        'exceptions' => [],
        'customer_tasks' => [[
            'type' => 'photo',
            'prompt' => 'Maak een scherpe foto van de muur waar de binnenunit moet komen.',
            'decision_area_key' => 'placement',
            'subject_reference' => 'subject:40',
            'reason' => 'Wandfoto ontbreekt nog.',
            'evidence_references' => [],
        ]],
    ];

    $result = app(DossierSynthesisPartialAcceptor::class)->accept($output, $input);

    expect($result['has_accepted_proposals'])->toBeTrue()
        ->and($result['accepted']['placement_proposals'])->toHaveCount(1)
        ->and($result['accepted']['option_proposals'])->toBe([])
        ->and($result['accepted']['customer_tasks'])->toBe([])
        ->and($result['validation_errors']['customer_tasks.0'][0] ?? '')->toContain('al een bruikbare muur');
});

test('dossier synthesis json schema encodes enums and required reference shapes', function () {
    $schema = app(DossierSynthesisJsonSchema::class)->schema();
    $format = app(DossierSynthesisJsonSchema::class)->responseFormat();

    expect($format['type'])->toBe('json_schema')
        ->and($format['json_schema']['strict'])->toBeTrue()
        ->and($format['json_schema']['name'])->toBe('dossier_synthesis')
        ->and($schema['required'])->toContain('option_proposals')
        ->and($schema['properties']['option_proposals']['items']['properties']['connections'])->toHaveKey('items')
        ->and($schema['properties']['option_proposals']['items']['properties']['connections'])->not->toHaveKey('minItems')
        ->and($schema['properties']['option_proposals']['items']['properties']['placement_references'])->not->toHaveKey('minItems')
        ->and($schema['properties']['option_proposals']['items']['properties']['connections']['items']['properties']['evidence_references'])->not->toHaveKey('minItems')
        ->and($schema['properties']['option_proposals']['items']['properties']['connections']['items']['properties']['from_placement_reference'])->not->toHaveKey('pattern')
        ->and($schema['properties']['placement_proposals']['items']['properties']['type']['enum'])
        ->toContain(AircoPlacementType::IndoorUnit->value)
        ->and(app(DossierSynthesisJsonSchema::class)->unsupportedKeywordsIn($schema))->toBe([]);
});

test('budget guard books fractional cents when rates are configured', function () {
    config([
        'ai.budget.reserve_cents_per_call' => 1,
        'ai.budget.input_cents_per_1k_tokens' => 0.1,
        'ai.budget.output_cents_per_1k_tokens' => 0.2,
        'ai.budget.image_cents_per_image' => 0.05,
    ]);

    $guard = app(AiBudgetGuard::class);
    // 1000*0.1/1000 + 500*0.2/1000 + 2*0.05 = 0.1 + 0.1 + 0.1 = 0.3
    $cost = $guard->estimateCostCents(1000, 500, 2);

    expect($cost)->toBeGreaterThan(0.299)
        ->and($cost)->toBeLessThan(0.301)
        ->and($guard->toMicrocents(0.3))->toBe(3000)
        ->and($guard->ceilCents(0.3))->toBe(1)
        ->and($guard->toMicrocentsFromCurrency('0.000123456789'))->toBe(123)
        ->and($guard->toMicrocentsFromCurrency('0.01'))->toBe(10_000);
});

test('budget guard falls back to reserve and warns once when rates are empty', function () {
    config([
        'ai.budget.reserve_cents_per_call' => 1,
        'ai.budget.input_cents_per_1k_tokens' => 0,
        'ai.budget.output_cents_per_1k_tokens' => 0,
        'ai.budget.image_cents_per_image' => 0,
    ]);

    Log::spy();
    $guard = app(AiBudgetGuard::class);

    expect($guard->estimateCostCents(1000, 500, 8))->toEqual(1.0);
    expect($guard->estimateCostCents(10, 10, 1))->toEqual(1.0);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'AI budget rates empty'));
});

test('null segments/obstacles/uncertainties default to empty lists before option validation', function () {
    $output = [
        'summary' => 'Optie met null-lijsten.',
        'placement_proposals' => [],
        'option_proposals' => [[
            'label' => 'Single-split',
            'configuration_type' => AircoConfigurationType::SingleSplit->value,
            'summary' => 'Volledige verbindingen.',
            'cost_impact' => 'medium',
            'confidence' => 0.7,
            'placement_references' => ['placement:81', 'placement:82', 'placement:83', 'placement:84'],
            'connections' => [
                array_merge(validConnection('refrigerant', 'placement:81', 'placement:82', 'dossier_image:101'), [
                    'segments' => null,
                    'obstacles' => null,
                    'uncertainties' => null,
                ]),
                validConnection('condensate', 'placement:81', 'placement:84', 'dossier_image:101'),
                validConnection('power', 'placement:83', 'placement:82', 'dossier_image:102'),
            ],
        ]],
        'exceptions' => [],
        'customer_tasks' => [],
    ];

    $normalized = app(DossierSynthesisOutputNormalizer::class)->normalize($output);
    $result = app(DossierSynthesisPartialAcceptor::class)->accept($normalized, partialAcceptorInput());

    expect($result['has_accepted_proposals'])->toBeTrue()
        ->and($result['accepted']['option_proposals'])->toHaveCount(1)
        ->and($normalized['option_proposals'][0]['connections'][0]['segments'])->toBe([])
        ->and($normalized['option_proposals'][0]['connections'][0]['obstacles'])->toBe([])
        ->and($normalized['option_proposals'][0]['connections'][0]['uncertainties'])->toBe([]);
});

test('stale placement ids in connections remap onto matching proposal keys by subject', function () {
    $output = [
        'summary' => 'Refresh met nieuwe proposals en oude connection-ids.',
        'placement_proposals' => [
            [
                'key' => 'proposal:indoor_woonkamer',
                'type' => AircoPlacementType::IndoorUnit->value,
                'label' => 'Binnenunit woonkamer',
                'description' => 'Vrije muur.',
                'room_reference' => 'room:12',
                'subject_reference' => 'subject:40',
                'confidence' => 0.8,
                'evidence_references' => ['dossier_image:101'],
            ],
            [
                'key' => 'proposal:outdoor_gevel',
                'type' => AircoPlacementType::OutdoorUnit->value,
                'label' => 'Buitenunit gevel',
                'description' => 'Gevelruimte.',
                'room_reference' => null,
                'subject_reference' => 'subject:41',
                'confidence' => 0.75,
                'evidence_references' => ['dossier_image:102'],
            ],
        ],
        'option_proposals' => [[
            'label' => 'Multi met stale refs',
            'configuration_type' => AircoConfigurationType::SingleSplit->value,
            'summary' => 'Connections wijzen nog naar placement:81/82.',
            'cost_impact' => 'medium',
            'confidence' => 0.7,
            'placement_references' => [
                'proposal:indoor_woonkamer',
                'proposal:outdoor_gevel',
                'placement:83',
                'placement:84',
            ],
            // Stale: placement:81/82 are the prior indoor/outdoor for the same subjects.
            'connections' => [
                validConnection('refrigerant', 'placement:81', 'placement:82', 'dossier_image:101'),
                validConnection('condensate', 'placement:81', 'placement:84', 'dossier_image:101'),
                validConnection('power', 'placement:83', 'placement:82', 'dossier_image:102'),
            ],
        ]],
        'exceptions' => [],
        'customer_tasks' => [],
    ];

    $result = app(DossierSynthesisPartialAcceptor::class)->accept($output, partialAcceptorInput());

    expect($result['has_accepted_proposals'])->toBeTrue()
        ->and($result['accepted']['option_proposals'])->toHaveCount(1)
        ->and($result['accepted']['option_proposals'][0]['connections'][0]['from_placement_reference'])
        ->toBe('proposal:indoor_woonkamer')
        ->and($result['accepted']['option_proposals'][0]['connections'][0]['to_placement_reference'])
        ->toBe('proposal:outdoor_gevel');
});

test('summary strips invented customer wishes and hedges overstated phase facts', function () {
    $output = [
        'summary' => 'De klant wenst een multi-split systeem. 3-fase aanwezig in de meterkast.',
        'placement_proposals' => [],
        'option_proposals' => [[
            'label' => 'Single-split',
            'configuration_type' => AircoConfigurationType::SingleSplit->value,
            'summary' => 'Technische kandidaat.',
            'cost_impact' => 'medium',
            'confidence' => 0.7,
            'placement_references' => ['placement:81', 'placement:82', 'placement:83', 'placement:84'],
            'connections' => [
                validConnection('refrigerant', 'placement:81', 'placement:82', 'dossier_image:101'),
                validConnection('condensate', 'placement:81', 'placement:84', 'dossier_image:101'),
                validConnection('power', 'placement:83', 'placement:82', 'dossier_image:102'),
            ],
        ]],
        'exceptions' => [],
        'customer_tasks' => [],
    ];

    $input = partialAcceptorInput();
    $input['synthesis_policy'] = ['free_group' => 'yes', 'subjects_with_room_photo' => []];

    $result = app(DossierSynthesisPartialAcceptor::class)->accept($output, $input);

    expect($result['has_accepted_proposals'])->toBeTrue()
        ->and($result['accepted']['summary'])->not->toContain('De klant wenst')
        ->and($result['accepted']['summary'])->not->toMatch('/3[\s-]?fase aanwezig/i')
        ->and($result['had_rejections'])->toBeTrue();
});

/** @return array<string, mixed> */
function intake84StyleAcceptorInput(): array
{
    return [
        'subjects' => [
            [
                'reference' => 'subject:240',
                'type' => 'survey',
                'parent_reference' => null,
                'usable_as_proposal_parent' => true,
            ],
            [
                'reference' => 'subject:241',
                'type' => 'airco_room',
                'parent_reference' => 'subject:240',
                'usable_as_proposal_parent' => true,
            ],
            [
                'reference' => 'subject:242',
                'type' => 'airco_room',
                'parent_reference' => 'subject:240',
                'usable_as_proposal_parent' => true,
            ],
            [
                'reference' => 'subject:243',
                'type' => 'airco_placement',
                'parent_reference' => 'subject:241',
                'usable_as_proposal_parent' => false,
            ],
            [
                'reference' => 'subject:244',
                'type' => 'airco_placement',
                'parent_reference' => 'subject:242',
                'usable_as_proposal_parent' => false,
            ],
            [
                'reference' => 'subject:245',
                'type' => 'airco_placement',
                'parent_reference' => 'subject:240',
                'usable_as_proposal_parent' => false,
            ],
        ],
        'rooms' => [
            [
                'reference' => 'room:91',
                'subject_reference' => 'subject:241',
                'name' => 'Woonkamer',
            ],
            [
                'reference' => 'room:92',
                'subject_reference' => 'subject:242',
                'name' => 'Slaapkamer',
            ],
        ],
        'placements' => [
            [
                'reference' => 'placement:83',
                'type' => AircoPlacementType::PowerSource->value,
                'subject_reference' => 'subject:240',
            ],
        ],
        'image_manifest' => [
            [
                'reference' => 'dossier_image:210',
                'content_assessment' => ['evidence' => 'Kamerwand zichtbaar'],
                'evidence_eligible' => true,
            ],
            [
                'reference' => 'dossier_image:211',
                'content_assessment' => ['evidence' => 'Kamerwand zichtbaar'],
                'evidence_eligible' => true,
            ],
            [
                'reference' => 'dossier_image:212',
                'content_assessment' => ['evidence' => '3-fase lijkt zichtbaar'],
                'evidence_eligible' => true,
            ],
        ],
        'legacy_evidence' => [
            'external_fact_context' => [[
                'reference' => 'external_fact:fusebox',
                'display' => 'Meterkastbeoordeling: 3-fase lijkt zichtbaar',
                'confidence' => 'medium',
            ]],
        ],
        'synthesis_policy' => [
            'free_group' => 'unknown',
            'subjects_with_room_photo' => ['subject:241', 'subject:242'],
        ],
    ];
}

test('run-282 fixture: missing per-indoor connections are filled instead of rejecting the option', function () {
    $fixture = json_decode(
        (string) file_get_contents(base_path('tests/fixtures/dossier-synthesis/run-282-incomplete-indoor-connections.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    unset($fixture['_comment']);

    $result = app(DossierSynthesisPartialAcceptor::class)->accept($fixture, intake84StyleAcceptorInput());

    expect($result['has_accepted_proposals'])->toBeTrue()
        ->and($result['accepted']['placement_proposals'])->toHaveCount(3)
        ->and($result['accepted']['option_proposals'])->toHaveCount(1);

    $option = $result['accepted']['option_proposals'][0];
    $indoorRefs = ['proposal:indoor_woonkamer', 'proposal:indoor_slaapkamer'];
    foreach (['refrigerant', 'condensate'] as $type) {
        foreach ($indoorRefs as $indoorRef) {
            $covered = collect($option['connections'])->contains(
                fn (array $connection): bool => ($connection['type'] ?? null) === $type
                    && in_array($indoorRef, [
                        $connection['from_placement_reference'] ?? null,
                        $connection['to_placement_reference'] ?? null,
                    ], true),
            );
            expect($covered)->toBeTrue("Missing {$type} for {$indoorRef}");
        }
    }

    $power = collect($option['connections'])->firstWhere('type', 'power');
    expect($power)->not->toBeNull()
        ->and($power['from_placement_reference'])->not->toBe($power['to_placement_reference'])
        ->and($power['from_placement_reference'])->toBe('placement:83');
});

test('intake-85 fixture: outdoor→outdoor power without power_source is kept as needs_evidence', function () {
    $fixture = json_decode(
        (string) file_get_contents(base_path('tests/fixtures/dossier-synthesis/intake-85-outdoor-outdoor-power-no-power-source.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    unset($fixture['_comment']);

    // Same subject/room graph as intake 84, but no power_source in existing placements
    // and none in the option placement_references (staging intake 85 shape).
    $input = intake84StyleAcceptorInput();
    $input['placements'] = [];

    $result = app(DossierSynthesisPartialAcceptor::class)->accept($fixture, $input);

    expect($result['has_accepted_proposals'])->toBeTrue()
        ->and($result['accepted']['placement_proposals'])->toHaveCount(3)
        ->and($result['accepted']['option_proposals'])->toHaveCount(1)
        ->and($result['validation_errors'])->not->toHaveKey('option_proposals.0');

    $option = $result['accepted']['option_proposals'][0];
    $types = collect($option['connections'])->pluck('type');
    expect($types)->toContain('refrigerant')
        ->and($types)->toContain('condensate')
        ->and($types)->toContain('power')
        ->and($types->filter(fn ($type) => $type === 'refrigerant')->count())->toBe(2)
        ->and($types->filter(fn ($type) => $type === 'condensate')->count())->toBe(2);

    $power = collect($option['connections'])->firstWhere('type', 'power');
    expect($power)->not->toBeNull()
        ->and($power['from_placement_reference'])->toBeNull()
        ->and($power['to_placement_reference'])->toBe('proposal:outdoor_tuin')
        ->and($power['status'])->toBe('needs_evidence')
        ->and($power['uncertainties'])->toContain('Stroomroute nog te bepalen');
});

test('run-283 fixture: airco_placement subject refs remap to room/survey parents', function () {
    $fixture = json_decode(
        (string) file_get_contents(base_path('tests/fixtures/dossier-synthesis/run-283-placement-subject-refs.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    unset($fixture['_comment']);

    $result = app(DossierSynthesisPartialAcceptor::class)->accept($fixture, intake84StyleAcceptorInput());

    expect($result['has_accepted_proposals'])->toBeTrue()
        ->and($result['accepted']['placement_proposals'])->toHaveCount(3)
        ->and($result['accepted']['option_proposals'])->toHaveCount(1)
        ->and($result['validation_errors'])->not->toHaveKey('placement_proposals.0')
        ->and($result['validation_errors'])->not->toHaveKey('placement_proposals.1')
        ->and($result['validation_errors'])->not->toHaveKey('placement_proposals.2');

    $byKey = collect($result['accepted']['placement_proposals'])->keyBy('key');
    expect($byKey['proposal:indoor_woonkamer']['subject_reference'])->toBe('subject:241')
        ->and($byKey['proposal:indoor_slaapkamer']['subject_reference'])->toBe('subject:242')
        ->and($byKey['proposal:outdoor_achtertuin']['subject_reference'])->toBe('subject:240');
});

test('hedged source observation caps exception confidence and wording', function () {
    $hedge = json_decode(
        (string) file_get_contents(base_path('tests/fixtures/dossier-synthesis/hedged-phase-observation.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $output = [
        'summary' => $hedge['derived_summary'],
        'placement_proposals' => [[
            'key' => 'proposal:outdoor_achtertuin',
            'type' => AircoPlacementType::OutdoorUnit->value,
            'label' => 'Buitenunit achtertuin',
            'description' => 'Zichtbaar.',
            'room_reference' => null,
            'subject_reference' => 'subject:240',
            'confidence' => 0.7,
            'evidence_references' => ['dossier_image:212'],
        ]],
        'option_proposals' => [],
        'exceptions' => [$hedge['derived_exception']],
        'customer_tasks' => [],
    ];

    $input = intake84StyleAcceptorInput();
    // Without placements for a valid option, exceptions alone are dropped unless
    // we keep a placement — has_accepted_proposals requires placements or options.
    $result = app(DossierSynthesisPartialAcceptor::class)->accept($output, $input);

    expect($result['has_accepted_proposals'])->toBeTrue()
        ->and($result['accepted']['summary'])->not->toMatch('/3[\s-]?fase aanwezig/i')
        ->and($result['accepted']['summary'])->toMatch('/lijkt|te controleren/i')
        ->and($result['accepted']['exceptions'])->toHaveCount(1)
        ->and($result['accepted']['exceptions'][0]['confidence'])->not->toBe('high')
        ->and($result['accepted']['exceptions'][0]['label'])->toMatch('/lijkt|te controleren/i');
});

test('intake 85 hedged meter observation cannot harden into certain assistant summary', function () {
    $fixture = json_decode(
        (string) file_get_contents(base_path('tests/fixtures/dossier-synthesis/intake-85-hedged-phase-certain-copy.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $output = [
        'summary' => $fixture['assistant_summary_raw'],
        'placement_proposals' => [[
            'key' => 'proposal:outdoor_achtertuin',
            'type' => AircoPlacementType::OutdoorUnit->value,
            'label' => 'Buitenunit achtertuin',
            'description' => 'Zichtbaar.',
            'room_reference' => null,
            'subject_reference' => 'subject:240',
            'confidence' => 0.7,
            'evidence_references' => ['dossier_image:212'],
        ]],
        'option_proposals' => [],
        'exceptions' => [],
        'customer_tasks' => [],
    ];

    $result = app(DossierSynthesisPartialAcceptor::class)->accept($output, intake84StyleAcceptorInput());

    expect($result['has_accepted_proposals'])->toBeTrue()
        ->and($result['accepted']['summary'])->not->toMatch('/voorzien van een 3[\s-]?fasen/i')
        ->and($result['accepted']['summary'])->not->toMatch('/\b3[\s-]?fasen\b(?!.*lijkt)/iu')
        ->and($result['accepted']['summary'])->toMatch('/lijkt|te controleren|mogelijk/i');
});
