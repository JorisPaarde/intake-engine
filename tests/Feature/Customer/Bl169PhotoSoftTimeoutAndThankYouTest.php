<?php

declare(strict_types=1);

use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Jobs\ProcessIntakePhotoVariantsJob;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Support\CustomerCompletionSummary;
use App\Domains\Intake\Support\PhotoAssessmentSoftTimeout;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
});

function bl169Intake(array $overrides = []): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create(array_merge([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_name' => 'BL169 Tester',
        'customer_email' => 'bl169@example.com',
        'address_line' => 'Voorbeeldstraat 1',
        'address_postal_code' => '2011AA',
        'address_house_number' => 1,
        'address_city' => 'Haarlem',
    ], $overrides));
}

function bl169SeedRoom(Intake $intake): void
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

test('default soft-timeout is 8 seconden', function () {
    expect(config('ai.photo_assessment.ui_soft_timeout_seconds'))->toBe(8)
        ->and(PhotoAssessmentSoftTimeout::seconds())->toBe(8)
        ->and(PhotoCustomerStatus::SOFT_TIMEOUT)->toBe('We kijken je foto nog na. Je kunt alvast verder.');
});

test('trage fotocheck blokkeert max ~8s; daarna mag Volgende terwijl assessment pending blijft', function () {
    Queue::fake([
        ProcessIntakePhotoVariantsJob::class,
        AssessUploadedPhotoJob::class,
    ]);
    // Expliciet 8s — de productiedefault; gemockte trage check blijft pending.
    config(['ai.photo_assessment.ui_soft_timeout_seconds' => 8]);

    $t0 = now()->startOfSecond();
    Carbon::setTestNow($t0);

    $intake = bl169Intake();
    bl169SeedRoom($intake);

    $steps = app(IntakeStepBuilder::class)->build(
        $intake->fresh() ?? $intake,
        $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail(),
    );
    $index = collect($steps)->search(
        fn (array $s): bool => ($s['question_key'] ?? '') === 'room_photos'
            && ($s['section_instance_key'] ?? null) === 'room-1',
    );
    expect($index)->not->toBeFalse();

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', (int) $index)
        ->set('activeStepKey', $steps[(int) $index]['key'])
        ->set('photoFiles.room-1__room_photos', UploadedFile::fake()->image('slow-room.jpg', 1200, 900))
        ->assertSet('uploadPhase', 'assessing');

    $upload = $intake->fresh()->uploads()->firstOrFail();
    expect($upload->assessment_status)->toBe(PhotoAssessmentStatus::Pending);

    // Binnen 8s: Volgende blijft geblokkeerd (looking).
    Carbon::setTestNow($t0->copy()->addSeconds(4));
    $component->call('next');
    expect($component->get('activeStepKey'))->toBe($steps[(int) $index]['key'])
        ->and($component->get('uploadPhase'))->toBe('assessing');

    // Na 8s soft-release: melding + Volgende mag (assessment blijft pending op de achtergrond).
    Carbon::setTestNow($t0->copy()->addSeconds(8));
    $upload->forceFill([
        'created_at' => $t0,
        'assessment_queued_at' => $t0,
        'assessment_status' => PhotoAssessmentStatus::Pending,
    ])->save();

    $component
        ->call('pollPendingAssessments', 'room-1__room_photos')
        ->assertSet('uploadPhase', '')
        ->assertSeeHtml('data-testid="photo-soft-timeout-panel"')
        ->assertSee(PhotoCustomerStatus::SOFT_TIMEOUT);

    expect($component->instance()->assessmentUiReleased)->toContain('room-1__room_photos')
        ->and($upload->fresh()->assessment_status)->toBe(PhotoAssessmentStatus::Pending);

    $component
        ->assertSeeHtml('data-testid="next-button"')
        ->call('next');

    expect($component->get('activeStepKey'))->not->toBe($steps[(int) $index]['key']);
});

test('afkeurkader toont type-titel, tip, Nieuwe foto maken en Toch deze foto gebruiken', function () {
    $intake = bl169Intake();
    bl169SeedRoom($intake);

    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'room_photos',
        'room-1',
        UploadedFile::fake()->image('wrong.jpg', 1200, 900),
    );
    $upload->updateQuietly([
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'content_assessment' => PhotoContentAssessment::wrongSubject(
            PhotoSubject::Room,
            PhotoSubject::OutdoorUnit,
        )->toArray(),
    ]);

    $steps = app(IntakeStepBuilder::class)->build(
        $intake->fresh() ?? $intake,
        $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail(),
    );
    $index = collect($steps)->search(
        fn (array $s): bool => ($s['question_key'] ?? '') === 'room_photos'
            && ($s['section_instance_key'] ?? null) === 'room-1',
    );

    $html = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', (int) $index)
        ->set('activeStepKey', $steps[(int) $index]['key'])
        ->html();

    expect($html)->toContain(PhotoOverridePolicy::PANEL_HEADING_ONE)
        ->and($html)->toContain(PhotoOverridePolicy::PRIMARY_RETRY)
        ->and($html)->toContain(PhotoOverridePolicy::SECONDARY_KEEP)
        ->and($html)->toContain('data-testid="photo-upload-button"')
        ->and($html)->toContain('Maak of kies een foto')
        ->and($html)->not->toContain('Vervang foto')
        ->and($html)->not->toContain(PhotoCustomerStatus::WRONG_SUBJECT)
        ->and($html)->not->toContain('data-testid="footer-mismatch-warning"');
});

test('max foto’s komt uit config, niet uit vraag-meta', function () {
    config(['intake.uploads.max_files_per_question' => 2]);

    $intake = bl169Intake();
    bl169SeedRoom($intake);

    app(StoreIntakeUpload::class)->handle(
        $intake,
        'room_photos',
        'room-1',
        UploadedFile::fake()->image('a.jpg', 800, 600),
    );
    app(StoreIntakeUpload::class)->handle(
        $intake,
        'room_photos',
        'room-1',
        UploadedFile::fake()->image('b.jpg', 801, 600),
    );

    expect(fn () => app(StoreIntakeUpload::class)->handle(
        $intake,
        'room_photos',
        'room-1',
        UploadedFile::fake()->image('c.jpg', 802, 600),
    ))->toThrow(ValidationException::class);
});

test('bedankscherm: kop, bedrijfsnaam, geen voortgangsbalk, Toestemming verhuurder', function () {
    config(['intake.customer_completion.installer_response_within' => 'binnen 2 werkdagen']);

    $intake = bl169Intake();
    $companyName = (string) $intake->company?->name;
    app(SaveIntakeAnswer::class)->handle($intake, 'ownership', null, ['value' => 'rented']);
    app(SaveIntakeAnswer::class)->handle($intake, 'landlord_permission', null, ['value' => 'yes']);

    $summary = CustomerCompletionSummary::forIntake(
        $intake->fresh(['answers', 'uploads', 'aircoRooms', 'templateVersion.sections.questions.options']) ?? $intake,
    );
    expect(collect($summary['highlights'])->pluck('label')->all())->toContain('Toestemming verhuurder');

    $html = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('completed', true)
        ->assertSee('Je aanvraag is verstuurd')
        ->assertSeeHtml('data-testid="thank-you-check"')
        ->assertDontSeeHtml('data-testid="wizard-progress"')
        ->assertDontSee('Jouw deel is compleet')
        ->assertSee('Toestemming verhuurder')
        ->html();

    expect($html)->toContain('heeft je gegevens en foto')
        ->and($html)->toContain('binnen 2 werkdagen')
        ->and($html)->toMatch('/data-testid="customer-thank-you"[^>]*(?!justify-center)[^>]*>/')
        ->and($html)->not->toContain('flex-1 flex-col justify-center rounded-xl border border-[#dde2da] bg-white p-6');

    if ($companyName !== '') {
        expect($html)->toContain($companyName);
    }
});
