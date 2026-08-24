<?php

declare(strict_types=1);

use App\Domains\Intake\Services\DecisionReadinessService;

it('maps decision area keys to plain Dutch labels', function () {
    expect(DecisionReadinessService::areaLabel('power'))->toBe('Stroomtoevoer')
        ->and(DecisionReadinessService::areaLabel('unknown_key'))->toBe('unknown_key');
});

it('phrases AI confidence without raw floats', function (mixed $input, string $needle) {
    $phrase = DecisionReadinessService::confidencePhrase($input);

    expect($phrase)->toContain($needle)
        ->and($phrase)->not->toMatch('/0\.\d+/')
        ->and($phrase)->not->toContain('%');
})->with([
    [0.3, 'nog onzeker'],
    [0.76, 'redelijk zeker'],
    [0.95, 'lijkt te kloppen'],
    [76, 'redelijk zeker'],
    [null, 'zekerheid onbekend'],
]);
