<?php

declare(strict_types=1);

use App\Domains\AI\Eval\FactScorer;
use App\Domains\AI\Eval\PhotoFeedbackScorer;
use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;

function photoFeedbackScorer(): PhotoFeedbackScorer
{
    return new PhotoFeedbackScorer(new FactScorer);
}

test('N16 root cause: drain_photo IndoorUnit mismatch uses outdoor-unit place copy', function () {
    $msg = PhotoSubject::OutdoorLocation->mismatchMessage(PhotoSubject::IndoorUnit, 'drain_photo');

    expect($msg)->toBe(
        'Dit is een binnenunit; we hebben een foto van de gevel, tuin of buitenplek voor de unit (zonder bestaande airco is prima) nodig.'
    );
});

test('N16 derive path: room + subject_match=no on drain_photo yields OutdoorLocation wrong_subject message', function () {
    $accepted = PhotoSubject::acceptedSubjectsForPhotoQuestion('drain_photo', 'drain');
    $assessment = PhotoContentAssessment::fromModelOutput(
        PhotoSubject::OutdoorLocation,
        [
            'detected_subject' => 'room',
            'subject_match' => 'no',
            'retake_instruction' => null,
        ],
        $accepted,
        'drain_photo',
    );

    expect($assessment->status())->toBe(PhotoContentAssessment::STATUS_WRONG_SUBJECT)
        ->and($assessment->customerMessage())->toContain('buitenplek voor de unit')
        ->and($assessment->customerMessage())->not->toContain('afvoer');
});

test('photo_feedback scorer fails current drain outdoor copy against afvoer expectation', function () {
    $fixture = json_decode(
        (string) file_get_contents(__DIR__.'/../../Eval/fixtures/photo_feedback/pf-n16-drain-livingroom-as-indoor.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $scores = photoFeedbackScorer()->score($fixture);

    expect($scores['customer_message']['status'])->toBe('wrong')
        ->and($scores['customer_message']['forbidden_hits'])->toContain('buitenplek voor de unit')
        ->and($scores['customer_message']['missing'])->toContain('afvoer');
});

test('photo_feedback scorer passes correct downspout control', function () {
    $fixture = json_decode(
        (string) file_get_contents(__DIR__.'/../../Eval/fixtures/photo_feedback/pf-n16-drain-correct-downspout.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $scores = photoFeedbackScorer()->score($fixture);

    expect($scores['assessment_status']['status'])->toBe('correct')
        ->and($scores['customer_message']['status'])->toBe('correct');
});

test('photo_feedback scorer would pass after drain-specific message fix', function () {
    $fixture = json_decode(
        (string) file_get_contents(__DIR__.'/../../Eval/fixtures/photo_feedback/pf-n16-drain-livingroom-as-indoor.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $fixed = 'Dit is een binnenunit; we hebben een foto van de afvoerplek (regenpijp, putje of goot) nodig.';
    $scores = photoFeedbackScorer()->score($fixture, $fixed);

    expect($scores['customer_message']['status'])->toBe('correct')
        ->and($scores['customer_message']['missing'])->toBe([])
        ->and($scores['customer_message']['forbidden_hits'])->toBe([]);
});
