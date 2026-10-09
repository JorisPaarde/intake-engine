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

it('shows LOOKING while assessing and SOFT_TIMEOUT after soft-timeout', function () {
    $pending = statusUpload([
        'id' => 7,
        'assessment_status' => PhotoAssessmentStatus::Pending,
    ]);

    expect(PhotoCustomerStatus::forUpload($pending, 'fusebox_photo', 'assessing', 'fusebox_photo', [7], []))
        ->toBe(PhotoCustomerStatus::LOOKING)
        ->and(PhotoCustomerStatus::forUpload($pending, 'fusebox_photo', '', 'fusebox_photo', [7], ['fusebox_photo']))
        ->toBe(PhotoCustomerStatus::SOFT_TIMEOUT);
});

it('never shows GOOD after Toch doorgaan on a judged problem', function () {
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
        ->not->toBe(PhotoCustomerStatus::GOOD)
        ->and(PhotoCustomerStatus::forUpload($upload, 'fusebox_photo', '', '', [], []))
        ->toBe((string) $wrong->customerMessage());
});

it('shows RECEIVED for not_assessed before the quality/content issue branch', function () {
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

    expect(PhotoOverridePolicy::hasQualityOrContentIssue($withContent))->toBeTrue()
        ->and(PhotoCustomerStatus::forUpload($withContent, 'fusebox_photo', '', '', [], []))
        ->toBe(PhotoCustomerStatus::RECEIVED)
        ->and(PhotoCustomerStatus::forUpload($statusOnly, 'fusebox_photo', '', '', [], []))
        ->toBe(PhotoCustomerStatus::RECEIVED)
        ->and(PhotoCustomerStatus::forUpload($withContent, 'fusebox_photo', '', '', [], []))
        ->not->toBe(PhotoCustomerStatus::GOOD);
});

it('puts advice under the bad photo in a mixed batch', function () {
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
        ->and($badLabel)->toBe((string) PhotoUsabilityVerdict::TooSmall->customerHint())
        ->and($otherLabel)->toContain('meterkast')
        ->and($badLabel)->not->toBe(PhotoCustomerStatus::RECEIVED)
        ->and($otherLabel)->not->toBe(PhotoCustomerStatus::GOOD);
});
