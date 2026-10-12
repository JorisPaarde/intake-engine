<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\IntakeStatus;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

function makeAutoAdvanceSkipIntake(): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_name' => 'Testmans',
        'customer_email' => 'testmans@example.com',
    ]);
}

/**
 * N17: wire:model.live auto-advances; a queued Volgende still carries the old step key.
 * That stale next() must be ignored so we stay on the direct successor.
 */
test('N17: stale next with old step key after choice lands on direct successor', function () {
    $intake = makeAutoAdvanceSkipIntake();
    $save = app(SaveIntakeAnswer::class);

    $save->handle($intake, 'crawl_space_present', null, ['value' => 'yes']);
    $save->handle($intake, 'pipe_visibility', null, ['value' => 'concealed']);
    $save->handle($intake, 'pipe_route_description', null, ['value' => 'along_facade']);
    $save->handle($intake, 'pipe_distance_indication', null, ['value' => 'medium']);

    $oldKey = 'building::crawl_space_present';
    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->fresh()->access_token])
        ->set('activeStepKey', $oldKey)
        ->assertSet('activeStepKey', $oldKey);

    $stepsMethod = new ReflectionMethod(IntakeWizard::class, 'steps');
    $keys = array_column($stepsMethod->invoke($component->instance()), 'key');
    $crawl = array_search($oldKey, $keys, true);
    expect($crawl)->not->toBeFalse();
    $immediateSuccessor = $keys[(int) $crawl + 1] ?? null;
    expect($immediateSuccessor)->not->toBeNull()
        ->and($keys)->toContain('electrical::fusebox_photo');

    $component
        ->set('form.crawl_space_present.value', 'unknown')
        ->assertJs('setTimeout(() => $wire.next('.json_encode($oldKey).'), 50)')
        ->assertSet('activeStepKey', $oldKey)
        ->call('next', $oldKey)
        ->assertSet('activeStepKey', $immediateSuccessor)
        ->call('next', $oldKey);

    expect($component->get('activeStepKey'))->toBe($immediateSuccessor)
        ->and($component->get('activeStepKey'))->not->toBe('electrical::fusebox_photo')
        ->and($component->get('showMissing'))->toBeFalse();
});

/**
 * N17 (Q24→Q26 style): same race when the successor is already answered.
 */
test('N17: stale next after cooling choice does not skip indoor_unit_count', function () {
    $intake = makeAutoAdvanceSkipIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);

    $oldKey = 'request::cooling_heating';
    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('activeStepKey', $oldKey)
        ->assertSet('activeStepKey', $oldKey)
        ->set('form.cooling_heating.value', 'cooling')
        ->assertJs('setTimeout(() => $wire.next('.json_encode($oldKey).'), 50)')
        ->assertSet('activeStepKey', $oldKey)
        ->call('next', $oldKey)
        ->assertSet('activeStepKey', 'request::indoor_unit_count')
        ->call('next', $oldKey);

    expect($component->get('activeStepKey'))->toBe('request::indoor_unit_count')
        ->and($component->get('showMissing'))->toBeFalse()
        ->and($component->html())->toContain('Hoeveel ruimtes wil je koelen of verwarmen?');
});

/**
 * Fresh Volgende for the landing step still advances normally.
 */
test('N17: next with landing step key advances forward normally', function () {
    $intake = makeAutoAdvanceSkipIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);

    $choiceKey = 'request::cooling_heating';
    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('activeStepKey', $choiceKey)
        ->set('form.cooling_heating.value', 'cooling')
        ->assertJs('setTimeout(() => $wire.next('.json_encode($choiceKey).'), 50)')
        ->call('next', $choiceKey)
        ->assertSet('activeStepKey', 'request::indoor_unit_count')
        ->call('next', 'request::indoor_unit_count');

    expect($component->get('activeStepKey'))->not->toBe('request::indoor_unit_count')
        ->and($component->get('activeStepKey'))->not->toBe('request::cooling_heating');
});

/**
 * N15: auto-advance onto an empty required step + stale Volgende must not set showMissing.
 */
test('N15: stale next after choice does not show missing alert on empty successor', function () {
    $intake = makeAutoAdvanceSkipIntake();

    $oldKey = 'request::cooling_heating';
    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('activeStepKey', $oldKey)
        ->set('form.cooling_heating.value', 'cooling')
        ->assertJs('setTimeout(() => $wire.next('.json_encode($oldKey).'), 50)')
        ->call('next', $oldKey)
        ->assertSet('activeStepKey', 'request::indoor_unit_count')
        ->assertSet('showMissing', false)
        ->call('next', $oldKey);

    expect($component->get('activeStepKey'))->toBe('request::indoor_unit_count')
        ->and($component->get('showMissing'))->toBeFalse();

    $component->assertDontSeeHtml('data-testid="step-missing-alert"');
});

/**
 * N15: truth_confirmation tick auto-advances; stale Volgende for the old key must not
 * leave the missing alert on the following step.
 */
test('N15: truth_confirmation tick plus stale next leaves Q32 without missing alert', function () {
    $intake = makeAutoAdvanceSkipIntake();

    $oldKey = 'closing::truth_confirmation';
    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('activeStepKey', $oldKey)
        ->assertSet('activeStepKey', $oldKey)
        ->call('next', $oldKey)
        ->assertSet('showMissing', true)
        ->assertSee('Beantwoord eerst deze verplichte vraag.')
        ->set('form.truth_confirmation.bool', true)
        ->assertJs('setTimeout(() => $wire.next('.json_encode($oldKey).'), 50)')
        ->assertSet('activeStepKey', $oldKey)
        ->call('next', $oldKey);

    $landingKey = $component->get('activeStepKey');
    expect($landingKey)->not->toBe($oldKey)
        ->and($component->get('showMissing'))->toBeFalse();

    $component->call('next', $oldKey);

    expect($component->get('activeStepKey'))->toBe($landingKey)
        ->and($component->get('showMissing'))->toBeFalse();

    $component
        ->assertDontSee('Beantwoord eerst deze verplichte vraag.')
        ->assertDontSeeHtml('data-testid="step-missing-alert"');
});

/**
 * Overview / Klopt-verder: the rendered next-button carries the active step key;
 * next(that key) must move forward (not be treated as stale).
 */
test('next-button HTML step key advances from known-summary overview', function () {
    $intake = makeAutoAdvanceSkipIntake();
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'cooling_heating', null, ['value' => 'cooling'], PrefillSources::AI_TEXT);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1], PrefillSources::AI_TEXT);
    $save->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom'], PrefillSources::AI_TEXT);
    $save->handle($intake, 'room_length_m', 'room-1', ['number' => 4], PrefillSources::AI_TEXT);
    $save->handle($intake, 'room_width_m', 'room-1', ['number' => 3], PrefillSources::AI_TEXT);
    app(DossierManager::class)->initialize($intake->fresh());

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->fresh()->access_token])
        ->set('form.request_reason.text', 'Ik wil een airco in de slaapkamer koelen.')
        ->call('next')
        ->assertSet('activeStepKey', '_known_summary')
        ->assertSee('Klopt, verder')
        ->assertSeeHtml('data-testid="next-button"');

    $html = $component->html();
    expect(preg_match(
        '/wire:click="next\(([^)]+)\)"[^>]*data-testid="next-button"|data-testid="next-button"[^>]*wire:click="next\(([^)]+)\)"/',
        $html,
        $matches,
    ))->toBe(1);

    $encodedKey = html_entity_decode(($matches[1] !== '' ? $matches[1] : $matches[2]), ENT_QUOTES);
    $stepKey = trim($encodedKey, " \t\n\r\0\x0B'\"");
    expect($stepKey)->toBe('_known_summary')
        ->and($stepKey)->toBe($component->get('activeStepKey'));

    $component->call('next', $stepKey);

    expect($component->get('activeStepKey'))->not->toBe('_known_summary');
});
