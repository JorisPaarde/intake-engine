<?php

declare(strict_types=1);

use App\Domains\AI\Services\LocalRequestIntentParser;
use App\Domains\AI\Support\RoomFloorLevelExtractor;

test('RoomFloorLevelExtractor koppelt verdieping alleen aan de genoemde ruimte', function (
    string $text,
    array $rooms,
    array $expectedFloors,
) {
    $floors = (new RoomFloorLevelExtractor)->floorsForRooms($text, $rooms);

    expect($floors)->toBe($expectedFloors);
})->with([
    'woonkamer begane grond, slaapkamer eerste verdieping' => [
        'woonkamer op de begane grond en de slaapkamer op de eerste verdieping',
        ['living_room', 'bedroom'],
        ['ground', '1'],
    ],
    'werkkamer eerste verdieping en woonkamer beneden (en is geen before-cue)' => [
        'Werkkamer op de eerste verdieping en de woonkamer beneden',
        ['office', 'living_room'],
        ['1', 'ground'],
    ],
    'slaapkamer 1e verdieping en woonkamer zonder floor' => [
        'slaapkamer op de 1e verdieping en de woonkamer',
        ['bedroom', 'living_room'],
        ['1', null],
    ],
    'leading cues: begane grond woonkamer, eerste verdieping slaapkamer' => [
        'Op de begane grond de woonkamer en op de eerste verdieping de slaapkamer',
        ['living_room', 'bedroom'],
        ['ground', '1'],
    ],
    'telwoord voor slaapkamers lekt niet naar woonkamer' => [
        'Op de begane grond de woonkamer en op de eerste verdieping drie slaapkamers',
        ['living_room', 'bedroom'],
        ['ground', '1'],
    ],
    'woonkamer beneden + eerste verdieping twee slaapkamers' => [
        'De woonkamer beneden en op de eerste verdieping twee slaapkamers',
        ['living_room', 'bedroom'],
        ['ground', '1'],
    ],
    'cijfer-prefix 2 slaapkamers' => [
        'Op de begane grond de woonkamer en op de eerste verdieping 2 slaapkamers',
        ['living_room', 'bedroom'],
        ['ground', '1'],
    ],
    'werkwoord in gap: zijn de slaapkamers' => [
        'De woonkamer beneden, op de eerste verdieping zijn de slaapkamers',
        ['living_room', 'bedroom'],
        ['ground', '1'],
    ],
    'bare verb + telwoord: zijn twee slaapkamers' => [
        'De woonkamer beneden en op de eerste verdieping zijn twee slaapkamers',
        ['living_room', 'bedroom'],
        ['ground', '1'],
    ],
    'bare verb + een: is een slaapkamer' => [
        'De woonkamer beneden en op de eerste verdieping is een slaapkamer',
        ['living_room', 'bedroom'],
        ['ground', '1'],
    ],
    'bare verb + cijfer: zijn 2 slaapkamers' => [
        'De woonkamer beneden en op de eerste verdieping zijn 2 slaapkamers',
        ['living_room', 'bedroom'],
        ['ground', '1'],
    ],
    'Boven de woonkamer: cue blijft bij woonkamer, niet slaapkamers' => [
        'Boven de woonkamer op de begane grond liggen twee slaapkamers',
        ['living_room', 'bedroom'],
        ['ground', null],
    ],
    'Boven de woonkamer + ligt een slaapkamer' => [
        'Boven de woonkamer op de begane grond ligt een slaapkamer',
        ['living_room', 'bedroom'],
        ['ground', null],
    ],
    'Onder de slaapkamer: cue blijft bij slaapkamer, niet woonkamer' => [
        'Onder de slaapkamer op de eerste verdieping ligt woonkamer',
        ['bedroom', 'living_room'],
        ['1', null],
    ],
    'slaapkamer boven, woonkamer beneden' => [
        'slaapkamer boven, woonkamer beneden',
        ['bedroom', 'living_room'],
        ['1', 'ground'],
    ],
    'geen verdiepingsinformatie blijft leeg' => [
        'De slaapkamer en de woonkamer worden te warm in de zomer.',
        ['bedroom', 'living_room'],
        [null, null],
    ],
    'gedeelde zolder voor twee slaapkamers' => [
        "Ik wil twee airco's om m'n slaapkamers op zolder te koelen.",
        ['bedroom', 'bedroom'],
        ['attic', 'attic'],
    ],
    'genummerde verdieping wint van zolder' => [
        'Ik wil de slaapkamer op zolder op de 2e verdieping koelen.',
        ['bedroom'],
        ['2'],
    ],
    'werkkamer eerste verdieping, woonkamer beneden' => [
        'Werkkamer op de eerste verdieping van 3 bij 3 meter en de woonkamer beneden van 6 bij 4 meter.',
        ['office', 'living_room'],
        ['1', 'ground'],
    ],
    'slaapkamers op zolder lekken niet naar woonkamer' => [
        'Ik wil twee slaapkamers op zolder koelen en de woonkamer ook verwarmen.',
        ['bedroom', 'bedroom', 'living_room'],
        ['attic', 'attic', null],
    ],
    'omgekeerde volgorde woonkamer beneden dan werkkamer' => [
        'De woonkamer beneden van 6 bij 4 meter en de werkkamer op de eerste verdieping.',
        ['living_room', 'office'],
        ['ground', '1'],
    ],
    'leading cue: op de begane grond de woonkamer' => [
        'Op de begane grond de woonkamer koelen.',
        ['living_room'],
        ['ground'],
    ],
    'leading cue: op de eerste verdieping de werkkamer, woonkamer beneden' => [
        'Op de eerste verdieping de werkkamer en de woonkamer beneden.',
        ['office', 'living_room'],
        ['1', 'ground'],
    ],
]);

test('lokale parser vult room_floors per ruimte en deelt floor_level niet globaal', function () {
    $parser = new LocalRequestIntentParser(new RoomFloorLevelExtractor);

    $mixed = $parser->parse(
        'Ik wil de woonkamer op de begane grond en de slaapkamer op de eerste verdieping koelen.',
    );
    expect($mixed)->not->toBeNull()
        ->and($mixed['rooms'])->toBe(['living_room', 'bedroom'])
        ->and($mixed['room_floors'])->toBe(['ground', '1'])
        ->and($mixed['floor_level'])->toBeNull();

    $relative = $parser->parse(
        'Slaapkamer boven, woonkamer beneden koelen omdat het te warm wordt.',
    );
    expect($relative)->not->toBeNull()
        ->and($relative['rooms'])->toBe(['bedroom', 'living_room'])
        ->and($relative['room_floors'])->toBe(['1', 'ground'])
        ->and($relative['floor_level'])->toBeNull();

    $none = $parser->parse(
        'De slaapkamer en de woonkamer worden te warm in de zomer.',
    );
    expect($none)->not->toBeNull()
        ->and($none['room_floors'])->toBe([null, null])
        ->and($none['floor_level'])->toBeNull();

    $attic = $parser->parse(
        "Ik wil twee airco's om m'n slaapkamers op zolder te koelen.",
    );
    expect($attic)->not->toBeNull()
        ->and($attic['room_floors'])->toBe(['attic', 'attic'])
        ->and($attic['floor_level'])->toBe('attic');
});
