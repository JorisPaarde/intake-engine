<?php

declare(strict_types=1);

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Support\OutdoorPhotoReuse;
use App\Enums\PhotoAssessmentStatus;
use App\Enums\PhotoUsabilityVerdict;
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

function makeKlantwizard14AroundIntake(): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
        'access_token' => str_repeat('d', 64),
        'token_expires_at' => now()->addDays(14),
    ]);
}

/**
 * @return list<string>
 */
function klantwizard14AroundStepKeys(Intake $intake): array
{
    $version = $intake->templateVersion()
        ->with(['sections.questions.options', 'sections.questions.rules'])
        ->firstOrFail();

    return array_column(
        app(IntakeStepBuilder::class)->build($intake->fresh(), $version),
        'question_key',
    );
}

test('A2: zonder bruikbare buitenfoto is around_house een vervolgvraag met sla-over', function () {
    $intake = makeKlantwizard14AroundIntake();
    $version = $intake->fresh()->templateVersion()->with(['sections.questions'])->firstOrFail();

    expect($version->version)->toBeGreaterThanOrEqual(28);

    $around = $version->sections->flatMap->questions->firstWhere('key', 'around_house_photos');
    expect($around)->not->toBeNull()
        ->and($around->is_required)->toBeFalse()
        ->and($around->label)->toBe('We zien de buitenkant nog niet goed')
        ->and($around->help_text)->toBe('Maak een foto van de gevel of tuin waar de buitenunit kan komen.')
        ->and($around->meta['allow_skip'] ?? null)->toBeTrue()
        ->and($around->meta['skip_label'] ?? null)->toBe('Weet ik niet / sla over');

    expect(OutdoorPhotoReuse::hasUsableOutdoorContext($intake))->toBeFalse()
        ->and(klantwizard14AroundStepKeys($intake))->toContain('around_house_photos');

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $viewSteps = $component->viewData('steps');
    $index = collect($viewSteps)->search(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'around_house_photos',
    );
    expect($index)->not->toBeFalse();

    $component
        ->set('stepIndex', (int) $index)
        ->set('activeStepKey', $viewSteps[(int) $index]['key'])
        ->assertSee('We zien de buitenkant nog niet goed')
        ->assertSee('Maak een foto van de gevel of tuin waar de buitenunit kan komen.')
        ->assertSee('Foto maken')
        ->assertSee('Weet ik niet / sla over')
        ->assertSee('data-testid="photo-skip"', false)
        ->call('skipOptionalPhoto')
        ->assertHasNoErrors();

    $after = $component->viewData('steps')[(int) $component->get('stepIndex')] ?? null;
    expect($after)->not->toBeNull()
        ->and($after['question_key'] ?? null)->not->toBe('around_house_photos');
});

test('A2: bruikbare outdoor-foto verbergt around_house en telt hem niet mee', function () {
    $intake = makeKlantwizard14AroundIntake();
    $before = klantwizard14AroundStepKeys($intake);
    expect($before)->toContain('around_house_photos');

    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'outdoor_location_photos',
        null,
        UploadedFile::fake()->image('gevel.jpg', 1600, 1200),
    );
    $upload->forceFill([
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'content_assessment' => PhotoContentAssessment::ok(PhotoSubject::OutdoorLocation)->toArray(),
    ])->save();

    expect(OutdoorPhotoReuse::hasUsableOutdoorContext($intake->fresh()))->toBeTrue();

    $after = klantwizard14AroundStepKeys($intake->fresh());
    expect($after)->not->toContain('around_house_photos')
        ->and(count($after))->toBe(count($before) - 1);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $viewKeys = array_column($component->viewData('steps'), 'question_key');
    expect($viewKeys)->not->toContain('around_house_photos')
        ->and($component->html())->not->toContain('We zien de buitenkant nog niet goed');
});
