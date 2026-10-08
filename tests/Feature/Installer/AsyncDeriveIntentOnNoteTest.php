<?php

declare(strict_types=1);

use App\Domains\AI\Jobs\DeriveIntentFromRequestJob;
use App\Domains\AI\Jobs\SynthesizeSurveyDossierJob;
use App\Domains\AI\Models\AiRun;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoPlacementType;
use App\Enums\ContributionMode;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

function asyncNoteSurvey(User $user, string $email): Intake
{
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Async Note',
        'customer_email' => $email,
        'address_line' => 'Testlaan 10',
        'address_postal_code' => '1000AA',
        'address_house_number' => 10,
        'address_city' => 'Amsterdam',
        'prefill' => [
            'request_reason' => 'Woonkamer koelen op de begane grond.',
        ],
    ]);

    return $intake->fresh() ?? $intake;
}

test('notitie opslaan dispatcht DeriveIntentJob zonder synchrone AI-call', function () {
    Queue::fake();
    config(['ai.text_inference.enabled' => true, 'ai.provider' => 'fake']);

    $user = User::factory()->create();
    $intake = asyncNoteSurvey($user, 'async-note@example.com');
    $room = app(AircoSurveyService::class)->createRoom($intake, $user, [
        'name' => 'Woonkamer',
        'use_type' => 'living_room',
    ]);

    $aiRunsBefore = AiRun::query()->where('intake_id', $intake->id)->count();

    $this->actingAs($user)
        ->post(route('intakes.workspace.notes.store', [$intake, $room->subject]), [
            'text' => 'Massieve buitenmuur, vanaf de grond bereikbaar.',
        ])
        ->assertRedirect(route('intakes.workspace', $intake))
        ->assertSessionHas('status', 'Notitie toegevoegd.');

    Queue::assertPushed(DeriveIntentFromRequestJob::class, function (DeriveIntentFromRequestJob $job) use ($intake): bool {
        return $job->intakeId === $intake->id
            && $job->allowExternal === true
            && $job->chainDossierSynthesis === true;
    });

    // Job staat in de queue (fake) → geen AiRun tijdens de request.
    expect(AiRun::query()->where('intake_id', $intake->id)->count())
        ->toBe($aiRunsBefore);
});

test('twee snelle notities delen één unieke DeriveIntentJob per intake', function () {
    Queue::fake();

    $user = User::factory()->create();
    $intake = asyncNoteSurvey($user, 'async-debounce@example.com');
    $room = app(AircoSurveyService::class)->createRoom($intake, $user, [
        'name' => 'Woonkamer',
        'use_type' => 'living_room',
    ]);

    $this->actingAs($user)
        ->post(route('intakes.workspace.notes.store', [$intake, $room->subject]), [
            'text' => 'Eerste notitie.',
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->post(route('intakes.workspace.notes.store', [$intake, $room->subject]), [
            'text' => 'Tweede notitie.',
        ])
        ->assertRedirect();

    // ShouldBeUnique: tweede dispatch binnen uniqueFor wordt genegeerd.
    Queue::assertPushed(DeriveIntentFromRequestJob::class, 1);
    expect((new DeriveIntentFromRequestJob($intake->id))->uniqueId())
        ->toBe('derive-intent:'.$intake->id);
});

test('installatiekeuze dispatcht dossiersynthese-job', function () {
    Queue::fake();
    config(['ai.dossier.enabled' => true]);

    $user = User::factory()->create();
    $intake = asyncNoteSurvey($user, 'async-option@example.com');
    $survey = app(AircoSurveyService::class);
    $room = $survey->createRoom($intake, $user, ['name' => 'Woonkamer', 'use_type' => 'living_room']);
    $indoor = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::IndoorUnit,
        'label' => 'Binnenunit',
        'airco_room_id' => $room->id,
    ]);
    $outdoor = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::OutdoorUnit,
        'label' => 'Buitenunit',
    ]);

    $this->actingAs($user)
        ->post(route('intakes.workspace.options.store', $intake), [
            'label' => 'Single-split',
            'configuration_type' => AircoConfigurationType::SingleSplit->value,
            'placement_ids' => [$indoor->id, $outdoor->id],
        ])
        ->assertRedirect();

    Queue::assertPushed(SynthesizeSurveyDossierJob::class, fn (SynthesizeSurveyDossierJob $job): bool => $job->intakeId === $intake->id);
});

test('workspace toont geen AI-voorstel vernieuwen knop', function () {
    $user = User::factory()->create();
    $intake = asyncNoteSurvey($user, 'no-refresh-btn@example.com');

    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertDontSee('AI-voorstel vernieuwen')
        ->assertDontSee('Tik op vernieuwen');
});

test('synthesis-route dispatcht job i.p.v. synchrone AI', function () {
    Queue::fake();
    config(['ai.dossier.enabled' => true, 'ai.provider' => 'fake']);

    $user = User::factory()->create();
    $intake = asyncNoteSurvey($user, 'synth-async@example.com');

    $this->actingAs($user)
        ->post(route('intakes.workspace.synthesis', $intake))
        ->assertRedirect(route('intakes.workspace', $intake))
        ->assertSessionHas('status', 'AI-voorstel wordt op de achtergrond bijgewerkt.');

    Queue::assertPushed(SynthesizeSurveyDossierJob::class, fn (SynthesizeSurveyDossierJob $job): bool => $job->intakeId === $intake->id);
});
