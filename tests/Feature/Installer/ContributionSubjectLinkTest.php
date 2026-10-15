<?php

declare(strict_types=1);

use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Models\AircoConnection;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\DossierSubject;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Support\ContributionSubjectLinker;
use App\Domains\Intake\Support\FollowUpContributionPresenter;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoConnectionType;
use App\Enums\AircoPlacementType;
use App\Enums\ContributionMode;
use App\Enums\FollowUpItemType;
use App\Enums\PhotoAssessmentStatus;
use App\Enums\PhotoUsabilityVerdict;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
    Mail::fake();
    config([
        'ai.provider' => 'fake',
        'ai.photo_inference.enabled' => true,
        'ai.dossier.enabled' => false,
    ]);
});

/**
 * @return array{intake: Intake, power: AircoConnection, condensate: AircoConnection}
 */
function n40SurveyWithPowerAndCondensate(User $user): array
{
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'N40 Link',
        'customer_email' => 'n40-link@example.com',
        'address_line' => 'Linkstraat 1',
        'address_postal_code' => '1000AA',
        'address_house_number' => 1,
        'address_city' => 'Amsterdam',
    ]);
    app(DossierManager::class)->initialize($intake);

    $survey = app(AircoSurveyService::class);
    $room = $survey->createRoom($intake, $user, [
        'name' => 'Woonkamer',
        'use_type' => 'living_room',
        'length_m' => 5,
        'width_m' => 4,
        'height_m' => 2.5,
    ]);
    $inside = $survey->createPlacement($intake, $user, [
        'airco_room_id' => $room->id,
        'type' => AircoPlacementType::IndoorUnit,
        'label' => 'Binnenunit woonkamer',
    ]);
    $outside = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::OutdoorUnit,
        'label' => 'Buitenunit tuin',
    ]);
    $option = $survey->createInstallationOption($intake, $user, [
        'label' => 'Optie A',
        'configuration_type' => AircoConfigurationType::SingleSplit,
        'placement_ids' => [$inside->id, $outside->id],
    ]);
    $power = $survey->createConnection($intake, $user, $option, [
        'type' => AircoConnectionType::Power,
        'label' => 'Stroom naar buitenunit',
        'from_placement_id' => $outside->id,
        'to_placement_id' => $outside->id,
        'status' => AircoConnectionStatus::NeedsEvidence,
    ]);
    $condensate = $survey->createConnection($intake, $user, $option, [
        'type' => AircoConnectionType::Condensate,
        'label' => 'Condensafvoer woonkamer',
        'from_placement_id' => $inside->id,
        'to_placement_id' => $outside->id,
        'status' => AircoConnectionStatus::NeedsEvidence,
    ]);

    return [
        'intake' => $intake->fresh(),
        'power' => $power,
        'condensate' => $condensate,
    ];
}

function n40FixturePhoto(): UploadedFile
{
    $path = base_path('tests/fixtures/klanttest-20261002/buitenunit-flow.jpeg');
    expect(is_file($path))->toBeTrue();

    return UploadedFile::fake()->createWithContent('gevel.jpeg', (string) file_get_contents($path));
}

function n40MarkUploadOk(IntakeUpload $upload): void
{
    $upload->update([
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'content_assessment' => PhotoContentAssessment::ok(PhotoSubject::OutdoorLocation)->toArray(),
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
    ]);
}

test('N40: concept stroom→condens koppelt aanvulling aan condens, niet aan stroomroute', function () {
    $this->withoutVite();
    Queue::fake([AssessUploadedPhotoJob::class]);

    $user = User::factory()->create();
    ['intake' => $intake, 'power' => $power, 'condensate' => $condensate] = n40SurveyWithPowerAndCondensate($user);

    $prompt = 'Maak een foto van de plek waar de condensafvoer kan lozen, zoals een regenpijp, putje of goot.';

    $this->actingAs($user)
        ->post(route('intakes.workspace.tasks.prepare', $intake), [
            'type' => FollowUpItemType::Photo->value,
            'prompt' => $prompt,
            'decision_area_key' => 'power',
            'dossier_subject_id' => $power->dossier_subject_id,
            'photo_task_subtype' => 'power_route',
        ])
        ->assertRedirect(route('intakes.workspace', $intake).'#demo-customer-task')
        ->assertSessionHas('customer_task_drafts.0.dossier_subject_id', $power->dossier_subject_id);

    // Installer wijzigde onderdeel naar Condensafvoer; hidden subject bleef stroom.
    $this->actingAs($user)
        ->post(route('intakes.workspace.tasks.store', $intake), [
            'contribution_items' => [[
                'type' => FollowUpItemType::Photo->value,
                'prompt' => $prompt,
                'decision_area_key' => 'condensate',
                'dossier_subject_id' => $power->dossier_subject_id,
                'photo_task_subtype' => 'auto',
            ]],
        ])
        ->assertRedirect();

    $task = ContributionTask::query()->where('intake_id', $intake->id)->latest('id')->first();
    expect($task)->not->toBeNull()
        ->and($task->decision_area_key)->toBe('condensate')
        ->and((int) $task->dossier_subject_id)->toBe((int) $condensate->dossier_subject_id)
        ->and((int) $task->dossier_subject_id)->not->toBe((int) $power->dossier_subject_id)
        ->and($task->meta['photo_task_subtype'] ?? null)->toBeNull();

    $intake->refresh();
    $item = $task->followUpItem;
    expect($item)->not->toBeNull();

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $component->set('followUpPhotoFiles.'.$item->id, n40FixturePhoto());
    $upload = $item->fresh()->uploads()->latest('id')->firstOrFail();
    n40MarkUploadOk($upload);
    $component->call('completeFollowUp')->assertSet('completed', true);

    $presenter = app(FollowUpContributionPresenter::class);
    $fresh = $intake->fresh();
    expect($presenter->forSubject($fresh, (int) $condensate->dossier_subject_id))->not->toBeEmpty()
        ->and($presenter->forSubject($fresh, (int) $power->dossier_subject_id))->toBeEmpty()
        ->and($presenter->forDecisionArea($fresh, 'condensate'))->not->toBeEmpty()
        ->and($presenter->forDecisionArea($fresh, 'power'))->toBeEmpty();

    $html = $this->actingAs($user)->get(route('intakes.workspace', $fresh))->assertOk()->getContent();

    preg_match(
        '/id="connection-'.preg_quote((string) $power->id, '/').'"[\s\S]*?(?=id="connection-\d+"|<\/div>\s*<\/div>\s*<details)/',
        $html,
        $powerMatch,
    );
    preg_match(
        '/id="connection-'.preg_quote((string) $condensate->id, '/').'"[\s\S]*?(?=id="connection-\d+"|<\/div>\s*<\/div>\s*<details)/',
        $html,
        $condensateMatch,
    );

    expect($powerMatch[0] ?? '')->not->toContain('data-testid="subject-contribution-evidence"')
        ->and($condensateMatch[0] ?? '')->toContain('data-testid="subject-contribution-evidence"')
        ->and($condensateMatch[0] ?? '')->toContain('Nieuwe aanvulling bij dit onderdeel')
        ->and($html)->toContain('data-testid="area-contribution-condensate"');
});

test('N40: ongewijzigd concept stroom houdt stroom-subject', function () {
    $user = User::factory()->create();
    ['intake' => $intake, 'power' => $power] = n40SurveyWithPowerAndCondensate($user);

    $prompt = 'Maak een foto van de plek waar de stroomkabel naar de buitenunit kan lopen, bijvoorbeeld langs de gevel of vanaf de meterkast.';

    $this->actingAs($user)
        ->post(route('intakes.workspace.tasks.store', $intake), [
            'contribution_items' => [[
                'type' => FollowUpItemType::Photo->value,
                'prompt' => $prompt,
                'decision_area_key' => 'power',
                'dossier_subject_id' => $power->dossier_subject_id,
                'photo_task_subtype' => 'power_route',
            ]],
        ])
        ->assertRedirect();

    $task = ContributionTask::query()->where('intake_id', $intake->id)->latest('id')->first();
    expect($task)->not->toBeNull()
        ->and($task->decision_area_key)->toBe('power')
        ->and((int) $task->dossier_subject_id)->toBe((int) $power->dossier_subject_id)
        ->and($task->meta['photo_task_subtype'] ?? null)->toBe('power_route');
});

test('N40: gebiedswijziging met 2 stroomroutes koppelt area-niveau (null), niet de eerste', function () {
    $user = User::factory()->create();
    ['intake' => $intake, 'power' => $power, 'condensate' => $condensate] = n40SurveyWithPowerAndCondensate($user);

    $survey = app(AircoSurveyService::class);
    $option = $intake->aircoInstallationOptions()->firstOrFail();
    $outside = $intake->aircoPlacements()->where('type', AircoPlacementType::OutdoorUnit)->firstOrFail();
    $survey->createConnection($intake, $user, $option, [
        'type' => AircoConnectionType::Power,
        'label' => 'Stroom tweede route',
        'from_placement_id' => $outside->id,
        'to_placement_id' => $outside->id,
        'status' => AircoConnectionStatus::NeedsEvidence,
    ]);

    expect(AircoConnection::query()->where('intake_id', $intake->id)->where('type', AircoConnectionType::Power)->count())
        ->toBe(2);

    // Condens-subject + onderdeel → power (2 kandidaten) ⇒ area-niveau.
    $resolved = app(ContributionSubjectLinker::class)->resolve(
        $intake->fresh(),
        $condensate->dossier_subject_id,
        'power',
    );
    expect($resolved)->toBeNull();

    $this->actingAs($user)
        ->post(route('intakes.workspace.tasks.store', $intake), [
            'contribution_items' => [[
                'type' => FollowUpItemType::Photo->value,
                'prompt' => 'Maak een foto van de plek waar de stroomkabel naar de buitenunit kan lopen.',
                'decision_area_key' => 'power',
                'dossier_subject_id' => $condensate->dossier_subject_id,
            ]],
        ])
        ->assertRedirect();

    $task = ContributionTask::query()->where('intake_id', $intake->id)->latest('id')->first();
    expect($task)->not->toBeNull()
        ->and($task->decision_area_key)->toBe('power')
        ->and($task->dossier_subject_id)->toBeNull()
        ->and((int) $power->dossier_subject_id)->not->toBe(0);
});

test('N45: verwijderd subject van deze intake mapt naar opvolger in hetzelfde gebied', function () {
    $user = User::factory()->create();
    ['intake' => $intake, 'power' => $power] = n40SurveyWithPowerAndCondensate($user);

    $staleSubjectId = (int) $power->dossier_subject_id;
    $survey = app(AircoSurveyService::class);
    $option = $intake->aircoInstallationOptions()->firstOrFail();
    $outside = $intake->aircoPlacements()->where('type', AircoPlacementType::OutdoorUnit)->firstOrFail();

    // Simuleer AI-heropbouw: subject wissen cascade’t de oude verbinding weg.
    DossierSubject::query()->whereKey($staleSubjectId)->delete();
    expect(AircoConnection::query()->whereKey($power->id)->exists())->toBeFalse();

    $successor = $survey->createConnection($intake, $user, $option, [
        'type' => AircoConnectionType::Power,
        'label' => 'Stroom naar buitenunit (nieuw)',
        'from_placement_id' => $outside->id,
        'to_placement_id' => $outside->id,
        'status' => AircoConnectionStatus::NeedsEvidence,
    ]);

    $resolved = app(ContributionSubjectLinker::class)->resolve(
        $intake->fresh(),
        $staleSubjectId,
        'power',
    );
    expect($resolved)->toBe((int) $successor->dossier_subject_id);

    $this->actingAs($user)
        ->post(route('intakes.workspace.tasks.prepare', $intake), [
            'type' => FollowUpItemType::Photo->value,
            'prompt' => 'Maak een foto van de plek waar de stroomkabel naar de buitenunit kan lopen.',
            'decision_area_key' => 'power',
            'dossier_subject_id' => $staleSubjectId,
        ])
        ->assertRedirect(route('intakes.workspace', $intake).'#demo-customer-task')
        ->assertSessionHas('customer_task_drafts.0.dossier_subject_id', $successor->dossier_subject_id);
});

test('N45: verwijderd subject zonder unieke opvolger toont ververs-melding', function () {
    $user = User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'N45 Stale',
        'customer_email' => 'n45-stale@example.com',
        'address_line' => 'Stale 2',
        'address_postal_code' => '1000AA',
        'address_house_number' => 2,
        'address_city' => 'Amsterdam',
    ]);
    app(DossierManager::class)->initialize($intake);

    $missingId = 9_999_991;

    expect(fn () => app(ContributionSubjectLinker::class)->resolve($intake, $missingId, 'power'))
        ->toThrow(ValidationException::class);

    try {
        app(ContributionSubjectLinker::class)->resolve($intake, $missingId, 'power');
    } catch (ValidationException $e) {
        expect($e->errors()['dossier_subject_id'][0] ?? null)
            ->toBe(ContributionSubjectLinker::MESSAGE_STALE_AFTER_REBUILD);
    }

    $this->actingAs($user)
        ->from(route('intakes.workspace', $intake))
        ->post(route('intakes.workspace.tasks.prepare', $intake), [
            'type' => FollowUpItemType::Photo->value,
            'prompt' => 'Maak een foto van de stroomroute.',
            'decision_area_key' => 'power',
            'dossier_subject_id' => $missingId,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors([
            'dossier_subject_id' => ContributionSubjectLinker::MESSAGE_STALE_AFTER_REBUILD,
        ]);
});

test('N45: verwijderd subject met 2 kandidaten toont ververs-melding', function () {
    $user = User::factory()->create();
    ['intake' => $intake, 'power' => $power] = n40SurveyWithPowerAndCondensate($user);

    $survey = app(AircoSurveyService::class);
    $option = $intake->aircoInstallationOptions()->firstOrFail();
    $outside = $intake->aircoPlacements()->where('type', AircoPlacementType::OutdoorUnit)->firstOrFail();
    $survey->createConnection($intake, $user, $option, [
        'type' => AircoConnectionType::Power,
        'label' => 'Stroom tweede route',
        'from_placement_id' => $outside->id,
        'to_placement_id' => $outside->id,
        'status' => AircoConnectionStatus::NeedsEvidence,
    ]);

    $staleSubjectId = 9_999_992;
    expect(DossierSubject::query()->whereKey($staleSubjectId)->exists())->toBeFalse();
    expect(AircoConnection::query()->where('intake_id', $intake->id)->where('type', AircoConnectionType::Power)->count())
        ->toBe(2);

    expect(fn () => app(ContributionSubjectLinker::class)->resolve($intake->fresh(), $staleSubjectId, 'power'))
        ->toThrow(ValidationException::class);

    try {
        app(ContributionSubjectLinker::class)->resolve($intake->fresh(), $staleSubjectId, 'power');
    } catch (ValidationException $e) {
        expect($e->errors()['dossier_subject_id'][0] ?? null)
            ->toBe(ContributionSubjectLinker::MESSAGE_STALE_AFTER_REBUILD);
    }

    // Bestaande power-subject blijft onaangeroerd (geen stille first-id-pick).
    expect((int) $power->fresh()->dossier_subject_id)->toBe((int) $power->dossier_subject_id);
});

test('N45: subject van andere intake houdt oude security-melding', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    ['intake' => $intake] = n40SurveyWithPowerAndCondensate($user);

    $foreignIntake = app(CreateIntake::class)->handle($other, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'N45 Foreign',
        'customer_email' => 'n45-foreign@example.com',
        'address_line' => 'Anders 3',
        'address_postal_code' => '1000BB',
        'address_house_number' => 3,
        'address_city' => 'Utrecht',
    ]);
    app(DossierManager::class)->initialize($foreignIntake);
    $foreignSurvey = app(AircoSurveyService::class);
    $foreignRoom = $foreignSurvey->createRoom($foreignIntake, $other, [
        'name' => 'Kamer',
        'use_type' => 'other',
    ]);
    $foreignInside = $foreignSurvey->createPlacement($foreignIntake, $other, [
        'airco_room_id' => $foreignRoom->id,
        'type' => AircoPlacementType::IndoorUnit,
        'label' => 'Binnen',
    ]);
    $foreignOutside = $foreignSurvey->createPlacement($foreignIntake, $other, [
        'type' => AircoPlacementType::OutdoorUnit,
        'label' => 'Buiten',
    ]);
    $foreignOption = $foreignSurvey->createInstallationOption($foreignIntake, $other, [
        'label' => 'Optie B',
        'configuration_type' => AircoConfigurationType::SingleSplit,
        'placement_ids' => [$foreignInside->id, $foreignOutside->id],
    ]);
    $foreignPower = $foreignSurvey->createConnection($foreignIntake, $other, $foreignOption, [
        'type' => AircoConnectionType::Power,
        'label' => 'Stroom ander',
        'from_placement_id' => $foreignOutside->id,
        'to_placement_id' => $foreignOutside->id,
        'status' => AircoConnectionStatus::NeedsEvidence,
    ]);

    expect(fn () => app(ContributionSubjectLinker::class)->resolve(
        $intake,
        $foreignPower->dossier_subject_id,
        'power',
    ))->toThrow(ValidationException::class);

    try {
        app(ContributionSubjectLinker::class)->resolve(
            $intake,
            $foreignPower->dossier_subject_id,
            'power',
        );
    } catch (ValidationException $e) {
        expect($e->errors()['dossier_subject_id'][0] ?? null)
            ->toBe(ContributionSubjectLinker::MESSAGE_FOREIGN_INTAKE);
    }

    $this->actingAs($user)
        ->from(route('intakes.workspace', $intake))
        ->post(route('intakes.workspace.tasks.prepare', $intake), [
            'type' => FollowUpItemType::Photo->value,
            'prompt' => 'Maak een foto van de stroomroute.',
            'decision_area_key' => 'power',
            'dossier_subject_id' => $foreignPower->dossier_subject_id,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors([
            'dossier_subject_id' => ContributionSubjectLinker::MESSAGE_FOREIGN_INTAKE,
        ]);

    expect(fn () => app(CreateCustomerContributionRequest::class)->handle($intake, $user, [[
        'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een foto van de stroomroute.',
        'decision_area_key' => 'power',
        'dossier_subject_id' => $foreignPower->dossier_subject_id,
    ]]))->toThrow(ValidationException::class);
});
