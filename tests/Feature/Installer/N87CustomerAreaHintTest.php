<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\DossierRecord;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Support\RoomDimensions;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->seed(IntakeTemplateSeeder::class);
});

function n87CustomerAreaRoom(): array
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'created_by' => $user->id, 'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id, 'status' => IntakeStatus::InProgress,
    ]);
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    $save->handle($intake, 'room_type', 'room-1', ['value' => 'living_room']);
    // Klant bevestigde de 30 m² ("Klopt, verder"): geen prefill-bron meer.
    $save->handle($intake, 'room_area_m2', 'room-1', ['number' => 30]);
    app(DossierManager::class)->initialize($intake->fresh());
    $intake = $intake->fresh();
    $room = $intake->aircoRooms()->firstOrFail();
    expect(RoomDimensions::from($room->dimensions)->areaSource())->toBe('customer');

    return [$user, $intake, $room];
}

test('N87: klant-m² staat als hint in het formulier, niet als invoerwaarde', function () {
    $this->withoutVite();
    [$user, $intake, $room] = n87CustomerAreaRoom();

    $html = $this->actingAs($user)->get(route('intakes.workspace', $intake))->assertOk()->getContent();

    expect($html)->toContain('data-testid="room-'.$room->id.'-customer-area-hint"')
        ->and($html)->toContain('Klant gaf 30 m² op.')
        ->and($html)->toMatch('/id="room-'.$room->id.'-area"[^>]*value=""/');
});

test('N87: leeg m²-veld of ongewijzigde echo bewaart de klantwaarde met herkomst klant', function (string $area) {
    [$user, $intake, $room] = n87CustomerAreaRoom();

    $this->actingAs($user)->post(route('intakes.workspace.rooms.update', [$intake, $room]), [
        'name' => $room->name, 'use_type' => 'living_room', 'length_m' => 6.5, 'width_m' => 4.5, 'area_m2' => $area,
    ])->assertRedirect();

    $dimensions = RoomDimensions::from($room->fresh()->dimensions);
    expect($dimensions->declaredAreaM2())->toBe(30.0)
        ->and($dimensions->areaSource())->toBe('customer')
        ->and($dimensions->lengthM())->toBe(6.5);
})->with(['leeg' => [''], 'echo' => ['30']]);

test('N87: een eigen installateurswaarde vervangt de klant-m²', function () {
    [$user, $intake, $room] = n87CustomerAreaRoom();

    $this->actingAs($user)->post(route('intakes.workspace.rooms.update', [$intake, $room]), [
        'name' => $room->name, 'use_type' => 'living_room', 'area_m2' => 28,
    ])->assertRedirect();

    $dimensions = RoomDimensions::from($room->fresh()->dimensions);
    expect($dimensions->declaredAreaM2())->toBe(28.0)
        ->and($dimensions->areaSource())->toBe('installer');
});

test('N87: klant-m² gelijk aan oude L×B blijft staan bij nieuwe L×B en leeg veld (conflict zichtbaar)', function () {
    [$user, $intake, $room] = n87CustomerAreaRoom();
    $room->update(['dimensions' => [...$room->dimensions, 'length_m' => 6.0, 'width_m' => 5.0]]);

    $this->actingAs($user)->post(route('intakes.workspace.rooms.update', [$intake, $room]), [
        'name' => $room->name, 'use_type' => 'living_room', 'length_m' => 5, 'width_m' => 4, 'area_m2' => '',
    ])->assertRedirect();

    $dimensions = RoomDimensions::from($room->fresh()->dimensions);
    expect($dimensions->declaredAreaM2())->toBe(30.0)
        ->and($dimensions->areaSource())->toBe('customer')
        ->and($dimensions->hasFloorAreaConflict())->toBeTrue();
});

test('N87: bewaarde klant-m² maakt geen installateurscorrectie in het dossier', function () {
    [$user, $intake, $room] = n87CustomerAreaRoom();

    $this->actingAs($user)->post(route('intakes.workspace.rooms.update', [$intake, $room]), [
        'name' => $room->name, 'use_type' => 'living_room', 'length_m' => 6.5, 'width_m' => 4.5, 'area_m2' => '',
    ])->assertRedirect();

    expect(DossierRecord::query()
        ->where('intake_id', $intake->id)->where('key', 'dimensions.area_m2')->exists())->toBeFalse();
});
