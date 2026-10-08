<?php

declare(strict_types=1);

use App\Domains\AI\Support\ExistingAircoExtractor;
use Tests\TestCase;

uses(TestCase::class);

test('ExistingAircoExtractor negeert negatie en losse al-een-airco', function (string $text, ?bool $present) {
    $extracted = (new ExistingAircoExtractor)->extract($text);

    if ($present === null) {
        expect($extracted)->toBeNull();

        return;
    }

    expect($extracted)->not->toBeNull()
        ->and($extracted['present'])->toBe($present)
        ->and($extracted['evidence'])->not->toBe('bestaande airco')
        ->and(mb_stripos($text, $extracted['evidence']) !== false || mb_stripos($extracted['evidence'], 'airco') !== false)->toBeTrue();
})->with([
    'nieuwbouw geen bestaande' => ['Nieuwbouw, geen bestaande airco.', null],
    'er is geen oude' => ['Er is geen oude airco aanwezig', null],
    'zonder bestaande' => ['Zonder bestaande airco', null],
    'zomer al een airco is planning' => ['Graag voor de zomer al een airco in de woonkamer', null],
]);

test('ExistingAircoExtractor positief: bestaande airco met vervanging', function () {
    $text = 'Er hangt al een bestaande airco in de slaapkamer die vervangen moet worden';
    $extracted = (new ExistingAircoExtractor)->extract($text);

    expect($extracted)->not->toBeNull()
        ->and($extracted['present'])->toBeTrue()
        ->and($extracted['replacement'])->toBeTrue()
        ->and($extracted['room_type'])->toBe('bedroom')
        ->and($extracted['evidence'])->not->toBe('bestaande airco')
        ->and(mb_stripos($text, trim($extracted['evidence'])) !== false
            || str_contains(mb_strtolower($extracted['evidence']), 'bestaande airco'))->toBeTrue();
});
