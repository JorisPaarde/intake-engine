<?php

declare(strict_types=1);

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Support\PhotoContinueAnywayAttention;

function clarityLabelUpload(PhotoContentAssessment $assessment): IntakeUpload
{
    $upload = new IntakeUpload;
    $upload->forceFill([
        'mime_type' => 'image/jpeg',
        'content_assessment' => $assessment->toArray(),
    ]);

    return $upload;
}

test('fusebox_clarity: alleen afgekeurde foto’s → Niet duidelijk genoeg', function () {
    $uploads = [
        clarityLabelUpload(PhotoContentAssessment::needsClearer(
            PhotoSubject::Fusebox,
            'Maak een scherpere foto van de meterkast.',
        )->withCustomerAcceptedOverride()),
        clarityLabelUpload(PhotoContentAssessment::wrongSubject(
            PhotoSubject::Fusebox,
            PhotoSubject::Room,
        )->withCustomerAcceptedOverride()),
    ];

    expect(PhotoContinueAnywayAttention::fuseboxClarityReportLabel($uploads))
        ->toBe('Niet duidelijk genoeg — zie aandachtspunt meterkast.');
});

test('fusebox_clarity: afgekeurd plus scherpe foto → Deels duidelijk', function () {
    $uploads = [
        clarityLabelUpload(PhotoContentAssessment::needsClearer(
            PhotoSubject::Fusebox,
            'Maak een scherpere foto van de meterkast.',
        )->withCustomerAcceptedOverride()),
        clarityLabelUpload(PhotoContentAssessment::ok(PhotoSubject::Fusebox)),
    ];

    expect(PhotoContinueAnywayAttention::fuseboxClarityReportLabel($uploads))
        ->toBe('Deels duidelijk — zie aandachtspunt meterkast.');
});

test('fusebox_clarity: alleen not_assessed → Niet automatisch beoordeeld', function () {
    $uploads = [
        clarityLabelUpload(PhotoContentAssessment::notAssessed(PhotoSubject::Fusebox)),
    ];

    expect(PhotoContinueAnywayAttention::fuseboxClarityReportLabel($uploads))
        ->toBe('Niet automatisch beoordeeld — zie aandachtspunt meterkast.');
});

test('fusebox_clarity: alle foto’s ok → geen override-label', function () {
    $uploads = [
        clarityLabelUpload(PhotoContentAssessment::ok(PhotoSubject::Fusebox)),
        clarityLabelUpload(PhotoContentAssessment::ok(PhotoSubject::Fusebox)),
    ];

    expect(PhotoContinueAnywayAttention::fuseboxClarityReportLabel($uploads))->toBeNull();
});
