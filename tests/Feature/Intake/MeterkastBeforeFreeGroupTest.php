<?php

declare(strict_types=1);

use App\Domains\AI\Actions\AssessFuseboxPhotos;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Enums\ContributionMode;
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

/** @return list<string> */
function bl077StepKeys(Intake $intake): array
{
    $version = $intake->templateVersion()
        ->with(['sections.questions.options', 'sections.questions.rules'])
        ->firstOrFail();

    return collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version))
        ->pluck('question_key')
        ->all();
}

function makeBl077Intake(): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'is_demo' => true,
        'workflow_mode' => ContributionMode::Customer,
        'customer_access_enabled' => true,
        'customer_name' => 'Demo Klant',
        'customer_email' => 'demo-klant@demo.invalid',
    ]);
}

test('airco latest template hides free_group_known until a meterkast photo exists', function () {
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    expect($version->version)->toBe(15);

    $freeGroup = $version->sections()
        ->where('key', 'electrical')
        ->firstOrFail()
        ->questions()
        ->where('key', 'free_group_known')
        ->firstOrFail();

    expect($freeGroup->is_required)->toBeTrue()
        ->and($freeGroup->meta['skip_when_prefilled_by'] ?? null)->toBe(['ai'])
        ->and($freeGroup->rules)->toHaveCount(1)
        ->and($freeGroup->rules->first()->source_question_key)->toBe('fusebox_photo')
        ->and($freeGroup->rules->first()->operator->value)->toBe('filled')
        ->and($freeGroup->rules->first()->effect->value)->toBe('show');

    $intake = makeBl077Intake();
    $steps = bl077StepKeys($intake);

    expect($steps)->toContain('fusebox_photo')
        ->and($steps)->not->toContain('free_group_known')
        ->and($steps)->not->toContain('electrical_phase');
});

test('customer wizard with zero uploads asks for meterkastfoto not vrije groep', function () {
    $intake = makeBl077Intake();
    $steps = bl077StepKeys($intake);

    expect($steps)->toContain('fusebox_photo')
        ->and($steps)->not->toContain('free_group_known')
        ->and($steps)->not->toContain('electrical_phase');

    // Screenshot-case: zero uploads — electrical path must not offer the ja/nee.
    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);

    /** @var list<array{question_key: string, key: string}> $viewSteps */
    $viewSteps = $component->viewData('steps');
    $viewKeys = collect($viewSteps)->pluck('question_key')->all();

    expect($viewKeys)->toContain('fusebox_photo')
        ->and($viewKeys)->not->toContain('free_group_known')
        ->and($component->html())->not->toContain('Is er een vrije groep in de meterkast?');

    $fuseboxIndex = collect($viewSteps)->search(
        static fn (array $step): bool => $step['question_key'] === 'fusebox_photo',
    );

    expect($fuseboxIndex)->not->toBeFalse();

    $component->set('stepIndex', (int) $fuseboxIndex)
        ->set('activeStepKey', $viewSteps[(int) $fuseboxIndex]['key'])
        ->assertSee('Foto van de meterkast')
        ->assertSee('Elektrische installatie')
        ->assertDontSee('Is er een vrije groep in de meterkast?');
});

test('clear fusebox photo with free_group derived skips the ja/nee question', function () {
    $intake = makeBl077Intake();
    FakeAiClient::alwaysReturn([
        'free_group' => 'yes',
        'phase' => 'three_phase',
        'confidence' => 'high',
        'evidence' => 'Drie faseleidingen en een vrije groep zijn zichtbaar.',
        'retake_instruction' => null,
    ]);

    app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('meterkast.jpg', 1200, 900),
    );

    app(AssessFuseboxPhotos::class)->handle($intake);

    $steps = bl077StepKeys($intake);

    expect($intake->answers()->where('question_key', 'free_group_known')->firstOrFail()->value)
        ->toBe(['value' => 'yes'])
        ->and($steps)->not->toContain('free_group_known')
        ->and($steps)->not->toContain('fusebox_photo_extra')
        ->and($steps)->not->toContain('electrical_phase');
});

test('fusebox photo without readable free_group shows the ja/nee fallback after the photo', function () {
    $intake = makeBl077Intake();
    FakeAiClient::alwaysReturn([
        'free_group' => 'unknown',
        'phase' => 'one_phase',
        'confidence' => 'high',
        'evidence' => 'Fase is zichtbaar; of er een vrije groep is blijft onduidelijk.',
        'retake_instruction' => null,
    ]);

    app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('meterkast.jpg', 1200, 900),
    );

    app(AssessFuseboxPhotos::class)->handle($intake);

    $steps = bl077StepKeys($intake);
    $fuseboxIndex = array_search('fusebox_photo', $steps, true);
    $freeGroupIndex = array_search('free_group_known', $steps, true);

    expect($steps)->toContain('free_group_known')
        ->and($fuseboxIndex)->not->toBeFalse()
        ->and($freeGroupIndex)->not->toBeFalse()
        ->and($freeGroupIndex)->toBeGreaterThan($fuseboxIndex)
        ->and($steps)->not->toContain('fusebox_photo_extra');
});
