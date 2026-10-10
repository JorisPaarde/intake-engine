<?php

declare(strict_types=1);

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Support\PhotoCustomerStatus;
use App\Domains\Intake\Support\PhotoOverridePolicy;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\IntakeStatus;
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
});

function fotoStatusIntake(array $overrides = []): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create(array_merge([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_name' => 'Foto Status Test',
        'customer_email' => 'foto-status@example.com',
    ], $overrides));
}

function seedOneRoomForPhotos(Intake $intake): void
{
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    app(SaveIntakeAnswer::class)->handle($intake, 'request_reason', null, ['text' => 'Eén woonkamer koelen']);
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    foreach ([
        ['room_type', ['value' => 'living_room'], PrefillSources::AI_TEXT],
        ['room_size_indication', ['value' => 'medium'], PrefillSources::AI_TEXT],
        ['room_length_m', ['number' => 4.0], PrefillSources::AI_TEXT],
        ['room_width_m', ['number' => 3.0], PrefillSources::AI_TEXT],
        ['room_area_m2', ['number' => 12.0], PrefillSources::AI_TEXT],
        ['ceiling_height_m', ['number' => 2.5], PrefillSources::AI_TEXT],
        ['sun_exposure', ['value' => 'medium'], PrefillSources::AI_TEXT],
        ['glass_amount', ['value' => 'average'], PrefillSources::AI_TEXT],
        ['glazing_type', ['value' => 'double'], PrefillSources::AI_TEXT],
        ['floor_level', ['value' => '1'], PrefillSources::AI_TEXT],
        ['room_outlet_status', ['value' => 'visible'], PrefillSources::AI_PHOTO],
        ['preferred_indoor_location', ['text' => 'Boven de deur'], PrefillSources::AI_TEXT],
    ] as [$key, $value, $source]) {
        app(SaveIntakeAnswer::class)->handle($intake, $key, 'room-1', $value, $source);
    }
}

function seedRejectedRoomPhoto(Intake $intake, string $filename = 'wrong-a.jpg', int $width = 1200): IntakeUpload
{
    // Unieke pixels → unieke checksum (anders collapsen identieke fakes tot 1 upload).
    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'room_photos',
        'room-1',
        UploadedFile::fake()->image($filename, $width, 900),
    );
    $upload->updateQuietly([
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'content_assessment' => PhotoContentAssessment::wrongSubject(
            PhotoSubject::Room,
            PhotoSubject::OutdoorUnit,
        )->toArray(),
    ]);

    return $upload->fresh() ?? $upload;
}

function goToRoomPhotosStep(Intake $intake): array
{
    $steps = app(IntakeStepBuilder::class)->build(
        $intake->fresh() ?? $intake,
        $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail(),
    );
    $index = collect($steps)->search(fn (array $s): bool => ($s['question_key'] ?? '') === 'room_photos'
        && ($s['section_instance_key'] ?? null) === 'room-1');
    expect($index)->not->toBeFalse();

    return [$steps, (int) $index];
}

test('na previous geen We bekijken meer zodra alle foto’s terminaal zijn', function () {
    $intake = fotoStatusIntake();
    seedOneRoomForPhotos($intake);
    seedRejectedRoomPhoto($intake);

    [$steps, $index] = goToRoomPhotosStep($intake);
    expect($steps[$index + 1]['key'] ?? null)->not->toBeNull();

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', $index)
        ->set('activeStepKey', $steps[$index]['key']);

    $html = $component->html();
    expect($html)->toContain(PhotoCustomerStatus::WRONG_SUBJECT)
        ->and($html)->not->toContain('data-testid="upload-phase"')
        ->and($html)->not->toContain('Status:')
        ->and($html)->toContain('data-testid="photo-mismatch-panel"')
        ->and($html)->toContain(PhotoOverridePolicy::PANEL_HEADING_ONE)
        ->and($html)->toContain('Je installateur krijgt de foto dan wel');

    $component
        ->call('acceptPhotoMismatch')
        ->call('previous')
        ->assertDontSee(PhotoCustomerStatus::LOOKING)
        ->assertDontSeeHtml('data-testid="upload-phase"')
        ->assertSee(PhotoCustomerStatus::OVERRIDE_ACCEPTED);
});

test('meerdere afgekeurde foto’s: meervoudskop en één Toch doorgaan', function () {
    $intake = fotoStatusIntake();
    seedOneRoomForPhotos($intake);
    seedRejectedRoomPhoto($intake, 'wrong-1.jpg', 1200);
    seedRejectedRoomPhoto($intake, 'wrong-2.jpg', 1201);
    seedRejectedRoomPhoto($intake, 'wrong-3.jpg', 1202);

    [$steps, $index] = goToRoomPhotosStep($intake);

    $html = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', $index)
        ->set('activeStepKey', $steps[$index]['key'])
        ->html();

    expect($html)->toContain('3 foto’s zijn nog niet goed.')
        ->and($html)->toContain(PhotoOverridePolicy::PANEL_EXPLAIN_MANY)
        ->and(substr_count($html, 'data-testid="photo-accept-mismatch"'))->toBe(1)
        ->and(substr_count($html, PhotoCustomerStatus::WRONG_SUBJECT))->toBe(3)
        ->and($html)->not->toContain('Status:');
});

test('Volgende zonder keuze toont OVERRIDE_MESSAGE alleen in het fotomeldingsvak', function () {
    $intake = fotoStatusIntake();
    seedOneRoomForPhotos($intake);
    seedRejectedRoomPhoto($intake);

    [$steps, $index] = goToRoomPhotosStep($intake);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', $index)
        ->set('activeStepKey', $steps[$index]['key'])
        ->call('next')
        ->assertSet('showMissing', true)
        ->assertSeeHtml('data-testid="mismatch-next-warning"')
        ->assertSee(PhotoOverridePolicy::OVERRIDE_MESSAGE)
        ->assertDontSeeHtml('data-testid="footer-mismatch-warning"')
        ->assertDontSeeHtml('data-testid="step-missing-alert"');
});

test('afgekeurde foto op eerdere stap toont banner met link terug', function () {
    $intake = fotoStatusIntake();
    seedOneRoomForPhotos($intake);
    seedRejectedRoomPhoto($intake);

    [$steps, $roomIndex] = goToRoomPhotosStep($intake);
    $laterIndex = min($roomIndex + 2, count($steps) - 1);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', $laterIndex)
        ->set('activeStepKey', $steps[$laterIndex]['key']);

    $html = $component->html();
    expect($html)->toContain('data-testid="distant-photo-banner"')
        ->and($html)->toContain('is nog niet goed')
        ->and($html)->toContain('Bekijk de foto')
        ->and($html)->not->toContain('Ruimtes 1');

    $component
        ->call('goToMissing', 'room_photos', 'room-1')
        ->assertSet('activeStepKey', $steps[$roomIndex]['key'])
        ->assertSeeHtml('data-testid="photo-mismatch-panel"')
        ->call('acceptPhotoMismatch')
        ->assertSet('activeStepKey', $steps[$laterIndex]['key'])
        ->assertDontSeeHtml('data-testid="distant-photo-banner"');
});

test('Afronden onderscheidt nog geen foto en foto nog niet goed met ruimtenaam', function () {
    $intake = fotoStatusIntake();
    seedOneRoomForPhotos($intake);
    seedRejectedRoomPhoto($intake);

    // Geen meterkastfoto → “nog geen foto”; room_photos afgekeurd → “foto nog niet goed”.
    $steps = app(IntakeStepBuilder::class)->build(
        $intake->fresh() ?? $intake,
        $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail(),
    );
    $lastIndex = count($steps) - 1;

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', $lastIndex)
        ->set('activeStepKey', $steps[$lastIndex]['key'])
        ->set('form.privacy_consent.bool', true)
        ->call('complete')
        ->assertSet('showMissing', true);

    $html = $component->html();
    expect($html)->toContain('Nog niet alles is klaar.')
        ->and($html)->toContain(' — foto nog niet goed')
        ->and($html)->toContain(' — nog geen foto')
        ->and($html)->not->toContain('foto verplicht')
        ->and($html)->not->toContain('Ruimtes 1');

    $reasons = collect($component->get('completionMissing'))->pluck('reason')->all();
    expect($reasons)->toContain('required_photo_not_good')
        ->and($reasons)->toContain('required_photo');
});

test('not_assessed blokkeert Volgende en Afronden niet', function () {
    $intake = fotoStatusIntake();
    seedOneRoomForPhotos($intake);

    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'room_photos',
        'room-1',
        UploadedFile::fake()->image('soft.jpg', 1200, 900),
    );
    $upload->updateQuietly([
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
        'assessment_status' => PhotoAssessmentStatus::NotAssessed,
        'content_assessment' => PhotoContentAssessment::notAssessed(PhotoSubject::Room)->toArray(),
    ]);

    [$steps, $index] = goToRoomPhotosStep($intake);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', $index)
        ->set('activeStepKey', $steps[$index]['key'])
        ->assertSee(PhotoCustomerStatus::RECEIVED)
        ->assertDontSeeHtml('data-testid="photo-mismatch-panel"')
        ->call('next')
        ->assertSet('showMissing', false)
        ->assertSet('activeStepKey', $steps[$index + 1]['key']);
});

test('Toch doorgaan markeert alle niet-goede uploads van de vraag', function () {
    $intake = fotoStatusIntake();
    seedOneRoomForPhotos($intake);
    $a = seedRejectedRoomPhoto($intake, 'a.jpg', 1200);
    $b = seedRejectedRoomPhoto($intake, 'b.jpg', 1201);

    [$steps, $index] = goToRoomPhotosStep($intake);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', $index)
        ->set('activeStepKey', $steps[$index]['key'])
        ->call('acceptPhotoMismatch');

    expect($a->fresh()->contentAssessment()?->customerAcceptedOverride())->toBeTrue()
        ->and($b->fresh()->contentAssessment()?->customerAcceptedOverride())->toBeTrue()
        ->and($a->fresh()->isDossierEvidenceEligible())->toBeTrue();
});

test('achtergrondpoll slaat composites van de huidige stap over', function () {
    Queue::fake();
    $intake = fotoStatusIntake();
    seedOneRoomForPhotos($intake);

    [$steps, $roomIndex] = goToRoomPhotosStep($intake);
    $composite = 'room-1__room_photos';
    $laterIndex = min($roomIndex + 2, count($steps) - 1);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', $roomIndex)
        ->set('activeStepKey', $steps[$roomIndex]['key'])
        ->set('photoFiles.'.$composite, UploadedFile::fake()->image('bg-poll.jpg', 1200, 900));

    expect($component->instance()->pendingAssessUploadIds[$composite] ?? [])->not->toBeEmpty();

    $onHtml = $component->html();
    expect($onHtml)->toContain('data-testid="assessment-poll"')
        ->and($onHtml)->toContain('data-poll-composite="'.$composite.'"')
        ->and($onHtml)->not->toContain('data-testid="bg-assessment-poll"');

    $component->call('goToStep', $laterIndex);

    $offHtml = $component->html();
    expect($offHtml)->toContain('data-testid="bg-assessment-poll"')
        ->and($offHtml)->toContain('data-poll-composite="'.$composite.'"')
        ->and($offHtml)->not->toContain('data-testid="assessment-poll"');
});

test('terminale poll off-step stelt foto-afleiding uit tot die stap zichtbaar is', function () {
    Queue::fake();
    $intake = fotoStatusIntake();
    seedOneRoomForPhotos($intake);

    [$steps, $roomIndex] = goToRoomPhotosStep($intake);
    $laterIndex = min($roomIndex + 2, count($steps) - 1);
    $composite = 'room-1__room_photos';

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', $roomIndex)
        ->set('activeStepKey', $steps[$roomIndex]['key'])
        ->set('photoFiles.'.$composite, UploadedFile::fake()->image('pending-derive.jpg', 1200, 900));

    $uploadId = (int) ($component->instance()->pendingAssessUploadIds[$composite][0] ?? 0);
    expect($uploadId)->toBeGreaterThan(0);

    $component->call('goToStep', $laterIndex);

    IntakeUpload::query()->whereKey($uploadId)->update([
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'content_assessment' => PhotoContentAssessment::ok(PhotoSubject::Room)->toArray(),
    ]);

    $component->instance()->pollPendingAssessments($composite);

    expect($component->instance()->deferredPhotoDerivations)->toContain([
        'question_key' => 'room_photos',
        'instance_key' => 'room-1',
    ]);

    $component
        ->call('goToStep', $roomIndex)
        ->assertSet('activeStepKey', $steps[$roomIndex]['key']);

    expect($component->get('deferredPhotoDerivations'))->toBe([]);
});

test('photoFixReturnStepKey wist bij Vorige/goToStep en bij goToMissing zonder doel', function () {
    $intake = fotoStatusIntake();
    seedOneRoomForPhotos($intake);
    seedRejectedRoomPhoto($intake);

    [$steps, $roomIndex] = goToRoomPhotosStep($intake);
    $laterIndex = min($roomIndex + 2, count($steps) - 1);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', $laterIndex)
        ->set('activeStepKey', $steps[$laterIndex]['key']);

    $component
        ->call('goToMissing', 'room_photos', 'room-1')
        ->assertSet('photoFixReturnStepKey', $steps[$laterIndex]['key']);

    $component
        ->call('previous')
        ->assertSet('photoFixReturnStepKey', '');

    $component->instance()->photoFixReturnStepKey = 'some-return';
    $component
        ->call('goToStep', $laterIndex)
        ->assertSet('photoFixReturnStepKey', '');

    $component->instance()->photoFixReturnStepKey = 'stale';
    $component
        ->call('goToMissing', 'does_not_exist_photo', null)
        ->assertSet('photoFixReturnStepKey', '');
});
