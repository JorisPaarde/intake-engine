<?php

declare(strict_types=1);

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;

test('BL-158: redelijke outdoor_location foto blijft ok ondanks subject_match=no en retake', function () {
    $accepted = PhotoSubject::acceptedSubjectsForPhotoQuestion('outdoor_location_photos', 'outdoor');

    $assessment = PhotoContentAssessment::fromModelOutput(
        PhotoSubject::OutdoorLocation,
        [
            'detected_subject' => 'outdoor_location',
            'subject_match' => 'no',
            'retake_instruction' => 'Maak een foto van de hele gevel van dichterbij.',
        ],
        $accepted,
        'outdoor_location_photos',
    );

    expect($assessment->status())->toBe(PhotoContentAssessment::STATUS_OK)
        ->and($assessment->customerMessage())->toBeNull();
});

test('BL-158: outdoor_unit in accepted-set blijft ok', function () {
    $accepted = PhotoSubject::acceptedSubjectsForPhotoQuestion('outdoor_location_photos', 'outdoor');

    $assessment = PhotoContentAssessment::fromModelOutput(
        PhotoSubject::OutdoorLocation,
        [
            'detected_subject' => 'outdoor_unit',
            'subject_match' => 'yes',
            'retake_instruction' => 'Fotografeer één specifieke muur.',
        ],
        $accepted,
        'outdoor_location_photos',
    );

    expect($assessment->status())->toBe(PhotoContentAssessment::STATUS_OK);
});

test('BL-158: verkeerd onderwerp (kamer) blijft wrong_subject', function () {
    $accepted = PhotoSubject::acceptedSubjectsForPhotoQuestion('outdoor_location_photos', 'outdoor');

    $assessment = PhotoContentAssessment::fromModelOutput(
        PhotoSubject::OutdoorLocation,
        [
            'detected_subject' => 'room',
            'subject_match' => 'no',
            'retake_instruction' => 'Maak een foto van de gevel.',
        ],
        $accepted,
        'outdoor_location_photos',
    );

    expect($assessment->status())->toBe(PhotoContentAssessment::STATUS_WRONG_SUBJECT);
});
