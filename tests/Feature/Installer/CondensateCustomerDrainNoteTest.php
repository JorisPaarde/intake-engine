<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Support\CondensateCustomerDrainNote;
use App\Enums\ContributionMode;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

function drainNoteIntake(): Intake
{
    $user = User::factory()->create();

    return app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Hybrid,
        'customer_name' => 'Drain Note Test',
        'customer_email' => 'drain-note@example.com',
        'address_line' => 'Demostraat 1',
        'address_postal_code' => '1234AB',
        'address_house_number' => 1,
        'address_city' => 'Voorbeeldstad',
    ]);
}

test('N26: customer drain_location outside_nearby shows on-screen note', function () {
    $intake = drainNoteIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'drain_location', null, ['value' => 'outside_nearby']);

    $note = app(CondensateCustomerDrainNote::class)->present($intake->fresh(['answers', 'templateVersion.sections.questions.options']));

    expect($note)->toBe('Volgens de klant: regenpijp of putje buiten in de buurt (nog niet gecontroleerd)');
});

test('N26: customer drain_location indoor_nearby shows on-screen note', function () {
    $intake = drainNoteIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'drain_location', null, ['value' => 'indoor_nearby']);

    $note = app(CondensateCustomerDrainNote::class)->present($intake->fresh(['answers', 'templateVersion.sections.questions.options']));

    expect($note)->toBe('Volgens de klant: afvoer binnen in de buurt, zoals keuken, badkamer of wasmachine (nog niet gecontroleerd)');
});

test('N26: ai_photo prefilled drain_location does not show on-screen note', function () {
    $intake = drainNoteIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'drain_location', null, ['value' => 'outside_nearby']);

    IntakeAnswer::query()
        ->where('intake_id', $intake->id)
        ->where('question_key', 'drain_location')
        ->update(['prefill_source' => 'ai_photo']);

    $note = app(CondensateCustomerDrainNote::class)->present($intake->fresh(['answers', 'templateVersion.sections.questions.options']));

    expect($note)->toBeNull();
});

test('N26: unknown drain_location does not show on-screen note', function () {
    $intake = drainNoteIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'drain_location', null, ['value' => 'unknown']);

    $note = app(CondensateCustomerDrainNote::class)->present($intake->fresh(['answers', 'templateVersion.sections.questions.options']));

    expect($note)->toBeNull();
});
