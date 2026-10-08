<?php

declare(strict_types=1);

use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Models\AiTrace;
use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\FollowUpProgressCalculator;
use App\Domains\Intake\Support\PhotoCustomerStatus;
use App\Enums\AiRunType;
use App\Enums\AiTraceCallType;
use App\Enums\FollowUpItemType;
use App\Enums\IntakeStatus;
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
        'ai.tracing.enabled' => true,
    ]);
    FakeAiClient::reset();
});

function queuePhotoFixture(string $name = 'buitenunit-leiding.jpeg'): UploadedFile
{
    $fixture = base_path('tests/fixtures/klanttest-20261002/'.$name);
    expect(is_file($fixture))->toBeTrue();

    return UploadedFile::fake()->createWithContent($name, (string) file_get_contents($fixture));
}

function queueBrightPhoto(string $name = 'meterkast.jpg'): UploadedFile
{
    $img = imagecreatetruecolor(1280, 960);
    imagefill($img, 0, 0, imagecolorallocate($img, 220, 220, 210));
    ob_start();
    imagejpeg($img, null, 90);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return UploadedFile::fake()->createWithContent($name, $bytes);
}

function makeQueuePhotoFollowUpIntake(string $decisionAreaKey = 'power'): array
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::InProgress,
        'access_token' => 'queuephoto'.str_repeat('a', 54),
    ]);

    app(DossierManager::class)->initialize($intake);

    $round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [[
        'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een duidelijke foto van de meterkast voor de stroomtoevoer.',
        'decision_area_key' => $decisionAreaKey,
    ]]);

    $item = $round->items()->firstOrFail();
    ContributionTask::query()
        ->where('intake_follow_up_item_id', $item->id)
        ->update(['decision_area_key' => $decisionAreaKey]);

    return [$intake->fresh(), $item->fresh()];
}

test('upload dispatcht AssessUploadedPhotoJob en roept AI niet synchroon aan', function () {
    Queue::fake([AssessUploadedPhotoJob::class]);

    [$intake, $item] = makeQueuePhotoFollowUpIntake();

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertSet('followUpMode', true)
        ->set('followUpPhotoFiles.'.$item->id, queueBrightPhoto())
        ->assertSet('uploadPhase', 'assessing');

    expect(AiRun::query()->where('intake_id', $intake->id)->where('type', AiRunType::PhotoAssessment)->count())->toBe(0);
    Queue::assertPushedOn(AssessUploadedPhotoJob::QUEUE, AssessUploadedPhotoJob::class);
    Queue::assertPushed(AssessUploadedPhotoJob::class, 1);

    $upload = $item->fresh()->uploads()->first();
    expect($upload)->not->toBeNull()
        ->and($upload->usability_verdict)->not->toBeNull()
        ->and($upload->contentAssessment())->toBeNull();
});

test('job schrijft assessment; poll toont resultaat en wrong_subject-feedback', function () {
    Queue::fake([AssessUploadedPhotoJob::class]);

    [$intake, $item] = makeQueuePhotoFollowUpIntake();

    FakeAiClient::alwaysReturn([
        'detected_subject' => 'outdoor_unit',
        'subject_match' => 'no',
        'evidence' => 'Foto toont een buitenunit, geen meterkast.',
    ]);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('followUpPhotoFiles.'.$item->id, queuePhotoFixture())
        ->assertSet('uploadPhase', 'assessing');

    $upload = $item->fresh()->uploads()->firstOrFail();
    runAssessUploadedPhotoJob($upload->id);

    $upload->refresh();
    expect($upload->contentAssessment()?->status())->toBe(PhotoContentAssessment::STATUS_WRONG_SUBJECT);

    $component->call('pollPendingAssessments')
        ->assertSet('uploadPhase', '')
        ->assertSee('Nog te vervangen')
        ->assertSee('Toch versturen');

    $progress = app(FollowUpProgressCalculator::class)->calculate(collect([$item->fresh()->load('uploads')]));
    expect($progress['percent'])->toBe(0)
        ->and($progress['item_statuses'][$item->id]['status'])->toBe('mismatch');
});

test('AI-fout of timeout leidt tot not_assessed soft-fail met klanttekst', function () {
    Queue::fake([AssessUploadedPhotoJob::class]);

    [$intake, $item] = makeQueuePhotoFollowUpIntake();

    FakeAiClient::alwaysFail('Simulated provider timeout');

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('followUpPhotoFiles.'.$item->id, queueBrightPhoto())
        ->assertSet('uploadPhase', 'assessing');

    $upload = $item->fresh()->uploads()->firstOrFail();
    runAssessUploadedPhotoJob($upload->id);

    $upload->refresh();
    expect($upload->contentAssessment()?->status())->toBe(PhotoContentAssessment::STATUS_NOT_ASSESSED)
        ->and($upload->assessment_status?->value)->toBe('not_assessed')
        ->and($upload->contentAssessment()?->customerMessage())
        ->toBe('We konden je foto nu niet automatisch beoordelen; de installateur kijkt mee.');

    // Per-thumb status is "Foto ontvangen." for not_assessed; override UI stays.
    $component->call('pollPendingAssessments')
        ->assertSet('uploadPhase', '')
        ->assertSee(PhotoCustomerStatus::RECEIVED)
        ->assertSee('Vervang foto')
        ->assertSee('Toch versturen')
        ->assertSee('Nieuwe foto nodig');

    $progress = app(FollowUpProgressCalculator::class)->calculate(collect([$item->fresh()->load('uploads')]));
    expect($progress['percent'])->toBe(0)
        ->and($progress['item_statuses'][$item->id]['status'])->toBe('unusable')
        ->and($progress['item_statuses'][$item->id]['label'])->toBe('Nieuwe foto nodig');
});

test('follow-up foto-assessment schrijft precies één complete follow_up_photo_subject-trace per ai_run', function () {
    [$intake, $item] = makeQueuePhotoFollowUpIntake();

    FakeAiClient::alwaysReturn([
        'detected_subject' => 'fusebox',
        'subject_match' => 'yes',
        'evidence' => 'Meterkast zichtbaar.',
    ]);

    // Sync queue: job runt in de uploadrequest.
    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('followUpPhotoFiles.'.$item->id, queueBrightPhoto())
        ->call('pollPendingAssessments');

    $runs = AiRun::query()
        ->where('intake_id', $intake->id)
        ->where('type', AiRunType::PhotoAssessment)
        ->get();

    expect($runs)->not->toBeEmpty();

    foreach ($runs as $run) {
        $traces = AiTrace::query()
            ->where('ai_run_id', $run->id)
            ->where('call_type', AiTraceCallType::FollowUpPhotoSubject)
            ->get();

        expect($traces)->toHaveCount(1)
            ->and($traces->first()->model)->not->toBeNull()
            ->and($traces->first()->upload_id)->not->toBeNull();
    }

    // Geen orphan follow-up-trace zonder ai_run van upload-persist.
    $orphans = AiTrace::query()
        ->where('intake_id', $intake->id)
        ->where('call_type', AiTraceCallType::FollowUpPhotoSubject)
        ->whereNull('ai_run_id')
        ->count();

    expect($orphans)->toBe(0);
});
