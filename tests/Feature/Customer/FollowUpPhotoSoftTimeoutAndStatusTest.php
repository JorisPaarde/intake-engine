<?php

declare(strict_types=1);

use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\AI\Jobs\SuggestAttentionPointsJob;
use App\Domains\AI\Jobs\SynthesizeSurveyDossierJob;
use App\Domains\Intake\Actions\CompleteFollowUpRound;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Jobs\GenerateIntakePdfJob;
use App\Domains\Intake\Jobs\ProcessIntakePhotoVariantsJob;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Support\PhotoAssessmentSoftTimeout;
use App\Domains\Intake\Support\PhotoCustomerStatus;
use App\Enums\FollowUpItemType;
use App\Enums\FollowUpRoundStatus;
use App\Enums\IntakeStatus;
use App\Enums\PhotoAssessmentStatus;
use App\Enums\PhotoUsabilityVerdict;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
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

afterEach(function () {
    Carbon::setTestNow();
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
    $upload->forceFill([
        'created_at' => now()->subSeconds(16),
        'assessment_queued_at' => now()->subSeconds(16),
    ])->save();

    $component
        ->call('pollPendingAssessments', (string) $item->id)
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

test('mixed photos show advice under the bad thumb and keep GOOD on the good one', function () {
    Queue::fake([AssessUploadedPhotoJob::class, ProcessIntakePhotoVariantsJob::class]);
    [$intake, $item] = softTimeoutFollowUpIntake();

    $good = $item->uploads()->create([
        'intake_id' => $intake->id,
        'question_key' => 'follow_up_photo',
        'disk' => (string) config('filesystems.media', 'local'),
        'path' => 'intakes/test/good.jpg',
        'original_filename' => 'good.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 1000,
        'checksum' => hash('sha256', 'good'),
        'sort_order' => 1,
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
        'content_assessment' => [
            'status' => 'ok',
            'detected_subject' => 'fusebox',
            'customer_message' => null,
        ],
    ]);
    $bad = $item->uploads()->create([
        'intake_id' => $intake->id,
        'question_key' => 'follow_up_photo',
        'disk' => (string) config('filesystems.media', 'local'),
        'path' => 'intakes/test/bad.jpg',
        'original_filename' => 'bad.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 500,
        'checksum' => hash('sha256', 'bad'),
        'sort_order' => 2,
        'assessment_status' => PhotoAssessmentStatus::HeuristicRejected,
        'usability_verdict' => PhotoUsabilityVerdict::TooSmall,
        'content_assessment' => null,
    ]);

    $html = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])->html();

    expect($html)->toContain(PhotoCustomerStatus::GOOD)
        ->and($html)->toContain((string) PhotoUsabilityVerdict::TooSmall->customerHint())
        ->and($html)->toContain('data-testid="photo-replace-one"')
        ->and($html)->toContain('data-upload-id="'.$bad->id.'"')
        ->and($html)->toContain('data-upload-id="'.$good->id.'"');
});

test('bij maximum aantal foto\'s blijft poll actief en soft-timeout-melding zichtbaar', function () {
    Queue::fake([AssessUploadedPhotoJob::class, ProcessIntakePhotoVariantsJob::class]);
    config(['intake.follow_up.max_photos_per_item' => 2]);

    [$intake, $item] = softTimeoutFollowUpIntake();
    $composite = (string) $item->id;

    // Distinct pixels → distinct checksums (identical fakes would collapse to 1).
    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('followUpPhotoFiles.'.$item->id, [
            UploadedFile::fake()->image('een.jpg', 800, 600),
            UploadedFile::fake()->image('twee.jpg', 801, 601),
        ])
        ->assertSet('uploadPhase', 'assessing');

    expect($item->fresh()->uploads()->count())->toBe(2);

    // Max slots filled → upload-control is gone; poll must still render on the wizard.
    $html = $component->html();
    expect($html)->toContain('data-testid="assessment-poll"')
        ->and($html)->toContain('wire:poll')
        ->and($html)->toContain('pollPendingAssessments')
        ->and($html)->not->toContain("Foto's maken of kiezen");

    $component->set('uploadPhaseStartedAt', now()->subSeconds(16)->getTimestamp());
    foreach ($item->fresh()->uploads as $upload) {
        $upload->forceFill([
            'created_at' => now()->subSeconds(16),
            'assessment_queued_at' => now()->subSeconds(16),
        ])->save();
    }

    $component
        ->call('pollPendingAssessments', $composite)
        ->assertSet('uploadPhase', '')
        ->assertSee(PhotoCustomerStatus::SOFT_TIMEOUT);

    expect($component->instance()->assessmentUiReleased)->toContain($composite)
        ->and($component->instance()->pendingAssessUploadIds[$composite] ?? [])->not->toBeEmpty();
});

test('CompleteFollowUpRound blokkeert jonge pending foto en laat soft-timeout door', function () {
    Queue::fake([
        AssessUploadedPhotoJob::class,
        ProcessIntakePhotoVariantsJob::class,
        SynthesizeSurveyDossierJob::class,
        SuggestAttentionPointsJob::class,
        GenerateIntakePdfJob::class,
    ]);
    config(['ai.photo_assessment.ui_soft_timeout_seconds' => 15]);

    [$intake, $item] = softTimeoutFollowUpIntake();
    $round = $intake->followUpRounds()->latest('round_number')->firstOrFail();

    $young = $item->uploads()->create([
        'intake_id' => $intake->id,
        'question_key' => 'follow_up_photo',
        'disk' => (string) config('filesystems.media', 'local'),
        'path' => 'intakes/test/young.jpg',
        'original_filename' => 'young.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 1000,
        'checksum' => hash('sha256', 'young-soft-timeout'),
        'sort_order' => 1,
        'assessment_status' => PhotoAssessmentStatus::Pending,
        'assessment_queued_at' => now()->subSeconds(5),
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
        'created_at' => now()->subSeconds(5),
    ]);

    expect(fn () => app(CompleteFollowUpRound::class)->handle(
        $intake->fresh(),
        $round->fresh()->load('items.uploads'),
        [],
    ))->toThrow(
        ValidationException::class,
        'Even geduld: we beoordelen je foto nog.',
    );

    $young->forceFill([
        'created_at' => now()->subSeconds(16),
        // Pipeline-style reset must not re-block after soft-timeout.
        'assessment_queued_at' => now(),
    ])->save();

    app(CompleteFollowUpRound::class)->handle(
        $intake->fresh(),
        $round->fresh()->load('items.uploads'),
        [],
    );

    expect($young->fresh()->assessment_status)->toBe(PhotoAssessmentStatus::Pending)
        ->and($round->fresh()->status)->toBe(FollowUpRoundStatus::Completed);
});

test('soft-timeout gate negeert verse assessment_queued_at als created_at oud genoeg is', function () {
    config(['ai.photo_assessment.ui_soft_timeout_seconds' => 15]);

    $upload = new IntakeUpload;
    $upload->forceFill([
        'assessment_status' => PhotoAssessmentStatus::Pending,
        'created_at' => now()->subSeconds(20),
        'assessment_queued_at' => now(),
    ]);

    expect(PhotoAssessmentSoftTimeout::hasElapsed($upload))->toBeTrue()
        ->and(PhotoAssessmentSoftTimeout::blocksCustomerProgress($upload))->toBeFalse();
});

test('wizard follow-up gate volgt soft-timeout-leeftijd (niet alleen assessmentUiReleased)', function () {
    Queue::fake([AssessUploadedPhotoJob::class, ProcessIntakePhotoVariantsJob::class]);
    config(['ai.photo_assessment.ui_soft_timeout_seconds' => 15]);

    [$intake, $item] = softTimeoutFollowUpIntake();

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('followUpPhotoFiles.'.$item->id, UploadedFile::fake()->image('gate.jpg', 820, 620))
        ->assertSet('uploadPhase', 'assessing')
        ->set('assessmentUiReleased', []);

    $upload = $item->fresh()->uploads()->firstOrFail();
    expect($upload->assessment_status)->toBe(PhotoAssessmentStatus::Pending);

    // Just uploaded → younger than soft-timeout → blocked.
    $component->call('nextFollowUp')->assertSee('Even geduld: we beoordelen je foto nog.');

    // Past soft-timeout → may continue while assessment stays pending.
    // Fresh assessment_queued_at (pipeline reset) must not re-block.
    IntakeUpload::query()->whereKey($upload->id)->update([
        'created_at' => now()->subSeconds(20),
        'assessment_queued_at' => now(),
    ]);

    expect(PhotoAssessmentSoftTimeout::blocksCustomerProgress($upload->fresh()))->toBeFalse();

    $component->call('nextFollowUp')->assertHasNoErrors('follow_up');
    expect($upload->fresh()->assessment_status)->toBe(PhotoAssessmentStatus::Pending);
});

test('nieuwe upload wist assessmentUiReleased zodat soft-timeout opnieuw loopt', function () {
    Queue::fake([AssessUploadedPhotoJob::class, ProcessIntakePhotoVariantsJob::class]);

    [$intake, $item] = softTimeoutFollowUpIntake();
    $composite = (string) $item->id;

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('followUpPhotoFiles.'.$item->id, UploadedFile::fake()->image('eerste.jpg', 800, 600))
        ->assertSet('uploadPhase', 'assessing');

    $first = $item->fresh()->uploads()->firstOrFail();
    $component->set('uploadPhaseStartedAt', now()->subSeconds(16)->getTimestamp());
    $first->forceFill([
        'created_at' => now()->subSeconds(16),
        'assessment_queued_at' => now()->subSeconds(16),
    ])->save();

    $component
        ->call('pollPendingAssessments', $composite)
        ->assertSet('uploadPhase', '');

    expect($component->instance()->assessmentUiReleased)->toContain($composite);

    // Vervang foto: soft-timeout clock must restart (not immediately "ontvangen").
    $component
        ->set('followUpPhotoFiles.'.$item->id, UploadedFile::fake()->image('tweede.jpg', 800, 600))
        ->assertSet('uploadPhase', 'assessing');

    expect($component->instance()->assessmentUiReleased)->not->toContain($composite)
        ->and($component->instance()->uploadPhaseStartedAt)->not->toBeNull();
});

test('failed upload phase blijft staan na poll; assessing zonder pending wordt wel opgeruimd', function () {
    Queue::fake([AssessUploadedPhotoJob::class, ProcessIntakePhotoVariantsJob::class]);

    [$intake, $item] = softTimeoutFollowUpIntake();
    $composite = (string) $item->id;

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $wizard = $component->instance();
    $wizard->uploadPhaseComposite = $composite;
    $wizard->pendingAssessUploadIds = [];

    $setPhase = new ReflectionMethod(IntakeWizard::class, 'setUploadPhase');
    $setPhase->invoke($wizard, 'failed', 'Uploaden mislukt. Je eerdere antwoorden blijven bewaard.');

    $wizard->pollPendingAssessments($composite);
    expect($wizard->uploadPhase)->toBe('failed')
        ->and($wizard->uploadPhaseMessage)->toContain('Uploaden mislukt');

    // Blade: failed is not a poll trigger (no endless clear of the retry panel).
    $followUpBlade = (string) file_get_contents(resource_path('views/livewire/customer/follow-up-wizard.blade.php'));
    expect($followUpBlade)->toContain("\$uploadPhase ?? '') === 'assessing'")
        ->and($followUpBlade)->not->toContain("['assessing', 'failed']");

    $setPhase->invoke($wizard, 'assessing', PhotoCustomerStatus::LOOKING);
    $wizard->pollPendingAssessments($composite);
    expect($wizard->uploadPhase)->toBe('');
});

test('poll stopt op soft-released item zodra foto\'s terminaal zijn (per composite)', function () {
    Queue::fake([
        AssessUploadedPhotoJob::class,
        ProcessIntakePhotoVariantsJob::class,
        SynthesizeSurveyDossierJob::class,
        SuggestAttentionPointsJob::class,
        GenerateIntakePdfJob::class,
    ]);

    [$intake, $first] = softTimeoutFollowUpIntake();
    $round = $intake->followUpRounds()->latest('round_number')->firstOrFail();
    $second = $round->items()->create([
        'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een foto van de buitenunit.',
    ]);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('followUpPhotoFiles.'.$first->id, UploadedFile::fake()->image('item1.jpg', 810, 610))
        ->assertSet('uploadPhase', 'assessing');

    $firstUpload = $first->fresh()->uploads()->firstOrFail();
    $firstComposite = (string) $first->id;
    $component->set('uploadPhaseStartedAt', now()->subSeconds(16)->getTimestamp());
    $firstUpload->forceFill([
        'created_at' => now()->subSeconds(16),
        'assessment_queued_at' => now()->subSeconds(16),
    ])->save();

    $component
        ->call('pollPendingAssessments', $firstComposite)
        ->assertSet('uploadPhase', '');

    expect($component->instance()->assessmentUiReleased)->toContain($firstComposite)
        ->and($component->instance()->pendingAssessUploadIds[$firstComposite] ?? [])->not->toBeEmpty();

    // Soft-release unlocks next; upload on item 2 until terminal.
    $component->call('nextFollowUp')->assertSet('followUpStepIndex', 1);

    $component
        ->set('followUpPhotoFiles.'.$second->id, UploadedFile::fake()->image('item2.jpg', 820, 620))
        ->assertSet('uploadPhase', 'assessing');

    $secondUpload = $second->fresh()->uploads()->firstOrFail();
    $secondComposite = (string) $second->id;
    $secondUpload->forceFill([
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
        'content_assessment' => [
            'status' => 'ok',
            'detected_subject' => 'outdoor_unit',
            'customer_message' => null,
        ],
        'assessment_queued_at' => null,
    ])->save();

    $component->call('pollPendingAssessments', $secondComposite);
    expect($component->instance()->pendingAssessUploadIds[$secondComposite] ?? [])->toBeEmpty();

    // Item 1 finishes in the background while customer was on item 2.
    $firstUpload->forceFill([
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
        'content_assessment' => [
            'status' => 'ok',
            'detected_subject' => 'fusebox',
            'customer_message' => null,
        ],
        'assessment_queued_at' => null,
    ])->save();

    $component->call('previousFollowUp')->assertSet('followUpStepIndex', 0);
    expect($component->html())->toContain('data-testid="assessment-poll"')
        ->and($component->html())->toContain('data-poll-composite="'.$firstComposite.'"');

    $component->call('pollPendingAssessments', $firstComposite);

    expect($component->instance()->pendingAssessUploadIds[$firstComposite] ?? [])->toBeEmpty()
        ->and($component->html())->not->toContain('data-testid="assessment-poll"');
});

test('nieuwe upload op andere follow-up composite start verse soft-timeout-klok', function () {
    Queue::fake([AssessUploadedPhotoJob::class, ProcessIntakePhotoVariantsJob::class]);

    [$intake, $first] = softTimeoutFollowUpIntake();
    $round = $intake->followUpRounds()->latest('round_number')->firstOrFail();
    $second = $round->items()->create([
        'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een foto van de buitenunit.',
    ]);

    // Satisfy item 1 so we can leave it without soft-timeout block.
    $first->uploads()->create([
        'intake_id' => $intake->id,
        'question_key' => 'follow_up_photo',
        'disk' => (string) config('filesystems.media', 'local'),
        'path' => 'intakes/test/clock-1.jpg',
        'original_filename' => 'clock-1.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 1000,
        'checksum' => hash('sha256', 'clock-1'),
        'sort_order' => 1,
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
        'content_assessment' => [
            'status' => 'ok',
            'detected_subject' => 'fusebox',
            'customer_message' => null,
        ],
        'created_at' => now()->subSeconds(30),
    ]);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->call('nextFollowUp')
        ->assertSet('followUpStepIndex', 1)
        ->set('followUpPhotoFiles.'.$second->id, UploadedFile::fake()->image('item2-clock.jpg', 830, 630))
        ->assertSet('uploadPhase', 'assessing');

    // Stale clock from item 2 (as if assessing for >15s).
    $component->set('uploadPhaseStartedAt', now()->subSeconds(20)->getTimestamp());

    $component->call('previousFollowUp')->assertSet('followUpStepIndex', 0);

    // More than 15s after item-2 assessing started: new photo on item 1 must not soft-timeout immediately.
    $component
        ->set('followUpPhotoFiles.'.$first->id, UploadedFile::fake()->image('item1-nieuw.jpg', 840, 640))
        ->assertSet('uploadPhase', 'assessing')
        ->assertSet('uploadPhaseMessage', PhotoCustomerStatus::LOOKING);

    expect($component->instance()->uploadPhaseStartedAt)->toBeGreaterThan(now()->subSeconds(5)->getTimestamp())
        ->and($component->get('saveMessage'))->not->toBe(PhotoCustomerStatus::SOFT_TIMEOUT);

    $component->call('pollPendingAssessments', (string) $first->id);

    expect($component->get('uploadPhase'))->toBe('assessing')
        ->and($component->instance()->assessmentUiReleased)->not->toContain((string) $first->id)
        ->and($component->get('saveMessage'))->not->toBe(PhotoCustomerStatus::SOFT_TIMEOUT);
});

test('herladen seed soft-timeout-klok vanaf created_at van pending foto', function () {
    Queue::fake([AssessUploadedPhotoJob::class, ProcessIntakePhotoVariantsJob::class]);
    config(['ai.photo_assessment.ui_soft_timeout_seconds' => 15]);

    [$intake, $item] = softTimeoutFollowUpIntake();
    $composite = (string) $item->id;

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('followUpPhotoFiles.'.$item->id, UploadedFile::fake()->image('reload.jpg', 850, 650))
        ->assertSet('uploadPhase', 'assessing');

    $upload = $item->fresh()->uploads()->firstOrFail();
    $upload->forceFill([
        'created_at' => now()->subSeconds(60),
        'assessment_queued_at' => now()->subSeconds(60),
    ])->save();

    // Reload: recover seeds uploadPhaseStartedAt from created_at (T-60), not now().
    $reloaded = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);

    expect($reloaded->get('uploadPhase'))->toBe('assessing')
        ->and($reloaded->instance()->uploadPhaseStartedAt)->toBeLessThanOrEqual(now()->subSeconds(50)->getTimestamp());

    $reloaded
        ->call('pollPendingAssessments', $composite)
        ->assertSet('uploadPhase', '')
        ->assertSee(PhotoCustomerStatus::SOFT_TIMEOUT);
});

test('poll B→A→B houdt soft-timeout-deadline op created_at(B)+15s', function () {
    Queue::fake([AssessUploadedPhotoJob::class, ProcessIntakePhotoVariantsJob::class]);
    config([
        'ai.provider' => 'fake',
        'ai.photo_inference.enabled' => true,
        'ai.photo_assessment.ui_soft_timeout_seconds' => 15,
    ]);

    $t0 = now()->startOfSecond();
    Carbon::setTestNow($t0);

    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::InProgress,
        'access_token' => 'clockbab'.str_repeat('b', 56),
    ]);
    app(DossierManager::class)->initialize($intake);

    $version->load(['sections.questions']);
    $photoQuestions = [];
    foreach ($version->sections as $section) {
        foreach ($section->questions as $question) {
            if (($question->meta['photo_analysis'] ?? null) !== null) {
                $photoQuestions[] = $question->key;
            }
        }
    }
    expect(count($photoQuestions))->toBeGreaterThanOrEqual(2);

    $stepA = $photoQuestions[0];
    $stepB = $photoQuestions[1];

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('photoFiles.'.$stepB, UploadedFile::fake()->image('slow-b.jpg', 880, 680))
        ->assertSet('uploadPhase', 'assessing')
        ->assertSet('uploadPhaseComposite', $stepB);

    $uploadB = IntakeUpload::query()
        ->where('intake_id', $intake->id)
        ->where('question_key', $stepB)
        ->firstOrFail();
    $deadlineB = Carbon::parse($uploadB->created_at)->getTimestamp();

    // t=5: upload on A (switches active composite away from B).
    Carbon::setTestNow($t0->copy()->addSeconds(5));
    $component
        ->set('photoFiles.'.$stepA, UploadedFile::fake()->image('slow-a.jpg', 890, 690))
        ->assertSet('uploadPhaseComposite', $stepA);

    // t=8: poll B again — must re-anchor to created_at(B), not now().
    Carbon::setTestNow($t0->copy()->addSeconds(8));
    $component->call('pollPendingAssessments', $stepB);

    expect($component->get('uploadPhase'))->toBe('assessing')
        ->and($component->get('uploadPhaseComposite'))->toBe($stepB)
        ->and($component->instance()->uploadPhaseStartedAt)->toBe($deadlineB)
        ->and($component->instance()->assessmentUiReleased)->not->toContain($stepB);

    // t=14: still before created_at(B)+15 → no soft-timeout.
    Carbon::setTestNow($t0->copy()->addSeconds(14));
    $component->call('pollPendingAssessments', $stepB);
    expect($component->get('uploadPhase'))->toBe('assessing')
        ->and($component->instance()->assessmentUiReleased)->not->toContain($stepB);

    // t=15: soft-timeout fires on B's original deadline.
    Carbon::setTestNow($t0->copy()->addSeconds(15));
    $component
        ->call('pollPendingAssessments', $stepB)
        ->assertSet('uploadPhase', '')
        ->assertSee(PhotoCustomerStatus::SOFT_TIMEOUT);

    expect($component->instance()->assessmentUiReleased)->toContain($stepB);

    Carbon::setTestNow();
});

test('follow-up photo-upload-control heeft unieke wire:key per item', function () {
    Queue::fake([AssessUploadedPhotoJob::class, ProcessIntakePhotoVariantsJob::class]);

    [$intake, $first] = softTimeoutFollowUpIntake();
    $round = $intake->followUpRounds()->latest('round_number')->firstOrFail();
    $second = $round->items()->create([
        'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een foto van de buitenunit.',
    ]);

    $blade = (string) file_get_contents(resource_path('views/livewire/customer/follow-up-wizard.blade.php'));
    expect($blade)->toContain('wire:key="follow-up-photo-control-{{ $item->id }}"');

    // Satisfy first so Volgende reaches item 2; both keys must appear across steps.
    $first->uploads()->create([
        'intake_id' => $intake->id,
        'question_key' => 'follow_up_photo',
        'disk' => (string) config('filesystems.media', 'local'),
        'path' => 'intakes/test/key-1.jpg',
        'original_filename' => 'key-1.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 1000,
        'checksum' => hash('sha256', 'key-1'),
        'sort_order' => 1,
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
        'content_assessment' => [
            'status' => 'ok',
            'detected_subject' => 'fusebox',
            'customer_message' => null,
        ],
    ]);

    $step1 = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    expect($step1->html())->toContain('wire:key="follow-up-photo-control-'.$first->id.'"')
        ->and($step1->html())->toContain('id="follow-up-photo-input-'.$first->id.'"');

    $step2 = $step1->call('nextFollowUp')->assertSet('followUpStepIndex', 1);
    expect($step2->html())->toContain('wire:key="follow-up-photo-control-'.$second->id.'"')
        ->and($step2->html())->toContain('id="follow-up-photo-input-'.$second->id.'"')
        ->and($step2->html())->not->toContain('wire:key="follow-up-photo-control-'.$first->id.'"');
});

test('main wizard: upload op stap B na verstrijken klok A toont LOOKING geen soft-timeout', function () {
    Queue::fake([AssessUploadedPhotoJob::class, ProcessIntakePhotoVariantsJob::class]);
    config([
        'ai.provider' => 'fake',
        'ai.photo_inference.enabled' => true,
        'ai.photo_assessment.ui_soft_timeout_seconds' => 15,
    ]);

    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::InProgress,
        'access_token' => 'clockmain'.str_repeat('m', 55),
    ]);
    app(DossierManager::class)->initialize($intake);

    $version->load(['sections.questions']);
    $photoQuestions = [];
    foreach ($version->sections as $section) {
        foreach ($section->questions as $question) {
            if (($question->meta['photo_analysis'] ?? null) !== null) {
                $photoQuestions[] = $question->key;
            }
        }
    }
    expect(count($photoQuestions))->toBeGreaterThanOrEqual(2);

    $q1 = $photoQuestions[0];
    $q2 = $photoQuestions[1];

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('photoFiles.'.$q1, UploadedFile::fake()->image('stap-a.jpg', 860, 660))
        ->assertSet('uploadPhase', 'assessing');

    // Simulate >15s on step A, then upload on step B (as after Volgende).
    $component->set('uploadPhaseStartedAt', now()->subSeconds(20)->getTimestamp());

    $component
        ->set('photoFiles.'.$q2, UploadedFile::fake()->image('stap-b.jpg', 870, 670))
        ->assertSet('uploadPhase', 'assessing')
        ->assertSet('uploadPhaseMessage', PhotoCustomerStatus::LOOKING)
        ->assertSet('uploadPhaseComposite', $q2);

    expect($component->instance()->uploadPhaseStartedAt)->toBeGreaterThan(now()->subSeconds(5)->getTimestamp())
        ->and($component->get('saveMessage'))->not->toBe(PhotoCustomerStatus::SOFT_TIMEOUT);

    $component->call('pollPendingAssessments', $q2);

    expect($component->get('uploadPhase'))->toBe('assessing')
        ->and($component->get('uploadPhaseMessage'))->toBe(PhotoCustomerStatus::LOOKING)
        ->and($component->instance()->assessmentUiReleased)->not->toContain($q2)
        ->and($component->get('saveMessage'))->not->toBe(PhotoCustomerStatus::SOFT_TIMEOUT);
});
