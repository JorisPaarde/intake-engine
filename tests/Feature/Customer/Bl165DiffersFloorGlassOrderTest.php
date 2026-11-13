<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
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
 * Klanttest embed 13 nov: 2037GR 273, 3 slaapkamers, Verschilt per ruimte op zon.
 *
 * @return array{0: Intake, 1: SaveIntakeAnswer}
 */
function bl165DiffersOrderIntake(): array
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
        'address_line' => 'Bernadottelaan 273',
    ]);

    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'privacy_consent', null, ['bool' => true]);
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

test('BL-165: Verschilt per ruimte op zon houdt verdieping en glas in normale volgorde vóór kamerfoto’s', function () {
    [$intake] = bl165DiffersOrderIntake();

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);

    // Ceiling shared → answer for all rooms.
    $steps = $component->viewData('steps');
    $ceiling = collect($steps)->first(
        fn (array $s): bool => ($s['question_key'] ?? '') === 'ceiling_height_m'
            && ($s['shared_scope'] ?? false) === true,
    );
    expect($ceiling)->not->toBeNull();
    $component
        ->set('activeStepKey', $ceiling['key'])
        ->set('form._shared__ceiling_height_m.number', 2.5)
        ->call('next', $ceiling['key']);

    // Shared sun → Verschilt per ruimte (floor/glass come AFTER sun in room sort_order).
    $steps = $component->viewData('steps');
    $sharedSun = collect($steps)->first(
        fn (array $s): bool => ($s['question_key'] ?? '') === 'sun_exposure'
            && ($s['shared_scope'] ?? false) === true,
    );
    expect($sharedSun)->not->toBeNull();
    $component->set('activeStepKey', $sharedSun['key'])->call('chooseSharedDiffers');
    expect($component->get('activeStepKey'))->toBe('rooms::room-1::sun_exposure');

    $intake = $intake->fresh([
        'answers',
        'templateVersion.sections.questions.options',
        'templateVersion.sections.questions.rules',
    ]);
    $stepsAfter = app(IntakeStepBuilder::class)->build($intake, $intake->templateVersion);
    $keys = collect($stepsAfter)->map(fn (array $s): string => $s['key'])->values()->all();

    $sun1 = array_search('rooms::room-1::sun_exposure', $keys, true);
    $sun3 = array_search('rooms::room-3::sun_exposure', $keys, true);
    $glass = array_search('rooms::_shared::glass_amount', $keys, true);
    $glazing = array_search('rooms::_shared::glazing_type', $keys, true);
    $floor = array_search('rooms::_shared::floor_level', $keys, true);
    $photo1 = collect($stepsAfter)->search(
        fn (array $s): bool => ($s['question_key'] ?? '') === 'room_photos'
            && ($s['section_instance_key'] ?? '') === 'room-1',
    );

    expect($sun1)->toBeInt()
        ->and($sun3)->toBeInt()
        ->and($glass)->toBeInt()
        ->and($glazing)->toBeInt()
        ->and($floor)->toBeInt()
        ->and($photo1)->toBeInt();

    // Per-kamer zon op de plek van het gedeelde scherm, daarna glas/beglazing/verdieping, dan foto’s.
    expect($sun1)->toBeLessThan($sun3)
        ->and($sun3)->toBeLessThan($glass)
        ->and($glass)->toBeLessThan($glazing)
        ->and($glazing)->toBeLessThan($floor)
        ->and($floor)->toBeLessThan($photo1);

    // Geen dubbele sun/floor in room-*-blok na de gedeelde expansie.
    $roomBlockSun = collect($stepsAfter)->filter(
        fn (array $s): bool => ($s['question_key'] ?? '') === 'sun_exposure'
            && str_starts_with((string) ($s['section_instance_key'] ?? ''), 'room-'),
    )->count();
    expect($roomBlockSun)->toBe(3);

    // Navigeer: na 3× zon komt glas (niet meteen kamerfoto).
    foreach (['room-1', 'room-2', 'room-3'] as $room) {
        $component
            ->set("form.{$room}__sun_exposure.value", 'medium')
            ->call('next', "rooms::{$room}::sun_exposure");
    }
    expect($component->get('activeStepKey'))->toBe('rooms::_shared::glass_amount');

    $component
        ->set('form._shared__glass_amount.value', 'medium')
        ->call('next', 'rooms::_shared::glass_amount');
    expect($component->get('activeStepKey'))->toBe('rooms::_shared::glazing_type');

    $component
        ->set('form._shared__glazing_type.value', 'double')
        ->call('next', 'rooms::_shared::glazing_type');
    expect($component->get('activeStepKey'))->toBe('rooms::_shared::floor_level');
});

test('BL-165: Afronden-link naar ontbrekende verdieping keert na antwoord terug zonder alle stappen opnieuw', function () {
    [$intake, $save] = bl165DiffersOrderIntake();

    // Beantwoord alles in het gedeelde blok behalve floor (simuleert de skip-bug).
    foreach (['room-1', 'room-2', 'room-3'] as $room) {
        $save->handle($intake, 'ceiling_height_m', $room, ['number' => 2.5]);
        $save->handle($intake, 'sun_exposure', $room, ['value' => 'medium']);
        $save->handle($intake, 'glass_amount', $room, ['value' => 'medium']);
        $save->handle($intake, 'glazing_type', $room, ['value' => 'double']);
        $save->handle($intake, 'room_outlet_status', $room, ['value' => 'unknown']);
    }

    $intake = $intake->fresh([
        'answers',
        'templateVersion.sections.questions.options',
        'templateVersion.sections.questions.rules',
    ]);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $steps = $component->viewData('steps');
    $lastIndex = count($steps) - 1;
    $lastKey = $steps[$lastIndex]['key'] ?? null;
    expect($lastKey)->toBeString();

    $sharedFloor = collect($steps)->first(
        fn (array $s): bool => ($s['question_key'] ?? '') === 'floor_level'
            && ($s['shared_scope'] ?? false) === true,
    );
    expect($sharedFloor)->not->toBeNull();

    // Sta op de laatste stap (Afronden-positie), dan missing-link → return-anker = lastKey.
    $component->call('goToStep', $lastIndex);
    expect($component->get('activeStepKey'))->toBe($lastKey);

    $component->call('goToMissing', 'floor_level', SharedRoomAnswers::SCOPE_INSTANCE);
    expect($component->get('activeStepKey'))->toBe('rooms::_shared::floor_level')
        ->and($component->get('photoFixReturnStepKey'))->toBe($lastKey);

    // Beantwoord gedeelde verdieping → stap verdwijnt bij persist/realign;
    // return-anker moet meteen terugpringen (niet eerst naar kamerfoto’s).
    $component->set('form._shared__floor_level.value', '1');

    $active = (string) $component->get('activeStepKey');
    expect($active)->not->toContain('room_photos')
        ->and($active)->not->toContain('sun_exposure')
        ->and($component->get('photoFixReturnStepKey'))->toBe('');

    $stepsNow = $component->viewData('steps');
    $lastNow = $stepsNow[count($stepsNow) - 1]['key'] ?? '';
    $returnedToEnd = $active === $lastNow
        || $active === $lastKey
        || $component->get('completed') === true
        || $component->get('showMissing') === true;
    expect($returnedToEnd)->toBeTrue();
});

test('BL-165: na zon-differs blijft voortgang % monotoon onder 90% tot kamerfoto’s', function () {
    [$intake] = bl165DiffersOrderIntake();
    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $percents = [(int) $component->viewData('progressPercent')];

    $steps = $component->viewData('steps');
    $ceiling = collect($steps)->first(
        fn (array $s): bool => ($s['question_key'] ?? '') === 'ceiling_height_m'
            && ($s['shared_scope'] ?? false) === true,
    );
    $component
        ->set('activeStepKey', $ceiling['key'])
        ->set('form._shared__ceiling_height_m.number', 2.5)
        ->call('next', $ceiling['key']);
    $percents[] = (int) $component->viewData('progressPercent');

    $steps = $component->viewData('steps');
    $sharedSun = collect($steps)->first(
        fn (array $s): bool => ($s['question_key'] ?? '') === 'sun_exposure'
            && ($s['shared_scope'] ?? false) === true,
    );
    $component->set('activeStepKey', $sharedSun['key'])->call('chooseSharedDiffers');
    $percents[] = (int) $component->viewData('progressPercent');

    foreach (['room-1', 'room-2', 'room-3'] as $room) {
        $component
            ->set("form.{$room}__sun_exposure.value", 'medium')
            ->call('next', "rooms::{$room}::sun_exposure");
        $percents[] = (int) $component->viewData('progressPercent');
    }

    // Glas + verdieping (verplicht) beantwoorden.
    $component
        ->set('form._shared__glass_amount.value', 'medium')
        ->call('next', 'rooms::_shared::glass_amount');
    $percents[] = (int) $component->viewData('progressPercent');
    $component
        ->set('form._shared__glazing_type.value', 'double')
        ->call('next', 'rooms::_shared::glazing_type');
    $percents[] = (int) $component->viewData('progressPercent');
    $component
        ->set('form._shared__floor_level.value', '1')
        ->call('next', 'rooms::_shared::floor_level');
    $percents[] = (int) $component->viewData('progressPercent');

    for ($i = 1; $i < count($percents); $i++) {
        expect($percents[$i])->toBeGreaterThanOrEqual($percents[$i - 1]);
    }
    expect(max($percents))->toBeLessThan(90)
        ->and((int) $component->get('progressHighWater'))->toBeLessThan(90);
});
