<?php

declare(strict_types=1);

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;

/**
 * Borging dat eval-fixtures voor tuin/gevel (BL-161) via fromModelOutput op ok landen.
 * Geen echte modelrun — pure pipeline-acceptatie op gereconstrueerde model_fields.
 */
test('BL-161 eval fixture achtertuin → content ok', function () {
    $path = dirname(__DIR__, 3).'/Eval/fixtures/photo_observations/obs-bl161-garden-overview-accept.json';
    /** @var array{model_fields: array<string, mixed>} $fixture */
    $fixture = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    $accepted = PhotoSubject::acceptedSubjectsForPhotoQuestion('outdoor_location_photos', 'outdoor');
    $assessment = PhotoContentAssessment::fromModelOutput(
        PhotoSubject::OutdoorLocation,
        $fixture['model_fields'],
        $accepted,
        'outdoor_location_photos',
    );

    expect($assessment->status())->toBe(PhotoContentAssessment::STATUS_OK)
        ->and($assessment->customerMessage())->toBeNull();
});

test('BL-161 eval fixture gevel → content ok', function () {
    $path = dirname(__DIR__, 3).'/Eval/fixtures/photo_observations/obs-bl161-facade-overview-accept.json';
    /** @var array{model_fields: array<string, mixed>} $fixture */
    $fixture = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    $accepted = PhotoSubject::acceptedSubjectsForPhotoQuestion('outdoor_location_photos', 'outdoor');
    $assessment = PhotoContentAssessment::fromModelOutput(
        PhotoSubject::OutdoorLocation,
        $fixture['model_fields'],
        $accepted,
        'outdoor_location_photos',
    );

    expect($assessment->status())->toBe(PhotoContentAssessment::STATUS_OK)
        ->and($assessment->customerMessage())->toBeNull();
});
