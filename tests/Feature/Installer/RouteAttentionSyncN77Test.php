<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\ApprovePipeRoute;
use App\Domains\Intake\Actions\CompleteInstallerSurvey;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\DecideAircoConnection;
use App\Domains\Intake\Actions\StartPipeRouteSession;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\CompletenessChecker;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoConnectionType;
use App\Enums\AircoPlacementType;
use App\Enums\AttentionPointSource;
use App\Enums\ContributionMode;
use App\Enums\PipeRouteStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->seed(IntakeTemplateSeeder::class);
    $this->user = User::factory()->create();
    $this->intake = app(CreateIntake::class)->handle($this->user, [
        'template_key' => 'airco', 'customer_name' => 'Routetest',
        'customer_email' => 'route@example.com', 'address_line' => 'Testlaan 10',
        'address_postal_code' => '1000AA', 'address_house_number' => 10, 'address_city' => 'Amsterdam',
    ]);
    $this->survey = app(AircoSurveyService::class);
    $this->room = $this->survey->createRoom($this->intake, $this->user, ['name' => 'Slaapkamer']);
    $inside = $this->survey->createPlacement($this->intake, $this->user, ['airco_room_id' => $this->room->id, 'type' => AircoPlacementType::IndoorUnit, 'label' => 'Binnen']);
    $outside = $this->survey->createPlacement($this->intake, $this->user, ['type' => AircoPlacementType::OutdoorUnit, 'label' => 'Buiten']);
    $this->option = $this->survey->createInstallationOption($this->intake, $this->user, [
        'label' => 'Voorstel', 'configuration_type' => 'single_split',
        'placement_ids' => [$inside->id, $outside->id],
        'refrigerant_links' => [['from_placement_id' => $inside->id, 'to_placement_id' => $outside->id]],
    ]);
    $this->cooling = $this->option->connections()->firstOrFail();
    $this->cooling->update(['status' => AircoConnectionStatus::Proposed]);
    $this->drain = $this->survey->createConnection($this->intake, $this->user, $this->option, [
        'type' => AircoConnectionType::Condensate, 'label' => 'Afvoer slaapkamer',
        'from_placement_id' => $inside->id, 'status' => AircoConnectionStatus::Proposed,
    ]);
    // SyncRouteAttentionPoints may already have created these during survey setup.
    foreach (['condensate_pump_open', 'pipe_route_open', 'drillings_open'] as $code) {
        $this->intake->attentionPoints()->updateOrCreate(
            ['source' => AttentionPointSource::System, 'code' => $code],
            ['label' => 'Oud punt', 'is_resolved' => false, 'resolved_at' => null, 'resolved_by' => null],
        );
    }
});

test('N77 route approvals resolve only corresponding points and restarting reopens them', function () {
    $decide = app(DecideAircoConnection::class);
    $decide->handle($this->intake, $this->cooling, $this->user, 'approve');
    expect($this->intake->attentionPoints()->where('code', 'pipe_route_open')->first()->is_resolved)->toBeTrue()
        ->and($this->intake->attentionPoints()->where('code', 'drillings_open')->first()->label)->toContain('Afvoer slaapkamer', 'route #'.$this->drain->id);
    $session = app(StartPipeRouteSession::class)->handle($this->intake, $this->drain);
    $session->refresh()->update(['status' => PipeRouteStatus::Proposed]);
    app(ApprovePipeRoute::class)->handle($session, $this->user);
    expect($this->intake->attentionPoints()->where('is_resolved', false)->count())->toBe(0);
    $codes = array_column(app(CompletenessChecker::class)->check($this->intake->fresh(), $this->intake->templateVersion)['attention_points'], 'code');
    expect($codes)->not->toContain('pipe_route_open', 'condensate_pump_open', 'drillings_open');
    app(StartPipeRouteSession::class)->handle($this->intake, $this->drain);
    expect($this->intake->attentionPoints()->where('code', 'condensate_pump_open')->first()->is_resolved)->toBeFalse()
        ->and($this->intake->attentionPoints()->where('code', 'drillings_open')->first()->is_resolved)->toBeFalse()
        ->and($this->intake->attentionPoints()->where('code', 'pipe_route_open')->first()->is_resolved)->toBeTrue();
});

test('N77 missing condensate stays concrete and N74 scope undo restores the open points', function () {
    app(DecideAircoConnection::class)->handle($this->intake, $this->cooling, $this->user, 'approve');
    $this->drain->delete();
    $checker = app(CompletenessChecker::class);
    expect($checker->routeDecisionAttention($this->intake, 'condensate_pump_open', 'Open')[0]['label'])->toContain('Binnen', 'Condensafvoer ontbreekt');
    $this->survey->confirmRoomExistingInstallation($this->intake, $this->user, $this->room);
    expect($this->intake->attentionPoints()->where('is_resolved', false)->count())->toBe(0);
    $this->survey->clearRoomScopeConfirmation($this->intake, $this->user, $this->room->fresh());
    expect($this->intake->attentionPoints()->where('code', 'condensate_pump_open')->first()->is_resolved)->toBeFalse();
});

test('N77 session state prevents stale approval and synchronization preserves reviewer points', function () {
    $reviewer = $this->intake->attentionPoints()->create([
        'source' => AttentionPointSource::Reviewer, 'code' => 'pipe_route_open',
        'label' => 'Eigen aandachtspunt', 'is_resolved' => false,
    ]);
    $session = app(StartPipeRouteSession::class)->handle($this->intake, $this->cooling);
    $this->cooling->update(['status' => AircoConnectionStatus::Approved]);
    $checker = app(CompletenessChecker::class);
    expect($checker->routeDecisionAttention($this->intake, 'pipe_route_open', 'Open')[0]['label'])->toContain('route #'.$this->cooling->id);
    $session->refresh()->update(['status' => PipeRouteStatus::Proposed]);
    app(ApprovePipeRoute::class)->handle($session, $this->user, false);
    expect($this->intake->attentionPoints()->where('source', AttentionPointSource::System)->where('code', 'pipe_route_open')->first()->is_resolved)->toBeFalse();
    $session->refresh()->update(['status' => PipeRouteStatus::Proposed]);
    app(ApprovePipeRoute::class)->handle($session, $this->user);
    expect($reviewer->fresh()->is_resolved)->toBeFalse()
        ->and($reviewer->fresh()->label)->toBe('Eigen aandachtspunt');
});

test('N77 recreates missing system points when a previously-approved route is restarted', function () {
    app(DecideAircoConnection::class)->handle($this->intake, $this->cooling, $this->user, 'approve');
    $session = app(StartPipeRouteSession::class)->handle($this->intake, $this->drain);
    $session->refresh()->update(['status' => PipeRouteStatus::Proposed]);
    app(ApprovePipeRoute::class)->handle($session, $this->user);
    expect($this->intake->attentionPoints()->where('source', AttentionPointSource::System)->whereIn('code', ['condensate_pump_open', 'pipe_route_open', 'drillings_open'])->where('is_resolved', false)->count())->toBe(0);

    // Simulate customer completion after all routes approved: CompletenessChecker
    // would not have created these codes, so purge the resolved system records.
    $this->intake->attentionPoints()->where('source', AttentionPointSource::System)->whereIn('code', ['condensate_pump_open', 'pipe_route_open', 'drillings_open'])->delete();
    expect($this->intake->attentionPoints()->where('code', 'condensate_pump_open')->count())->toBe(0);

    app(StartPipeRouteSession::class)->handle($this->intake, $this->drain);
    expect($this->intake->attentionPoints()->where('source', AttentionPointSource::System)->where('code', 'condensate_pump_open')->where('is_resolved', false)->exists())->toBeTrue()
        ->and($this->intake->attentionPoints()->where('source', AttentionPointSource::System)->where('code', 'drillings_open')->where('is_resolved', false)->exists())->toBeTrue()
        ->and($this->intake->attentionPoints()->where('source', AttentionPointSource::System)->where('code', 'pipe_route_open')->where('is_resolved', false)->exists())->toBeFalse();
});

test('N77 bulk approval via CompleteInstallerSurvey resolves the three route attention points', function () {
    // Self-contained fixture (CI shards do not load helpers from other test files).
    $user = User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Bulk Route',
        'customer_email' => 'bulk-route@example.com',
        'address_line' => 'Voorbeeldstraat 12',
        'address_postal_code' => '1234AB',
        'address_house_number' => 12,
        'address_city' => 'Utrecht',
    ]);
    $survey = app(AircoSurveyService::class);
    $room = $survey->createRoom($intake, $user, [
        'name' => 'Woonkamer', 'use_type' => 'living_room',
        'length_m' => 5.0, 'width_m' => 4.0, 'height_m' => 2.6,
    ]);
    $inside = $survey->createPlacement($intake, $user, [
        'airco_room_id' => $room->id, 'type' => AircoPlacementType::IndoorUnit, 'label' => 'Binnen',
    ]);
    $outside = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::OutdoorUnit, 'label' => 'Buiten',
    ]);
    $powerPoint = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::PowerSource, 'label' => 'Meterkast',
    ]);
    $drainPoint = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::DrainPoint, 'label' => 'Regenpijp',
    ]);
    foreach ([$powerPoint, $outside] as $index => $placement) {
        IntakeUpload::query()->create([
            'intake_id' => $intake->id,
            'question_key' => 'installer_evidence',
            'section_instance_key' => 'subject-'.$placement->dossier_subject_id,
            'disk' => 'local',
            'path' => 'test/n77-bulk-'.$index.'.jpg',
            'original_filename' => $index === 0 ? 'meterkast.jpg' : 'gevel.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
            'checksum' => hash('sha256', 'n77-bulk-'.$index),
            'sort_order' => 1,
        ]);
    }
    $option = $survey->createInstallationOption($intake, $user, [
        'label' => 'Single-split',
        'configuration_type' => 'single_split',
        'placement_ids' => [$inside->id, $outside->id, $powerPoint->id, $drainPoint->id],
    ]);
    $cooling = $survey->createConnection($intake, $user, $option, [
        'type' => AircoConnectionType::Refrigerant, 'label' => 'Koelleiding',
        'from_placement_id' => $inside->id, 'to_placement_id' => $outside->id,
        'status' => AircoConnectionStatus::Proposed, 'uncertainties' => [],
    ]);
    $drain = $survey->createConnection($intake, $user, $option, [
        'type' => AircoConnectionType::Condensate, 'label' => 'Condensafvoer',
        'from_placement_id' => $inside->id, 'to_placement_id' => $drainPoint->id,
        'status' => AircoConnectionStatus::Proposed, 'uncertainties' => [],
    ]);
    $power = $survey->createConnection($intake, $user, $option, [
        'type' => AircoConnectionType::Power, 'label' => 'Stroom',
        'from_placement_id' => $powerPoint->id, 'to_placement_id' => $outside->id,
        'status' => AircoConnectionStatus::Proposed, 'uncertainties' => [],
    ]);
    $survey->markInstallationOptionFeasible($intake, $user, $option);
    $survey->selectInstallationOption($intake, $user, $option);

    foreach (['condensate_pump_open', 'pipe_route_open', 'drillings_open'] as $code) {
        $intake->attentionPoints()->updateOrCreate(
            ['source' => AttentionPointSource::System, 'code' => $code],
            ['label' => 'Oud punt', 'is_resolved' => false, 'resolved_at' => null, 'resolved_by' => null],
        );
    }

    foreach ([$cooling, $drain, $power] as $toApprove) {
        $this->actingAs($user)
            ->post(route('intakes.workspace.connections.decide', [$intake, $toApprove]), ['decision' => 'approve'])
            ->assertRedirect();
    }

    // Individual decides already sync; force the three points open so bulk must resolve them.
    $intake->attentionPoints()->where('source', AttentionPointSource::System)->whereIn('code', ['condensate_pump_open', 'pipe_route_open', 'drillings_open'])->update([
        'is_resolved' => false, 'resolved_at' => null, 'resolved_by' => null, 'label' => 'Oud punt',
    ]);

    app(CompleteInstallerSurvey::class)->handle($intake->fresh(), $user);

    expect($intake->attentionPoints()->where('source', AttentionPointSource::System)->whereIn('code', ['condensate_pump_open', 'pipe_route_open', 'drillings_open'])->where('is_resolved', false)->count())->toBe(0)
        ->and($intake->fresh()->status->value)->toBe('completed');
});
