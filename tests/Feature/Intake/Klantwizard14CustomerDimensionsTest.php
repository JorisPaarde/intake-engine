<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\ContributionMode;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

function makeKlantwizard14DimsIntake(User $user): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::InProgress,
        'workflow_mode' => ContributionMode::Customer,
        'customer_name' => 'Maten Klant',
        'customer_email' => 'maten-klant@example.com',
        'access_token' => str_repeat('d', 64),
        'token_expires_at' => now()->addDays(14),
    ]);
}

test('A5: lege werkplekmaten blokkeren klantmaten niet in syncRooms', function () {
    $user = User::factory()->create();
    $intake = makeKlantwizard14DimsIntake($user);

    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom']);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_length_m', 'room-1', ['number' => 3.5]);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_width_m', 'room-1', ['number' => 3.0]);

    $intake->refresh();
    app(DossierManager::class)->initialize($intake->fresh());

    $room = AircoRoom::query()
        ->where('intake_id', $intake->id)
        ->where('key', 'room-1')
        ->firstOrFail();

    // Lege werkplekmaten (null/0/"") + valse installer-eigendom — repro van "Maten nog leeg".
    $room->update([
        'dimensions' => [
            'length_m' => 0,
            'width_m' => '',
            'height_m' => null,
            'dimensions_source' => 'installer',
        ],
    ]);

    app(DossierManager::class)->initialize($intake->fresh());
    $room->refresh();

    expect((float) ($room->dimensions['length_m'] ?? 0))->toBe(3.5)
        ->and((float) ($room->dimensions['width_m'] ?? 0))->toBe(3.0)
        ->and((float) ($room->dimensions['area_m2'] ?? 0))->toBe(10.5)
        ->and($room->dimensions['dimensions_source'] ?? null)->not->toBe('installer');
});

test('A5: echte installateurscorrectie wint van klantmaten', function () {
    $user = User::factory()->create();
    $intake = makeKlantwizard14DimsIntake($user);

    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom']);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_length_m', 'room-1', ['number' => 3.5]);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_width_m', 'room-1', ['number' => 3.0]);

    app(DossierManager::class)->initialize($intake->fresh());
    $room = AircoRoom::query()
        ->where('intake_id', $intake->id)
        ->where('key', 'room-1')
        ->firstOrFail();

    app(AircoSurveyService::class)->updateRoom($intake, $user, $room, [
        'name' => $room->name,
        'use_type' => 'bedroom',
        'length_m' => 4.0,
        'width_m' => 3.5,
    ]);

    app(DossierManager::class)->initialize($intake->fresh());
    $room->refresh();

    expect((float) ($room->dimensions['length_m'] ?? 0))->toBe(4.0)
        ->and((float) ($room->dimensions['width_m'] ?? 0))->toBe(3.5)
        ->and($room->dimensions['dimensions_source'] ?? null)->toBe('installer');
});

test('A5: werkplek en overzicht tonen klantmaten met van klant', function () {
    $user = User::factory()->create();
    $intake = makeKlantwizard14DimsIntake($user);

    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom']);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_name', 'room-1', ['text' => 'Slaapkamer voor']);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_length_m', 'room-1', ['number' => 3.5]);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_width_m', 'room-1', ['number' => 3.0]);
    app(SaveIntakeAnswer::class)->handle($intake, 'drain_location', null, ['value' => 'outside_nearby']);

    // Forceer de lege-installer-shell-regressie vóór weergave.
    app(DossierManager::class)->initialize($intake->fresh());
    $room = AircoRoom::query()->where('intake_id', $intake->id)->where('key', 'room-1')->firstOrFail();
    $room->update([
        'dimensions' => [
            'length_m' => null,
            'width_m' => 0,
            'dimensions_source' => 'installer',
        ],
    ]);

    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('3,5 × 3,0 m')
        ->assertSee('(10,5 m²)')
        ->assertSee('van klant')
        ->assertDontSee('Maten nog leeg')
        ->assertSee('Antwoorden van de klant');

    $this->actingAs($user)
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee('3,5 × 3,0 m')
        ->assertSee('(10,5 m²)')
        ->assertSee('van klant')
        ->assertSee('Antwoorden van de klant');
});

test('A5: derived_lxw-area uit klant-L×B blijft zichtbaar na sync', function () {
    $user = User::factory()->create();
    $intake = makeKlantwizard14DimsIntake($user);

    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom']);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_length_m', 'room-1', ['number' => 3.5]);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_width_m', 'room-1', ['number' => 3.0]);

    $area = $intake->fresh()->answers()
        ->where('question_key', 'room_area_m2')
        ->where('section_instance_key', 'room-1')
        ->first();

    expect($area)->not->toBeNull()
        ->and($area?->prefill_source)->toBe(PrefillSources::DERIVED_LXW);

    app(DossierManager::class)->initialize($intake->fresh());
    $room = AircoRoom::query()->where('intake_id', $intake->id)->where('key', 'room-1')->firstOrFail();

    expect((float) ($room->dimensions['area_m2'] ?? 0))->toBe(10.5)
        ->and($room->dimensions['area_source'] ?? null)->toBe('derived_lxw');
});
