<?php

declare(strict_types=1);

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
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

test('N73: extra meterkastfoto is niet verplicht na Toch doorgaan op de hoofdfoto', function () {
    $intake = n73Intake();
    n73FuseboxUpload($intake, override: false);
    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('activeStepKey', 'electrical::fusebox_photo')
        ->call('acceptPhotoMismatch');

    expect(n73MissingKeys($intake))->not->toContain('fusebox_photo_extra');

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('activeStepKey', 'electrical::fusebox_photo_extra')
        ->call('next');

    expect($component->get('showMissing'))->toBeFalse()
        ->and($component->get('activeStepKey'))->not->toBe('electrical::fusebox_photo_extra');
});

test('N73: extra meterkastfoto blijft verplicht zolang de klant niet Toch doorgaan koos', function () {
    $intake = n73Intake();
    n73FuseboxUpload($intake, override: false);

    expect(n73MissingKeys($intake))->toContain('fusebox_photo_extra');
});
