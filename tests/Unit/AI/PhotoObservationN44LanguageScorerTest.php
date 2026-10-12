<?php

declare(strict_types=1);

use App\Domains\AI\Eval\FactScorer;
use App\Domains\AI\Eval\PhotoObservationCaseRunner;
use App\Domains\AI\Eval\ReportClaimsScorer;
use App\Domains\AI\Support\DerivedClaimConfidenceGuard;

function n44PhotoObservationRunner(): PhotoObservationCaseRunner
{
    return new PhotoObservationCaseRunner(
        new FactScorer,
        new DerivedClaimConfidenceGuard,
        new ReportClaimsScorer(new FactScorer),
    );
}

test('N44 English room evidence fails forbidden english_free_text claim in stub mode', function () {
    $fixture = json_decode(
        (string) file_get_contents(__DIR__.'/../../Eval/fixtures/photo_observations/obs-n44-room-evidence-english.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $result = n44PhotoObservationRunner()->run($fixture, 1);

    expect($result['kind'])->toBe('photo_observation')
        ->and($result['image_present'])->toBeTrue()
        ->and($result['facts']['model_raw']['evidence'])->toContain('The room contains')
        ->and($result['scores']['model_raw']['claim.english_free_text']['status'])->toBe('wrong')
        ->and($result['scores']['model_raw']['claim.english_free_text']['detail'])->toBe('forbidden_concept_present')
        ->and($result['scores']['model_raw']['room_type']['status'])->toBe('correct');
});

test('N44 Dutch bedroom control passes english_free_text forbidden claim', function () {
    $fixture = json_decode(
        (string) file_get_contents(__DIR__.'/../../Eval/fixtures/photo_observations/obs-n44-room-evidence-dutch-control.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $result = n44PhotoObservationRunner()->run($fixture, 1);

    expect($result['scores']['model_raw']['claim.english_free_text']['status'])->toBe('correct')
        ->and($result['scores']['model_raw']['claim.english_free_text']['detail'])->toBe('concept_absent')
        ->and($result['scores']['model_raw']['room_type']['status'])->toBe('correct')
        ->and($result['image_present'])->toBeTrue();
});

test('N44 living-room Dutch control passes english_free_text forbidden claim', function () {
    $fixture = json_decode(
        (string) file_get_contents(__DIR__.'/../../Eval/fixtures/photo_observations/obs-n44-livingroom-dutch-control.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $result = n44PhotoObservationRunner()->run($fixture, 1);

    expect($result['scores']['model_raw']['claim.english_free_text']['status'])->toBe('correct')
        ->and($result['facts']['model_raw']['room_type'])->toBe('living_room')
        ->and($result['image_present'])->toBeTrue();
});

test('N44 outdoor English evidence risk fails forbidden claim (sibling prompt weakness)', function () {
    $fixture = json_decode(
        (string) file_get_contents(__DIR__.'/../../Eval/fixtures/photo_observations/obs-n44-outdoor-evidence-english-risk.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $result = n44PhotoObservationRunner()->run($fixture, 1);

    expect($result['scores']['model_raw']['claim.english_free_text']['status'])->toBe('wrong')
        ->and($result['scores']['model_raw']['outdoor_location']['status'])->toBe('correct');
});

test('N44 proposed room-assessment-v9 is held out; live prompt stays v8', function () {
    $liveMeta = require __DIR__.'/../../../app/Domains/AI/Prompts/room_assessment/meta.php';
    expect($liveMeta['version'])->toBe('room-assessment-v8');

    $proposedMeta = require __DIR__.'/../../../app/Domains/AI/Prompts/room_assessment/proposed-v9/meta.php';
    expect($proposedMeta['version'])->toBe('room-assessment-v9');

    $proposedBody = (string) file_get_contents(
        __DIR__.'/../../../app/Domains/AI/Prompts/room_assessment/proposed-v9/prompt.md',
    );
    $liveBody = (string) file_get_contents(
        __DIR__.'/../../../app/Domains/AI/Prompts/room_assessment/prompt.md',
    );

    expect($proposedBody)->toContain('schrijf alle vrije tekst')
        ->and($proposedBody)->toContain('Nederlands')
        ->and($liveBody)->not->toContain('schrijf alle vrije tekst')
        ->and($liveBody)->not->toBe($proposedBody);
});

test('N44 photo fixtures exist without EXIF marker', function () {
    $photos = [
        __DIR__.'/../../Eval/fixtures/photos/slaapkamer-1.jpg',
        __DIR__.'/../../Eval/fixtures/photos/slaapkamer-control-2.jpg',
        __DIR__.'/../../Eval/fixtures/photos/woonkamer-control.jpg',
    ];

    foreach ($photos as $path) {
        expect(is_file($path))->toBeTrue();
        $bytes = (string) file_get_contents($path);
        expect(str_contains($bytes, 'Exif') || str_contains($bytes, 'exif'))->toBeFalse();
    }
});
