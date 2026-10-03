<?php

declare(strict_types=1);

use App\Domains\AI\Services\AiBudgetGuard;
use App\Domains\AI\Services\DossierSynthesisJsonSchema;
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

test('dossier synthesis json schema encodes enums and required reference shapes', function () {
    $schema = app(DossierSynthesisJsonSchema::class)->schema();
    $format = app(DossierSynthesisJsonSchema::class)->responseFormat();

    expect($format['type'])->toBe('json_schema')
        ->and($format['json_schema']['strict'])->toBeTrue()
        ->and($format['json_schema']['name'])->toBe('dossier_synthesis')
        ->and($schema['required'])->toContain('option_proposals')
        ->and($schema['properties']['option_proposals']['items']['properties']['connections']['minItems'])->toBe(3)
        ->and($schema['properties']['option_proposals']['items']['properties']['placement_references']['minItems'])->toBe(2)
        ->and($schema['properties']['option_proposals']['items']['properties']['connections']['items']['properties']['evidence_references']['minItems'])->toBe(1)
        ->and($schema['properties']['option_proposals']['items']['properties']['connections']['items']['properties']['from_placement_reference']['pattern'])
        ->toContain('placement:')
        ->and($schema['properties']['placement_proposals']['items']['properties']['type']['enum'])
        ->toContain(AircoPlacementType::IndoorUnit->value);
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
        ->and($guard->ceilCents(0.3))->toBe(1);
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
