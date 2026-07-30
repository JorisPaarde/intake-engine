<?php

declare(strict_types=1);

use App\Domains\AI\Services\LocalRequestIntentParser;

test('it reads the explicit installer sentence without treating the attic as a third room', function () {
    $result = app(LocalRequestIntentParser::class)->parse(
        'Ik wil twee airco’s om m’n slaapkamers op zolder te koelen.',
    );

    expect($result)->not->toBeNull()
        ->and($result['cooling_heating'])->toBe('cooling')
        ->and($result['rooms'])->toBe(['bedroom', 'bedroom'])
        ->and($result['floor_level'])->toBe('attic')
        ->and($result['confidence'])->toBe('high');
});

test('it reads two separately named physical rooms', function () {
    $result = app(LocalRequestIntentParser::class)->parse(
        'De slaapkamer en de woonkamer worden te warm in de zomer.',
    );

    expect($result)->not->toBeNull()
        ->and($result['cooling_heating'])->toBe('cooling')
        ->and($result['rooms'])->toBe(['bedroom', 'living_room'])
        ->and($result['floor_level'])->toBeNull();
});

test('it does not guess the count from an unquantified plural room', function () {
    expect(app(LocalRequestIntentParser::class)->parse(
        'Mijn slaapkamers moeten worden gekoeld.',
    ))->toBeNull();
});

test('it does not derive anything when the requested function is unclear', function () {
    expect(app(LocalRequestIntentParser::class)->parse(
        'Ik wil twee airco’s voor mijn slaapkamers.',
    ))->toBeNull();
});

test('it does not force conflicting airco and room counts into a high confidence answer', function () {
    expect(app(LocalRequestIntentParser::class)->parse(
        'Ik wil twee airco’s om de slaapkamer, woonkamer en werkkamer te koelen.',
    ))->toBeNull();
});

test('it does not read a digit from a number outside the supported range', function () {
    expect(app(LocalRequestIntentParser::class)->parse(
        'Ik wil twaalf slaapkamers koelen.',
    ))->toBeNull();
});
