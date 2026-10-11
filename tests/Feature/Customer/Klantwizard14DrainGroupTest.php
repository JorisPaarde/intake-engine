<?php

declare(strict_types=1);

use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\AI\Services\IntakeAttentionContextBuilder;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\GenerateIntakeReportHtml;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Support\CustomerAnswerBlocks;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\IntakeStatus;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
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

function makeKlantwizard14Intake(?int $templateVersion = null): Intake
{
    $user = User::factory()->create();
    $template = IntakeTemplate::query()->where('key', 'airco')->firstOrFail();
    $version = $templateVersion === null
        ? $template->latestPublishedVersion()
        : $template->versions()->where('version', $templateVersion)->firstOrFail();

    return Intake::factory()->create([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
        'access_token' => str_repeat('b', 64),
        'token_expires_at' => now()->addDays(14),
    ]);
}

test('A1: v27 combineert drain_location en drain_photo in één wizardstap', function () {
    $intake = makeKlantwizard14Intake();
    $version = $intake->fresh()->templateVersion()->with(['sections.questions'])->firstOrFail();

    expect($version->version)->toBe(27);

    $drainLocation = $version->sections->flatMap->questions->firstWhere('key', 'drain_location');
    $drainPhoto = $version->sections->flatMap->questions->firstWhere('key', 'drain_photo');

    expect($drainLocation->meta['wizard_group'] ?? null)->toBe('drain_nearby')
        ->and($drainLocation->meta['wizard_group_title'] ?? null)->toBe('Afvoer in de buurt')
        ->and($drainLocation->meta['wizard_group_help'] ?? null)->toBe('Een foto helpt de installateur. Weet je het niet? Ga gewoon verder.')
        ->and($drainPhoto->meta['wizard_group'] ?? null)->toBe('drain_nearby')
        ->and($drainPhoto->is_required)->toBeFalse()
        ->and($drainPhoto->meta['allow_skip'] ?? null)->toBeNull();

    $steps = app(IntakeStepBuilder::class)->build($intake->fresh(), $version);
    $drainSteps = collect($steps)->filter(
        static fn (array $step): bool => ($step['group_key'] ?? null) === 'drain_nearby'
            || in_array($step['question_key'] ?? null, ['drain_location', 'drain_photo'], true),
    )->values();

    expect($drainSteps)->toHaveCount(1)
        ->and($drainSteps[0]['kind'])->toBe('question_group')
        ->and($drainSteps[0]['title'])->toBe('Afvoer in de buurt')
        ->and($drainSteps[0]['group_question_keys'])->toBe(['drain_location', 'drain_photo'])
        ->and($drainSteps[0]['is_required'])->toBeFalse();

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $viewSteps = $component->viewData('steps');
    $index = collect($viewSteps)->search(
        static fn (array $step): bool => ($step['group_key'] ?? null) === 'drain_nearby',
    );
    expect($index)->not->toBeFalse();

    $component->set('stepIndex', (int) $index)
        ->set('activeStepKey', $viewSteps[(int) $index]['key'])
        ->assertSee('Afvoer in de buurt')
        ->assertSee('Een foto helpt de installateur. Weet je het niet? Ga gewoon verder.')
        ->assertSee('data-testid="drain-nearby-group"', false)
        ->assertDontSee('data-testid="photo-skip"', false);
});

test('A1: v26-intakes blijven twee aparte afvoerstappen zonder wizard_group', function () {
    seedAllAircoTemplateVersions();
    $intake = makeKlantwizard14Intake(26);
    $version = $intake->fresh()->templateVersion()->with(['sections.questions'])->firstOrFail();

    expect($version->version)->toBe(26);

    $steps = app(IntakeStepBuilder::class)->build($intake->fresh(), $version);
    $keys = array_column($steps, 'question_key');

    expect($keys)->toContain('drain_location')
        ->and($keys)->toContain('drain_photo')
        ->and(collect($steps)->firstWhere('group_key', 'drain_nearby'))->toBeNull();

    $drainPhoto = collect($steps)->firstWhere('question_key', 'drain_photo');
    expect($drainPhoto)->not->toBeNull()
        ->and($drainPhoto['kind'] ?? 'question')->toBe('question');
});

test('A1: afvoerfoto blijft optioneel — Volgende zonder foto is toegestaan', function () {
    $intake = makeKlantwizard14Intake();
    app(SaveIntakeAnswer::class)->handle($intake, 'drain_location', null, [
        'value' => 'unknown',
    ]);

    $version = $intake->fresh()->templateVersion()->with(['sections.questions.rules'])->firstOrFail();
    $step = collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version))
        ->firstWhere('group_key', 'drain_nearby');

    expect($step)->not->toBeNull()
        ->and($step['is_required'])->toBeFalse();

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->call('next')
        ->assertHasNoErrors();
});

function drainNearbyStepKeyForGroup(Intake $intake): string
{
    $version = $intake->fresh()->templateVersion()->with(['sections.questions.rules'])->firstOrFail();
    $step = collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version))
        ->firstWhere('group_key', 'drain_nearby');

    expect($step)->not->toBeNull();

    return (string) $step['key'];
}

function drainLocationAnswer(Intake $intake): ?IntakeAnswer
{
    return IntakeAnswer::query()
        ->where('intake_id', $intake->id)
        ->where('question_key', 'drain_location')
        ->whereNull('section_instance_key')
        ->first();
}

/** @return array<string, mixed> */
function differingDrainAiOutput(): array
{
    return [
        'drain_location' => 'indoor_nearby',
        'detected_subject' => 'outdoor_location',
        'subject_match' => 'yes',
        'confidence' => 'high',
        'evidence' => 'Synthetische afvoerplek zonder klantdata.',
        'retake_instruction' => null,
    ];
}

/** @param  Testable  $component */
function uploadDrainPhotoAndAssess(mixed $component): void
{
    Queue::fake([AssessUploadedPhotoJob::class]);
    FakeAiClient::alwaysReturn(differingDrainAiOutput());

    $component->set('photoFiles.drain_photo', UploadedFile::fake()->image('achtergevel.jpg', 1200, 900));

    $uploadIds = [];
    foreach (Queue::pushed(AssessUploadedPhotoJob::class) as $job) {
        if ($job instanceof AssessUploadedPhotoJob) {
            $uploadIds[] = $job->uploadId;
        }
    }

    expect($uploadIds)->not->toBeEmpty();

    foreach (array_values(array_unique($uploadIds)) as $uploadId) {
        runAssessUploadedPhotoJob($uploadId);
    }

    $component->call('pollPendingAssessments');
}

test('P1-3: keuze + afwijkende foto-afleiding → drain_location blijft klantkeuze outside_nearby', function () {
    $intake = makeKlantwizard14Intake();
    $intake->update(['status' => IntakeStatus::Sent]);
    $drainKey = drainNearbyStepKeyForGroup($intake);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('activeStepKey', $drainKey)
        ->set('form.drain_location.value', 'outside_nearby');

    $afterChoice = drainLocationAnswer($intake->fresh());
    expect($afterChoice)->not->toBeNull()
        ->and($afterChoice->value)->toBe(['value' => 'outside_nearby'])
        ->and($afterChoice->prefill_source)->toBeNull();

    uploadDrainPhotoAndAssess($component);

    $afterPhoto = drainLocationAnswer($intake->fresh());
    expect($afterPhoto)->not->toBeNull()
        ->and($afterPhoto->value)->toBe(['value' => 'outside_nearby'])
        ->and($afterPhoto->prefill_source)->toBeNull()
        ->and($component->get('form.drain_location'))->toBe(['value' => 'outside_nearby']);

    $component->call('next')->assertHasNoErrors();

    $afterNext = drainLocationAnswer($intake->fresh());
    expect($afterNext)->not->toBeNull()
        ->and($afterNext->value)->toBe(['value' => 'outside_nearby'])
        ->and($afterNext->prefill_source)->toBeNull();
});

test('P1-3: foto eerst (afwijkende afleiding) daarna klantkeuze → drain_location = outside_nearby', function () {
    $intake = makeKlantwizard14Intake();
    $intake->update(['status' => IntakeStatus::Sent]);
    $drainKey = drainNearbyStepKeyForGroup($intake);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('activeStepKey', $drainKey);

    uploadDrainPhotoAndAssess($component);

    $afterPhoto = drainLocationAnswer($intake->fresh());
    expect($afterPhoto)->not->toBeNull()
        ->and($afterPhoto->value)->toBe(['value' => 'indoor_nearby'])
        ->and($afterPhoto->prefill_source)->toBe(PrefillSources::AI_PHOTO);

    $component->set('form.drain_location.value', 'outside_nearby')
        ->call('next')
        ->assertHasNoErrors();

    $afterNext = drainLocationAnswer($intake->fresh());
    expect($afterNext)->not->toBeNull()
        ->and($afterNext->value)->toBe(['value' => 'outside_nearby'])
        ->and($afterNext->prefill_source)->toBeNull();
});

test('P1-3: alleen keuze zonder foto → drain_location opgeslagen', function () {
    $intake = makeKlantwizard14Intake();
    $intake->update(['status' => IntakeStatus::Sent]);
    $drainKey = drainNearbyStepKeyForGroup($intake);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('activeStepKey', $drainKey)
        ->set('form.drain_location.value', 'outside_nearby')
        ->call('next')
        ->assertHasNoErrors();

    $answer = drainLocationAnswer($intake->fresh());
    expect($answer)->not->toBeNull()
        ->and($answer->value)->toBe(['value' => 'outside_nearby'])
        ->and($answer->prefill_source)->toBeNull();
});

test('P1-3: na afronden tonen rapport, Antwoorden van de klant en AI-context de keuze', function () {
    $intake = makeKlantwizard14Intake();
    $intake->update(['status' => IntakeStatus::Sent]);
    $drainKey = drainNearbyStepKeyForGroup($intake);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('activeStepKey', $drainKey)
        ->set('form.drain_location.value', 'outside_nearby');

    uploadDrainPhotoAndAssess($component);
    $component->call('next')->assertHasNoErrors();

    // Vul rest minimaal zodat CompleteIntake mag (privacy + truth).
    app(SaveIntakeAnswer::class)->handle($intake, 'privacy_consent', null, ['bool' => true]);
    app(SaveIntakeAnswer::class)->handle($intake, 'truth_confirmation', null, ['bool' => true]);
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-1', ['value' => 'living_room']);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_name', 'room-1', ['text' => 'Woonkamer']);
    app(SaveIntakeAnswer::class)->handle($intake, 'floor_level', 'room-1', ['value' => '0']);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_length_m', 'room-1', ['number' => 4]);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_width_m', 'room-1', ['number' => 3]);
    app(SaveIntakeAnswer::class)->handle($intake, 'ceiling_height_m', 'room-1', ['number' => 2.5]);
    app(SaveIntakeAnswer::class)->handle($intake, 'outdoor_location', null, ['value' => 'garden']);
    app(SaveIntakeAnswer::class)->handle($intake, 'ownership', null, ['value' => 'owned']);

    // Force complete via action if wizard finish is heavy — check report/display on current state.
    $intake = $intake->fresh();
    $version = $intake->templateVersion()->with(['sections.questions.options'])->firstOrFail();

    $html = app(GenerateIntakeReportHtml::class)->handle($intake, $version);
    expect($html)->toContain('Regenpijp of putje buiten in de buurt');

    $blocks = CustomerAnswerBlocks::forIntake($intake);
    $drainItems = collect($blocks)
        ->flatMap(static fn (array $b) => $b['items'])
        ->filter(static fn (array $item): bool => str_contains($item['label'], 'afvoer'));
    expect($drainItems)->not->toBeEmpty()
        ->and($drainItems->first()['value'] ?? null)->toBe('Regenpijp of putje buiten in de buurt');

    $context = app(IntakeAttentionContextBuilder::class)->build($intake);
    expect($context['answers']['drain_location'] ?? null)->toBe(['value' => 'outside_nearby']);
});

test('P1-3: foto-afleiding overschrijft of wist nooit een expliciete klantkeuze', function () {
    $intake = makeKlantwizard14Intake();
    $intake->update(['status' => IntakeStatus::Sent]);
    app(SaveIntakeAnswer::class)->handle($intake, 'drain_location', null, ['value' => 'outside_nearby']);

    FakeAiClient::alwaysReturn(differingDrainAiOutput());
    Queue::fake([AssessUploadedPhotoJob::class]);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('activeStepKey', drainNearbyStepKeyForGroup($intake));

    uploadDrainPhotoAndAssess($component);

    $answer = drainLocationAnswer($intake->fresh());
    expect($answer)->not->toBeNull()
        ->and($answer->value)->toBe(['value' => 'outside_nearby'])
        ->and($answer->prefill_source)->toBeNull();
});

test('P1-3: weggehaalde afvoerfoto laat geen drain_location-klantantwoord achter na Volgende', function () {
    $intake = makeKlantwizard14Intake();
    $intake->update(['status' => IntakeStatus::Sent]);
    $drainKey = drainNearbyStepKeyForGroup($intake);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('activeStepKey', $drainKey);

    uploadDrainPhotoAndAssess($component);

    $aiAnswer = drainLocationAnswer($intake->fresh());
    expect($aiAnswer)->not->toBeNull()
        ->and($aiAnswer->value)->toBe(['value' => 'indoor_nearby'])
        ->and($aiAnswer->prefill_source)->toBe(PrefillSources::AI_PHOTO);

    $uploadId = (int) $intake->fresh()->uploads()->where('question_key', 'drain_photo')->value('id');
    expect($uploadId)->toBeGreaterThan(0);

    $component->call('removePhoto', $uploadId)->assertHasNoErrors();

    expect(drainLocationAnswer($intake->fresh()))->toBeNull()
        ->and($component->get('form.drain_location'))->toBe([]);

    $component->call('next')->assertHasNoErrors();

    $afterNext = drainLocationAnswer($intake->fresh());
    expect($afterNext)->toBeNull();
});

test('P1-3: leeg form op Volgende wist geen eerder opgeslagen drain_location (foto-refresh-race)', function () {
    $intake = makeKlantwizard14Intake();
    $intake->update(['status' => IntakeStatus::Sent]);
    $drainKey = drainNearbyStepKeyForGroup($intake);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('activeStepKey', $drainKey)
        ->set('form.drain_location.value', 'outside_nearby');

    expect(drainLocationAnswer($intake->fresh())?->value)->toBe(['value' => 'outside_nearby']);

    // Simuleer Livewire-race: foto-poll response kwam terug zonder de keuze in form,
    // terwijl de DB-rij al bestaat (updated() had eerder opgeslagen).
    $component->set('form.drain_location', []);

    $component->call('next')->assertHasNoErrors();

    $answer = drainLocationAnswer($intake->fresh());
    expect($answer)->not->toBeNull()
        ->and($answer->value)->toBe(['value' => 'outside_nearby'])
        ->and($answer->prefill_source)->toBeNull();
});

test('P1-3: closing_wishes planning + opmerkingen blijven bewaard in rapport', function () {
    $intake = makeKlantwizard14Intake();
    $intake->update(['status' => IntakeStatus::Sent]);

    $version = $intake->fresh()->templateVersion()->with(['sections.questions.rules'])->firstOrFail();
    $steps = app(IntakeStepBuilder::class)->build($intake->fresh(), $version);
    $closing = collect($steps)->firstWhere('kind', 'closing_wishes');
    expect($closing)->not->toBeNull();

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('activeStepKey', $closing['key'])
        ->set('form.desired_planning.value', 'asap')
        ->set('form.additional_comments.text', 'Synthetische testopmerking zonder persoonsgegevens.')
        ->call('next')
        ->assertHasNoErrors();

    $planning = IntakeAnswer::query()
        ->where('intake_id', $intake->id)
        ->where('question_key', 'desired_planning')
        ->first();
    $remarks = IntakeAnswer::query()
        ->where('intake_id', $intake->id)
        ->where('question_key', 'additional_comments')
        ->first();

    expect($planning?->value)->toBe(['value' => 'asap'])
        ->and($remarks?->value)->toBe(['text' => 'Synthetische testopmerking zonder persoonsgegevens.']);

    $html = app(GenerateIntakeReportHtml::class)->handle(
        $intake->fresh(),
        $version->load('sections.questions.options'),
    );
    expect($html)->toContain('Synthetische testopmerking zonder persoonsgegevens.');
});
