<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\DossierRecord;
use App\Domains\Intake\Models\DossierSubject;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\ContextualCustomerTaskBuilder;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoConnectionType;
use App\Enums\AircoOptionStatus;
use App\Enums\AircoPlacementType;
use App\Enums\ContributionAudience;
use App\Enums\ContributionMode;
use App\Enums\ContributionTaskStatus;
use App\Enums\DossierRecordKind;
use App\Enums\DossierRecordStatus;
use App\Enums\FollowUpItemType;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
    Mail::fake();
});

function makeSynthesisExceptionIntake(User $user): Intake
{
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Uitzondering Klant',
        'customer_email' => 'exception-'.uniqid('', true).'@example.com',
        'address_line' => 'Testlaan 10',
        'address_postal_code' => '1000AA',
        'address_house_number' => 10,
        'address_city' => 'Amsterdam',
    ]);

    $survey = app(AircoSurveyService::class);
    $room = $survey->createRoom($intake, $user, [
        'name' => 'Woonkamer',
        'use_type' => 'living_room',
        'length_m' => 5,
        'width_m' => 4,
        'height_m' => 2.6,
    ]);
    $indoor = $survey->createPlacement($intake, $user, [
        'airco_room_id' => $room->id,
        'type' => AircoPlacementType::IndoorUnit,
        'label' => 'Binnenunit',
    ]);
    $outdoor = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::OutdoorUnit,
        'label' => 'Buitenunit',
    ]);
    $option = $survey->createInstallationOption($intake, $user, [
        'label' => 'Keuze A',
        'configuration_type' => AircoConfigurationType::SingleSplit,
        'placement_ids' => [$indoor->id, $outdoor->id],
    ]);
    $option->update(['status' => AircoOptionStatus::Selected]);
    $survey->createConnection($intake, $user, $option->fresh(), [
        'type' => AircoConnectionType::Condensate,
        'label' => 'Condensroute',
        'from_placement_id' => $indoor->id,
        'to_placement_id' => $outdoor->id,
        'status' => AircoConnectionStatus::NeedsEvidence,
    ]);

    return $intake->fresh([
        'aircoInstallationOptions.connections',
        'contributionTasks',
        'decisionAreas',
        'dossierSubjects.records',
    ]) ?? $intake;
}

function seedSynthesisException(Intake $intake, string $label = 'Natuurlijk afschot voor condensafvoer op verdiepingen is onzeker.'): void
{
    $root = $intake->dossierSubjects->firstWhere('key', 'survey');
    expect($root)->toBeInstanceOf(DossierSubject::class);

    DossierRecord::query()->create([
        'intake_id' => $intake->id,
        'company_id' => $intake->company_id,
        'dossier_subject_id' => $root->id,
        'kind' => DossierRecordKind::Conclusion,
        'key' => 'ai_dossier_synthesis',
        'value' => [
            'summary' => 'Voorstel beschikbaar.',
            'exceptions' => [[
                'label' => $label,
                'decision_area_key' => 'condensate',
                'confidence' => 0.4,
                'evidence_references' => [],
            ]],
            'customer_task_count' => 0,
        ],
        'status' => DossierRecordStatus::Proposed,
        'source_type' => 'ai_run',
        'method' => 'evidence_synthesis',
        'actor_type' => 'ai',
        'actor_id' => null,
        'observed_at' => now(),
    ]);
}

test('N33: builder never uses internal exception text as customer prompt', function () {
    $user = User::factory()->create();
    $intake = makeSynthesisExceptionIntake($user);
    $internal = 'Natuurlijk afschot voor condensafvoer op verdiepingen is onzeker.';

    $ask = app(ContextualCustomerTaskBuilder::class)->forSynthesisException($intake, [
        'label' => $internal,
        'decision_area_key' => 'condensate',
        'confidence' => 0.4,
    ]);

    expect($ask)->not->toBeNull()
        ->and($ask['decision_area_key'])->toBe('condensate')
        ->and($ask['type'])->toBe(FollowUpItemType::Photo->value)
        ->and($ask['prompt'])->not->toBe($internal)
        ->and($ask['prompt'])->not->toContain('afschot')
        ->and($ask['prompt'])->toContain('condensafvoer');
});

test('N33: matching AI customer_task by decision_area_key wins over fallback', function () {
    $user = User::factory()->create();
    $intake = makeSynthesisExceptionIntake($user);

    ContributionTask::query()->create([
        'intake_id' => $intake->id,
        'company_id' => $intake->company_id,
        'dossier_subject_id' => null,
        'intake_follow_up_item_id' => null,
        'audience' => ContributionAudience::Customer,
        'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een duidelijke foto van de regenpijp of het putje bij de buitenmuur.',
        'decision_area_key' => 'condensate',
        'status' => ContributionTaskStatus::Proposed,
        'requested_by' => null,
        'meta' => ['source_type' => 'ai', 'reason' => 'Afschot onzeker'],
    ]);

    $ask = app(ContextualCustomerTaskBuilder::class)->forSynthesisException($intake->fresh(['contributionTasks']), [
        'label' => 'Natuurlijk afschot voor condensafvoer op verdiepingen is onzeker.',
        'decision_area_key' => 'condensate',
    ]);

    expect($ask['prompt'])->toBe('Maak een duidelijke foto van de regenpijp of het putje bij de buitenmuur.');
});

test('N33: Vraag de klant on AI exception prepares draft without activating a round', function () {
    $this->withoutVite();

    $user = User::factory()->create();
    $intake = makeSynthesisExceptionIntake($user);
    $internal = 'Natuurlijk afschot voor condensafvoer op verdiepingen is onzeker.';
    seedSynthesisException($intake, $internal);

    $html = $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee($internal)
        ->assertSee('data-testid="synthesis-exception-ask"', false)
        ->getContent();

    expect($html)->toContain(route('intakes.workspace.tasks.prepare', $intake, false))
        ->and($html)->not->toContain(route('intakes.workspace.tasks.quick', $intake, false))
        ->and($html)->not->toContain('name="prompt" value="'.$internal.'"');

    $ask = app(ContextualCustomerTaskBuilder::class)->forSynthesisException($intake->fresh([
        'contributionTasks',
        'decisionAreas',
        'aircoInstallationOptions.connections',
    ]), [
        'label' => $internal,
        'decision_area_key' => 'condensate',
    ]);

    $this->actingAs($user)
        ->post(route('intakes.workspace.tasks.prepare', $intake), [
            'type' => $ask['type'],
            'prompt' => $ask['prompt'],
            'decision_area_key' => $ask['decision_area_key'],
            'dossier_subject_id' => $ask['dossier_subject_id'],
        ])
        ->assertRedirect(route('intakes.workspace', $intake).'#demo-customer-task')
        ->assertSessionHas('customer_task_drafts.0.prompt', $ask['prompt'])
        ->assertSessionMissing('status_error');

    expect($intake->fresh()->contributionTasks()->count())->toBe(0)
        ->and($intake->fresh()->followUpRounds()->count())->toBe(0)
        ->and($intake->fresh()->customer_access_enabled)->toBeFalse()
        ->and($ask['prompt'])->not->toBe($internal);
});

test('workspace exception heading uses proper Dutch plural without (en)', function () {
    $this->withoutVite();
    $user = User::factory()->create();
    $intake = makeSynthesisExceptionIntake($user);
    seedSynthesisException($intake, 'Oppervlak ca. 35 m2 nog onzeker.');

    $html = $this->actingAs($user)
        ->get(route('intakes.workspace', $intake->fresh(['dossierSubjects.records'])))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('1 uitzondering bekijken')
        ->and($html)->not->toContain('uitzondering(en)')
        ->and($html)->toContain('35 m²')
        ->and($html)->not->toContain('35 m2');

    DossierRecord::query()
        ->where('intake_id', $intake->id)
        ->where('method', 'evidence_synthesis')
        ->update([
            'value' => [
                'summary' => 'Voorstel beschikbaar.',
                'exceptions' => [
                    [
                        'label' => 'Eerste punt',
                        'decision_area_key' => 'condensate',
                        'confidence' => 0.4,
                        'evidence_references' => [],
                    ],
                    [
                        'label' => 'Tweede punt',
                        'decision_area_key' => 'power',
                        'confidence' => 0.5,
                        'evidence_references' => [],
                    ],
                ],
                'customer_task_count' => 0,
            ],
        ]);

    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake->fresh(['dossierSubjects.records'])))
        ->assertOk()
        ->assertSee('2 uitzonderingen bekijken', false)
        ->assertDontSee('uitzondering(en)', false);
});
