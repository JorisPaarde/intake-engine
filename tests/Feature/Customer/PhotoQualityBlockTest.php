<?php

declare(strict_types=1);

use App\Domains\AI\Actions\DerivePhotoAnswers;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Support\PhotoDerivationProfile;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\IntakeStepBuilder;
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
    config(['ai.provider' => 'fake', 'ai.photo_inference.enabled' => true]);
});

afterEach(fn () => FakeAiClient::reset());

function blockIntake(): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
    ]);
}

function outdoorStepIndex(Intake $intake): int
{
    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    $steps = app(IntakeStepBuilder::class)->build($intake->fresh(), $version);

    return (int) collect($steps)->search(fn (array $s): bool => $s['question_key'] === 'outdoor_location_photos');
}

/** @param array<string, mixed> $extra */
function outdoorVerdict(string $confidence, ?string $retake, array $extra = []): array
{
    return [
        'outdoor_location' => 'garden',
        'outdoor_mount_type' => 'wall',
        'outdoor_accessibility' => 'ladder',
        'confidence' => $confidence,
        'evidence' => 'Beoordeling voor de test.',
        'retake_instruction' => $retake,
        ...$extra,
    ];
}

function uploadOutdoor(Intake $intake, string $name = 'buiten.jpg'): void
{
    app(StoreIntakeUpload::class)->handle(
        $intake, 'outdoor_location_photos', null, UploadedFile::fake()->image($name, 1200, 900)
    );
    app(DerivePhotoAnswers::class)->handle(
        $intake->fresh(), 'outdoor_location_photos', null, PhotoDerivationProfile::require('outdoor')
    );
}

test('an unusable photo blocks Volgende and shows the concrete instruction', function () {
    $intake = blockIntake();
    FakeAiClient::alwaysReturn(outdoorVerdict('low', 'Zorg dat de hele gevel tot aan de grond zichtbaar is.'));
    uploadOutdoor($intake);

    $index = outdoorStepIndex($intake);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', $index)
        ->set('activeStepKey', 'outdoor_unit::outdoor_location_photos')
        ->call('next');

    // De stap mag niet verschuiven.
    expect($component->get('stepIndex'))->toBe($index);

    $component->assertHasErrors('photoFiles.outdoor_location_photos');

    $errors = $component->errors()->get('photoFiles.outdoor_location_photos');

    expect($errors[0])->toContain('Zorg dat de hele gevel tot aan de grond zichtbaar is.')
        ->and($errors[0])->toContain('Verwijder de foto en maak een nieuwe, of voeg een extra foto toe.');
});

test('a good photo lets the applicant continue', function () {
    $intake = blockIntake();
    FakeAiClient::alwaysReturn(outdoorVerdict('high', null));
    uploadOutdoor($intake);

    $index = outdoorStepIndex($intake);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', $index)
        ->set('activeStepKey', 'outdoor_unit::outdoor_location_photos')
        ->call('next');

    expect($component->get('stepIndex'))->toBeGreaterThan($index);
    $component->assertHasNoErrors();
});

test('a replacement photo lifts the block', function () {
    $intake = blockIntake();
    FakeAiClient::alwaysReturn(outdoorVerdict('low', 'Zorg dat de hele gevel zichtbaar is.'));
    uploadOutdoor($intake);

    $index = outdoorStepIndex($intake);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', $index)
        ->set('activeStepKey', 'outdoor_unit::outdoor_location_photos')
        ->call('next')
        ->assertHasErrors('photoFiles.outdoor_location_photos');

    // Nieuwe, wél bruikbare foto erbij.
    FakeAiClient::alwaysReturn(outdoorVerdict('high', null));
    uploadOutdoor($intake->fresh(), 'beter.jpg');

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', $index)
        ->set('activeStepKey', 'outdoor_unit::outdoor_location_photos')
        ->call('next');

    expect($component->get('stepIndex'))->toBeGreaterThan($index);
});

test('a failing AI provider never blocks the applicant', function () {
    $intake = blockIntake();
    FakeAiClient::alwaysFail('provider plat');
    uploadOutdoor($intake);

    $index = outdoorStepIndex($intake);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', $index)
        ->set('activeStepKey', 'outdoor_unit::outdoor_location_photos')
        ->call('next');

    // Geen oordeel is geen negatief oordeel: een storing mag de opname niet stilleggen.
    expect($component->get('stepIndex'))->toBeGreaterThan($index);
});

test('photo inference switched off never blocks either', function () {
    config(['ai.photo_inference.enabled' => false]);

    $intake = blockIntake();
    uploadOutdoor($intake);

    $index = outdoorStepIndex($intake);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', $index)
        ->set('activeStepKey', 'outdoor_unit::outdoor_location_photos')
        ->call('next');

    expect($component->get('stepIndex'))->toBeGreaterThan($index);
});

test('the pipe route never blocks, because a route cannot fit in one photo', function () {
    $intake = blockIntake();
    FakeAiClient::alwaysReturn([
        'pipe_route_description' => 'along_facade',
        'pipe_distance_indication' => 'unknown',
        'drillings_needed' => 'unknown',
        'confidence' => 'low',
        'evidence' => 'De route is over deze opnames niet te volgen.',
        'retake_instruction' => 'Fotografeer de muur over de volle lengte.',
    ]);

    app(StoreIntakeUpload::class)->handle(
        $intake, 'pipe_route_photos', null, UploadedFile::fake()->image('route.jpg', 1200, 900)
    );
    app(DerivePhotoAnswers::class)->handle(
        $intake->fresh(), 'pipe_route_photos', null, PhotoDerivationProfile::require('pipe_route')
    );

    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    $steps = app(IntakeStepBuilder::class)->build($intake->fresh(), $version);
    $index = (int) collect($steps)->search(fn (array $s): bool => $s['question_key'] === 'pipe_route_photos');

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', $index)
        ->set('activeStepKey', 'pipe_route::pipe_route_photos')
        ->call('next');

    expect($component->get('stepIndex'))->toBeGreaterThan($index);
});

test('the pipe route profile sends more photos than a single-subject profile', function () {
    expect(PhotoDerivationProfile::require('pipe_route')->maxImages)->toBe(5)
        ->and(PhotoDerivationProfile::require('room')->maxImages)->toBe(2);
});
