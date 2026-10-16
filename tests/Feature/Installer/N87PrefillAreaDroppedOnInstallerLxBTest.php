<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\AircoSurveyService;
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

function n87Room(): array
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
    $save->handle($intake, 'room_area_m2', 'room-1', ['number' => 30], 'ai_text');
    app(DossierManager::class)->initialize($intake->fresh());
    $intake = $intake->fresh();

    return [$user, $intake, $intake->aircoRooms()->firstOrFail()];
}

test('N87: AI-voorzet oppervlak 30 vervalt als installateur L×B invult en het veld niet aanraakt', function () {
    [$user, $intake, $room] = n87Room();
    expect(RoomDimensions::from($room->dimensions)->declaredAreaM2())->toBe(30.0);

    $room = app(AircoSurveyService::class)->updateRoom($intake, $user, $room, [
        'name' => $room->name, 'length_m' => 6.5, 'width_m' => 4.5, 'area_m2' => 30,
    ]);

    expect(RoomDimensions::from($room->dimensions)->declaredAreaM2())->toBeNull();
});

test('N87: door installateur ingevuld oppervlak blijft naast L×B staan', function () {
    [$user, $intake, $room] = n87Room();
    $survey = app(AircoSurveyService::class);
    $room = $survey->updateRoom($intake, $user, $room, ['name' => $room->name, 'area_m2' => 28]);
    $room = $survey->updateRoom($intake, $user, $room, [
        'name' => $room->name, 'length_m' => 6.5, 'width_m' => 4.5, 'area_m2' => 28,
    ]);

    expect(RoomDimensions::from($room->dimensions)->declaredAreaM2())->toBe(28.0);
});
