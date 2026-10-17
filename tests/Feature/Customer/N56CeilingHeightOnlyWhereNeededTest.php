<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

function n56HeightSteps(array $roomAnswers): array
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'created_by' => $user->id, 'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
    ]);
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    foreach ($roomAnswers as $key => $value) {
        $save->handle($intake, $key, 'room-1', ['value' => $value], 'ai_text');
    }
    $intake = $intake->fresh();
    $steps = app(IntakeStepBuilder::class)->build($intake, $intake->templateVersion()->with(['sections.questions.rules'])->firstOrFail());

    return array_column($steps, 'question_key');
}

test('N56: hoogte niet gevraagd voor een woonkamer op de begane grond', function () {
    expect(n56HeightSteps(['room_type' => 'living_room', 'floor_level' => 'ground']))->not->toContain('ceiling_height_m');
});

test('N56: hoogte blijft gevraagd op zolder of bij onbekende verdieping', function (array $answers) {
    expect(n56HeightSteps($answers))->toContain('ceiling_height_m');
})->with([
    'zolder als type' => [['room_type' => 'attic', 'floor_level' => 'attic']],
    'slaapkamer op zolder' => [['room_type' => 'bedroom', 'floor_level' => 'attic']],
    'verdieping onbekend' => [['room_type' => 'bedroom']],
]);
