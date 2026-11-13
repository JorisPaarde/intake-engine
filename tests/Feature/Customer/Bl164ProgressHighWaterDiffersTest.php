<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\DossierManager;
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
 * Klanttest embed-flow: 3 kamers, Verschilt per ruimte (verdieping + zon).
 *
 * @return array{0: Intake, 1: SaveIntakeAnswer}
 */
function bl164DiffersProgressIntake(): array
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

test('BL-164: Verschilt per ruimte met 3 kamers — % stijgt monotoon en blijft onder 90% zolang kamerfoto’s open zijn', function () {
    [$intake] = bl164DiffersProgressIntake();

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $percents = [(int) $component->viewData('progressPercent')];

    $steps = $component->viewData('steps');
    $knownIdx = collect($steps)->search(fn (array $s): bool => ($s['question_key'] ?? '') === '_known_summary');
    if (is_int($knownIdx)) {
        $component
            ->set('stepIndex', $knownIdx)
            ->set('activeStepKey', $steps[$knownIdx]['key'])
            ->call('next', $steps[$knownIdx]['key']);
        $percents[] = (int) $component->viewData('progressPercent');
    }

    // Shared ceiling → real answer (list shrinks; must not jump to Afronden / ~97%).
    $steps = $component->viewData('steps');
    $ceiling = collect($steps)->first(
        fn (array $s): bool => ($s['question_key'] ?? '') === 'ceiling_height_m'
            && ($s['shared_scope'] ?? false) === true,
    );
    expect($ceiling)->not->toBeNull();
    $component
        ->set('activeStepKey', $ceiling['key'])
        ->set('form._shared__ceiling_height_m.number', 2.5);
    $percents[] = (int) $component->viewData('progressPercent');
    expect($component->get('activeStepKey'))->not->toContain('truth_confirmation')
        ->and((int) $component->viewData('progressPercent'))->toBeLessThan(90);

    // Floor differs → 3 per-room steps.
    $steps = $component->viewData('steps');
    $sharedFloor = collect($steps)->first(
        fn (array $s): bool => ($s['question_key'] ?? '') === 'floor_level'
            && ($s['shared_scope'] ?? false) === true,
    );
    expect($sharedFloor)->not->toBeNull();
    $component->set('activeStepKey', $sharedFloor['key'])->call('chooseSharedDiffers');
    $percents[] = (int) $component->viewData('progressPercent');
    expect($component->get('activeStepKey'))->toBe('rooms::room-1::floor_level');

    foreach (['room-1', 'room-2', 'room-3'] as $i => $room) {
        $component
            ->set("form.{$room}__floor_level.value", (string) $i)
            ->call('next', "rooms::{$room}::floor_level");
        $percents[] = (int) $component->viewData('progressPercent');
    }

    // Sun differs.
    $steps = $component->viewData('steps');
    $sharedSun = collect($steps)->first(
        fn (array $s): bool => ($s['question_key'] ?? '') === 'sun_exposure'
            && ($s['shared_scope'] ?? false) === true,
    );
    if (is_array($sharedSun)) {
        $component->set('activeStepKey', $sharedSun['key'])->call('chooseSharedDiffers');
        $percents[] = (int) $component->viewData('progressPercent');
        foreach (['room-1', 'room-2', 'room-3'] as $room) {
            $component
                ->set("form.{$room}__sun_exposure.value", 'medium')
                ->call('next', "rooms::{$room}::sun_exposure");
            $percents[] = (int) $component->viewData('progressPercent');
        }
    }

    // Advance until Kamer 1 room_photos (skip other shared/optional with unknowns).
    for ($guard = 0; $guard < 25; $guard++) {
        $steps = $component->viewData('steps');
        $active = (string) $component->get('activeStepKey');
        $step = collect($steps)->firstWhere('key', $active);
        $qk = (string) ($step['question_key'] ?? '');
        if ($qk === 'room_photos' && ($step['section_instance_key'] ?? '') === 'room-1') {
            break;
        }
        if ($qk === '' || str_contains($qk, 'photo')) {
            break;
        }
        if (($step['shared_scope'] ?? false) === true) {
            $component->set('activeStepKey', $active)->call('chooseSharedDiffers');
            $percents[] = (int) $component->viewData('progressPercent');

            continue;
        }
        $inst = $step['section_instance_key'] ?? null;
        $formKey = is_string($inst) && $inst !== '' ? "{$inst}__{$qk}" : $qk;
        try {
            $component->set("form.{$formKey}.value", 'unknown')->call('next', $active);
        } catch (Throwable) {
            try {
                $component->call('next', $active);
            } catch (Throwable) {
                break;
            }
        }
        $percents[] = (int) $component->viewData('progressPercent');
    }

    $atPhoto = (int) $component->viewData('progressPercent');
    $activeStep = collect($component->viewData('steps'))
        ->firstWhere('key', $component->get('activeStepKey'));

    expect($activeStep['question_key'] ?? null)->toBe('room_photos')
        ->and($activeStep['section_instance_key'] ?? null)->toBe('room-1')
        ->and($atPhoto)->toBeLessThan(90)
        ->and((int) $component->get('progressHighWater'))->toBeLessThan(90);

    // Monotone: never decreases.
    for ($i = 1; $i < count($percents); $i++) {
        expect($percents[$i])->toBeGreaterThanOrEqual($percents[$i - 1]);
    }

    // Spurious jump guard: never locked near-complete before photos.
    expect(max($percents))->toBeLessThan(90);
});

test('BL-164: beantwoorden gedeelde _shared-stap springt niet naar Afronden bij stale knownStepKeys', function () {
    [$intake] = bl164DiffersProgressIntake();

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $steps = $component->viewData('steps');
    $ceiling = collect($steps)->first(
        fn (array $s): bool => ($s['question_key'] ?? '') === 'ceiling_height_m'
            && ($s['section_instance_key'] ?? '') === SharedRoomAnswers::SCOPE_INSTANCE,
    );
    expect($ceiling)->not->toBeNull();

    // Simulate stale short knownStepKeys (pre-rooms list) — the BL-164 failure mode.
    $staleKeys = [
        'request::request_reason',
        'request::cooling_heating',
        'request::indoor_unit_count',
        'housing::outdoor_location_photos',
        'closing::truth_confirmation',
    ];
    $idx = collect($steps)->search(fn (array $s): bool => $s['key'] === $ceiling['key']);
    $component
        ->set('knownStepKeys', $staleKeys)
        ->set('stepIndex', (int) $idx)
        ->set('activeStepKey', $ceiling['key'])
        ->set('form._shared__ceiling_height_m.number', 2.5);

    expect($component->get('activeStepKey'))->not->toContain('truth_confirmation')
        ->and((int) $component->get('stepIndex'))->toBeLessThan(10)
        ->and((int) $component->viewData('progressPercent'))->toBeLessThan(90)
        ->and((int) $component->get('progressHighWater'))->toBeLessThan(90);
});
