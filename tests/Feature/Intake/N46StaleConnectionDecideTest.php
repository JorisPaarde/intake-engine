<?php

declare(strict_types=1);

use App\Domains\AI\Support\InstallerCandidateDecisionPreserver;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\DecideAircoConnection;
use App\Domains\Intake\Actions\ResolveAircoConnection;
use App\Domains\Intake\Models\AircoConnection;
use App\Domains\Intake\Models\DossierSubject;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\DossierManager;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoConnectionType;
use App\Enums\AircoOptionStatus;
use App\Enums\AircoPlacementType;
use App\Enums\ContributionMode;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Mail::fake();
});

/**
 * @return array{intake: Intake, user: User, oldId: int, successor: AircoConnection}
 */
function n46SurveyWithSuccessor(): array
{
    $user = User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'N46 Stale',
        'customer_email' => 'n46@example.com',
        'address_line' => 'Route 1',
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
        'label' => 'Binnenunit',
    ]);
    $outside = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::OutdoorUnit,
        'label' => 'Buitenunit',
    ]);
    $option = $survey->createInstallationOption($intake, $user, [
        'label' => 'Optie A',
        'configuration_type' => AircoConfigurationType::SingleSplit,
        'placement_ids' => [$inside->id, $outside->id],
    ]);
    $old = $survey->createConnection($intake, $user, $option, [
        'type' => AircoConnectionType::Condensate,
        'label' => 'Condensafvoer oud',
        'from_placement_id' => $inside->id,
        'to_placement_id' => $outside->id,
        'status' => AircoConnectionStatus::Proposed,
    ]);
    $oldId = (int) $old->id;
    $oldFingerprint = ResolveAircoConnection::fingerprint($old);

    // Simuleer AI-heropbouw: oude verbinding weg, opvolger met supersedes-meta.
    DossierSubject::query()->whereKey($old->dossier_subject_id)->delete();
    expect(AircoConnection::query()->whereKey($oldId)->exists())->toBeFalse();

    $successor = $survey->createConnection($intake, $user, $option, [
        'type' => AircoConnectionType::Condensate,
        'label' => 'Condensafvoer nieuw',
        'from_placement_id' => $inside->id,
        'to_placement_id' => $outside->id,
        'status' => AircoConnectionStatus::Proposed,
    ]);
    $subject = $successor->subject;
    $meta = is_array($subject->meta) ? $subject->meta : [];
    $meta['supersedes_connection_ids'] = [$oldId];
    $meta['supersedes_fingerprints'] = [(string) $oldId => $oldFingerprint];
    $subject->update(['meta' => $meta]);

    return [
        'intake' => $intake->fresh(),
        'user' => $user,
        'oldId' => $oldId,
        'successor' => $successor->fresh(['subject']) ?? $successor,
    ];
}

test('N46: decide op verouderde connection-id mapt naar opvolger', function () {
    ['intake' => $intake, 'user' => $user, 'oldId' => $oldId, 'successor' => $successor] = n46SurveyWithSuccessor();

    $resolved = app(ResolveAircoConnection::class)->handle($intake, $oldId);
    expect($resolved->id)->toBe($successor->id);

    $decided = app(DecideAircoConnection::class)->handle($intake, $oldId, $user, 'site_visit');
    expect($decided->id)->toBe($successor->id)
        ->and($decided->status)->toBe(AircoConnectionStatus::NotRemotelyResolvable);
});

test('N46: decide op onbekende connection-id geeft ververs-melding, geen 404', function () {
    $user = User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'N46 Missing',
        'customer_email' => 'n46-missing@example.com',
        'address_line' => 'Route 2',
        'address_postal_code' => '1000AA',
        'address_house_number' => 2,
        'address_city' => 'Amsterdam',
    ]);
    app(DossierManager::class)->initialize($intake);

    expect(fn () => app(DecideAircoConnection::class)->handle($intake, 9_999_991, $user, 'approve'))
        ->toThrow(ValidationException::class);

    try {
        app(DecideAircoConnection::class)->handle($intake, 9_999_991, $user, 'approve');
    } catch (ValidationException $e) {
        expect($e->errors()['decision'][0] ?? null)
            ->toBe(ResolveAircoConnection::MESSAGE_STALE_AFTER_REBUILD);
    }

    $this->actingAs($user)
        ->from(route('intakes.workspace', $intake))
        ->post(route('intakes.workspace.connections.decide', [$intake, 9_999_991]), [
            'decision' => 'approve',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors([
            'decision' => ResolveAircoConnection::MESSAGE_STALE_AFTER_REBUILD,
        ]);
});

test('N46: HTTP decide op stale id keurt opvolger goed', function () {
    ['intake' => $intake, 'user' => $user, 'oldId' => $oldId, 'successor' => $successor] = n46SurveyWithSuccessor();

    $this->actingAs($user)
        ->from(route('intakes.workspace', $intake))
        ->post(route('intakes.workspace.connections.decide', [$intake, $oldId]), [
            'decision' => 'approve',
        ])
        ->assertRedirect();

    expect($successor->fresh()->status)->toBe(AircoConnectionStatus::Approved);
});

test('N46: decide met niet-numeriek connection-id geeft 404', function () {
    ['intake' => $intake, 'user' => $user] = n46SurveyWithSuccessor();

    $this->actingAs($user)
        ->post("/intakes/{$intake->id}/opname/connections/abc/decide", ['decision' => 'approve'])
        ->assertNotFound();
});

test('N46: decide op connection van andere intake geeft 404', function () {
    ['intake' => $intake, 'user' => $user] = n46SurveyWithSuccessor();
    ['successor' => $foreign] = n46SurveyWithSuccessor();

    $this->actingAs($user)
        ->post(route('intakes.workspace.connections.decide', [$intake, $foreign->id]), ['decision' => 'approve'])
        ->assertNotFound();

    expect($foreign->fresh()->status)->not->toBe(AircoConnectionStatus::Approved);
});

/**
 * Simulates an AI rebuild: new Candidate option + connection replace the old one,
 * with the installer's decision carried over by the preserver.
 */
function n46Rebuild(Intake $intake, User $user, AircoConnection $old, array $segments): AircoConnection
{
    $survey = app(AircoSurveyService::class);
    $oldOption = $old->installationOption()->with('placements')->first();
    $preserver = app(InstallerCandidateDecisionPreserver::class);
    $snapshots = $preserver->snapshotProtected(collect([$oldOption]));

    $newOption = $survey->createInstallationOption($intake, $user, [
        'label' => 'Optie rebuild',
        'configuration_type' => $oldOption->configuration_type,
        'placement_ids' => $oldOption->placements->pluck('id')->all(),
    ]);
    $newOption->update(['source_type' => 'ai', 'status' => AircoOptionStatus::Candidate]);
    $new = $survey->createConnection($intake, $user, $newOption, [
        'type' => $old->type,
        'label' => 'Rebuild',
        'from_placement_id' => $old->from_placement_id,
        'to_placement_id' => $old->to_placement_id,
        'status' => AircoConnectionStatus::Proposed,
        'segments' => $segments,
    ]);

    $preserver->reapply($intake, $snapshots);
    $oldOption->delete();

    return $new->fresh(['subject']);
}

test('N46: stale klik op opvolger met gewijzigd voorstel wordt niet toegepast', function () {
    ['intake' => $intake, 'user' => $user, 'successor' => $first] = n46SurveyWithSuccessor();
    $first->update(['status' => AircoConnectionStatus::Approved]);
    $first->installationOption->update(['source_type' => 'ai', 'status' => AircoOptionStatus::Candidate]);
    $oldId = $first->id;

    $changed = n46Rebuild($intake, $user, $first->fresh(), ['Nieuw traject via kruipruimte']);

    expect(fn () => app(DecideAircoConnection::class)->handle($intake, $oldId, $user, 'approve'))
        ->toThrow(ValidationException::class, ResolveAircoConnection::MESSAGE_PROPOSAL_REFRESHED);

    $this->actingAs($user)
        ->from(route('intakes.workspace', $intake))
        ->post(route('intakes.workspace.connections.decide', [$intake, $oldId]), ['decision' => 'site_visit'])
        ->assertRedirect()
        ->assertSessionHasErrors(['decision' => ResolveAircoConnection::MESSAGE_PROPOSAL_REFRESHED]);

    expect($changed->fresh()->status)->not->toBe(AircoConnectionStatus::NotRemotelyResolvable);
});

test('N46: stale klik op inhoudelijk gelijk voorstel wordt wel toegepast, ook over keten A→B→C', function () {
    ['intake' => $intake, 'user' => $user, 'successor' => $a] = n46SurveyWithSuccessor();
    $a->update(['status' => AircoConnectionStatus::Approved]);
    $a->installationOption->update(['source_type' => 'ai', 'status' => AircoOptionStatus::Candidate]);
    $a = $a->fresh();

    $b = n46Rebuild($intake, $user, $a, []);
    $b->installationOption->update(['source_type' => 'ai', 'status' => AircoOptionStatus::Candidate]);
    $c = n46Rebuild($intake, $user, $b->fresh(), []);

    $ids = $c->subject->fresh()->meta['supersedes_connection_ids'];
    expect($ids)->toContain($a->id)->toContain($b->id);

    foreach ([$a->id, $b->id] as $staleId) {
        expect(app(ResolveAircoConnection::class)->handle($intake->fresh(), $staleId)->id)->toBe($c->id);
    }
});
