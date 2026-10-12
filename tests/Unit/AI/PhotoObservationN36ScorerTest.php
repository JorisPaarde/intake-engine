<?php

declare(strict_types=1);

use App\Domains\AI\Eval\PhotoObservationCaseRunner;

test('N36 room photo without windows scores sun/glass/glazing against expected unknown', function () {
    $fixture = json_decode(
        (string) file_get_contents(__DIR__.'/../../Eval/fixtures/photo_observations/obs-n36-room-no-windows-unknown-sun-glass.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $result = app(PhotoObservationCaseRunner::class)->run($fixture, 1);

    expect($result['kind'])->toBe('photo_observation')
        ->and($result['facts']['model_raw']['sun_exposure'])->toBe('medium')
        ->and($result['facts']['model_raw']['glass_amount'])->toBe('average')
        ->and($result['facts']['model_raw']['glazing_type'])->toBe('double')
        ->and($result['scores']['model_raw']['sun_exposure']['status'])->toBe('wrong')
        ->and($result['scores']['model_raw']['glass_amount']['status'])->toBe('wrong')
        ->and($result['scores']['model_raw']['glazing_type']['status'])->toBe('wrong')
        ->and($result['scores']['model_raw']['room_type']['status'])->toBe('correct');
});
