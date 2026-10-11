<?php

declare(strict_types=1);

use App\Domains\AI\Eval\FactScorer;
use App\Domains\AI\Eval\ReportClaimsScorer;

function n20Scorer(): ReportClaimsScorer
{
    return new ReportClaimsScorer(new FactScorer);
}

/** @return list<array{id: string, concept: string, allowed: string, terms?: list<string>}> */
function n20NoConflictClaims(): array
{
    return [
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
        [
            'id' => 'photo_conflict',
            'concept' => 'photo_conflict',
            'allowed' => 'forbidden',
            'terms' => [
                'conflict',
                'tegenstrijdig',
                'spreken elkaar tegen',
                '/spreken? elkaar tegen/iu',
                'wijken af',
                'verschil tussen',
                '/tegenspre\\w*/iu',
            ],
        ],
    ];
}

test('N20 verbatim false-conflict sentences fail photo_conflict forbidden and enum leak', function () {
    $scores = n20Scorer()->scoreLayer(
        ['claims' => n20NoConflictClaims()],
        [
            'summary' => 'De eerste en de tweede foto van de meterkast spreken elkaar tegen over het aantal fasen, waarbij de tweede foto een driefasige aansluiting toont. De klant is onbekend met het aantal fasen van de meterkast, maar de analyse van de tweede meterkastfoto toont een driefasige aansluiting.',
            'highlights' => [
                'Conflict tussen meterkastfoto’s over fase',
            ],
        ],
        [
            [
                'code' => 'fusebox_conflict',
                'label' => 'Meterkast: conflict tussen AI-analyse (3-fase) en upload-status (retake gevraagd voor eerste foto).',
                'confidence' => 'medium',
                'evidence' => [['source_type' => 'upload', 'reference' => 'fusebox_photo@upload:upload_blurry']],
            ],
            [
                'code' => 'no_free_group',
                'label' => "Beschikbaarheid vrije groep in meterkast is onbevestigd; AI-analyse geeft 'none_visible' voor vrije module-ruimte.",
                'confidence' => 'medium',
                'evidence' => [['source_type' => 'external_fact', 'reference' => 'fusebox_photo_assessment@fact:x']],
            ],
        ],
        [],
        ['C5', 'C7', 'C8'],
    );

    expect($scores['claim.photo_conflict']['status'])->toBe('wrong')
        ->and($scores['claim.photo_conflict']['detail'])->toBe('forbidden_concept_present')
        ->and($scores['no_raw_schema_enums']['status'])->toBe('wrong')
        ->and($scores['no_raw_schema_enums']['detail'])->toBe('raw_schema_enum_in_text')
        ->and(collect($scores['no_raw_schema_enums']['hits'])->pluck('token')->all())
        ->toContain('none_visible');
});

test('N20 hedged unsharp-then-sharp wording passes without conflict or enum leak', function () {
    $scores = n20Scorer()->scoreLayer(
        ['claims' => n20NoConflictClaims(), 'attention_confidence_ceiling' => 'medium'],
        [
            'summary' => 'De eerste meterkastfoto was te onscherp; op de tweede lijkt een 3-fase aansluiting zichtbaar, te controleren. Geen vrije moduleplek lijkt zichtbaar; te controleren.',
            'highlights' => [
                '3-fase lijkt zichtbaar — te controleren',
                'Geen vrije moduleplek zichtbaar — te controleren',
            ],
        ],
        [
            [
                'code' => 'verify_meter_phase',
                'label' => '3-fase lijkt zichtbaar op de tweede meterkastfoto; te controleren',
                'confidence' => 'medium',
                'evidence' => [['source_type' => 'upload', 'reference' => 'fusebox_photo@upload:upload_large']],
            ],
        ],
        [],
        ['C5'],
    );

    expect($scores['claim.photo_conflict']['status'])->toBe('correct')
        ->and($scores['claim.photo_conflict']['detail'])->toBe('concept_absent')
        ->and($scores['claim.phase_3']['status'])->toBe('correct')
        ->and($scores['claim.capacity_full']['status'])->toBe('correct')
        ->and($scores['no_raw_schema_enums']['status'])->toBe('correct')
        ->and($scores['no_raw_schema_enums']['detail'])->toBe('clean')
        ->and($scores['attention_confidence_ceiling']['status'])->toBe('correct');
});

test('N20 real-conflict control passes when both photos and conflict are named', function () {
    $scores = n20Scorer()->scoreLayer(
        [
            'claims' => [[
                'id' => 'photo_conflict',
                'concept' => 'photo_conflict',
                'allowed' => 'assert',
                'terms' => [
                    'conflict',
                    'tegenstrijdig',
                    'spreken elkaar tegen',
                    '/spreken? elkaar tegen/iu',
                    'wijken af',
                    'verschil tussen',
                    '/tegenspre\\w*/iu',
                ],
            ]],
        ],
        [
            'summary' => 'De eerste en de tweede meterkastfoto spreken elkaar tegen over het aantal fasen; er is geen vaste conclusie.',
            'highlights' => ['Conflict tussen eerste en tweede meterkastfoto over fase'],
        ],
        [
            [
                'code' => 'fusebox_photo_conflict',
                'label' => 'De eerste en de tweede meterkastfoto spreken elkaar tegen over het aantal fasen; te controleren',
                'confidence' => 'medium',
                'evidence' => [
                    ['source_type' => 'upload', 'reference' => 'fusebox_photo@upload:upload_phase1'],
                    ['source_type' => 'upload', 'reference' => 'fusebox_photo@upload:upload_phase3'],
                ],
            ],
        ],
        [],
        ['C5'],
    );

    expect($scores['claim.photo_conflict']['status'])->toBe('correct')
        ->and($scores['claim.photo_conflict']['detail'])->toBe('assert_ok_photo_conflict')
        ->and($scores['no_raw_schema_enums']['status'])->toBe('correct');
});

test('N20 retake is not scored as a schema enum leak', function () {
    $scores = n20Scorer()->scoreLayer(
        ['claims' => n20NoConflictClaims()],
        [
            'summary' => 'De eerste meterkastfoto was te onscherp; een scherpere foto is gevraagd. Op de tweede lijkt 3-fase zichtbaar, te controleren.',
            'highlights' => ['Geen retake-enum in schema'],
        ],
        [
            [
                'code' => 'verify_phase',
                'label' => 'Retake gevraagd voor de eerste foto; tweede foto lijkt 3-fase, te controleren',
                'confidence' => 'medium',
                'evidence' => [['source_type' => 'upload', 'reference' => 'fusebox_photo@upload:upload_blurry']],
            ],
        ],
        [],
        ['C5'],
    );

    expect($scores['no_raw_schema_enums']['status'])->toBe('correct')
        ->and($scores['claim.photo_conflict']['status'])->toBe('correct');
});
