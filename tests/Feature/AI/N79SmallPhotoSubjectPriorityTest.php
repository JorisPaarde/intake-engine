<?php

declare(strict_types=1);

use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\FollowUpProgressCalculator;
use App\Domains\Intake\Support\PhotoOverridePolicy;
use App\Enums\FollowUpItemType;
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
    config(['ai.provider' => 'fake', 'ai.photo_inference.enabled' => true]);
    FakeAiClient::reset();
    Queue::fake([AssessUploadedPhotoJob::class]);
});

test('N79 small room photo prioritizes subject assessment with quality fallback', function (string $scenario, bool $followUp) {
    config(['ai.photo_inference.enabled' => $scenario !== 'disabled']);
    $wrong = $scenario === 'wrong';
    FakeAiClient::alwaysReturn([
        'detected_subject' => $wrong ? 'other' : 'room',
        'subject_match' => $wrong ? 'no' : 'yes',
        'confidence' => 'high',
        'evidence' => 'Fictief beeldbewijs.',
        'retake_instruction' => null,
        'room_type' => 'living_room',
        'room_size_indication' => 'medium',
        'sun_exposure' => 'unknown',
        'glass_amount' => 'unknown',
        'glazing_type' => 'unknown',
        'room_outlet_status' => 'unknown',
    ]);
    if ($scenario === 'failed') {
        FakeAiClient::alwaysFail();
    }
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create(['intake_template_version_id' => $version->id]);
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    $intake->update(['current_section_key' => 'rooms', 'current_question_key' => 'room_photos', 'current_section_instance_key' => 'room-1']);
    $image = imagecreatetruecolor(800, 600);
    imagefill($image, 0, 0, imagecolorallocate($image, 210, 210, 210));
    ob_start();
    imagejpeg($image);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    $item = null;
    if ($followUp) {
        app(DossierManager::class)->initialize($intake);
        $round = app(CreateCustomerContributionRequest::class)->handle(
            $intake->fresh(),
            User::findOrFail($intake->created_by),
            [['type' => FollowUpItemType::Photo, 'prompt' => 'Maak een foto van de ruimte.', 'decision_area_key' => 'capacity']],
        );
        $item = $round->items()->firstOrFail();
        $intake->refresh();
    }
    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set($item ? 'followUpPhotoFiles.'.$item->id : 'photoFiles.room-1__room_photos',
            $item ? UploadedFile::fake()->createWithContent('cat.jpg', $bytes) : [UploadedFile::fake()->createWithContent('cat.jpg', $bytes)]);
    $upload = $intake->uploads()->firstOrFail();
    expect($upload->usability_verdict)->toBe(PhotoUsabilityVerdict::TooSmall);

    if ($scenario === 'disabled') {
        Queue::assertNotPushed(AssessUploadedPhotoJob::class);
    } else {
        expect($upload->assessment_status)->toBe(PhotoAssessmentStatus::Pending)
            ->and(PhotoOverridePolicy::needsOverride($upload))->toBeFalse();
        Queue::assertPushed(AssessUploadedPhotoJob::class);
        runAssessUploadedPhotoJob($upload->id);
        expect(FakeAiClient::lastRequest())->not->toBeNull();
    }
    $upload->refresh();
    $component->call('pollPendingAssessments');
    expect(PhotoOverridePolicy::needsOverride($upload))->toBeTrue();
    if ($item) {
        expect(app(FollowUpProgressCalculator::class)->itemStatus($item->fresh()))
            ->toBe($wrong ? 'mismatch' : 'unusable');
    }
    if ($wrong) {
        expect($upload->contentAssessment()?->status())->toBe(PhotoContentAssessment::STATUS_WRONG_SUBJECT)
            ->and(PhotoOverridePolicy::customerFeedback($upload))->not->toContain('lage resolutie')
            ->and($upload->feedbackUsabilityVerdict())->toBeNull()
            ->and($upload->contentAssessment()?->installerLabel())->toContain('AI: lijkt');
        $component->assertSee($upload->contentAssessment()->customerMessage())->assertDontSee('lage resolutie');
    } else {
        expect(PhotoOverridePolicy::customerFeedback($upload))->toContain('lage resolutie')
            ->and($upload->feedbackUsabilityVerdict())->toBe(PhotoUsabilityVerdict::TooSmall);
        $component->assertSee('lage resolutie');
        if (in_array($scenario, ['disabled', 'failed'], true)) {
            expect($upload->assessment_status)->toBe(PhotoAssessmentStatus::HeuristicRejected);
        }
    }
})->with(['wrong', 'matching', 'disabled', 'failed'])->with(['intake' => false, 'follow-up' => true]);
