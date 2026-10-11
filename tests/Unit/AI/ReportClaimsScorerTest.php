<?php

declare(strict_types=1);

use App\Domains\AI\Eval\FactScorer;
use App\Domains\AI\Eval\ReportClaimsScorer;

/**
 * Measurement-only: claim-level concepts (assert / hedged_only / forbidden).
 */
function reportClaimsScorer(): ReportClaimsScorer
{
    return new ReportClaimsScorer(new FactScorer);
}

/** @return array{summary: string, highlights: list<string>} */
function baselineConflictSummary(): array
{
    return [
        'summary' => 'De groepenkast oogt volledig bezet met automaten. Op een scherpere foto lijkt een 3-fase aansluiting zichtbaar.',
        'highlights' => [
            'Groepenkast is volledig bezet',
            '3-fase lijkt zichtbaar',
        ],
    ];
}

test('literal baseline oogt capacity is hedged_only pass; hard fully occupied highlight fails', function () {
    $expected = [
        'claims' => [
            [
                'id' => 'capacity_full',
                'concept' => 'capacity_full',
                'allowed' => 'hedged_only',
            ],
            [
                'id' => 'phase_3',
                'concept' => 'phase_3',
                'allowed' => 'hedged_only',
            ],
        ],
    ];

    $scores = reportClaimsScorer()->scoreLayer(
        $expected,
        baselineConflictSummary(),
        [],
        [],
        ['C5', 'C7', 'C8'],
    );

    expect($scores['claim.capacity_full']['status'])->toBe('wrong')
        ->and($scores['claim.capacity_full']['detail'])->toBe('unhedged_claim_for_hedged_only')
        ->and($scores['claim.phase_3']['status'])->toBe('correct');
});

test('check-instruction wording counts as hedged for hedged_only capacity', function () {
    $scores = reportClaimsScorer()->scoreLayer(
        [
            'claims' => [[
                'id' => 'capacity_full',
                'concept' => 'capacity_full',
                'allowed' => 'hedged_only',
            ]],
        ],
        [
            'summary' => 'Controleer of de groepenkast volledig bezet is; bevestiging van vrije moduleplek is vereist.',
            'highlights' => ['Verifieer: geen vrije moduleplaatsen — te controleren'],
        ],
        [[
            'code' => 'verify_capacity',
            'label' => 'Nagaan of uitbreiding nodig is',
            'confidence' => 'medium',
            'evidence' => [['source_type' => 'upload', 'reference' => 'fusebox_photo@upload:x']],
        ]],
        [],
        ['C5'],
    );

    expect($scores['claim.capacity_full']['status'])->toBe('correct')
        ->and($scores['claim.capacity_full']['detail'])->toBe('all_matches_hedged');
});

test('unhedged attention capacity fact fails hedged_only capacity_full', function () {
    $scores = reportClaimsScorer()->scoreLayer(
        [
            'claims' => [[
                'id' => 'capacity_full',
                'concept' => 'capacity_full',
                'allowed' => 'hedged_only',
            ]],
        ],
        [
            'summary' => 'Korte samenvatting zonder capaciteitsclaim voor de installateur.',
            'highlights' => ['Geen opvallende punten'],
        ],
        [[
            'code' => 'no_free_group',
            'label' => 'Geen vrije moduleplaatsen in groepenkast',
            'confidence' => 'high',
            'evidence' => [['source_type' => 'external_fact', 'reference' => 'fusebox_photo_assessment@fact:x']],
        ]],
        [],
        ['C5'],
    );

    expect($scores['claim.capacity_full']['status'])->toBe('wrong');
});

test('hedged medium-confidence phase wording passes hedged_only', function () {
    $scores = reportClaimsScorer()->scoreLayer(
        [
            'claims' => [
                ['id' => 'phase_3', 'concept' => 'phase_3', 'allowed' => 'hedged_only'],
                ['id' => 'capacity_full', 'concept' => 'capacity_full', 'allowed' => 'forbidden'],
            ],
        ],
        [
            'summary' => 'Op de meterkastfoto lijkt een 3-fase aansluiting zichtbaar; te controleren op locatie.',
            'highlights' => ['3-fase lijkt zichtbaar', 'Vrije moduleplek onzeker — te controleren'],
        ],
        [[
            'code' => 'verify_meter_phase',
            'label' => '3-fase lijkt zichtbaar; controleer vrije groepen op locatie',
            'confidence' => 'medium',
            'evidence' => [['source_type' => 'external_fact', 'reference' => 'fusebox_photo_assessment@fact:fact_eval_hedged']],
        ]],
        [],
        ['C5'],
    );

    expect($scores['claim.phase_3']['status'])->toBe('correct')
        ->and($scores['claim.capacity_full']['status'])->toBe('correct');
});

test('clear control counts driefasige aansluiting zichtbaar as phase_3 assert', function () {
    $scorer = reportClaimsScorer();
    expect($scorer->mentionsPhase('driefasige aansluiting zichtbaar'))->toBeTrue();

    $scores = $scorer->scoreLayer(
        [
            'claims' => [[
                'id' => 'phase_3',
                'concept' => 'phase_3',
                'allowed' => 'assert',
            ]],
        ],
        [
            'summary' => 'Duidelijke meterkastfoto: driefasige aansluiting zichtbaar. Vrije moduleplek aanwezig.',
            'highlights' => ['driefasige aansluiting zichtbaar'],
        ],
        [[
            'code' => 'three_phase_supply',
            'label' => 'driefasige aansluiting zichtbaar',
            'confidence' => 'high',
            'evidence' => [['source_type' => 'upload', 'reference' => 'fusebox_photo@upload:upload_clear']],
        ]],
        [],
        ['C5'],
    );

    expect($scores['claim.phase_3']['status'])->toBe('correct')
        ->and($scores['claim.phase_3']['detail'])->toBe('assert_ok');
});

test('over-hedged summary fails assert even when attention says bevestigd', function () {
    $scores = reportClaimsScorer()->scoreLayer(
        [
            'claims' => [[
                'id' => 'phase_3',
                'concept' => 'phase_3',
                'allowed' => 'assert',
            ]],
        ],
        [
            'summary' => 'Op de scherpe meterkastfoto lijkt een 3-fase aansluiting zichtbaar.',
            'highlights' => ['3-fase lijkt zichtbaar'],
        ],
        [[
            'code' => 'three_phase_confirmed',
            'label' => '3-fase aansluiting bevestigd',
            'confidence' => 'high',
            'evidence' => [['source_type' => 'upload', 'reference' => 'fusebox_photo@upload:upload_clear']],
        ]],
        [],
        ['C5'],
    );

    expect($scores['claim.phase_3']['status'])->toBe('wrong')
        ->and($scores['claim.phase_3']['detail'])->toBe('unnecessary_hedging_for_assert_on_summary');
});

test('photo_conflict assert does not penalize soft conflict wording', function () {
    $scores = reportClaimsScorer()->scoreLayer(
        [
            'claims' => [[
                'id' => 'photo_conflict',
                'concept' => 'photo_conflict',
                'allowed' => 'assert',
            ]],
        ],
        [
            'summary' => 'De meterkastfoto’s wijken af; de eerste foto is mogelijk onscherp — te controleren.',
            'highlights' => ['Mogelijk conflict tussen foto 1 en foto 2'],
        ],
        [],
        [],
        ['C5'],
    );

    expect($scores['claim.photo_conflict']['status'])->toBe('correct')
        ->and($scores['claim.photo_conflict']['detail'])->toBe('assert_ok_photo_conflict');
});

test('photo_conflict terms match onvoldoende scherp and wisselende beeldkwaliteit', function () {
    $scores = reportClaimsScorer()->scoreLayer(
        [
            'claims' => [[
                'id' => 'photo_conflict',
                'concept' => 'photo_conflict',
                'allowed' => 'assert',
            ]],
        ],
        [
            'summary' => 'De eerste meterkastfoto is onvoldoende scherp; wisselende beeldkwaliteit tussen de foto’s.',
            'highlights' => ['Onduidelijke foto — niet scherp / wazig'],
        ],
        [],
        [],
        ['C5'],
    );

    expect($scores['claim.photo_conflict']['status'])->toBe('correct')
        ->and($scores['claim.photo_conflict']['detail'])->toBe('assert_ok_photo_conflict');
});

test('exact input references in prose fail; upload_quality_low code does not', function () {
    $ref = 'fusebox_photo@upload:upload_blurry';

    $leak = reportClaimsScorer()->scoreLayer(
        [
            'claims' => [[
                'id' => 'phase_3',
                'concept' => 'phase_3',
                'allowed' => 'forbidden',
            ]],
        ],
        [
            'summary' => "Controleer {$ref} opnieuw.",
            'highlights' => ['Geen faseclaim'],
        ],
        [[
            'code' => 'upload_quality_low',
            'label' => 'Eerste meterkastfoto onscherp',
            'confidence' => 'medium',
            'evidence' => [['source_type' => 'upload', 'reference' => $ref]],
        ]],
        [],
        ['C5'],
        ['input_references' => [$ref]],
    );

    expect($leak['no_internal_upload_refs']['status'])->toBe('wrong')
        ->and($leak['no_internal_upload_refs']['detail'])->toBe('internal_upload_ref_in_text')
        ->and($leak['no_internal_upload_refs']['hits'])->not->toBeEmpty();

    $opaqueLeak = reportClaimsScorer()->scoreLayer(
        [
            'claims' => [[
                'id' => 'phase_3',
                'concept' => 'phase_3',
                'allowed' => 'forbidden',
            ]],
        ],
        [
            'summary' => "Foto 'upload_blurry' is onvoldoende scherp.",
            'highlights' => ['Geen faseclaim'],
        ],
        [[
            'code' => 'upload_quality_low',
            'label' => 'Eerste meterkastfoto onscherp',
            'confidence' => 'medium',
            'evidence' => [['source_type' => 'upload', 'reference' => $ref]],
        ]],
        [],
        ['C5'],
        ['input_references' => [$ref]],
    );

    expect($opaqueLeak['no_internal_upload_refs']['status'])->toBe('wrong')
        ->and($opaqueLeak['no_internal_upload_refs']['hits'][0]['reference'] ?? null)->toBe('upload_blurry');

    $clean = reportClaimsScorer()->scoreLayer(
        [
            'claims' => [[
                'id' => 'phase_3',
                'concept' => 'phase_3',
                'allowed' => 'forbidden',
            ]],
        ],
        [
            'summary' => 'Controleer de eerste meterkastfoto opnieuw.',
            'highlights' => ['Geen faseclaim'],
        ],
        [[
            'code' => 'upload_quality_low',
            'label' => 'Eerste meterkastfoto onscherp',
            'confidence' => 'medium',
            'evidence' => [['source_type' => 'upload', 'reference' => $ref]],
        ]],
        [],
        ['C5'],
        ['input_references' => [$ref]],
    );

    expect($clean['no_internal_upload_refs']['status'])->toBe('correct')
        ->and($clean['no_internal_upload_refs']['detail'])->toBe('clean');
});

test('cannot-determine phase sentence is not forbidden and counts as hedged', function () {
    $scores = reportClaimsScorer()->scoreLayer(
        [
            'claims' => [
                ['id' => 'phase_3', 'concept' => 'phase_3', 'allowed' => 'hedged_only'],
                ['id' => 'capacity_full', 'concept' => 'capacity_full', 'allowed' => 'forbidden'],
            ],
            'must_not_invent_phase' => true,
        ],
        [
            'summary' => 'Het aantal fasen is niet vast te stellen door afdekplaatjes; 3-fase is onbekend.',
            'highlights' => ['Fase niet te bepalen'],
        ],
        [],
        [],
        ['C5'],
    );

    expect($scores['claim.phase_3']['status'])->toBe('correct')
        ->and($scores['claim.phase_3']['detail'])->toBe('all_matches_hedged')
        ->and($scores['must_not_invent_phase']['status'])->toBe('correct');
});

test('cannot-determine marker in another semicolon clause does not clear a hard claim', function () {
    $capacity = reportClaimsScorer()->scoreLayer(
        [
            'claims' => [[
                'id' => 'capacity_full',
                'concept' => 'capacity_full',
                'allowed' => 'forbidden',
            ]],
        ],
        [
            'summary' => 'Groepenkast volledig bezet; aantal fasen onbekend.',
            'highlights' => [],
        ],
        [],
        [],
        ['C5'],
    );

    expect($capacity['claim.capacity_full']['status'])->toBe('wrong')
        ->and($capacity['claim.capacity_full']['detail'])->toBe('forbidden_concept_present');

    $phase = reportClaimsScorer()->scoreLayer(
        [
            'claims' => [[
                'id' => 'phase_3',
                'concept' => 'phase_3',
                'allowed' => 'hedged_only',
            ]],
            'must_not_invent_phase' => true,
        ],
        [
            'summary' => '3-fase aanwezig; ruimte onbekend.',
            'highlights' => [],
        ],
        [],
        [],
        ['C5'],
    );

    expect($phase['claim.phase_3']['status'])->toBe('wrong')
        ->and($phase['claim.phase_3']['detail'])->toBe('unhedged_claim_for_hedged_only')
        ->and($phase['must_not_invent_phase']['status'])->toBe('wrong');
});

test('1-fase/3-fase as alternatives is cannot-determine; onzeker is a hedge', function () {
    $scorer = reportClaimsScorer();

    expect($scorer->textLooksCannotDetermine(
        'controleer of er sprake is van een 1-fase of 3-fase aansluiting',
    ))->toBeTrue()
        ->and($scorer->textLooksHedged('De 3-fase aansluiting is onzeker'))->toBeTrue()
        // Existing probes stay: hard claim + cannot-determine in another clause still fails.
        ->and($scorer->textLooksHedged('3-fase aansluiting gecontroleerd en aanwezig'))->toBeFalse()
        ->and($scorer->textLooksCannotDetermine('3-fase aansluiting gecontroleerd en aanwezig'))->toBeFalse();

    $capacity = $scorer->scoreLayer(
        [
            'claims' => [[
                'id' => 'capacity_full',
                'concept' => 'capacity_full',
                'allowed' => 'forbidden',
            ]],
        ],
        [
            'summary' => 'Groepenkast volledig bezet; aantal fasen onbekend.',
            'highlights' => [],
        ],
        [],
        [],
        ['C5'],
    );

    expect($capacity['claim.capacity_full']['status'])->toBe('wrong')
        ->and($capacity['claim.capacity_full']['detail'])->toBe('forbidden_concept_present');

    $phaseHard = $scorer->scoreLayer(
        [
            'claims' => [[
                'id' => 'phase_3',
                'concept' => 'phase_3',
                'allowed' => 'hedged_only',
            ]],
        ],
        [
            'summary' => '3-fase aansluiting gecontroleerd en aanwezig.',
            'highlights' => [],
        ],
        [],
        [],
        ['C5'],
    );

    expect($phaseHard['claim.phase_3']['status'])->toBe('wrong')
        ->and($phaseHard['claim.phase_3']['detail'])->toBe('unhedged_claim_for_hedged_only');
});

test('eenfasige of driefasige and enkelfasig variants count as cannot-determine', function () {
    $scorer = reportClaimsScorer();

    expect($scorer->textLooksCannotDetermine(
        'Controleer of de aansluiting eenfasige of driefasige is.',
    ))->toBeTrue()
        ->and($scorer->textLooksCannotDetermine(
            'Mogelijk driefasige of eenfasige voeding.',
        ))->toBeTrue()
        ->and($scorer->textLooksCannotDetermine(
            'Het kan enkelfasig of driefasig zijn.',
        ))->toBeTrue()
        ->and($scorer->textLooksCannotDetermine(
            'Driefasig / enkelfasig nog onduidelijk.',
        ))->toBeTrue()
        ->and($scorer->textLooksCannotDetermine(
            'Het betreft een- of driefasig.',
        ))->toBeTrue()
        ->and($scorer->textLooksCannotDetermine(
            'Nog enkel- of driefasig vast te stellen.',
        ))->toBeTrue()
        ->and($scorer->textLooksCannotDetermine(
            'Drie- of eenfasig is onduidelijk.',
        ))->toBeTrue();

    $scores = $scorer->scoreLayer(
        [
            'claims' => [[
                'id' => 'phase_3',
                'concept' => 'phase_3',
                'allowed' => 'hedged_only',
            ]],
            'must_not_invent_phase' => true,
        ],
        [
            'summary' => 'Controleer of de meterkast eenfasige of driefasige is.',
            'highlights' => ['Nog enkelfasig of driefasig — niet te bepalen'],
        ],
        [],
        [],
        ['C5'],
    );

    expect($scores['must_not_invent_phase']['status'])->toBe('correct')
        ->and($scores['claim.phase_3']['status'])->toBe('correct');
});

test('hedged_only phase: strip X-of-Y then judge remaining phase term on its own hedge', function () {
    $scorer = reportClaimsScorer();

    expect($scorer->stripPhaseAlternatives(
        'De klant weet niet of de aansluiting een- of driefasig is',
    ))->not->toMatch('/fasig/iu');

    // Both-options only → OK (no firm phase claim left after strip).
    $ok = $scorer->scoreLayer(
        [
            'claims' => [[
                'id' => 'phase_3',
                'concept' => 'phase_3',
                'allowed' => 'hedged_only',
            ]],
        ],
        [
            'summary' => 'De klant weet niet of de aansluiting een- of driefasig is.',
            'highlights' => [],
        ],
        [],
        [],
        ['C5'],
    );

    expect($ok['claim.phase_3']['status'])->toBe('correct')
        ->and($ok['claim.phase_3']['detail'])->toBe('all_matches_cannot_determine');

    // Both-options + firm "toont … driefasige" in the same clause → FAIL.
    $fail = $scorer->scoreLayer(
        [
            'claims' => [[
                'id' => 'phase_3',
                'concept' => 'phase_3',
                'allowed' => 'hedged_only',
            ]],
        ],
        [
            'summary' => 'De klant weet niet of het een 1-fase of 3-fase aansluiting is, maar de meterkastfoto toont een driefasige hoofdschakelaar.',
            'highlights' => [],
        ],
        [],
        [],
        ['C5'],
    );

    expect($fail['claim.phase_3']['status'])->toBe('wrong')
        ->and($fail['claim.phase_3']['detail'])->toBe('unhedged_claim_for_hedged_only');
});

test('no-fusebox fixture missing photo assert fails when point is dropped', function () {
    $fixture = json_decode(
        (string) file_get_contents(__DIR__.'/../../Eval/fixtures/report_claims/rc-no-fusebox-photo.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $missingClaim = collect($fixture['expected']['claims'])->firstWhere('id', 'missing_fusebox_photo');
    expect($missingClaim['allowed'])->toBe('assert');

    $absent = reportClaimsScorer()->scoreLayer(
        ['claims' => [$missingClaim]],
        [
            'summary' => 'Korte samenvatting zonder meterkastfoto-melding voor de installateur.',
            'highlights' => ['Geen faseclaim'],
        ],
        [],
        [],
        ['C5'],
    );

    expect($absent['claim.missing_fusebox_photo']['status'])->toBe('wrong')
        ->and($absent['claim.missing_fusebox_photo']['detail'])->toBe('assert_concept_missing');

    $present = reportClaimsScorer()->scoreLayer(
        ['claims' => [$missingClaim]],
        [
            'summary' => $fixture['fake_summary_output']['summary'],
            'highlights' => $fixture['fake_summary_output']['highlights'],
        ],
        [[
            'code' => $fixture['fake_attention_output']['points'][0]['code'],
            'label' => $fixture['fake_attention_output']['points'][0]['label'],
            'confidence' => 'medium',
            'evidence' => $fixture['fake_attention_output']['points'][0]['evidence'],
        ]],
        [],
        ['C5'],
    );

    expect($present['claim.missing_fusebox_photo']['status'])->toBe('correct');

    $paraphrase = reportClaimsScorer()->scoreLayer(
        ['claims' => [$missingClaim]],
        [
            'summary' => 'Stroomvoorziening is nog niet beoordeeld omdat een meterkastfoto ontbreekt.',
            'highlights' => ['Vrije groep nog onbekend'],
        ],
        [[
            'code' => 'electrical_provision_open',
            'label' => 'Open technisch punt: stroomvoorziening / vrije groep nog te beoordelen',
            'confidence' => 'medium',
            'evidence' => [['source_type' => 'system_attention_point', 'reference' => 'electrical_provision_open']],
        ]],
        [],
        ['C5'],
    );

    expect($paraphrase['claim.missing_fusebox_photo']['status'])->toBe('correct');
});

test('named-photo attribution is hedged_only only when same layer names conflict', function () {
    $withConflict = reportClaimsScorer()->scoreLayer(
        [
            'claims' => [[
                'id' => 'phase_3',
                'concept' => 'phase_3',
                'allowed' => 'hedged_only',
            ]],
        ],
        [
            'summary' => 'De meterkastfoto’s wijken af. De tweede meterkastfoto toont een 3-fase aansluiting.',
            'highlights' => ['Op de tweede foto is 3-fase zichtbaar'],
        ],
        [],
        [],
        ['C5'],
    );

    expect($withConflict['claim.phase_3']['status'])->toBe('correct')
        ->and($withConflict['claim.phase_3']['detail'])->toBe('all_matches_hedged');

    $withoutConflict = reportClaimsScorer()->scoreLayer(
        [
            'claims' => [[
                'id' => 'phase_3',
                'concept' => 'phase_3',
                'allowed' => 'hedged_only',
            ]],
        ],
        [
            'summary' => 'De tweede meterkastfoto toont een 3-fase aansluiting.',
            'highlights' => ['Op de tweede foto is 3-fase zichtbaar'],
        ],
        [],
        [],
        ['C5'],
    );

    expect($withoutConflict['claim.phase_3']['status'])->toBe('wrong')
        ->and($withoutConflict['claim.phase_3']['detail'])->toBe('unhedged_claim_for_hedged_only');
});

test('named-photo conclusion wording stays unhedged even with conflict', function () {
    $scores = reportClaimsScorer()->scoreLayer(
        [
            'claims' => [[
                'id' => 'capacity_full',
                'concept' => 'capacity_full',
                'allowed' => 'hedged_only',
            ]],
        ],
        [
            'summary' => 'De meterkastfoto’s wijken af. De tweede meterkastfoto toont dat uitbreiding vereist is.',
            'highlights' => [],
        ],
        [],
        [],
        ['C5'],
    );

    expect($scores['claim.capacity_full']['status'])->toBe('wrong')
        ->and($scores['claim.capacity_full']['detail'])->toBe('unhedged_claim_for_hedged_only');
});

test('bare gecontroleerd is not a hedge; to-be-done form still is', function () {
    $scorer = reportClaimsScorer();

    expect($scorer->textLooksHedged('3-fase aansluiting gecontroleerd en aanwezig.'))->toBeFalse()
        ->and($scorer->textLooksHedged('De groepenkast is gecontroleerd en volledig bezet.'))->toBeFalse()
        ->and($scorer->textLooksHedged(
            'Dit dient gecontroleerd te worden vanwege de gemiddelde zekerheid.',
        ))->toBeTrue()
        ->and($scorer->textLooksHedged('De fase moet nog gecontroleerd worden op locatie.'))->toBeTrue();

    $phase = $scorer->scoreLayer(
        [
            'claims' => [[
                'id' => 'phase_3',
                'concept' => 'phase_3',
                'allowed' => 'hedged_only',
            ]],
        ],
        [
            'summary' => '3-fase aansluiting gecontroleerd en aanwezig.',
            'highlights' => [],
        ],
        [],
        [],
        ['C5'],
    );

    expect($phase['claim.phase_3']['status'])->toBe('wrong')
        ->and($phase['claim.phase_3']['detail'])->toBe('unhedged_claim_for_hedged_only');

    $capacity = $scorer->scoreLayer(
        [
            'claims' => [[
                'id' => 'capacity_full',
                'concept' => 'capacity_full',
                'allowed' => 'hedged_only',
            ]],
        ],
        [
            'summary' => 'De groepenkast is gecontroleerd en volledig bezet.',
            'highlights' => [],
        ],
        [],
        [],
        ['C5'],
    );

    expect($capacity['claim.capacity_full']['status'])->toBe('wrong')
        ->and($capacity['claim.capacity_full']['detail'])->toBe('unhedged_claim_for_hedged_only');
});

test('comma and maar stay in the same clause so same-sentence caveats hedge', function () {
    $scorer = reportClaimsScorer();
    expect($scorer->splitClauses(
        'Lijkt er, gezien de hoofdschakelaar, een 3-fase aansluiting.',
    ))->toHaveCount(1)
        ->and($scorer->splitClauses(
            'De foto toont een 3-fase aansluiting, maar dit dient gecontroleerd te worden vanwege de gemiddelde zekerheid.',
        ))->toHaveCount(1);

    $commaHedge = $scorer->scoreLayer(
        [
            'claims' => [[
                'id' => 'phase_3',
                'concept' => 'phase_3',
                'allowed' => 'hedged_only',
            ]],
        ],
        [
            'summary' => 'Lijkt er, gezien de hoofdschakelaar, een 3-fase aansluiting.',
            'highlights' => [
                'Mogelijk is er, net als bij de buren, een 3-fase aansluiting.',
            ],
        ],
        [],
        [],
        ['C5'],
    );

    expect($commaHedge['claim.phase_3']['status'])->toBe('correct')
        ->and($commaHedge['claim.phase_3']['detail'])->toBe('all_matches_hedged');

    $blankingComma = $scorer->scoreLayer(
        [
            'claims' => [[
                'id' => 'phase_3',
                'concept' => 'phase_3',
                'allowed' => 'hedged_only',
            ]],
            'must_not_invent_phase' => true,
        ],
        [
            'summary' => 'De fase is, door de afdekplaatjes, onbekend.',
            'highlights' => ['3-fase is niet vastgesteld'],
        ],
        [],
        [],
        ['C5'],
    );

    expect($blankingComma['claim.phase_3']['status'])->toBe('correct')
        ->and($blankingComma['must_not_invent_phase']['status'])->toBe('correct');

    $maarCaveat = $scorer->scoreLayer(
        [
            'claims' => [[
                'id' => 'phase_3',
                'concept' => 'phase_3',
                'allowed' => 'hedged_only',
            ]],
        ],
        [
            'summary' => 'De meterkastfoto’s wijken af. De tweede meterkastfoto toont een 3-fase aansluiting, maar dit dient gecontroleerd te worden vanwege de gemiddelde zekerheid.',
            'highlights' => [],
        ],
        [],
        [],
        ['C5'],
    );

    expect($maarCaveat['claim.phase_3']['status'])->toBe('correct')
        ->and($maarCaveat['claim.phase_3']['detail'])->toBe('all_matches_hedged');
});

test('over-hedged attention fails assert even when summary is firm', function () {
    $scores = reportClaimsScorer()->scoreLayer(
        [
            'claims' => [[
                'id' => 'phase_3',
                'concept' => 'phase_3',
                'allowed' => 'assert',
            ]],
        ],
        [
            'summary' => 'Duidelijke meterkastfoto: driefasige aansluiting zichtbaar.',
            'highlights' => ['driefasige aansluiting zichtbaar'],
        ],
        [[
            'code' => 'three_phase_check',
            'label' => '3-fase lijkt zichtbaar — te controleren',
            'confidence' => 'medium',
            'evidence' => [['source_type' => 'upload', 'reference' => 'fusebox_photo@upload:upload_clear']],
        ]],
        [],
        ['C5'],
    );

    expect($scores['claim.phase_3']['status'])->toBe('wrong')
        ->and($scores['claim.phase_3']['detail'])->toBe('unnecessary_hedging_for_assert_on_attention');
});

test('empty or rejected layer fails and does not auto-pass claims', function () {
    $scores = reportClaimsScorer()->scoreLayer(
        [
            'claims' => [[
                'id' => 'phase_3',
                'concept' => 'phase_3',
                'allowed' => 'forbidden',
            ]],
        ],
        null,
        null,
        [],
        ['C5'],
        [
            'summary_rejection' => 'pipeline_rejected: validation',
            'attention_rejection' => 'gateway_output_not_attention_points_shape',
        ],
    );

    expect($scores['summary_output']['status'])->toBe('wrong')
        ->and($scores['summary_output']['rejection'])->toBe('pipeline_rejected: validation')
        ->and($scores['attention_output']['status'])->toBe('wrong')
        ->and($scores['claim.phase_3']['status'])->toBe('not_supported');
});

test('attention code synonyms match capacity_full concept without exact code list', function () {
    $scores = reportClaimsScorer()->scoreLayer(
        [
            'claims' => [[
                'id' => 'capacity_full',
                'concept' => 'capacity_full',
                'allowed' => 'forbidden',
            ]],
        ],
        [
            'summary' => 'Samenvatting zonder capaciteitstekst voor de installateur hier.',
            'highlights' => ['Overige aandachtspunten volgen'],
        ],
        [[
            'code' => 'expansion_required',
            'label' => 'Uitbreiding van de groepenkast is nodig',
            'confidence' => 'medium',
            'evidence' => [['source_type' => 'upload', 'reference' => 'fusebox_photo@upload:x']],
        ]],
        [],
        ['C5'],
    );

    expect($scores['claim.capacity_full']['status'])->toBe('wrong');
});

test('hedge markers need a word boundary only at the start', function () {
    $scorer = reportClaimsScorer();

    expect($scorer->textLooksHedged('Driefasige aansluiting indicatief vastgesteld op basis van foto'))->toBeTrue()
        ->and($scorer->textLooksHedged('Onbekende fase-aansluiting in de meterkast'))->toBeTrue()
        ->and($scorer->textLooksHedged('Mogelijke 3-fase aansluiting'))->toBeTrue()
        ->and($scorer->textLooksHedged('3-fase aansluiting gecontroleerd en aanwezig.'))->toBeFalse()
        ->and($scorer->textLooksHedged('De groepenkast is gecontroleerd en volledig bezet.'))->toBeFalse()
        ->and($scorer->textLooksHedged('De meterkastfoto toont een 3-fase aansluiting, maar dit dient bij installatie gecontroleerd te worden.'))->toBeTrue();
});

test('suggereert and wijst op count as hedge markers', function () {
    $scorer = reportClaimsScorer();

    expect($scorer->textLooksHedged('De meterkastfoto suggereert een 3-fase aansluiting.'))->toBeTrue()
        ->and($scorer->textLooksHedged('De foto wijst op een volle groepenkast.'))->toBeTrue();

    $scores = $scorer->scoreLayer(
        [
            'claims' => [
                ['id' => 'phase_3', 'concept' => 'phase_3', 'allowed' => 'hedged_only'],
                ['id' => 'capacity_full', 'concept' => 'capacity_full', 'allowed' => 'hedged_only'],
            ],
        ],
        [
            'summary' => 'De meterkastfoto suggereert een 3-fase aansluiting. De foto wijst op geen vrije moduleplek.',
            'highlights' => [],
        ],
        [],
        [],
        ['C5'],
    );

    expect($scores['claim.phase_3']['status'])->toBe('correct')
        ->and($scores['claim.phase_3']['detail'])->toBe('all_matches_hedged')
        ->and($scores['claim.capacity_full']['status'])->toBe('correct')
        ->and($scores['claim.capacity_full']['detail'])->toBe('all_matches_hedged');
});
