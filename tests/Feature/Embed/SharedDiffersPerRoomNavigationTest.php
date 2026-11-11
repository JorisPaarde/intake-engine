<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\CompletenessChecker;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Support\PrefillSources;
use App\Domains\Intake\Support\SharedRoomAnswers;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(IntakeTemplateSeeder::class);
    config([
        'ai.request_prefill.sync_on_create' => false,
        'ai.text_inference.enabled' => false,
        'ai.provider' => 'fake',
    ]);
});

/**
 * Klanttest 11 nov run 3: tussenwoning, 3 slaapkamers 3×5 (2037GR 273).
 *
 * @return array{0: Intake, 1: SaveIntakeAnswer}
 */
function differsNavigationIntake(): array
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::InProgress,
        'source' => IntakeSource::Embed,
        'customer_access_enabled' => true,
        'address_postal_code' => '2037GR',
        'address_house_number' => '273',
        'address_city' => 'Haarlem',
        'address_line' => 'Teststraat 273',
    ]);

    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'request_reason', null, [
        'text' => 'Ik wil airco in 3 slaapkamers, zowel verwarmen als koelen. De ruimtes zijn ongeveer 3 bij 5 meter.',
    ]);
    $save->handle(
        $intake,
        'indoor_unit_count',
        null,
        ['number' => 3],
        PrefillSources::REQUEST_TEXT,
        factProvenance: 'stated',
        factConfidence: 100,
        factEvidence: '3 slaapkamers',
        factSource: 'klantantwoord',
    );
    $save->handle(
        $intake,
        'cooling_heating',
        null,
        ['value' => 'both'],
        PrefillSources::REQUEST_TEXT,
        factProvenance: 'stated',
        factConfidence: 100,
        factEvidence: 'verwarmen als koelen',
        factSource: 'klantantwoord',
    );

    foreach (['room-1', 'room-2', 'room-3'] as $room) {
        $save->handle(
            $intake,
            'room_type',
            $room,
            ['value' => 'bedroom'],
            PrefillSources::REQUEST_TEXT,
            factProvenance: 'stated',
            factConfidence: 100,
            factEvidence: 'slaapkamers',
            factSource: 'klantantwoord',
        );
        $save->handle(
            $intake,
            'room_length_m',
            $room,
            ['number' => 3],
            PrefillSources::REQUEST_TEXT,
            factProvenance: 'stated',
            factConfidence: 100,
            factEvidence: '3 bij 5 meter',
            factSource: 'klantantwoord',
        );
        $save->handle(
            $intake,
            'room_width_m',
            $room,
            ['number' => 5],
            PrefillSources::REQUEST_TEXT,
            factProvenance: 'stated',
            factConfidence: 100,
            factEvidence: '3 bij 5 meter',
            factSource: 'klantantwoord',
        );
        $save->handle(
            $intake,
            'room_area_m2',
            $room,
            ['number' => 15],
            PrefillSources::DERIVED_LXW,
            factConfidence: 100,
            factSource: 'afgeleid',
        );
    }

    app(DossierManager::class)->initialize($intake->fresh());

    return [
        $intake->fresh([
            'answers',
            'templateVersion.sections.questions.options',
            'templateVersion.sections.questions.rules',
        ]),
        $save,
    ];
}

test('afronden-links naar floor_level zonder differs landen op gedeelde stap (geen dode room-N link)', function () {
    [$intake] = differsNavigationIntake();

    $check = app(CompletenessChecker::class)->check($intake, $intake->templateVersion);
    $floorMissing = collect($check['missing'])->where('question_key', 'floor_level')->values();
    expect($floorMissing)->toHaveCount(3);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    // Simuleer Afronden-lijst (enrich remap).
    $component->call('complete');
    expect($component->get('showMissing'))->toBeTrue();

    $completion = collect($component->get('completionMissing'))->where('question_key', 'floor_level')->values();
    expect($completion)->not->toBeEmpty()
        ->and($completion->first()['section_instance_key'])->toBe(SharedRoomAnswers::SCOPE_INSTANCE)
        ->and($completion)->toHaveCount(1);

    $component->call(
        'goToMissing',
        'floor_level',
        $completion->first()['section_instance_key'],
    );
    expect($component->get('activeStepKey'))->toBe('rooms::_shared::floor_level');
});

test('verschilt per ruimte op verdieping toont drie kamervragen en goToMissing werkt na afronden-check', function () {
    [$intake] = differsNavigationIntake();

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $steps = app(IntakeStepBuilder::class)->build($intake, $intake->templateVersion);
    $shared = collect($steps)->first(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'floor_level'
            && ($step['shared_scope'] ?? false) === true,
    );
    expect($shared)->not->toBeNull();

    $component->set('activeStepKey', $shared['key'])->call('chooseSharedDiffers');

    expect($component->get('activeStepKey'))->toBe('rooms::room-1::floor_level');

    $marker = IntakeAnswer::query()
        ->where('intake_id', $intake->id)
        ->where('question_key', SharedRoomAnswers::differsMarkerKey('floor_level'))
        ->whereNull('section_instance_key')
        ->first();
    expect($marker)->not->toBeNull();

    $intake = $intake->fresh([
        'answers',
        'templateVersion.sections.questions.options',
        'templateVersion.sections.questions.rules',
    ]);
    $stepsAfter = app(IntakeStepBuilder::class)->build($intake, $intake->templateVersion);
    $perRoom = collect($stepsAfter)->filter(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'floor_level'
            && str_starts_with((string) ($step['section_instance_key'] ?? ''), 'room-'),
    )->values();
    expect($perRoom)->toHaveCount(3);

    // Beantwoord kamer 1, laat 2/3 open.
    $component->set('form.room-1__floor_level.value', '1')->call('next', 'rooms::room-1::floor_level');

    // Completeness + remap (zelfde pad als Afronden-lijst), zonder must-accept-blokkade.
    $check = app(CompletenessChecker::class)->check(
        $intake->fresh(['answers', 'uploads']),
        $intake->templateVersion,
    );
    expect(collect($check['missing'])->where('question_key', 'floor_level')->count())->toBe(2);

    $component->call('goToMissing', 'floor_level', 'room-2');
    expect($component->get('activeStepKey'))->toBe('rooms::room-2::floor_level');

    $component->call('goToMissing', 'floor_level', 'room-3');
    expect($component->get('activeStepKey'))->toBe('rooms::room-3::floor_level');
});

test('verschilt per ruimte op zon toont per-kamer stappen vóór ruimtefoto', function () {
    [$intake] = differsNavigationIntake();

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $steps = app(IntakeStepBuilder::class)->build($intake, $intake->templateVersion);
    $shared = collect($steps)->first(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'sun_exposure'
            && ($step['shared_scope'] ?? false) === true,
    );
    expect($shared)->not->toBeNull();

    $component->set('activeStepKey', $shared['key'])->call('chooseSharedDiffers');
    expect($component->get('activeStepKey'))->toBe('rooms::room-1::sun_exposure');

    $stepsAfter = app(IntakeStepBuilder::class)->build(
        $intake->fresh([
            'answers',
            'templateVersion.sections.questions.options',
            'templateVersion.sections.questions.rules',
        ]),
        $intake->templateVersion,
    );
    $perRoomSun = collect($stepsAfter)->filter(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'sun_exposure'
            && str_starts_with((string) ($step['section_instance_key'] ?? ''), 'room-'),
    )->values();
    expect($perRoomSun)->toHaveCount(3);

    $room1Photo = collect($stepsAfter)->search(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'room_photos'
            && ($step['section_instance_key'] ?? null) === 'room-1',
    );
    $room1Sun = collect($stepsAfter)->search(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'sun_exposure'
            && ($step['section_instance_key'] ?? null) === 'room-1',
    );
    expect($room1Sun)->toBeInt()
        ->and($room1Photo)->toBeInt()
        ->and($room1Sun)->toBeLessThan($room1Photo);
});

test('na differs wordt verdieping niet stil van kamer 1 naar kamer 2 gekopieerd', function () {
    [$intake] = differsNavigationIntake();

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $steps = app(IntakeStepBuilder::class)->build($intake, $intake->templateVersion);
    $shared = collect($steps)->first(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'floor_level'
            && ($step['shared_scope'] ?? false) === true,
    );

    $component->set('activeStepKey', $shared['key'])->call('chooseSharedDiffers');
    $component->set('form.room-1__floor_level.value', 'ground')->call('next', 'rooms::room-1::floor_level');

    // Navigeer naar kamer-2 verdieping (kan na andere differs-first vragen komen).
    $stepsAfter = app(IntakeStepBuilder::class)->build(
        $intake->fresh([
            'answers',
            'templateVersion.sections.questions.options',
            'templateVersion.sections.questions.rules',
        ]),
        $intake->templateVersion,
    );
    $room2Floor = collect($stepsAfter)->first(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'floor_level'
            && ($step['section_instance_key'] ?? null) === 'room-2',
    );
    expect($room2Floor)->not->toBeNull();

    $room2Index = collect($stepsAfter)->search(
        static fn (array $step): bool => $step['key'] === $room2Floor['key'],
    );
    expect($room2Index)->toBeInt();
    $component->call('goToStep', $room2Index);

    $room2Answer = $intake->fresh(['answers'])->answers->first(
        static fn (IntakeAnswer $row): bool => $row->question_key === 'floor_level'
            && $row->section_instance_key === 'room-2',
    );
    // Mag leeg/ontbrekend zijn, maar nooit stil gekopieerd van kamer 1.
    $copied = is_array($room2Answer?->value) ? ($room2Answer->value['value'] ?? null) : null;
    expect($copied)->not->toBe('ground');
    expect($component->get('form.room-2__floor_level.value') ?? null)->not->toBe('ground');
});
