<?php

declare(strict_types=1);

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\DecideAircoConnection;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\DossierRecord;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\GenerateIntakeReportHtml;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoConnectionType;
use App\Enums\AircoPlacementType;
use App\Enums\DossierRecordKind;
use App\Enums\DossierRecordStatus;
use App\Enums\IntakeStatus;
use App\Enums\PhotoAssessmentStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
});

function makeReportLabelsIntake(): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::InProgress,
        'customer_name' => 'Rapport Labels',
        'customer_email' => 'rapport-labels-'.uniqid('', true).'@example.com',
    ]);
}

test('N35 rest: zonder voorstellen toont rapport neutrale keuzestatus', function () {
    $intake = makeReportLabelsIntake();
    $version = $intake->fresh()->templateVersion()->firstOrFail();
    $html = app(GenerateIntakeReportHtml::class)->handle($intake->fresh(), $version);

    expect($html)->toContain('Installatiekeuze')
        ->and($html)->toContain(GenerateIntakeReportHtml::NO_INSTALLATION_CHOICE_LABEL)
        ->and($html)->not->toContain('Geselecteerde installatiekeuze')
        ->and($html)->not->toContain('Beschikbare voorstellen')
        ->and($html)->not->toContain('Verbindingen en routes');
});

test('N35 rest: voorstellen zonder Deze keuze tonen routestatus per verbinding', function () {
    $intake = makeReportLabelsIntake();
    $user = User::query()->findOrFail($intake->created_by);
    $survey = app(AircoSurveyService::class);

    $room = $survey->createRoom($intake, $user, [
        'name' => 'Woonkamer',
        'use_type' => 'living_room',
    ]);
    $indoor = $survey->createPlacement($intake, $user, [
        'airco_room_id' => $room->id,
        'type' => AircoPlacementType::IndoorUnit,
        'label' => 'Binnenunit woonkamer',
    ]);
    $outdoor = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::OutdoorUnit,
        'label' => 'Buitenunit',
    ]);
    $option = $survey->createInstallationOption($intake, $user, [
        'label' => 'Kandidaat single-split',
        'configuration_type' => AircoConfigurationType::SingleSplit,
        'placement_ids' => [$indoor->id, $outdoor->id],
        'summary' => 'Voorstel uit klantinfo: woonkamer naar gevel.',
    ]);
    $survey->markInstallationOptionFeasible($intake, $user, $option);

    $refrigerant = $survey->createConnection($intake, $user, $option->fresh(), [
        'type' => AircoConnectionType::Refrigerant,
        'label' => 'Koelleidingroute kandidaat',
        'from_placement_id' => $indoor->id,
        'to_placement_id' => $outdoor->id,
        'status' => AircoConnectionStatus::Proposed,
        'segments' => ['Via gevel'],
    ]);
    app(DecideAircoConnection::class)->handle($intake, $refrigerant, $user, 'approve');

    $condensate = $survey->createConnection($intake, $user, $option->fresh(), [
        'type' => AircoConnectionType::Condensate,
        'label' => 'Condensroute kandidaat',
        'from_placement_id' => $indoor->id,
        'to_placement_id' => $outdoor->id,
        'status' => AircoConnectionStatus::Proposed,
        'segments' => ['Naar regenpijp'],
        'uncertainties' => ['Afschot onzeker'],
    ]);
    app(DecideAircoConnection::class)->handle($intake, $condensate, $user, 'site_visit');

    $power = $survey->createConnection($intake, $user, $option->fresh(), [
        'type' => AircoConnectionType::Power,
        'label' => 'Stroomroute kandidaat',
        'from_placement_id' => $outdoor->id,
        'to_placement_id' => $indoor->id,
        'status' => AircoConnectionStatus::NeedsEvidence,
        'segments' => ['Van meterkast'],
    ]);

    expect($option->fresh()->status->value)->toBe('candidate')
        ->and($power->fresh()->status)->toBe(AircoConnectionStatus::NeedsEvidence);

    $version = $intake->fresh()->templateVersion()->firstOrFail();
    $html = app(GenerateIntakeReportHtml::class)->handle($intake->fresh(), $version);

    expect($html)->toContain('Installatiekeuze')
        ->and($html)->toContain(GenerateIntakeReportHtml::NO_INSTALLATION_CHOICE_LABEL)
        ->and($html)->toContain('Beschikbare voorstellen')
        ->and($html)->toContain('Kandidaat single-split')
        ->and($html)->toContain('Voorstel uit klantinfo: woonkamer naar gevel.')
        ->and($html)->toContain('Koelleidingroute kandidaat')
        ->and($html)->toContain('Goedgekeurd')
        ->and($html)->toContain('Condensroute kandidaat')
        ->and($html)->toContain('Alleen te zien op locatie')
        ->and($html)->toContain('Afschot onzeker')
        ->and($html)->toContain('Stroomroute kandidaat')
        ->and($html)->toContain('Aanvulling nodig')
        ->and($html)->not->toContain('Geselecteerde installatiekeuze')
        ->and($html)->not->toContain('Verbindingen en routes');
});

test('N35: rapport toont per route status, notitie en open onzekerheden', function () {
    $intake = makeReportLabelsIntake();
    $user = User::query()->findOrFail($intake->created_by);
    $survey = app(AircoSurveyService::class);

    $room = $survey->createRoom($intake, $user, [
        'name' => 'Woonkamer',
        'use_type' => 'living_room',
    ]);
    $indoor = $survey->createPlacement($intake, $user, [
        'airco_room_id' => $room->id,
        'type' => AircoPlacementType::IndoorUnit,
        'label' => 'Binnenunit woonkamer',
    ]);
    $outdoor = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::OutdoorUnit,
        'label' => 'Buitenunit',
    ]);
    $option = $survey->createInstallationOption($intake, $user, [
        'label' => 'Single-split keuze',
        'configuration_type' => AircoConfigurationType::SingleSplit,
        'placement_ids' => [$indoor->id, $outdoor->id],
    ]);
    $survey->markInstallationOptionFeasible($intake, $user, $option);
    $survey->selectInstallationOption($intake, $user, $option);

    $approved = $survey->createConnection($intake, $user, $option->fresh(), [
        'type' => AircoConnectionType::Refrigerant,
        'label' => 'Koelroute woonkamer',
        'from_placement_id' => $indoor->id,
        'to_placement_id' => $outdoor->id,
        'status' => AircoConnectionStatus::Proposed,
        'segments' => ['Via gevel'],
        'uncertainties' => [],
    ]);
    app(DecideAircoConnection::class)->handle($intake, $approved, $user, 'approve');

    $siteVisit = $survey->createConnection($intake, $user, $option->fresh(), [
        'type' => AircoConnectionType::Condensate,
        'label' => 'Condensroute zolder',
        'from_placement_id' => $indoor->id,
        'to_placement_id' => $outdoor->id,
        'status' => AircoConnectionStatus::Proposed,
        'segments' => ['Nog open'],
        'uncertainties' => ['Afschot onzeker'],
    ]);

    DossierRecord::query()->create([
        'intake_id' => $intake->id,
        'company_id' => $intake->company_id,
        'dossier_subject_id' => $siteVisit->dossier_subject_id,
        'kind' => DossierRecordKind::Observation,
        'key' => 'installer_note.route',
        'value' => ['text' => 'Op zolder eerst ter plaatse kijken.'],
        'status' => DossierRecordStatus::Established,
        'source_type' => 'installer',
        'method' => 'installer_note',
        'confidence' => 1.0,
        'actor_type' => 'installer',
        'actor_id' => $user->id,
        'observed_at' => now(),
    ]);

    app(DecideAircoConnection::class)->handle($intake, $siteVisit, $user, 'site_visit');

    $version = $intake->fresh()->templateVersion()->firstOrFail();
    $html = app(GenerateIntakeReportHtml::class)->handle($intake->fresh(), $version);

    expect($html)->toContain('Geselecteerde installatiekeuze')
        ->and($html)->toContain('Single-split keuze')
        ->and($html)->toContain('Verbindingen en routes')
        ->and($html)->toContain('Routebesluit per verbinding')
        ->and($html)->toContain('Koelroute woonkamer')
        ->and($html)->toContain('Goedgekeurd')
        ->and($html)->toContain('Condensroute zolder')
        ->and($html)->toContain('Alleen te zien op locatie')
        ->and($html)->toContain('Op zolder eerst ter plaatse kijken.')
        ->and($html)->toContain('Afschot onzeker')
        ->and($html)->not->toContain(GenerateIntakeReportHtml::NO_INSTALLATION_CHOICE_LABEL)
        ->and($html)->not->toContain('Beschikbare voorstellen');
});

test('N36: AI-prefill zonder klantvraag wordt gelabeld of weggelaten; klant-unknown blijft', function () {
    $intake = makeReportLabelsIntake();
    $user = User::query()->findOrFail($intake->created_by);
    $survey = app(AircoSurveyService::class);
    $save = app(SaveIntakeAnswer::class);

    $save->handle($intake, 'indoor_unit_count', null, ['number' => 2]);
    $survey->createRoom($intake, $user, [
        'name' => 'Woonkamer',
        'use_type' => 'living_room',
    ]);
    $survey->createRoom($intake, $user, [
        'name' => 'Zolder',
        'use_type' => 'bedroom',
    ]);

    // AI photo unknown for living room — must not appear as "Weet ik niet".
    IntakeAnswer::query()->create([
        'intake_id' => $intake->id,
        'question_key' => 'sun_exposure',
        'section_instance_key' => 'room-1',
        'value' => ['value' => 'unknown'],
        'prefill_source' => PrefillSources::AI_PHOTO,
        'fact_source' => 'foto',
        'answered_at' => now(),
    ]);

    // AI photo concrete value — labeled, not as bare customer answer.
    IntakeAnswer::query()->create([
        'intake_id' => $intake->id,
        'question_key' => 'glass_amount',
        'section_instance_key' => 'room-1',
        'value' => ['value' => 'average'],
        'prefill_source' => PrefillSources::AI_PHOTO,
        'fact_source' => 'foto',
        'answered_at' => now(),
    ]);

    // Real customer "Weet ik niet" on attic — stays as option label without AI suffix.
    $save->handle($intake, 'sun_exposure', 'room-2', ['value' => 'unknown']);

    $version = $intake->fresh()->templateVersion()->with([
        'sections.questions.options',
        'sections.questions.rules',
    ])->firstOrFail();
    $html = app(GenerateIntakeReportHtml::class)->handle($intake->fresh(), $version);

    expect($html)->toContain('Gemiddeld (Voorzet uit foto)')
        ->and($html)->toContain('Weet ik niet')
        ->and($html)->not->toContain('Weet ik niet (Voorzet uit foto')
        ->and($html)->not->toContain('Weet ik niet (Klantantwoord)');

    // room-1 AI unknown sun: question still visible only if customer answered — omitted here.
    $room1Chunk = '';
    if (preg_match('/\(room-1\)[\s\S]*?(?=<h2>|$)/u', $html, $matches) === 1) {
        $room1Chunk = $matches[0];
    }
    expect($room1Chunk)->not->toBe('')
        ->and($room1Chunk)->toContain('Gemiddeld (Voorzet uit foto)')
        ->and($room1Chunk)->not->toContain('Weet ik niet');
});

test('N42: BAG, tekst zonder bevestiging, bevestigde prefill, klantkeuze, lege around_house', function () {
    $intake = makeReportLabelsIntake();
    $save = app(SaveIntakeAnswer::class);

    // BAG / PDOK — niet “klantantwoord, niet aan de klant gevraagd”.
    IntakeAnswer::query()->create([
        'intake_id' => $intake->id,
        'question_key' => 'building_type',
        'section_instance_key' => null,
        'value' => ['value' => 'terraced'],
        'prefill_source' => 'pdok',
        'fact_source' => 'klantantwoord',
        'answered_at' => now(),
    ]);
    IntakeAnswer::query()->create([
        'intake_id' => $intake->id,
        'question_key' => 'build_year',
        'section_instance_key' => null,
        'value' => ['number' => 1971],
        'prefill_source' => 'pdok',
        'fact_source' => 'klantantwoord',
        'answered_at' => now(),
    ]);

    // Tekstprefill zonder opgeslagen bevestiging → “Voorzet uit tekst”.
    IntakeAnswer::query()->create([
        'intake_id' => $intake->id,
        'question_key' => 'cooling_heating',
        'section_instance_key' => null,
        'value' => ['value' => 'cooling'],
        'prefill_source' => PrefillSources::AI_TEXT,
        'fact_source' => 'aanvraag (installateur)',
        'answered_at' => now(),
    ]);

    // Installer-prefill → installerSourceLabel, geen “bevestigd door klant”.
    IntakeAnswer::query()->create([
        'intake_id' => $intake->id,
        'question_key' => 'ownership',
        'section_instance_key' => null,
        'value' => ['value' => 'owned'],
        'prefill_source' => 'installer',
        'fact_source' => 'aanvraag (installateur)',
        'answered_at' => now(),
    ]);

    // Bevestigd: fact_source=klantantwoord + behouden tekstprefill (Wijzigen→zelfde waarde).
    IntakeAnswer::query()->create([
        'intake_id' => $intake->id,
        'question_key' => 'indoor_unit_count',
        'section_instance_key' => null,
        'value' => ['number' => 1],
        'prefill_source' => PrefillSources::AI_TEXT,
        'fact_source' => 'klantantwoord',
        'answered_at' => now(),
    ]);

    // Klant koos zelf afvoer (prefill gewist) → geen suffix.
    $save->handle($intake, 'drain_location', null, ['value' => 'outside_nearby']);

    // Bruikbare buitenfoto → lege around_house-rij mag niet in het rapport.
    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'outdoor_location_photos',
        null,
        UploadedFile::fake()->image('gevel.jpg', 1200, 900),
    );
    $upload->forceFill([
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'content_assessment' => PhotoContentAssessment::ok(PhotoSubject::OutdoorLocation)->toArray(),
    ])->save();

    $version = $intake->fresh()->templateVersion()->with([
        'sections.questions.options',
        'sections.questions.rules',
    ])->firstOrFail();
    $html = app(GenerateIntakeReportHtml::class)->handle($intake->fresh(), $version);

    expect($html)->toContain('Tussenwoning (Voorzet uit BAG)')
        ->and($html)->toContain('1971 (Voorzet uit BAG)')
        ->and($html)->not->toContain('klantantwoord, niet aan de klant gevraagd')
        ->and($html)->toContain('Voorzet uit tekst')
        ->and($html)->toContain('aanvraag (installateur)')
        ->and($html)->not->toMatch('/owned.*Voorzet uit aanvraag, bevestigd door klant/u')
        ->and($html)->toContain('1 (Voorzet uit aanvraag, bevestigd door klant)')
        ->and($html)->toContain('Regenpijp of putje buiten in de buurt')
        ->and($html)->not->toContain('Regenpijp of putje buiten in de buurt (Klantantwoord)')
        ->and($html)->not->toContain('Voorzet uit foto, niet aan de klant gevraagd')
        ->and($html)->not->toContain('We zien de buitenkant nog niet goed');
});
