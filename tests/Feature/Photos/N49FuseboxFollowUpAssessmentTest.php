<?php

declare(strict_types=1);

use App\Domains\AI\Actions\AssessFuseboxPhotos;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\AI\Jobs\SynthesizeSurveyDossierJob;
use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\CompleteFollowUpRound;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\StoreFollowUpUpload;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeExternalFact;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Photos\Actions\RefreshFuseboxAssessmentFromEvidence;
use App\Enums\ContributionMode;
use App\Enums\FollowUpItemType;
use App\Enums\IntakeStatus;
use App\Enums\PhotoAssessmentStatus;
use App\Enums\PhotoUsabilityVerdict;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
    FakeAiClient::reset();
    Mail::fake();
    Queue::fake([AssessUploadedPhotoJob::class, SynthesizeSurveyDossierJob::class]);
    config([
        'ai.provider' => 'fake',
        'ai.photo_inference.enabled' => true,
        'ai.dossier.enabled' => false,
    ]);
});

afterEach(function () {
    FakeAiClient::reset();
});

/** @return array{empty_module_space: string, phase: string, confidence: string, evidence: string, retake_instruction: null, detected_subject: string, subject_match: string} */
function n49FuseboxOutput(string $phase = 'three_phase', string $confidence = 'high'): array
{
    return [
        'empty_module_space' => 'visible',
        'phase' => $phase,
        'confidence' => $confidence,
        'detected_subject' => 'fusebox',
        'subject_match' => 'yes',
        'evidence' => 'Drie gekoppelde hoofdschakelaars zijn zichtbaar.',
        'retake_instruction' => null,
    ];
}

/** @return array{user: User, intake: Intake} */
function n49MakeIntake(): array
{
    $user = User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'N49 Fusebox',
        'customer_email' => 'n49@example.com',
        'address_line' => 'Meterkast 1',
        'address_postal_code' => '1000AA',
        'address_house_number' => 1,
        'address_city' => 'Amsterdam',
    ]);
    app(DossierManager::class)->initialize($intake);
    $intake->update(['status' => IntakeStatus::Sent]);

    return ['user' => $user, 'intake' => $intake->fresh()];
}

function n49SeedStaleWizardFusebox(Intake $intake): IntakeUpload
{
    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('meterkast-blur.jpg', 800, 600),
    );

    $upload->update([
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'content_assessment' => PhotoContentAssessment::needsClearer(
            PhotoSubject::Fusebox,
            'Maak een scherpere foto van de hoofdschakelaar.',
        )->toArray(),
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
    ]);

    IntakeExternalFact::query()->create([
        'intake_id' => $intake->id,
        'fact_key' => 'fusebox_photo_assessment',
        'label' => 'Automatische beoordeling meterkastfoto',
        'value' => [
            'phase' => 'unknown',
            'empty_module_space' => 'unknown',
            'confidence' => 'low',
            'evidence' => 'Onvoldoende scherp.',
            'upload_ids' => [$upload->id],
        ],
        'source' => AssessFuseboxPhotos::SOURCE,
        'confidence' => 'low',
        'captured_at' => now(),
    ]);

    IntakeAnswer::query()->create([
        'intake_id' => $intake->id,
        'question_key' => 'fusebox_clarity',
        'value' => ['value' => 'needs_clearer_photo'],
        'prefill_source' => 'ai_photo',
        'answered_at' => now(),
    ]);

    return $upload;
}

test('N49: follow-up meterkastfoto wordt meegenomen in meterkastanalyse', function () {
    ['user' => $user, 'intake' => $intake] = n49MakeIntake();
    n49SeedStaleWizardFusebox($intake);

    $round = app(CreateCustomerContributionRequest::class)->handle($intake, $user, [
        [
            'type' => FollowUpItemType::Photo,
            'prompt' => 'Maak een duidelijke foto van de meterkast.',
            'decision_area_key' => 'power',
            'photo_task_subtype' => 'fusebox',
        ],
    ]);

    $item = $round->items->firstOrFail();
    $followUp = app(StoreFollowUpUpload::class)->handle(
        $intake->fresh(),
        $item,
        UploadedFile::fake()->image('meterkast-groot.jpg', 1600, 1200),
    );
    $followUp->update([
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'content_assessment' => PhotoContentAssessment::ok(PhotoSubject::Fusebox)->toArray(),
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
    ]);

    FakeAiClient::alwaysReturn(n49FuseboxOutput('three_phase', 'high'));

    app(RefreshFuseboxAssessmentFromEvidence::class)->handle(
        $intake->fresh(),
        $round->fresh()?->load('items.uploads'),
    );

    $followUp->refresh();
    expect($followUp->question_key)->toBe('fusebox_photo');

    $fact = IntakeExternalFact::query()
        ->where('intake_id', $intake->id)
        ->where('fact_key', 'fusebox_photo_assessment')
        ->first();

    expect($fact)->not->toBeNull()
        ->and($fact->value['phase'] ?? null)->toBe('three_phase');

    $clarity = IntakeAnswer::query()
        ->where('intake_id', $intake->id)
        ->where('question_key', 'fusebox_clarity')
        ->first();

    $clarityValue = is_array($clarity?->value)
        ? ($clarity->value['value'] ?? $clarity->value['choice'] ?? null)
        : $clarity?->value;

    expect($clarity)->not->toBeNull()
        ->and($clarityValue)->toBe('clear');
});

test('N49: CompleteFollowUpRound triggert meterkast-herbeoordeling', function () {
    ['user' => $user, 'intake' => $intake] = n49MakeIntake();
    n49SeedStaleWizardFusebox($intake);

    $round = app(CreateCustomerContributionRequest::class)->handle($intake, $user, [
        [
            'type' => FollowUpItemType::Photo,
            'prompt' => 'Maak een duidelijke foto van de meterkast.',
            'decision_area_key' => 'power',
            'photo_task_subtype' => 'fusebox',
        ],
    ]);

    $item = $round->items->firstOrFail();
    $followUp = app(StoreFollowUpUpload::class)->handle(
        $intake->fresh(),
        $item,
        UploadedFile::fake()->image('meterkast-groot.jpg', 1600, 1200),
    );
    $followUp->update([
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'content_assessment' => PhotoContentAssessment::ok(PhotoSubject::Fusebox)->toArray(),
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
    ]);

    FakeAiClient::alwaysReturn(n49FuseboxOutput('one_phase', 'high'));

    app(CompleteFollowUpRound::class)->handle($intake->fresh(), $round->fresh(), []);

    $followUp->refresh();
    expect($followUp->question_key)->toBe('fusebox_photo');

    $fact = IntakeExternalFact::query()
        ->where('intake_id', $intake->id)
        ->where('fact_key', 'fusebox_photo_assessment')
        ->first();

    expect($fact)->not->toBeNull()
        ->and($fact->value['phase'] ?? null)->toBe('one_phase');
});
