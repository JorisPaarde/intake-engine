<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Services\VisibilityResolver;
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
 * @return array{0: Intake, 1: SaveIntakeAnswer}
 */
function jorisSharedScenarioIntake(): array
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
    ]);

    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'request_reason', null, ['text' => 'Ik wil airco in 3 slaapkamers, zowel verwarmen als koelen. Merk maakt niet uit. De ruimtes zijn ongeveer 3 bij 5 meter.']);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 3], PrefillSources::REQUEST_TEXT, factProvenance: 'stated', factConfidence: 100, factEvidence: '3 slaapkamers', factSource: 'klantantwoord');
    $save->handle($intake, 'cooling_heating', null, ['value' => 'both'], PrefillSources::REQUEST_TEXT, factProvenance: 'stated', factConfidence: 100, factEvidence: 'verwarmen als koelen', factSource: 'klantantwoord');
    $save->handle($intake, 'brand_preference', null, ['values' => ['no_preference']], PrefillSources::REQUEST_TEXT, factProvenance: 'stated', factConfidence: 100, factEvidence: 'Merk maakt niet uit', factSource: 'klantantwoord');

    foreach (['room-1', 'room-2', 'room-3'] as $room) {
        $save->handle($intake, 'room_type', $room, ['value' => 'bedroom'], PrefillSources::REQUEST_TEXT, factProvenance: 'stated', factConfidence: 100, factEvidence: 'slaapkamers', factSource: 'klantantwoord');
        $save->handle($intake, 'room_length_m', $room, ['number' => 3], PrefillSources::REQUEST_TEXT, factProvenance: 'stated', factConfidence: 100, factEvidence: '3 bij 5 meter', factSource: 'klantantwoord');
        $save->handle($intake, 'room_width_m', $room, ['number' => 5], PrefillSources::REQUEST_TEXT, factProvenance: 'stated', factConfidence: 100, factEvidence: '3 bij 5 meter', factSource: 'klantantwoord');
        $save->handle($intake, 'room_area_m2', $room, ['number' => 15], PrefillSources::DERIVED_LXW, factConfidence: 100, factSource: 'afgeleid');
        $save->handle($intake, 'room_size_indication', $room, ['value' => 'medium'], PrefillSources::DERIVED_LXW, factConfidence: 100, factSource: 'afgeleid');
    }

    app(DossierManager::class)->initialize($intake->fresh());

    return [$intake->fresh(['answers', 'aircoRooms', 'templateVersion.sections.questions.options']), $save];
}

test('joris-scenario: gedeelde ruimtevagen één keer + geen herhaalde kamernamen/maten', function () {
    [$intake] = jorisSharedScenarioIntake();
    $steps = app(IntakeStepBuilder::class)->build($intake, $intake->templateVersion);

    expect(count($steps))->toBe(30);

    $sharedFloor = collect($steps)->filter(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'floor_level',
    )->values();
    expect($sharedFloor)->toHaveCount(1)
        ->and($sharedFloor->first()['section_instance_key'])->toBe(SharedRoomAnswers::SCOPE_INSTANCE)
        ->and($sharedFloor->first()['section_title'])->toBe('Geldt voor alle slaapkamers')
        ->and($sharedFloor->first()['shared_scope'] ?? false)->toBeTrue();

    foreach (['sun_exposure', 'glass_amount', 'glazing_type', 'ceiling_height_m'] as $key) {
        $hits = collect($steps)->filter(
            static fn (array $step): bool => ($step['question_key'] ?? null) === $key,
        );
        expect($hits)->toHaveCount(1, $key)
            ->and($hits->first()['section_instance_key'])->toBe(SharedRoomAnswers::SCOPE_INSTANCE);
    }

    $roomNames = collect($steps)->filter(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'room_name',
    );
    expect($roomNames)->toHaveCount(0);

    $dims = collect($steps)->filter(
        static fn (array $step): bool => ($step['group_key'] ?? null) === 'room_dimensions',
    );
    expect($dims)->toHaveCount(0);

    // cooling_heating / brand: al in aanvraag → niet opnieuw als aparte vraag
    // (brand zit in closing_wishes-bundel; cooling_heating is household en geprefilld).
    $cooling = collect($steps)->filter(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'cooling_heating',
    );
    expect($cooling)->toHaveCount(0);
});

test('gedeeld antwoord wordt per ruimte opgeslagen voor rapport/export', function () {
    [$intake] = jorisSharedScenarioIntake();

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $steps = app(IntakeStepBuilder::class)->build($intake->fresh(['answers', 'templateVersion.sections.questions.options']), $intake->templateVersion);
    $shared = collect($steps)->first(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'floor_level'
            && ($step['shared_scope'] ?? false) === true,
    );
    expect($shared)->not->toBeNull();

    $component->set('activeStepKey', $shared['key']);
    $composite = VisibilityResolver::compositeKey('floor_level', SharedRoomAnswers::SCOPE_INSTANCE);
    $component->set("form.{$composite}.value", '1')
        ->call('next');

    $intake = $intake->fresh(['answers']);
    foreach (['room-1', 'room-2', 'room-3'] as $room) {
        $answer = $intake->answers->first(
            static fn (IntakeAnswer $row): bool => $row->question_key === 'floor_level'
                && $row->section_instance_key === $room,
        );
        expect($answer)->not->toBeNull()
            ->and($answer?->value['value'] ?? null)->toBe('1');
    }

    // Geen _shared-antwoord meer na broadcast (alleen per-ruimte data).
    $sharedAnswer = $intake->answers->first(
        static fn (IntakeAnswer $row): bool => $row->question_key === 'floor_level'
            && $row->section_instance_key === SharedRoomAnswers::SCOPE_INSTANCE,
    );
    expect($sharedAnswer)->toBeNull();
});

test('verschilt per ruimte toont daarna per-ruimte stappen', function () {
    [$intake] = jorisSharedScenarioIntake();

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $steps = app(IntakeStepBuilder::class)->build($intake->fresh(['answers', 'templateVersion.sections.questions.options']), $intake->templateVersion);
    $shared = collect($steps)->first(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'sun_exposure'
            && ($step['shared_scope'] ?? false) === true,
    );
    expect($shared)->not->toBeNull();

    $component->set('activeStepKey', $shared['key'])
        ->call('chooseSharedDiffers');

    $intake = $intake->fresh(['answers', 'templateVersion.sections.questions.options']);
    $stepsAfter = app(IntakeStepBuilder::class)->build($intake, $intake->templateVersion);

    $sharedSun = collect($stepsAfter)->filter(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'sun_exposure'
            && ($step['shared_scope'] ?? false) === true,
    );
    expect($sharedSun)->toHaveCount(0);

    $perRoomSun = collect($stepsAfter)->filter(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'sun_exposure'
            && str_starts_with((string) ($step['section_instance_key'] ?? ''), 'room-'),
    )->values();
    expect($perRoomSun)->toHaveCount(3);

    // Direct na Verschilt: land op eerste kamer, vóór die kamerfoto.
    expect($component->get('activeStepKey'))->toBe('rooms::room-1::sun_exposure');
    $room1PhotoIndex = collect($stepsAfter)->search(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'room_photos'
            && ($step['section_instance_key'] ?? null) === 'room-1',
    );
    $room1SunIndex = collect($stepsAfter)->search(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'sun_exposure'
            && ($step['section_instance_key'] ?? null) === 'room-1',
    );
    expect($room1SunIndex)->toBeInt()
        ->and($room1PhotoIndex)->toBeInt()
        ->and($room1SunIndex)->toBeLessThan($room1PhotoIndex);
});

test('verschilt per ruimte: AI-prefill slaat per-kamer zon niet stil over', function () {
    [$intake, $save] = jorisSharedScenarioIntake();

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $steps = app(IntakeStepBuilder::class)->build(
        $intake->fresh(['answers', 'templateVersion.sections.questions.options']),
        $intake->templateVersion,
    );
    $shared = collect($steps)->first(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'sun_exposure'
            && ($step['shared_scope'] ?? false) === true,
    );
    $component->set('activeStepKey', $shared['key'])->call('chooseSharedDiffers');

    // Simuleer fotoprefill ná Verschilt — mag de per-kamer-vraag niet verbergen.
    foreach (['room-1', 'room-2', 'room-3'] as $room) {
        $save->handle(
            $intake,
            'sun_exposure',
            $room,
            ['value' => 'medium'],
            PrefillSources::AI_PHOTO,
            factProvenance: 'inferred',
            factConfidence: 80,
            factSource: 'foto',
        );
    }

    $stepsAfter = app(IntakeStepBuilder::class)->build(
        $intake->fresh(['answers', 'templateVersion.sections.questions.options', 'templateVersion.sections.questions.rules']),
        $intake->templateVersion,
    );
    $perRoomSun = collect($stepsAfter)->filter(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'sun_exposure'
            && str_starts_with((string) ($step['section_instance_key'] ?? ''), 'room-'),
    );
    expect($perRoomSun)->toHaveCount(3);
});

test('blade toont Verschilt per ruimte op gedeelde single_choice stap', function () {
    [$intake] = jorisSharedScenarioIntake();

    $steps = app(IntakeStepBuilder::class)->build($intake, $intake->templateVersion);
    $shared = collect($steps)->first(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'floor_level'
            && ($step['shared_scope'] ?? false) === true,
    );

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('activeStepKey', $shared['key'])
        ->assertSee('Geldt voor alle slaapkamers', false)
        ->assertSee('data-testid="shared-differs"', false)
        ->assertSee('Verschilt per ruimte', false);
});

test('gedeelde zon/glas-tekst is meervoud zonder fotoverwijzing vóór ruimtefoto', function () {
    [$intake] = jorisSharedScenarioIntake();

    $steps = app(IntakeStepBuilder::class)->build($intake, $intake->templateVersion);
    $sun = collect($steps)->first(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'sun_exposure'
            && ($step['shared_scope'] ?? false) === true,
    );
    $glass = collect($steps)->first(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'glass_amount'
            && ($step['shared_scope'] ?? false) === true,
    );
    $roomPhotoIndex = collect($steps)->search(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'room_photos',
    );
    $sunIndex = collect($steps)->search(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'sun_exposure'
            && ($step['shared_scope'] ?? false) === true,
    );

    expect($sun)->not->toBeNull()
        ->and($sun['title'])->toBe('Hoeveel zon krijgen deze ruimtes?')
        ->and((string) ($sun['help_text'] ?? ''))->not->toContain('foto')
        ->and($glass['title'])->toBe('Hoeveel glas is er in deze ruimtes?')
        ->and((string) ($glass['help_text'] ?? ''))->not->toContain('foto')
        ->and($sunIndex)->toBeInt()
        ->and($roomPhotoIndex)->toBeInt()
        ->and($sunIndex)->toBeLessThan($roomPhotoIndex);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('activeStepKey', $sun['key'])
        ->assertSee('Hoeveel zon krijgen deze ruimtes?', false)
        ->assertDontSee('volgde dat uit je foto', false)
        ->assertDontSee('uit je foto', false);
});
