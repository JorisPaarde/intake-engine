<?php

declare(strict_types=1);

use App\Domains\Intake\Services\DossierManager;
use Tests\TestCase;

uses(TestCase::class);

test('stripKnownFloorLabels alleen trailing suffix, niet midden-in', function (string $input, string $expected) {
    $method = new ReflectionMethod(DossierManager::class, 'stripKnownFloorLabels');
    $method->setAccessible(true);

    expect($method->invoke(app(DossierManager::class), $input))->toBe($expected);
})->with([
    'zolderverdieping blijft intact' => [
        'Logeerkamer, zolderverdieping',
        'Logeerkamer, zolderverdieping',
    ],
    'begane grond voorzijde blijft intact' => [
        'Woonkamer, begane grond voorzijde',
        'Woonkamer, begane grond voorzijde',
    ],
    'trailing begane grond verdwijnt' => [
        'Woonkamer, begane grond',
        'Woonkamer',
    ],
    'trailing zolder verdwijnt' => [
        'Slaapkamer, zolder',
        'Slaapkamer',
    ],
]);
