<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Support\PrefillSources;
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

test('Wijzigen then refresh lands on known-summary, not past cooling_heating', function () {
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
    expect(app(IntakeStepBuilder::class)->indexForExactCursor($before, 'rooms', 'floor_level', 'room-2'))
        ->toBeNull();

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->call('editKnownAnswer', 'floor_level', 'room-2');

    expect($component->get('forceShowKnown'))->toContain('room-2__floor_level')
        // Session-only edit step is shown, but persisted cursor stays on known-summary.
        ->and($intake->fresh()->current_section_key)->toBe('request')
        ->and($intake->fresh()->current_question_key)->toBe('_known_summary')
        ->and($intake->fresh()->current_section_instance_key)->toBeNull();

    // Simulate refresh: new component instance, empty forceShowKnown.
    $resumed = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $activeKey = $resumed->get('activeStepKey');
    $steps = resumeKnownEditSteps($intake);

    expect($activeKey)->toBe('_known_summary');
    assertStepNotPastUnansweredRequired($activeKey, $steps);
    expect($activeKey)->not->toBe('rooms::room-1::room_photos');
    expect($resumed->get('forceShowKnown'))->toBe([]);

    $resumed->call('next');
    expect($resumed->get('activeStepKey'))->toBe('request::cooling_heating');
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
