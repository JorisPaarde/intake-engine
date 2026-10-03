<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Support\FactProvenance;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\ContributionMode;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

test('stated ownership from request text is not asked again in the customer wizard', function () {
    $user = User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Customer,
        'customer_name' => 'Koop Klant',
        'customer_email' => 'koop@example.com',
        'address_line' => 'Kooplaan 1',
        'address_postal_code' => '1000AA',
        'address_house_number' => 1,
        'address_city' => 'Amsterdam',
    ]);

    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'ownership',
        null,
        ['value' => 'owned'],
        PrefillSources::REQUEST_TEXT,
        FactProvenance::Stated,
    );

    $version = $intake->fresh()->templateVersion()
        ->with(['sections.questions.options', 'sections.questions.rules'])
        ->firstOrFail();
    $steps = collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version));

    expect($intake->answers()->where('question_key', 'ownership')->value('fact_provenance'))->toBe('stated')
        ->and($steps->pluck('question_key')->all())->not->toContain('ownership');
});

test('stated room_name from AI text is not asked again in the customer wizard', function () {
    $user = User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Customer,
        'customer_name' => 'Kamer Klant',
        'customer_email' => 'kamer@example.com',
        'address_line' => 'Kamerlaan 1',
        'address_postal_code' => '1000AA',
        'address_house_number' => 1,
        'address_city' => 'Amsterdam',
    ]);

    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'indoor_unit_count',
        null,
        ['number' => 2],
        PrefillSources::REQUEST_TEXT,
        FactProvenance::Stated,
    );
    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'room_type',
        'room-1',
        ['value' => 'bedroom'],
        PrefillSources::AI_TEXT,
        FactProvenance::Stated,
    );
    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'room_type',
        'room-2',
        ['value' => 'bedroom'],
        PrefillSources::AI_TEXT,
        FactProvenance::Stated,
    );
    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'room_name',
        'room-1',
        ['text' => 'Slaapkamer ouders'],
        PrefillSources::AI_TEXT,
        FactProvenance::Stated,
    );
    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'room_name',
        'room-2',
        ['text' => 'Kinderkamer'],
        PrefillSources::AI_TEXT,
        FactProvenance::Stated,
    );

    $version = $intake->fresh()->templateVersion()
        ->with(['sections.questions.options', 'sections.questions.rules'])
        ->firstOrFail();
    $steps = collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version));
    $roomNameSteps = $steps->where('question_key', 'room_name');

    expect($intake->answers()->where('question_key', 'room_name')->pluck('fact_provenance')->unique()->all())
        ->toBe(['stated'])
        ->and($roomNameSteps)->toHaveCount(0);
});
