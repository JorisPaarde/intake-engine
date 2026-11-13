<?php

declare(strict_types=1);

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Support\PhotoCustomerStatus;
use App\Domains\Intake\Support\PhotoOverridePolicy;
use App\Enums\PhotoAssessmentStatus;
use App\Enums\PhotoUsabilityVerdict;

function statusUpload(array $attrs = []): IntakeUpload
{
    $upload = new IntakeUpload;
    $upload->forceFill(array_merge([
        'id' => 1,
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
        'content_assessment' => null,
    ], $attrs));

    return $upload;
}

it('shows LOOKING while assessing and RECEIVED after soft-timeout', function () {
    $pending = statusUpload([
        'id' => 7,
        'assessment_status' => PhotoAssessmentStatus::Pending,
    ]);

    expect(PhotoCustomerStatus::forUpload($pending, 'fusebox_photo', 'assessing', 'fusebox_photo', [7], []))
        ->toBe(PhotoCustomerStatus::LOOKING)
        ->and(PhotoCustomerStatus::forUpload($pending, 'fusebox_photo', '', 'fusebox_photo', [7], ['fusebox_photo']))
        ->toBe(PhotoCustomerStatus::RECEIVED);
});

it('never revives LOOKING when the DB status is already terminal', function () {
    $terminal = statusUpload([
        'id' => 9,
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'content_assessment' => PhotoContentAssessment::wrongSubject(
            PhotoSubject::Fusebox,
            PhotoSubject::Room,
        )->toArray(),
    ]);

    expect(PhotoCustomerStatus::forUpload(
        $terminal,
        'fusebox_photo',
        'assessing',
        'fusebox_photo',
        [9],
        [],
    ))->toBe(PhotoCustomerStatus::WRONG_SUBJECT)
        ->and(PhotoCustomerStatus::forUpload(
            $terminal,
            'fusebox_photo',
            'assessing',
            'fusebox_photo',
            [9],
            [],
        ))->not->toBe(PhotoCustomerStatus::LOOKING);
});

it('shows OVERRIDE_ACCEPTED after Toch doorgaan on a judged problem', function () {
    $wrong = PhotoContentAssessment::wrongSubject(PhotoSubject::Fusebox, PhotoSubject::Room)
        ->withCustomerAcceptedOverride();

    $upload = statusUpload([
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
        'content_assessment' => $wrong->toArray(),
    ]);

    expect(PhotoOverridePolicy::needsOverride($upload))->toBeFalse()
        ->and(PhotoOverridePolicy::hasQualityOrContentIssue($upload))->toBeTrue()
        ->and(PhotoCustomerStatus::forUpload($upload, 'fusebox_photo', '', '', [], []))
        ->toBe(PhotoCustomerStatus::OVERRIDE_ACCEPTED)
        ->and(PhotoCustomerStatus::forUpload($upload, 'fusebox_photo', '', '', [], []))
        ->not->toBe(PhotoCustomerStatus::GOOD);
});

it('shows RECEIVED for not_assessed and does not require override', function () {
    $withContent = statusUpload([
        'assessment_status' => PhotoAssessmentStatus::NotAssessed,
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
        'content_assessment' => PhotoContentAssessment::notAssessed(PhotoSubject::Fusebox)->toArray(),
    ]);
    $statusOnly = statusUpload([
        'assessment_status' => PhotoAssessmentStatus::NotAssessed,
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
        'content_assessment' => null,
    ]);

    expect(PhotoOverridePolicy::hasQualityOrContentIssue($withContent))->toBeFalse()
        ->and(PhotoOverridePolicy::needsOverride($withContent))->toBeFalse()
        ->and(PhotoCustomerStatus::forUpload($withContent, 'fusebox_photo', '', '', [], []))
        ->toBe(PhotoCustomerStatus::RECEIVED)
        ->and(PhotoCustomerStatus::forUpload($statusOnly, 'fusebox_photo', '', '', [], []))
        ->toBe(PhotoCustomerStatus::RECEIVED)
        ->and(PhotoCustomerStatus::forUpload($withContent, 'fusebox_photo', '', '', [], []))
        ->not->toBe(PhotoCustomerStatus::GOOD);
});

it('still requires override for TooDark even when assessment_status is not_assessed', function () {
    $dark = statusUpload([
        'assessment_status' => PhotoAssessmentStatus::NotAssessed,
        'usability_verdict' => PhotoUsabilityVerdict::TooDark,
        'content_assessment' => PhotoContentAssessment::notAssessed(PhotoSubject::Room)->toArray(),
    ]);

    expect(PhotoOverridePolicy::needsOverride($dark))->toBeTrue()
        ->and(PhotoCustomerStatus::forUpload($dark, 'room_photos', '', '', [], []))
        ->toBe(PhotoCustomerStatus::UNCLEAR);
});

it('puts short status on the bad photo and keeps GOOD on the good one', function () {
    $good = statusUpload([
        'id' => 1,
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
        'content_assessment' => PhotoContentAssessment::ok(PhotoSubject::Fusebox)->toArray(),
    ]);
    $bad = statusUpload([
        'id' => 2,
        'assessment_status' => PhotoAssessmentStatus::HeuristicRejected,
        'usability_verdict' => PhotoUsabilityVerdict::TooSmall,
        'content_assessment' => null,
    ]);
    $otherBad = statusUpload([
        'id' => 3,
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'content_assessment' => PhotoContentAssessment::wrongSubject(
            PhotoSubject::Fusebox,
            PhotoSubject::Room,
        )->toArray(),
    ]);

    $goodLabel = PhotoCustomerStatus::forUpload($good, 'fusebox_photo', '', '', [], []);
    $badLabel = PhotoCustomerStatus::forUpload($bad, 'fusebox_photo', '', '', [], []);
    $otherLabel = PhotoCustomerStatus::forUpload($otherBad, 'fusebox_photo', '', '', [], []);

    expect($goodLabel)->toBe(PhotoCustomerStatus::GOOD)
        ->and($badLabel)->toBe(PhotoCustomerStatus::UNCLEAR)
        ->and($otherLabel)->toBe(PhotoCustomerStatus::WRONG_SUBJECT)
        ->and($badLabel)->not->toBe(PhotoCustomerStatus::RECEIVED)
        ->and($otherLabel)->not->toBe(PhotoCustomerStatus::GOOD);
});

it('groups feedback by structured status key not by text', function () {
    $a = statusUpload([
        'id' => 1,
        'content_assessment' => PhotoContentAssessment::wrongSubject(
            PhotoSubject::Room,
            PhotoSubject::OutdoorUnit,
        )->toArray(),
    ]);
    $b = statusUpload([
        'id' => 2,
        'content_assessment' => PhotoContentAssessment::wrongSubject(
            PhotoSubject::Room,
            PhotoSubject::OutdoorUnit,
        )->toArray(),
    ]);
    $c = statusUpload([
        'id' => 3,
        'usability_verdict' => PhotoUsabilityVerdict::TooDark,
        'assessment_status' => PhotoAssessmentStatus::HeuristicRejected,
        'content_assessment' => null,
    ]);

    expect(PhotoOverridePolicy::feedbackGroupKey($a))
        ->toBe(PhotoOverridePolicy::feedbackGroupKey($b))
        ->and(PhotoOverridePolicy::feedbackGroupKey($a))
        ->not->toBe(PhotoOverridePolicy::feedbackGroupKey($c))
        ->and(PhotoOverridePolicy::uniqueCustomerFeedback(collect([$a, $b, $c])))
        ->toHaveCount(2)
        ->and(PhotoOverridePolicy::panelHeadingForSubject(PhotoSubject::Room))
        ->toBe(PhotoOverridePolicy::PANEL_HEADING_ONE)
        ->and(PhotoOverridePolicy::panelHeadingForSubject(PhotoSubject::Fusebox))
        ->toBe('Deze foto laat de meterkast nog niet goed zien')
        ->and(PhotoOverridePolicy::defaultTipFor(PhotoSubject::Room))
        ->toContain('deuropening');
});

it('afkeurkader-titel volgt fototype; tip uit feedback of default', function () {
    $blurry = statusUpload([
        'id' => 11,
        'question_key' => 'fusebox_photo',
        'content_assessment' => PhotoContentAssessment::needsClearer(
            PhotoSubject::Fusebox,
            'Maak een scherpere foto van de hoofdschakelaar.',
        )->toArray(),
    ]);
    $good = statusUpload(['id' => 12, 'question_key' => 'fusebox_photo']);
    $wrong = statusUpload([
        'id' => 13,
        'question_key' => 'fusebox_photo',
        'content_assessment' => PhotoContentAssessment::wrongSubject(
            PhotoSubject::Fusebox,
            PhotoSubject::Room,
        )->toArray(),
    ]);
    $roomWrong = statusUpload([
        'id' => 14,
        'question_key' => 'room_photos',
        'content_assessment' => PhotoContentAssessment::wrongSubject(
            PhotoSubject::Room,
            PhotoSubject::OutdoorUnit,
        )->toArray(),
    ]);

    expect(PhotoOverridePolicy::panelHeadingFor(collect([$blurry])))
        ->toBe('Deze foto laat de meterkast nog niet goed zien')
        ->and(PhotoOverridePolicy::panelTipsFor(collect([$blurry]))[0] ?? '')
        ->toContain('scherpere foto')
        ->and(PhotoOverridePolicy::panelHeadingFor(collect([$blurry, $good])))
        ->toBe('Deze foto laat de meterkast nog niet goed zien')
        ->and(PhotoOverridePolicy::panelHeadingFor(collect([$blurry, $wrong])))
        ->toBe('Deze foto’s laten de meterkast nog niet goed zien')
        ->and(PhotoOverridePolicy::panelHeadingFor(collect([$roomWrong])))
        ->toBe(PhotoOverridePolicy::PANEL_HEADING_ONE)
        ->and(PhotoOverridePolicy::PRIMARY_RETRY)->toBe('Nieuwe foto maken')
        ->and(PhotoOverridePolicy::SECONDARY_KEEP)->toBe('Toch deze foto gebruiken');
});
