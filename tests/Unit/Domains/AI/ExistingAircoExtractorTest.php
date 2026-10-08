<?php

declare(strict_types=1);

use App\Domains\AI\Support\ExistingAircoExtractor;
use Tests\TestCase;

uses(TestCase::class);

test('ExistingAircoExtractor negeert negatie en losse al-een-airco', function (string $text) {
    expect((new ExistingAircoExtractor)->extract($text))->toBeNull();
})->with([
    'nieuwbouw geen bestaande' => ['Nieuwbouw, geen bestaande airco.'],
    'er is geen oude' => ['Er is geen oude airco aanwezig'],
    'zonder bestaande' => ['Zonder bestaande airco'],
    'zomer al een airco is planning' => ['Graag voor de zomer al een airco in de woonkamer'],
]);

test('ExistingAircoExtractor positief: bestaande airco met vervanging', function (
    string $text,
    bool $replacement,
    ?string $roomType,
) {
    $extracted = (new ExistingAircoExtractor)->extract($text);

    expect($extracted)->not->toBeNull()
        ->and($extracted['present'])->toBeTrue()
        ->and($extracted['replacement'])->toBe($replacement)
        ->and($extracted['room_type'])->toBe($roomType)
        ->and($extracted['evidence'])->not->toBe('bestaande airco')
        ->and($extracted['evidence'])->not->toBe('')
        ->and(mb_stripos($text, trim($extracted['evidence'], '.?!')) !== false
            || mb_stripos($extracted['evidence'], 'airco') !== false)->toBeTrue();
})->with([
    'werkt niet meer moet weg' => [
        'Werkt niet meer, de bestaande airco moet weg',
        true,
        null,
    ],
    'geen tuin dan bestaande in woonkamer' => [
        'Wij hebben geen tuin. Er hangt al een oude airco in de woonkamer die vervangen moet worden.',
        true,
        'living_room',
    ],
    'niet groot dan hangt al oude airco' => [
        'De woonkamer is niet groot, er hangt al een oude airco die vervangen moet worden.',
        true,
        'living_room',
    ],
    'geen in woonkamer maar oude in slaapkamer' => [
        'Geen bestaande airco in de woonkamer, maar in de slaapkamer hangt al een oude airco die weg moet',
        true,
        'bedroom',
    ],
    'we hebben al een airco in slaapkamer' => [
        'We hebben al een airco in de slaapkamer die vervangen moet worden',
        true,
        'bedroom',
    ],
    'bestaande airco in slaapkamer' => [
        'Er hangt al een bestaande airco in de slaapkamer die vervangen moet worden',
        true,
        'bedroom',
    ],
]);

test('ExistingAircoExtractor evidence komt uit de zin rond de match', function () {
    $text = 'Er hangt al een buitenunit aan de gevel. De oude airco in de woonkamer moet vervangen worden.';
    $extracted = (new ExistingAircoExtractor)->extract($text);

    expect($extracted)->not->toBeNull()
        ->and($extracted['present'])->toBeTrue()
        ->and($extracted['replacement'])->toBeTrue()
        ->and($extracted['room_type'])->toBe('living_room')
        ->and($extracted['evidence'])->toBe('De oude airco in de woonkamer moet vervangen worden.');
});
