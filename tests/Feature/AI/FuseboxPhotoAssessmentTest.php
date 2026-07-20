<?php

declare(strict_types=1);

use App\Domains\AI\Actions\AssessFuseboxPhotos;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Models\AiRun;
use App\Domains\Intake\Actions\DeleteIntakeUpload;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\GenerateIntakeReportHtml;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\IntakeStatus;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
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

/** @return array{free_group: string, phase: string, confidence: string, evidence: string, retake_instruction: string|null} */
function fuseboxOutput(
    string $freeGroup = 'yes',
    string $phase = 'three_phase',
    string $confidence = 'high',
    ?string $retakeInstruction = null,
): array {
    return [
        'free_group' => $freeGroup,
        'phase' => $phase,
        'confidence' => $confidence,
        'evidence' => 'Een vrije positie en drie gekoppelde hoofdschakelaars zijn zichtbaar.',
        'retake_instruction' => $retakeInstruction,
    ];
}

test('high confidence fusebox assessment creates a confirmable prefill and sourced dossier fact', function () {
    $intake = makeFuseboxAssessmentIntake();
    FakeAiClient::alwaysReturn(fuseboxOutput());

    app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('meterkast.jpg', 1200, 900),
    );

    $run = app(AssessFuseboxPhotos::class)->handle($intake);
    $answer = $intake->answers()->where('question_key', 'free_group_known')->firstOrFail();
    $fact = $intake->externalFacts()->where('fact_key', 'fusebox_photo_assessment')->firstOrFail();
    $html = app(GenerateIntakeReportHtml::class)->handle(
        $intake,
        $intake->templateVersion()->firstOrFail(),
    );

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(AiRunStatus::Succeeded)
        ->and($run->type)->toBe(AiRunType::PhotoAssessment)
        ->and($answer->value)->toBe(['value' => 'yes'])
        ->and($answer->prefill_source)->toBe('ai')
        ->and($fact->source)->toBe(AssessFuseboxPhotos::SOURCE)
        ->and($fact->value['phase'])->toBe('three_phase')
        ->and($fact->confidence)->toBe('medium')
        ->and($fact->source_reference)->toBe('ai-run:'.$run->id)
        ->and($fact->value)->not->toHaveKey('binary')
        ->and($html)->toContain('Automatische beoordeling meterkastfoto')
        ->and($html)->toContain('AI-fotoanalyse')
        ->and($html)->toContain('controleer vrije groep en fase');
});

test('medium confidence assessment stays an uncertainty and never fills an answer', function () {
    $intake = makeFuseboxAssessmentIntake();
    FakeAiClient::alwaysReturn(fuseboxOutput(
        freeGroup: 'unknown',
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
    FakeAiClient::alwaysReturn(fuseboxOutput(freeGroup: 'yes'));

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

    expect($intake->answers()->where('prefill_source', 'ai')->exists())->toBeFalse()
        ->and($intake->externalFacts()->where('fact_key', 'fusebox_photo_assessment')->exists())->toBeFalse();
});

test('wizard exposes the photo prefill and a precise retake hint without blocking upload', function () {
    $intake = makeFuseboxAssessmentIntake();
    FakeAiClient::alwaysReturn(fuseboxOutput(
        freeGroup: 'unknown',
        phase: 'unknown',
        confidence: 'low',
        retakeInstruction: 'Neem één foto recht van voren waarop alle groepen en de hoofdschakelaar leesbaar zijn.',
    ));

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('photoFiles.fusebox_photo', UploadedFile::fake()->image('meterkast.jpg', 1200, 900));

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
        ->assertSee('Voor een betere beoordeling')
        ->assertSee('alle groepen en de hoofdschakelaar');
});

test('wizard presents a high confidence image result as a choice to confirm', function () {
    $intake = makeFuseboxAssessmentIntake();
    FakeAiClient::alwaysReturn(fuseboxOutput());

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('photoFiles.fusebox_photo', UploadedFile::fake()->image('meterkast.jpg', 1200, 900));

    $notices = $component->get('prefillNotice');

    expect($component->get('form')['free_group_known']['value'] ?? null)->toBe('yes')
        ->and($notices['free_group_known'] ?? null)->toContain('klopt deze keuze');
});
