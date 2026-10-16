<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\DossierManager;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->seed(IntakeTemplateSeeder::class);
});

test('N65: installateursmaten opslaan ververst het opgeslagen rapport', function () {
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'created_by' => $user->id, 'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id, 'status' => IntakeStatus::InProgress,
    ]);
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    $save->handle($intake, 'room_type', 'room-1', ['value' => 'living_room']);
    $save->handle($intake, 'room_area_m2', 'room-1', ['number' => 30]);
    app(DossierManager::class)->initialize($intake->fresh());
    $intake = $intake->fresh();
    $intake->report()->create(['html' => '<html><body>oud rapport</body></html>', 'generated_at' => now()->subHour()]);
    $room = $intake->aircoRooms()->firstOrFail();

    app(AircoSurveyService::class)->updateRoom($intake, $user, $room, [
        'name' => $room->name, 'length_m' => 6.5, 'width_m' => 4.5,
    ]);

    $html = $intake->fresh()->report->html;
    expect($html)->not->toContain('oud rapport')->toContain('6,5');
});
