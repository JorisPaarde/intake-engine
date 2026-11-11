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

/**
 * Realistische modeloutputs uit klanttests: tuin/gevel zonder unit, model zet
 * detected=other of subject_match=no — moet toch ok zijn (BL-161).
 */
test('BL-161: achtertuin-overzicht met detected=other + outdoor_location=garden is ok', function () {
    $accepted = PhotoSubject::acceptedSubjectsForPhotoQuestion('outdoor_location_photos', 'outdoor');

    $assessment = PhotoContentAssessment::fromModelOutput(
        PhotoSubject::OutdoorLocation,
        [
            'outdoor_location' => 'garden',
            'outdoor_mount_type' => 'unknown',
            'outdoor_accessibility' => 'unknown',
            'detected_subject' => 'other',
            'subject_match' => 'no',
            'confidence' => 'medium',
            'evidence' => 'Achtertuin met gras en schutting; geen bestaande buitenunit zichtbaar.',
            'retake_instruction' => 'Maak een foto dichter bij de beoogde montageplek.',
        ],
        $accepted,
        'outdoor_location_photos',
    );

    expect($assessment->status())->toBe(PhotoContentAssessment::STATUS_OK)
        ->and($assessment->detectedSubject())->toBe(PhotoSubject::OutdoorLocation)
        ->and($assessment->customerMessage())->toBeNull();
});

test('BL-161: gevel-overzicht met detected=other + outdoor_location=facade is ok', function () {
    $accepted = PhotoSubject::acceptedSubjectsForPhotoQuestion('outdoor_location_photos', 'outdoor');

    $assessment = PhotoContentAssessment::fromModelOutput(
        PhotoSubject::OutdoorLocation,
        [
            'outdoor_location' => 'facade',
            'outdoor_mount_type' => 'unknown',
            'outdoor_accessibility' => 'unknown',
            'detected_subject' => 'other',
            'subject_match' => 'yes',
            'confidence' => 'low',
            'evidence' => 'Achtergevel van rijtjeswoning vanaf de tuin; montagevlak niet aangewezen.',
            'retake_instruction' => null,
        ],
        $accepted,
        'outdoor_location_photos',
    );

    expect($assessment->status())->toBe(PhotoContentAssessment::STATUS_OK);
});

test('BL-161: subject_match=yes + detected=other zonder enum blijft ok', function () {
    $accepted = PhotoSubject::acceptedSubjectsForPhotoQuestion('around_house_photos', 'around_house');

    $assessment = PhotoContentAssessment::fromModelOutput(
        PhotoSubject::OutdoorLocation,
        [
            'outdoor_location' => 'unknown',
            'detected_subject' => 'other',
            'subject_match' => 'yes',
            'retake_instruction' => 'Toon één specifieke muur.',
        ],
        $accepted,
        'around_house_photos',
    );

    expect($assessment->status())->toBe(PhotoContentAssessment::STATUS_OK);
});

test('BL-161: echt verkeerd (meterkast) blijft wrong_subject', function () {
    $accepted = PhotoSubject::acceptedSubjectsForPhotoQuestion('outdoor_location_photos', 'outdoor');

    $assessment = PhotoContentAssessment::fromModelOutput(
        PhotoSubject::OutdoorLocation,
        [
            'outdoor_location' => 'unknown',
            'detected_subject' => 'fusebox',
            'subject_match' => 'no',
            'retake_instruction' => 'Maak een foto van de gevel of tuin.',
        ],
        $accepted,
        'outdoor_location_photos',
    );

    expect($assessment->status())->toBe(PhotoContentAssessment::STATUS_WRONG_SUBJECT);
});

test('BL-161: other zonder plek-enum en zonder match blijft wrong_subject', function () {
    $accepted = PhotoSubject::acceptedSubjectsForPhotoQuestion('outdoor_location_photos', 'outdoor');

    $assessment = PhotoContentAssessment::fromModelOutput(
        PhotoSubject::OutdoorLocation,
        [
            'outdoor_location' => 'unknown',
            'detected_subject' => 'other',
            'subject_match' => 'no',
            'retake_instruction' => 'Maak een foto van de gevel.',
        ],
        $accepted,
        'outdoor_location_photos',
    );

    expect($assessment->status())->toBe(PhotoContentAssessment::STATUS_WRONG_SUBJECT);
});
