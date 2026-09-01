<?php

declare(strict_types=1);

use App\Domains\AI\Actions\SuggestInstallerPhotoObservations;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\StoreInstallerDossierUpload;
use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\DossierEvidenceLink;
use App\Domains\Intake\Models\DossierRecord;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Enums\AiRunStatus;
use App\Enums\ContributionMode;
use App\Enums\DossierRecordKind;
use App\Enums\DossierRecordStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
    FakeAiClient::reset();
    config([
        'ai.provider' => 'fake',
        'ai.photo_inference.enabled' => true,
        'ai.photo_inference.observation_min_confidence' => 0.65,
    ]);
});

afterEach(function () {
    FakeAiClient::reset();
});

/** @return array{0: User, 1: Intake, 2: AircoRoom} */
function installerPhotoObservationContext(): array
{
    $installer = User::factory()->create();
    $intake = app(CreateIntake::class)->handle($installer, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Foto Test',
        'customer_email' => 'foto@example.com',
        'address_line' => 'Testlaan 10',
        'address_postal_code' => '1000AA',
        'address_house_number' => 10,
        'address_city' => 'Amsterdam',
    ]);
    $room = app(AircoSurveyService::class)->createRoom($intake, $installer, [
        'name' => 'Slaapkamer',
        'use_type' => 'bedroom',
    ]);

    return [$installer, $intake, $room];
}

test('installer photo produces only a proposed observation linked to its photo and subject', function () {
    [$installer, $intake, $room] = installerPhotoObservationContext();
    FakeAiClient::alwaysReturn([
        'observations' => [[
            'text' => 'Bakstenen buitenmuur, vanaf de grond bereikbaar.',
            'impact' => 'installation',
            'confidence' => 0.91,
        ]],
    ]);
    $upload = app(StoreInstallerDossierUpload::class)->handle(
        $intake,
        $installer,
        $room->subject,
        UploadedFile::fake()->image('wand.jpg', 1200, 900),
    );

    $run = app(SuggestInstallerPhotoObservations::class)->handle(
        $intake->fresh(),
        $room->subject,
        $upload,
    );
    $record = DossierRecord::query()
        ->where('source_type', 'ai')
        ->where('source_id', $run?->id)
        ->sole();

    expect($run?->status)->toBe(AiRunStatus::Succeeded)
        ->and($record->dossier_subject_id)->toBe($room->dossier_subject_id)
        ->and($record->status)->toBe(DossierRecordStatus::Proposed)
        ->and($record->method)->toBe('photo_inference')
        ->and($record->confidence)->toBe(0.91)
        ->and($record->value)->toMatchArray([
            'text' => 'Bakstenen buitenmuur, vanaf de grond bereikbaar.',
            'impact' => 'installation',
        ])
        ->and(DossierEvidenceLink::query()
            ->where('dossier_record_id', $record->id)
            ->where('evidence_type', 'intake_upload')
            ->where('evidence_id', $upload->id)
            ->exists())->toBeTrue()
        ->and(DossierEvidenceLink::query()
            ->where('dossier_record_id', $record->id)
            ->where('evidence_type', 'ai_run')
            ->where('evidence_id', $run?->id)
            ->exists())->toBeTrue();
});

test('low confidence photo output never becomes dossier noise', function () {
    [$installer, $intake, $room] = installerPhotoObservationContext();
    FakeAiClient::alwaysReturn([
        'observations' => [[
            'text' => 'Er staat een blauwe stoel in de kamer.',
            'impact' => 'installation',
            'confidence' => 0.4,
        ]],
    ]);
    $upload = app(StoreInstallerDossierUpload::class)->handle(
        $intake,
        $installer,
        $room->subject,
        UploadedFile::fake()->image('kamer.jpg', 1200, 900),
    );

    $run = app(SuggestInstallerPhotoObservations::class)->handle(
        $intake->fresh(),
        $room->subject,
        $upload,
    );

    expect($run?->status)->toBe(AiRunStatus::Succeeded)
        ->and($run?->output['stored_observation_count'] ?? null)->toBe(0)
        ->and(DossierRecord::query()
            ->where('source_type', 'ai')
            ->where('source_id', $run?->id)
            ->exists())->toBeFalse();
});

test('installer confirms or adjusts a photo observation into an established human record', function () {
    [$installer, $intake, $room] = installerPhotoObservationContext();
    FakeAiClient::alwaysReturn([
        'observations' => [[
            'text' => 'Wand lijkt gemetseld.',
            'impact' => 'materials',
            'confidence' => 0.83,
        ]],
    ]);
    $upload = app(StoreInstallerDossierUpload::class)->handle(
        $intake,
        $installer,
        $room->subject,
        UploadedFile::fake()->image('wand.jpg', 1200, 900),
    );
    $run = app(SuggestInstallerPhotoObservations::class)->handle($intake, $room->subject, $upload);
    $proposal = DossierRecord::query()
        ->where('source_type', 'ai')
        ->where('source_id', $run?->id)
        ->sole();

    $this->actingAs($installer)
        ->post(route('intakes.workspace.photo-observations.confirm', [$intake, $proposal]), [
            'text' => 'Massieve gemetselde buitenmuur.',
        ])
        ->assertRedirect(route('intakes.workspace', $intake))
        ->assertSessionHas('status', 'Notitie aangepast en bevestigd.');

    $confirmed = DossierRecord::query()
        ->where('dossier_subject_id', $room->dossier_subject_id)
        ->where('source_type', 'installer')
        ->where('key', $proposal->key)
        ->sole();

    expect($proposal->fresh()->status)->toBe(DossierRecordStatus::Superseded)
        ->and($confirmed->status)->toBe(DossierRecordStatus::Established)
        ->and($confirmed->method)->toBe('installer_adjusted')
        ->and($confirmed->confidence)->toBe(1.0)
        ->and($confirmed->value['text'])->toBe('Massieve gemetselde buitenmuur.')
        ->and($confirmed->evidenceLinks()
            ->where('evidence_type', 'intake_upload')
            ->where('evidence_id', $upload->id)
            ->exists())->toBeTrue();
});

test('photo observation without its original photo evidence cannot be confirmed', function () {
    [$installer, $intake, $room] = installerPhotoObservationContext();
    FakeAiClient::alwaysReturn([
        'observations' => [[
            'text' => 'Wand lijkt gemetseld.',
            'impact' => 'materials',
            'confidence' => 0.83,
        ]],
    ]);
    $upload = app(StoreInstallerDossierUpload::class)->handle(
        $intake,
        $installer,
        $room->subject,
        UploadedFile::fake()->image('wand.jpg', 1200, 900),
    );
    $run = app(SuggestInstallerPhotoObservations::class)->handle($intake, $room->subject, $upload);
    $proposal = DossierRecord::query()
        ->where('source_type', 'ai')
        ->where('source_id', $run?->id)
        ->sole();
    $proposal->evidenceLinks()->where('evidence_type', 'intake_upload')->delete();

    $this->actingAs($installer)
        ->post(route('intakes.workspace.photo-observations.confirm', [$intake, $proposal]))
        ->assertSessionHasErrors('observation');

    expect($proposal->fresh()->status)->toBe(DossierRecordStatus::Proposed)
        ->and(DossierRecord::query()
            ->where('dossier_subject_id', $room->dossier_subject_id)
            ->where('kind', DossierRecordKind::Observation)
            ->where('source_type', 'installer')
            ->exists())->toBeFalse();
});
