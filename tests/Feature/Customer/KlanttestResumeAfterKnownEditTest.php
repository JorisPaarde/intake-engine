<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\CompletenessChecker;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\IntakeStatus;
use App\Enums\QuestionType;
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

function resumeKnownEditIntake(): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    $intake = Intake::factory()->create([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_name' => 'Resume Test',
        'customer_email' => 'resume-known-edit@example.com',
    ]);

    $save = app(SaveIntakeAnswer::class);
    // No cooling/heating keywords — offline local parser must not fill cooling_heating.
    $save->handle($intake, 'request_reason', null, ['text' => 'Drie ruimtes: woonkamer, slaapkamer en zolder.'], PrefillSources::REQUEST_TEXT);
    // Room count prefilled so rooms exist; cooling_heating stays unanswered (required).
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 3], PrefillSources::AI_TEXT);

    foreach (['room-1', 'room-2', 'room-3'] as $i => $room) {
        $save->handle(
            $intake,
            'room_type',
            $room,
            ['value' => $i === 0 ? 'living_room' : 'bedroom'],
            PrefillSources::AI_TEXT,
        );
        $save->handle($intake, 'floor_level', $room, ['value' => (string) $i], PrefillSources::AI_TEXT);
    }

    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    return $intake->fresh() ?? $intake;
}

/**
 * @return list<array<string, mixed>>
 */
function resumeKnownEditSteps(Intake $intake, array $forceShow = []): array
{
    $version = $intake->templateVersion()
        ->with(['sections.questions.options', 'sections.questions.rules'])
        ->firstOrFail();

    return app(IntakeStepBuilder::class)->build($intake->fresh() ?? $intake, $version, [], $forceShow);
}

function assertStepNotPastUnansweredRequired(string $activeKey, array $steps): void
{
    $activeIndex = collect($steps)->search(fn (array $s): bool => ($s['key'] ?? '') === $activeKey);
    expect($activeIndex)->toBeInt();

    $coolingIndex = collect($steps)->search(fn (array $s): bool => ($s['question_key'] ?? '') === 'cooling_heating');
    expect($coolingIndex)->toBeInt('cooling_heating must be a visible required step');

    expect($activeIndex)->toBeLessThanOrEqual($coolingIndex);
}

test('refresh after Wijzigen never lands past unanswered required cooling_heating', function () {
    $intake = resumeKnownEditIntake();

    $before = resumeKnownEditSteps($intake);
    $coolingIndex = collect($before)->search(fn (array $s): bool => ($s['question_key'] ?? '') === 'cooling_heating');
    $photosIndex = collect($before)->search(
        fn (array $s): bool => ($s['question_key'] ?? '') === 'room_photos' && ($s['section_instance_key'] ?? null) === 'room-1',
    );
    expect($coolingIndex)->toBeInt()
        ->and($photosIndex)->toBeInt()
        ->and($photosIndex)->toBeGreaterThan($coolingIndex);

    // Prove the old fallback: hidden floor cursor → first rooms step (past cooling).
    $legacyResume = app(IntakeStepBuilder::class)->indexForCursor($before, 'rooms', 'floor_level', 'room-2');
    expect($legacyResume)->toBe($photosIndex);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->call('editKnownAnswer', 'floor_level', 'room-2');

    expect($component->get('forceShowKnown'))->toContain('room-2__floor_level');
    expect($intake->fresh()->current_question_key)->toBe('floor_level');
    expect($intake->fresh()->current_section_instance_key)->toBe('room-2');

    // Simulate refresh: new component instance, empty forceShowKnown.
    $resumed = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $activeKey = $resumed->get('activeStepKey');
    $steps = resumeKnownEditSteps($intake);

    assertStepNotPastUnansweredRequired($activeKey, $steps);
    expect($activeKey)->not->toBe('rooms::room-1::room_photos');
    expect($resumed->get('forceShowKnown'))->toBe([]);
});

test('Wijzigen without refresh does not skip required cooling_heating on next', function () {
    $intake = resumeKnownEditIntake();

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->call('editKnownAnswer', 'floor_level', 'room-2')
        ->call('next');

    // After forced edit, back on known-summary — not past cooling_heating.
    $steps = resumeKnownEditSteps($intake);
    $activeKey = $component->get('activeStepKey');
    assertStepNotPastUnansweredRequired($activeKey, $steps);
    expect($activeKey)->toBe('_known_summary');

    $component->call('next');
    expect($component->get('activeStepKey'))->toBe('request::cooling_heating');
});

test('resume with cursor on hidden step lands on first unanswered required', function () {
    $intake = resumeKnownEditIntake();
    $intake->update([
        'current_section_key' => 'rooms',
        'current_question_key' => 'floor_level',
        'current_section_instance_key' => 'room-2',
    ]);

    $resumed = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $steps = resumeKnownEditSteps($intake);
    $activeKey = $resumed->get('activeStepKey');

    assertStepNotPastUnansweredRequired($activeKey, $steps);
    expect($activeKey)->toBe('request::cooling_heating');
});

test('goToMissing then next returns to next missing or Afronden, skipping answered steps', function () {
    $intake = resumeKnownEditIntake();
    // Fill everything except cooling_heating and privacy_consent so the gap between
    // them is full of already-answered steps that must not be walked one-by-one.
    fillResumeIntakeExcept($intake, ['cooling_heating', 'privacy_consent']);

    $stepsBefore = resumeKnownEditSteps($intake->fresh() ?? $intake);
    $coolingIndex = collect($stepsBefore)->search(fn (array $s): bool => ($s['question_key'] ?? '') === 'cooling_heating');
    expect($coolingIndex)->toBeInt();
    $immediateNextKey = $stepsBefore[$coolingIndex + 1]['key'] ?? null;
    expect($immediateNextKey)->not->toBeNull()
        ->and($immediateNextKey)->not->toBe('closing::privacy_consent')
        ->and($immediateNextKey)->not->toContain('privacy_consent');

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->call('complete');

    expect($component->get('showMissing'))->toBeTrue();
    $missingKeys = collect($component->get('completionMissing'))->pluck('question_key')->all();
    expect($missingKeys)->toContain('cooling_heating')
        ->and($missingKeys)->toContain('privacy_consent');

    $component->call('goToMissing', 'cooling_heating', null);
    expect($component->get('returnAfterMissing'))->toBeTrue()
        ->and($component->get('activeStepKey'))->toBe('request::cooling_heating');

    $component->set('form.cooling_heating.value', 'both')
        ->call('next');

    $activeKey = $component->get('activeStepKey');
    expect($component->get('returnAfterMissing'))->toBeFalse()
        // Must not advance only one step into already-answered territory.
        ->and($activeKey)->not->toBe($immediateNextKey)
        ->and($activeKey)->toContain('privacy_consent');
});

/**
 * Fill all CompletenessChecker missing items except the given question keys.
 *
 * @param  list<string>  $exceptKeys
 */
function fillResumeIntakeExcept(Intake $intake, array $exceptKeys): void
{
    $save = app(SaveIntakeAnswer::class);
    $store = app(StoreIntakeUpload::class);
    $checker = app(CompletenessChecker::class);
    $except = array_fill_keys($exceptKeys, true);

    for ($attempt = 0; $attempt < 50; $attempt++) {
        $intake->refresh();
        $version = $intake->templateVersion()
            ->with(['sections.questions.options', 'sections.questions.rules'])
            ->firstOrFail();
        $check = $checker->check($intake, $version);
        $remaining = array_values(array_filter(
            $check['missing'],
            static fn (array $item): bool => ! isset($except[$item['question_key']]),
        ));

        if ($remaining === []) {
            return;
        }

        foreach ($remaining as $item) {
            $question = null;
            foreach ($version->sections as $section) {
                foreach ($section->questions as $candidate) {
                    if ($candidate->key === $item['question_key']) {
                        $question = $candidate;
                        break 2;
                    }
                }
            }

            if (! $question instanceof IntakeQuestion) {
                continue;
            }

            if ($question->type === QuestionType::Photo) {
                markTestUploadSatisfied($store->handle(
                    $intake,
                    $item['question_key'],
                    $item['section_instance_key'],
                    UploadedFile::fake()->image($item['question_key'].'.jpg', 1280, 960),
                ));

                continue;
            }

            $payload = match ($question->type) {
                QuestionType::ShortText, QuestionType::LongText => ['text' => 'Test '.$question->key],
                QuestionType::Number => ['number' => 1],
                QuestionType::SingleChoice => ['value' => $question->options->first()?->value ?? 'yes'],
                QuestionType::MultiChoice => ['values' => [$question->options->first()?->value ?? 'a']],
                QuestionType::Boolean => ['bool' => true],
                default => ['text' => 'Test'],
            };

            $save->handle($intake, $item['question_key'], $item['section_instance_key'], $payload);
        }
    }

    throw new RuntimeException('Could not fill resume intake except '.implode(',', $exceptKeys));
}

test('normal mid-wizard refresh still resumes on the same step', function () {
    $intake = resumeKnownEditIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'cooling_heating', null, ['value' => 'both']);

    // Satisfy earlier required room photo so preferred_indoor is a valid resume target.
    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'room_photos',
        'room-1',
        UploadedFile::fake()->image('room-1.jpg', 1280, 960),
    );
    markTestUploadSatisfied($upload);

    $intake->update([
        'current_section_key' => 'rooms',
        'current_question_key' => 'preferred_indoor_location',
        'current_section_instance_key' => 'room-1',
    ]);

    $resumed = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);

    expect($resumed->get('activeStepKey'))->toBe('rooms::room-1::preferred_indoor_location');
});
