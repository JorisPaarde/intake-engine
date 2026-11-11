<?php

declare(strict_types=1);

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Services\ProgressCalculator;
use App\Enums\IntakeStatus;
use App\Enums\PhotoAssessmentStatus;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
});

function n73Intake(): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'is_demo' => false,
    ]);
}

function n73FuseboxUpload(Intake $intake, bool $override): void
{
    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('meterkast.jpg', 800, 600),
    );

    $assessment = PhotoContentAssessment::needsClearer(PhotoSubject::Fusebox, 'Scherpere foto graag.');
    $upload->forceFill([
        'content_assessment' => ($override ? $assessment->withCustomerAcceptedOverride() : $assessment)->toArray(),
        'assessment_status' => PhotoAssessmentStatus::Assessed,
    ])->save();

    $intake->answers()->updateOrCreate(
        ['question_key' => 'fusebox_clarity', 'section_instance_key' => null],
        ['value' => ['value' => 'needs_clearer_photo'], 'prefill_source' => 'ai', 'answered_at' => now()],
    );
}

function n73MissingKeys(Intake $intake): array
{
    $intake = $intake->fresh();
    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();

    return collect(app(ProgressCalculator::class)->calculate($intake, $version)['missing_required'])
        ->pluck('question_key')->all();
}

function n73StepKeys(Intake $intake): array
{
    $intake = $intake->fresh();
    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();

    return collect(app(IntakeStepBuilder::class)->build($intake, $version))
        ->pluck('question_key')
        ->all();
}

test('N73/BL-158: Toch doorgaan wist fusebox_clarity zodat extra meterkastfoto verdwijnt', function () {
    $intake = n73Intake();
    n73FuseboxUpload($intake, override: false);

    expect(n73StepKeys($intake))->toContain('fusebox_photo_extra');

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('activeStepKey', 'electrical::fusebox_photo')
        ->call('acceptPhotoMismatch')
        ->call('advanceAfterPhotoOverride');

    expect(n73MissingKeys($intake))->not->toContain('fusebox_photo_extra')
        ->and(n73StepKeys($intake))->not->toContain('fusebox_photo_extra')
        ->and($intake->fresh()->answers()->where('question_key', 'fusebox_clarity')->value('value'))
        ->toBe(['value' => 'clear']);
});

test('N73: zonder Toch doorgaan blijft extra meterkastfoto in de flow (v30 niet-verplicht)', function () {
    $intake = n73Intake();
    n73FuseboxUpload($intake, override: false);

    // v30: fusebox_photo_extra is optioneel → niet in missing_required, wel zichtbaar.
    expect(n73StepKeys($intake))->toContain('fusebox_photo_extra')
        ->and(n73MissingKeys($intake))->not->toContain('fusebox_photo_extra');
});
