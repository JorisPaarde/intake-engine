<?php

declare(strict_types=1);

use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Jobs\ProcessIntakePhotoVariantsJob;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Support\PhotoCustomerStatus;
use App\Enums\FollowUpItemType;
use App\Enums\IntakeStatus;
use App\Enums\PhotoAssessmentStatus;
use App\Enums\PhotoUsabilityVerdict;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
    config([
        'ai.provider' => 'fake',
        'ai.photo_inference.enabled' => true,
        'ai.photo_assessment.ui_soft_timeout_seconds' => 15,
    ]);
});

function softTimeoutFollowUpIntake(): array
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::InProgress,
        'access_token' => 'softto'.str_repeat('x', 58),
    ]);

    app(DossierManager::class)->initialize($intake);

    $round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [[
        'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een duidelijke foto van de meterkast voor de stroomtoevoer.',
        'decision_area_key' => 'power',
    ]]);

    $item = $round->items()->firstOrFail();
    ContributionTask::query()
        ->where('intake_follow_up_item_id', $item->id)
        ->update(['decision_area_key' => 'power']);

    return [$intake->fresh(), $item->fresh()];
}

test('follow-up soft-timeout na 15s toont UX-melding en laat Volgende toe', function () {
    Queue::fake([AssessUploadedPhotoJob::class, ProcessIntakePhotoVariantsJob::class]);

    [$intake, $item] = softTimeoutFollowUpIntake();

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('followUpPhotoFiles.'.$item->id, UploadedFile::fake()->image('dak.jpg', 800, 600))
        ->assertSet('uploadPhase', 'assessing');

    $upload = $item->fresh()->uploads()->firstOrFail();
    expect($upload->assessment_status)->toBe(PhotoAssessmentStatus::Pending);

    $component->set('uploadPhaseStartedAt', now()->subSeconds(16)->getTimestamp());
    $upload->forceFill(['assessment_queued_at' => now()->subSeconds(16)])->save();

    $component
        ->call('pollPendingAssessments')
        ->assertSet('uploadPhase', '')
        ->assertSee(PhotoCustomerStatus::SOFT_TIMEOUT);

    // Soft-release: Volgende mag door ondanks pending assessment.
    $component->call('nextFollowUp')->assertHasNoErrors('follow_up');
});

test('follow-up herlaadt actieve onderdeel via sessie', function () {
    Queue::fake([AssessUploadedPhotoJob::class, ProcessIntakePhotoVariantsJob::class]);
    [$intake, $first] = softTimeoutFollowUpIntake();
    $round = $intake->followUpRounds()->latest('round_number')->firstOrFail();

    $round->items()->create([
        'type' => $first->type,
        'prompt' => 'Tweede opdracht voor plat dak',
    ]);

    // Seed satisfied first item with a terminal upload so nextFollowUp may advance.
    $first->uploads()->create([
        'intake_id' => $intake->id,
        'question_key' => 'follow_up_photo',
        'disk' => (string) config('filesystems.media', 'local'),
        'path' => 'intakes/test/soft-1.jpg',
        'original_filename' => 'soft-1.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 1000,
        'checksum' => hash('sha256', 'soft-1'),
        'sort_order' => 1,
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
        'content_assessment' => [
            'status' => 'ok',
            'detected_subject' => 'fusebox',
            'customer_message' => null,
        ],
    ]);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertSet('followUpStepIndex', 0)
        ->call('nextFollowUp')
        ->assertSet('followUpStepIndex', 1);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertSet('followUpStepIndex', 1)
        ->assertSee('Tweede opdracht voor plat dak');
});

test('follow-up slaat niet-foto over met Nederlandse melding', function () {
    Queue::fake([AssessUploadedPhotoJob::class, ProcessIntakePhotoVariantsJob::class]);
    [$intake, $item] = softTimeoutFollowUpIntake();

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('followUpPhotoFiles.'.$item->id, [
            UploadedFile::fake()->image('goed.jpg', 640, 480),
            UploadedFile::fake()->create('readme.txt', 1, 'text/plain'),
        ])
        ->assertSee('readme.txt is geen foto en is niet meegenomen');

    expect($item->fresh()->uploads()->count())->toBe(1);
});

test('PhotoCustomerStatus labels matchen UX-teksten', function () {
    expect(PhotoCustomerStatus::LOOKING)->toBe('We bekijken je foto…')
        ->and(PhotoCustomerStatus::GOOD)->toBe('Goed te zien. Dank je.')
        ->and(PhotoCustomerStatus::RECEIVED)->toBe('Foto ontvangen.')
        ->and(PhotoCustomerStatus::SOFT_TIMEOUT)->toBe('Dit duurt langer dan normaal. Je kunt alvast verder.')
        ->and(PhotoCustomerStatus::UPLOADING)->toBe('Foto uploaden…');
});
