<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Support\CondensateCustomerDrainNote;
use App\Domains\Intake\Support\CustomerAnswerBlocks;
use App\Enums\ContributionMode;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

function n38DrainIntake(): Intake
{
    $user = User::factory()->create();

    return app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Hybrid,
        'customer_name' => 'Drain Compact Test',
        'customer_email' => 'drain-compact-'.uniqid('', true).'@example.com',
        'address_line' => 'Demostraat 1',
        'address_postal_code' => '1234AB',
        'address_house_number' => 1,
        'address_city' => 'Voorbeeldstad',
        'request_reason' => 'Airco voor woonkamer, koelen en verwarmen.',
    ]);
}

test('N38: pad kamernaam + koelen/verwarmen toont afvoer in compact blok en Volgens de klant', function () {
    $intake = n38DrainIntake();
    $user = User::query()->findOrFail($intake->created_by);
    $save = app(SaveIntakeAnswer::class);
    $survey = app(AircoSurveyService::class);

    $save->handle($intake, 'cooling_heating', null, ['value' => 'both']);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    $survey->createRoom($intake, $user, [
        'name' => 'Zolderkamer',
        'use_type' => 'bedroom',
    ]);
    // Kamernaam later gewijzigd (pad uit testronde).
    $save->handle($intake, 'room_name', 'room-1', ['text' => 'Slaapkamer boven']);
    $save->handle($intake, 'drain_location', null, ['value' => 'outside_nearby']);

    $intake = $intake->fresh(['answers', 'aircoRooms', 'templateVersion.sections.questions.options', 'templateVersion.sections.questions.rules']);

    $blocks = CustomerAnswerBlocks::forIntake($intake);
    $headings = array_column($blocks, 'heading');
    $drainValues = [];
    foreach ($blocks as $block) {
        foreach ($block['items'] as $item) {
            if (str_contains(mb_strtolower($item['label']), 'afvoer')) {
                $drainValues[] = $item['value'];
            }
        }
    }

    expect($headings)->toContain('Condensafvoer')
        ->and($drainValues)->toContain('Regenpijp of putje buiten in de buurt');

    $note = app(CondensateCustomerDrainNote::class)->present($intake);
    expect($note)->toBe('Volgens de klant: regenpijp of putje buiten in de buurt. Nog niet gecontroleerd.');
});

test('N38: lege section_instance_key op drain_location telt als klantantwoord', function () {
    $intake = n38DrainIntake();
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'drain_location', null, ['value' => 'indoor_nearby']);

    IntakeAnswer::query()
        ->where('intake_id', $intake->id)
        ->where('question_key', 'drain_location')
        ->update(['section_instance_key' => '']);

    $intake = $intake->fresh(['answers', 'templateVersion.sections.questions.options', 'templateVersion.sections.questions.rules', 'aircoRooms']);

    $note = app(CondensateCustomerDrainNote::class)->present($intake);
    expect($note)->toContain('Volgens de klant:')
        ->and($note)->toContain('afvoer binnen');

    $blocks = CustomerAnswerBlocks::forIntake($intake);
    $drainValues = collect($blocks)
        ->flatMap(static fn (array $b) => $b['items'])
        ->filter(static fn (array $item): bool => str_contains(mb_strtolower($item['label']), 'afvoer'))
        ->pluck('value')
        ->all();

    expect($drainValues)->not->toBeEmpty();
});
