<?php

declare(strict_types=1);

use App\Domains\AI\Actions\SynthesizeSurveyDossier;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\DTOs\AiCompletionRequest;
use App\Domains\AI\Jobs\DeriveIntentFromRequestJob;
use App\Domains\AI\Jobs\SynthesizeSurveyDossierJob;
use App\Domains\AI\Models\AiRun;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoPlacementType;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\ContributionMode;
use App\Enums\ContributionTaskStatus;
use App\Enums\IntakeStatus;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    FakeAiClient::reset();
    Mail::fake();
});

afterEach(function () {
    FakeAiClient::reset();
});

function productChoiceIntake(User $user, string $email): Intake
{
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Product Choice',
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

/** @return array<string, mixed> */
function productChoiceSynthesisOutput(AiCompletionRequest $request): array
{
    $placements = collect($request->input['placements'])->keyBy('type');
    $inside = $placements->get('indoor_unit')['reference'];
    $outside = $placements->get('outdoor_unit')['reference'];
    $power = $placements->get('power_source')['reference'] ?? $outside;
    $drain = $placements->get('drain_point')['reference'] ?? $outside;
    $roomSubject = collect($request->input['rooms'])->first()['subject_reference'] ?? null;

    return [
        'summary' => 'Voorstel na optiewijziging.',
        'placement_proposals' => [],
        'option_proposals' => [[
            'key' => 'option-a',
            'label' => 'Single-split voorstel',
            'configuration_type' => AircoConfigurationType::SingleSplit->value,
            'summary' => 'Eén binnen- en buitenunit.',
            'cost_impact' => 'medium',
            'confidence' => 0.8,
            'placement_references' => array_values(array_filter([$inside, $outside, $power, $drain])),
            'connections' => [
                [
                    'type' => 'refrigerant',
                    'label' => 'Koelleiding',
                    'from_placement_reference' => $inside,
                    'to_placement_reference' => $outside,
                    'status' => 'proposed',
                    'length_class' => 'short',
                    'segments' => [],
                    'obstacles' => [],
                    'uncertainties' => [],
                    'cost_impact' => 'low',
                    'confidence' => 0.7,
                    'evidence_references' => [$inside],
                ],
                [
                    'type' => 'condensate',
                    'label' => 'Condens',
                    'from_placement_reference' => $inside,
                    'to_placement_reference' => $drain,
                    'status' => 'proposed',
                    'length_class' => 'short',
                    'segments' => [],
                    'obstacles' => [],
                    'uncertainties' => [],
                    'cost_impact' => 'low',
                    'confidence' => 0.7,
                    'evidence_references' => [$inside],
                ],
                [
                    'type' => 'power',
                    'label' => 'Stroom',
                    'from_placement_reference' => $outside,
                    'to_placement_reference' => $power,
                    'status' => 'proposed',
                    'length_class' => 'short',
                    'segments' => [],
                    'obstacles' => [],
                    'uncertainties' => [],
                    'cost_impact' => 'low',
                    'confidence' => 0.7,
                    'evidence_references' => [$power],
                ],
            ],
        ]],
        'exceptions' => [],
        'customer_tasks' => [[
            'type' => 'photo',
            'prompt' => 'Maak een foto van de buitenmuur bij de woonkamer.',
            'decision_area_key' => 'placement',
            'subject_reference' => $roomSubject,
            'reason' => 'Buitenplaatsing nog onzeker.',
            'evidence_references' => [$outside],
        ]],
    ];
}

test('1A: achtergrond-synthese behoudt Proposed taak zodat send-by-id werkt', function () {
    config(['ai.dossier.enabled' => true, 'ai.provider' => 'fake']);

    $user = User::factory()->create();
    $intake = productChoiceIntake($user, 'preserve-task@example.com');
    $survey = app(AircoSurveyService::class);
    $room = $survey->createRoom($intake, $user, [
        'name' => 'Woonkamer',
        'use_type' => 'living_room',
        'length_m' => 5,
        'width_m' => 4,
    ]);
    $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::IndoorUnit,
        'label' => 'Binnenunit',
        'airco_room_id' => $room->id,
    ]);
    $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::OutdoorUnit,
        'label' => 'Buitenunit',
    ]);
    $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::PowerSource,
        'label' => 'Meterkast',
    ]);
    $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::DrainPoint,
        'label' => 'Afvoer',
    ]);

    FakeAiClient::respondUsing(static fn (AiCompletionRequest $request): array => productChoiceSynthesisOutput($request));

    $first = app(SynthesizeSurveyDossier::class)->handle($intake->fresh() ?? $intake);
    expect($first?->status)->toBeIn([AiRunStatus::Succeeded, AiRunStatus::Partial], $first?->error_message ?? '');

    $task = ContributionTask::query()
        ->where('intake_id', $intake->id)
        ->where('status', ContributionTaskStatus::Proposed)
        ->sole();
    $taskId = $task->id;

    $second = app(SynthesizeSurveyDossier::class)->handle(
        $intake->fresh() ?? $intake,
        preserveProposedCustomerTasks: true,
    );

    expect($second?->status)->toBeIn([AiRunStatus::Succeeded, AiRunStatus::Partial], $second?->error_message ?? '')
        ->and(ContributionTask::query()->find($taskId)?->status)->toBe(ContributionTaskStatus::Proposed);

    $this->actingAs($user)
        ->post(route('intakes.workspace.tasks.send', [$intake, $taskId]))
        ->assertRedirect(route('intakes.workspace', $intake));

    expect(ContributionTask::query()->find($taskId)?->status)->toBe(ContributionTaskStatus::Cancelled);
});

test('1A: preserve en replace hebben aparte unique-ids op dezelfde jobklasse', function () {
    $preserve = new SynthesizeSurveyDossierJob(42, preserveProposedCustomerTasks: true);
    $replace = new SynthesizeSurveyDossierJob(42, preserveProposedCustomerTasks: false);

    expect($preserve->uniqueId())->toBe('dossier-synthesis:42:preserve')
        ->and($replace->uniqueId())->toBe('dossier-synthesis:42:replace')
        ->and(SynthesizeSurveyDossierJob::DELAY_SECONDS)->toBe(20);
});

test('2A: sync_on_create false dispatcht job + placeholder; true draait synchroon', function () {
    Queue::fake();
    config([
        'ai.request_prefill.sync_on_create' => false,
        'ai.text_inference.enabled' => false,
        'ai.provider' => 'fake',
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)->post(route('intakes.store'), [
        'template_key' => 'airco',
        'customer_name' => 'Async Prefill',
        'customer_email' => 'async-prefill@example.com',
        'address_line' => 'Testlaan 10',
        'address_postal_code' => '1000AA',
        'address_house_number' => 10,
        'address_city' => 'Amsterdam',
        'prefill' => [
            'request_reason' => 'Woonkamer koelen.',
        ],
    ])->assertRedirect();

    $asyncIntake = Intake::query()->where('customer_email', 'async-prefill@example.com')->firstOrFail();
    Queue::assertPushed(DeriveIntentFromRequestJob::class, fn (DeriveIntentFromRequestJob $job): bool => $job->intakeId === $asyncIntake->id);
    expect(DeriveIntentFromRequestJob::hasRecentPending($asyncIntake->id))->toBeTrue();

    Queue::fake();
    config(['ai.request_prefill.sync_on_create' => true]);

    $this->actingAs($user)->post(route('intakes.store'), [
        'template_key' => 'airco',
        'customer_name' => 'Sync Prefill',
        'customer_email' => 'sync-prefill@example.com',
        'address_line' => 'Testlaan 11',
        'address_postal_code' => '1000AA',
        'address_house_number' => 11,
        'address_city' => 'Amsterdam',
        'prefill' => [
            'request_reason' => 'Slaapkamer koelen.',
        ],
    ])->assertRedirect();

    $syncIntake = Intake::query()->where('customer_email', 'sync-prefill@example.com')->firstOrFail();
    Queue::assertNotPushed(DeriveIntentFromRequestJob::class);
    expect(DeriveIntentFromRequestJob::hasRecentPending($syncIntake->id))->toBeFalse();
});

test('2A: wizard toont geen wachtscherm als klant al begonnen is', function () {
    config([
        'ai.request_prefill.sync_on_create' => false,
        'ai.request_prefill.wizard_wait_seconds' => 20,
    ]);

    $user = User::factory()->create();
    $intake = productChoiceIntake($user, 'wizard-no-wait-started@example.com');
    $intake->forceFill([
        'customer_access_enabled' => true,
        'access_token' => str_repeat('c', 64),
        'token_expires_at' => now()->addDay(),
        'status' => IntakeStatus::InProgress,
        'current_question_key' => 'ownership',
    ])->save();

    DeriveIntentFromRequestJob::markPending($intake);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertSet('waitingForPrefill', false)
        ->assertDontSee('Even geduld, we zetten je vragen klaar');
});

test('2A: wizard toont wachtscherm bij pending prefill en start na afronden', function () {
    config([
        'ai.request_prefill.sync_on_create' => false,
        'ai.request_prefill.wizard_wait_seconds' => 20,
    ]);

    $user = User::factory()->create();
    $intake = productChoiceIntake($user, 'wizard-wait@example.com');
    $intake->forceFill([
        'customer_access_enabled' => true,
        'access_token' => str_repeat('a', 64),
        'token_expires_at' => now()->addDay(),
        'status' => IntakeStatus::Sent,
    ])->save();

    DeriveIntentFromRequestJob::markPending($intake);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertSet('waitingForPrefill', true)
        ->assertSee('Even geduld, we zetten je vragen klaar')
        ->assertSeeHtml('data-testid="prefill-wait"');

    AiRun::query()
        ->where('intake_id', $intake->id)
        ->where('type', AiRunType::RequestIntent)
        ->where('status', AiRunStatus::Pending)
        ->update([
            'status' => AiRunStatus::Succeeded,
            'finished_at' => now(),
        ]);

    $component->call('pollPrefillWait')
        ->assertSet('waitingForPrefill', false)
        ->assertDontSee('Even geduld, we zetten je vragen klaar');
});

test('2A: wizard-wacht stopt na timeout ook als prefill nog pending is', function () {
    config([
        'ai.request_prefill.sync_on_create' => false,
        'ai.request_prefill.wizard_wait_seconds' => 1,
    ]);

    $user = User::factory()->create();
    $intake = productChoiceIntake($user, 'wizard-timeout@example.com');
    $intake->forceFill([
        'customer_access_enabled' => true,
        'access_token' => str_repeat('b', 64),
        'token_expires_at' => now()->addDay(),
        'status' => IntakeStatus::Sent,
    ])->save();

    DeriveIntentFromRequestJob::markPending($intake);

    $started = now();
    $this->travelTo($started);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertSet('waitingForPrefill', true);

    $this->travelTo($started->copy()->addSeconds(5));

    $component->call('pollPrefillWait')
        ->assertSet('waitingForPrefill', false);
});
