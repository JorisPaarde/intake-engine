<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Enums\IntakeStatus;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

function prefillIntake(int $unitCount = 2): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_name' => 'Klant Test',
        'customer_email' => 'klant@example.com',
    ]);

    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => $unitCount]);

    return $intake->fresh();
}

function focusRoom2FloorLevel(Intake $intake): void
{
    $intake->update([
        'current_section_key' => 'rooms',
        'current_question_key' => 'floor_level',
        'current_section_instance_key' => 'room-2',
    ]);
}

// --- Slice A: repeatable-instance prefill -----------------------------------

test('room 2 prefills floor_level from room 1 as a labelled voorzet', function () {
    $intake = prefillIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'floor_level', 'room-1', ['value' => '1']);
    focusRoom2FloorLevel($intake);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertSet('activeStepKey', 'rooms::room-2::floor_level')
        ->assertSet('form.room-2__floor_level', ['value' => '1']);

    expect($component->get('prefillNotice'))->toHaveKey('room-2__floor_level');
});

test('first room is never prefilled', function () {
    $intake = prefillIntake();
    $intake->update([
        'current_section_key' => 'rooms',
        'current_question_key' => 'floor_level',
        'current_section_instance_key' => 'room-1',
    ]);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertSet('activeStepKey', 'rooms::room-1::floor_level');

    expect($component->get('form'))->not->toHaveKey('room-1__floor_level')
        ->and($component->get('prefillNotice'))->toBe([]);
});

test('advancing persists the prefilled voorzet as a confirmed answer', function () {
    $intake = prefillIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'floor_level', 'room-1', ['value' => '1']);
    focusRoom2FloorLevel($intake);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->call('next');

    $answer = $intake->answers()
        ->where('question_key', 'floor_level')
        ->where('section_instance_key', 'room-2')
        ->first();

    expect($answer)->not->toBeNull()
        ->and($answer->value)->toBe(['value' => '1'])
        ->and($answer->prefill_source)->toBeNull();
});

test('editing a prefilled field clears its notice', function () {
    $intake = prefillIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'floor_level', 'room-1', ['value' => '1']);
    focusRoom2FloorLevel($intake);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('form.room-2__floor_level.value', 'ground');

    expect($component->get('prefillNotice'))->not->toHaveKey('room-2__floor_level');

    $answer = $intake->answers()
        ->where('question_key', 'floor_level')
        ->where('section_instance_key', 'room-2')
        ->first();

    expect($answer->value)->toBe(['value' => 'ground'])
        ->and($answer->prefill_source)->toBeNull();
});

// --- Slice B: installer pre-fill shown to the customer -----------------------

test('installer-prefilled answers are shown to the customer as to-confirm', function () {
    $user = User::factory()->create();

    $intake = app(CreateIntake::class)->handle($user, [
        'customer_name' => 'Klant Test',
        'customer_email' => 'klant@example.com',
        'address_line' => 'Teststraat 1',
        'template_key' => 'airco',
        'prefill' => [
            'request_reason' => 'Slaapkamer te warm',
            'cooling_heating' => 'cooling',
        ],
    ]);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertSet('form.request_reason', ['text' => 'Slaapkamer te warm']);

    expect($component->get('prefillNotice'))->toHaveKey('request_reason');
});
