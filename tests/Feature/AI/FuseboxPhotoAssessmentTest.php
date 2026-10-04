<?php

declare(strict_types=1);

use App\Domains\AI\Actions\AssessFuseboxPhotos;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\AI\Models\AiRun;
use App\Domains\Intake\Actions\DeleteIntakeUpload;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\GenerateIntakeReportHtml;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
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
    FakeAiClient::reset();
    config([
        'ai.provider' => 'fake',
        'ai.photo_inference.enabled' => true,
    ]);
});

afterEach(function () {
    FakeAiClient::reset();
});

function makeFuseboxAssessmentIntake(): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_name' => 'Fotoanalyse Klant',
        'customer_email' => 'fotoanalyse@example.com',
    ]);
}

/** @return array{empty_module_space: string, phase: string, confidence: string, evidence: string, retake_instruction: string|null, detected_subject: string, subject_match: string} */
function fuseboxOutput(
    string $emptyModuleSpace = 'visible',
    string $phase = 'three_phase',
    string $confidence = 'high',
    ?string $retakeInstruction = null,
    string $subjectMatch = 'yes',
    string $detectedSubject = 'fusebox',
): array {
    return [
        'empty_module_space' => $emptyModuleSpace,
        'phase' => $phase,
        'confidence' => $confidence,
        'detected_subject' => $detectedSubject,
        'subject_match' => $subjectMatch,
        'evidence' => 'Een lege modulepositie en drie gekoppelde hoofdschakelaars zijn zichtbaar.',
        'retake_instruction' => $retakeInstruction,
    ];
}

test('high confidence fusebox assessment stores observation but never fills free_group_known', function () {
    $intake = makeFuseboxAssessmentIntake();
    FakeAiClient::alwaysReturn(fuseboxOutput());

    app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('meterkast.jpg', 1200, 900),
    );

    $run = app(AssessFuseboxPhotos::class)->handle($intake);
    $fact = $intake->externalFacts()->where('fact_key', 'fusebox_photo_assessment')->firstOrFail();
    $html = app(GenerateIntakeReportHtml::class)->handle(
        $intake,
        $intake->templateVersion()->firstOrFail(),
    );

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(AiRunStatus::Succeeded)
        ->and($run->type)->toBe(AiRunType::PhotoAssessment)
        ->and($intake->answers()->where('question_key', 'free_group_known')->exists())->toBeFalse()
        ->and($fact->source)->toBe(AssessFuseboxPhotos::SOURCE)
        ->and($fact->value['phase'])->toBe('three_phase')
        ->and($fact->value['empty_module_space'])->toBe('visible')
        ->and($fact->confidence)->toBe('medium')
        ->and($fact->source_reference)->toBe('ai-run:'.$run->id)
        ->and($fact->value)->not->toHaveKey('binary')
        ->and($html)->toContain('Automatische beoordeling meterkastfoto')
        ->and($html)->toContain('AI-fotoanalyse');
});

test('medium confidence assessment stays an uncertainty and never fills an answer', function () {
    $intake = makeFuseboxAssessmentIntake();
    FakeAiClient::alwaysReturn(fuseboxOutput(
        emptyModuleSpace: 'unknown',
        phase: 'unknown',
        confidence: 'medium',
        retakeInstruction: 'Fotografeer de volledige groepenkast recht van voren met alle labels scherp in beeld.',
    ));

    app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('onduidelijk.jpg', 1200, 900),
    );
    $run = app(AssessFuseboxPhotos::class)->handle($intake);

    expect($run?->status)->toBe(AiRunStatus::Succeeded)
        ->and($run?->output['retake_instruction'])->toContain('recht van voren')
        ->and($intake->answers()->where('question_key', 'free_group_known')->exists())->toBeFalse()
        ->and($intake->externalFacts()->where('fact_key', 'fusebox_photo_assessment')->exists())->toBeTrue();
});

test('photo assessment never overwrites a customer answer', function () {
    $intake = makeFuseboxAssessmentIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'free_group_known', null, ['value' => 'no']);
    FakeAiClient::alwaysReturn(fuseboxOutput(emptyModuleSpace: 'visible'));

    app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('meterkast.jpg', 1200, 900),
    );
    app(AssessFuseboxPhotos::class)->handle($intake);

    $answer = $intake->answers()->where('question_key', 'free_group_known')->firstOrFail();

    expect($answer->value)->toBe(['value' => 'no'])
        ->and($answer->prefill_source)->toBeNull();
});

test('assessment is idempotent and deleting its evidence removes the derived state', function () {
    $intake = makeFuseboxAssessmentIntake();
    FakeAiClient::alwaysReturn(fuseboxOutput());
    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('meterkast.jpg', 1200, 900),
    );

    $first = app(AssessFuseboxPhotos::class)->handle($intake);
    $second = app(AssessFuseboxPhotos::class)->handle($intake);

    expect($second?->id)->toBe($first?->id)
        ->and(AiRun::query()->where('type', AiRunType::PhotoAssessment)->count())->toBe(1);

    app(DeleteIntakeUpload::class)->handle($intake, $upload);
    app(AssessFuseboxPhotos::class)->handle($intake);

    expect($intake->answers()->where('prefill_source', 'ai_photo')->exists())->toBeFalse()
        ->and($intake->externalFacts()->where('fact_key', 'fusebox_photo_assessment')->exists())->toBeFalse();
});

test('replacing identical photo bytes during analysis rejects stale upload provenance', function () {
    $intake = makeFuseboxAssessmentIntake();

    // BL-143: keep AI for the explicit AssessFuseboxPhotos call with respondUsing.
    Queue::fake([AssessUploadedPhotoJob::class]);

    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('meterkast.jpg', 1200, 900),
    );

    FakeAiClient::respondUsing(function () use ($upload): array {
        $attributes = $upload->getAttributes();
        unset($attributes['id'], $attributes['created_at'], $attributes['updated_at'], $attributes['deleted_at']);
        $upload->delete();
        IntakeUpload::query()->create($attributes);

        return fuseboxOutput();
    });

    $run = app(AssessFuseboxPhotos::class)->handle($intake);

    expect($run?->status)->toBe(AiRunStatus::Failed)
        ->and($intake->answers()->where('prefill_source', 'ai_photo')->exists())->toBeFalse()
        ->and($intake->answers()->where('prefill_source', 'ai')->exists())->toBeFalse()
        ->and($intake->externalFacts()->where('fact_key', 'fusebox_photo_assessment')->exists())->toBeFalse();
});

test('wizard exposes the photo prefill and a precise retake hint without blocking upload', function () {
    $intake = makeFuseboxAssessmentIntake();
    FakeAiClient::alwaysReturn(fuseboxOutput(
        emptyModuleSpace: 'unknown',
        phase: 'unknown',
        confidence: 'low',
        retakeInstruction: 'Neem één foto recht van voren waarop alle groepen en de hoofdschakelaar leesbaar zijn.',
    ));

    Queue::fake([AssessUploadedPhotoJob::class]);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('photoFiles.fusebox_photo', UploadedFile::fake()->image('meterkast.jpg', 1200, 900))
        ->assertSet('uploadPhase', 'assessing');

    $upload = $intake->fresh()->uploads()->where('question_key', 'fusebox_photo')->firstOrFail();
    runAssessUploadedPhotoJob($upload->id);

    $component->call('assessPendingUploads');

    $hints = $component->get('photoHint');

    expect($hints['fusebox_photo'] ?? null)->toContain('alle groepen en de hoofdschakelaar')
        ->and($intake->uploads()->where('question_key', 'fusebox_photo')->count())->toBe(1);

    $component->assertHasNoErrors('photoFiles.fusebox_photo');

    $intake->update([
        'current_section_key' => 'electrical',
        'current_question_key' => 'fusebox_photo',
        'current_section_instance_key' => null,
    ]);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertSee('alle groepen en de hoofdschakelaar');
});

test('wizard does not ask the customer to reconfirm a high confidence image result', function () {
    $intake = makeFuseboxAssessmentIntake();
    FakeAiClient::alwaysReturn(fuseboxOutput());

    Queue::fake([AssessUploadedPhotoJob::class]);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('photoFiles.fusebox_photo', UploadedFile::fake()->image('meterkast.jpg', 1200, 900))
        ->assertSet('uploadPhase', 'assessing');

    $upload = $intake->fresh()->uploads()->where('question_key', 'fusebox_photo')->firstOrFail();
    runAssessUploadedPhotoJob($upload->id);

    $component->call('assessPendingUploads');

    $notices = $component->get('prefillNotice');

    expect($component->get('form')['free_group_known']['value'] ?? null)->toBeNull()
        ->and($intake->answers()->where('question_key', 'free_group_known')->exists())->toBeFalse()
        ->and($notices)->not->toHaveKey('free_group_known');
});
