<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\GenerateIntakeReportHtml;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;

beforeEach(fn () => $this->seed(IntakeTemplateSeeder::class));

test('N81: rapport toont geen interne ruimtecode (room-1) in de Ruimtes-kop', function () {
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'created_by' => $user->id, 'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id, 'status' => IntakeStatus::InProgress,
    ]);
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 2]);
    foreach (['room-1' => 'bedroom', 'room-2' => 'living_room'] as $key => $type) {
        $save->handle($intake, 'room_type', $key, ['value' => $type]);
        $save->handle($intake, 'room_length_m', $key, ['number' => 4]);
    }
    app(DossierManager::class)->initialize($intake->fresh());
    $intake = $intake->fresh();

    $html = app(GenerateIntakeReportHtml::class)->handle($intake, $version);

    expect($html)->not->toContain('(room-1)')->not->toContain('(room-2)');
    foreach ($intake->aircoRooms as $room) {
        expect($html)->toContain('('.e($room->name).')');
    }
});
