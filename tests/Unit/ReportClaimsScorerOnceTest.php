<?php

declare(strict_types=1);

use App\Domains\AI\Eval\ReportClaimsScorer;

function onceScore(array $summary): array
{
    $scorer = app(ReportClaimsScorer::class);
    $out = $scorer->scoreLayer(
        ['claims' => [['id' => 'v', 'concept' => 'v', 'allowed' => 'once', 'terms' => ['3x25']]]],
        $summary,
        [],
        [],
        ['C8'],
    );

    return $out['claim.v'];
}

test('once claim passes when the concept appears in exactly one summary unit', function () {
    $r = onceScore(['summary' => 'De klant wil airco.', 'highlights' => ['Volgens de klant 3x25A.']]);
    expect($r['status'])->toBe('correct')->and($r['detail'])->toBe('once_ok')->and($r['got'])->toBe(1);
});

test('once claim fails when summary and highlight repeat the concept', function () {
    $r = onceScore(['summary' => 'Er is 3x25A aanwezig.', 'highlights' => ['3x25A volgens de klant.']]);
    expect($r['status'])->toBe('wrong')->and($r['detail'])->toBe('once_repeated')->and($r['got'])->toBe(2);
});

test('once claim fails when the concept is missing', function () {
    $r = onceScore(['summary' => 'De klant wil airco.', 'highlights' => []]);
    expect($r['detail'])->toBe('once_concept_missing');
});
