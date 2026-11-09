<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Support\SharedRoomAnswers;
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

function focusSharedFloorLevel(Intake $intake): void
{
    $intake->update([
        'current_section_key' => 'rooms',
        'current_question_key' => 'floor_level',
        'current_section_instance_key' => SharedRoomAnswers::SCOPE_INSTANCE,
    ]);
}

// --- Slice A: gedeelde ruimtevraag (verdieping) --------------------------------

test('met meerdere ruimtes is floor_level een gedeelde stap', function () {
    $intake = prefillIntake();
    focusSharedFloorLevel($intake);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertSet('activeStepKey', 'rooms::_shared::floor_level')
        ->assertSee('Geldt voor alle ruimtes', false)
        ->assertSee('Verschilt per ruimte', false);
});

test('gedeeld floor_level-antwoord wordt naar alle ruimtes geschreven', function () {
    $intake = prefillIntake();
    focusSharedFloorLevel($intake);

    $composite = SharedRoomAnswers::SCOPE_INSTANCE.'__floor_level';

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set("form.{$composite}.value", '1')
        ->call('next');

    foreach (['room-1', 'room-2'] as $room) {
        $answer = $intake->answers()
            ->where('question_key', 'floor_level')
            ->where('section_instance_key', $room)
            ->first();

        expect($answer)->not->toBeNull()
            ->and($answer?->value)->toBe(['value' => '1'])
            ->and($answer?->prefill_source)->toBeNull();
    }
});

test('één beantwoorde ruimte houdt de gedeelde floor_level-stap zichtbaar', function () {
    $intake = prefillIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'floor_level', 'room-1', ['value' => '1']);
    focusSharedFloorLevel($intake);

    // Nog niet alle instanties beantwoord → gedeelde stap blijft (geen stille voorzet room-2).
    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertSet('activeStepKey', 'rooms::_shared::floor_level');
});

test('editing a shared floor_level field persists to every room', function () {
    $intake = prefillIntake();
    focusSharedFloorLevel($intake);

    $composite = SharedRoomAnswers::SCOPE_INSTANCE.'__floor_level';

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set("form.{$composite}.value", 'ground');

    foreach (['room-1', 'room-2'] as $room) {
        $answer = $intake->answers()
            ->where('question_key', 'floor_level')
            ->where('section_instance_key', $room)
            ->first();

        expect($answer)->not->toBeNull()
            ->and($answer?->value)->toBe(['value' => 'ground'])
            ->and($answer?->prefill_source)->toBeNull();
    }
});

// --- Slice B: installer pre-fill shown to the customer -----------------------

test('installer-prefilled answers are shown to the customer as to-confirm', function () {
    $user = User::factory()->create();

    $intake = app(CreateIntake::class)->handle($user, [
        'customer_name' => 'Klant Test',
        'customer_email' => 'klant@example.com',
        'address_line' => 'Teststraat 1',
        'address_postal_code' => '1000AA',
        'address_house_number' => 1,
        'address_city' => 'Amsterdam',
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
