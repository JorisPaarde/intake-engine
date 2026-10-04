<?php

declare(strict_types=1);

use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\PhotoAssessmentStatus;
use App\Enums\PhotoUsabilityVerdict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Run AssessUploadedPhotoJob synchronously with container-resolved handle() deps.
 * Prefer this over positional handle() arguments so new DI parameters do not break tests.
 */
function runAssessUploadedPhotoJob(int $uploadId, ?string $correlationId = null, ?float $dispatchedAt = null): void
{
    $job = new AssessUploadedPhotoJob($uploadId, $correlationId, $dispatchedAt);

    app()->call([$job, 'handle']);
}

/**
 * Mark a stored upload as terminaal beoordeeld/geaccepteerd zodat
 * PhotoContentSatisfaction/ProgressCalculator hem als gedaan tellen in fill-helpers.
 */
function markTestUploadSatisfied(IntakeUpload $upload): IntakeUpload
{
    $upload->forceFill([
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'content_assessment' => PhotoContentAssessment::ok(
            PhotoSubject::Other,
        )->toArray(),
    ])->save();

    return $upload->fresh();
}
