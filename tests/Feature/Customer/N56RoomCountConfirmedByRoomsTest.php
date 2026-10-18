<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Support\FactProvenance;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

function n56RoomCountSteps(int $count, int $statedRooms, string $countSource = PrefillSources::AI_TEXT): array
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_email' => 'n56-count@example.com',
    ]);
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'request_reason', null, ['text' => 'Airco in woonkamer en twee slaapkamers'], PrefillSources::REQUEST_TEXT);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => $count], $countSource, FactProvenance::Inferred, 70, 'woonkamer en twee slaapkamers');
    $types = ['living_room', 'bedroom', 'bedroom'];
    for ($i = 1; $i <= 3; $i++) {
        $save->handle(
            $intake,
            'room_type',
            'room-'.$i,
            ['value' => $types[$i - 1]],
            PrefillSources::AI_TEXT,
            $i <= $statedRooms ? FactProvenance::Stated : FactProvenance::Inferred,
            90,
            'kamer',
        );
    }

    $v = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();

    return collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $v))->pluck('question_key')->all();
}

test('N56: afgeleid aantal ruimtes gelijk aan letterlijk genoemde ruimtes → niet opnieuw vragen', function () {
    expect(n56RoomCountSteps(3, 3))->not->toContain('indoor_unit_count');
});

test('N56: aantal wijkt af van genoemde ruimtes → vraag blijft', function () {
    expect(n56RoomCountSteps(3, 2))->toContain('indoor_unit_count');
});

test('N56: alleen een tekst-prefill valt onder deze regel', function () {
    expect(n56RoomCountSteps(3, 3, PrefillSources::AI_PHOTO))->toContain('indoor_unit_count');
});
