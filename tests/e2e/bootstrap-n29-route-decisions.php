<?php

declare(strict_types=1);

/**
 * Bootstrap for Playwright N29: installer route decisions survive AI rebuild
 * on a Candidate option (before "Deze keuze").
 */

use App\Domains\AI\Actions\SynthesizeSurveyDossier;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\DTOs\AiCompletionRequest;
use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\DecideAircoConnection;
use App\Domains\Intake\Actions\SaveInstallerObservation;
use App\Domains\Intake\Models\AircoConnection;
use App\Domains\Intake\Models\AircoInstallationOption;
use App\Domains\Intake\Models\DossierSubject;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\DossierManager;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoOptionStatus;
use App\Enums\AircoPlacementType;
use App\Enums\ContributionMode;
use App\Enums\IntakeStatus;
use App\Enums\PhotoAssessmentStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config([
    'intake.seed_latest_template_only' => true,
    'ai.provider' => 'fake',
    'ai.dossier.enabled' => true,
    'ai.dossier.max_images' => 8,
]);

if (config('database.default') === 'sqlite') {
    try {
        DB::statement('PRAGMA journal_mode=WAL');
        DB::statement('PRAGMA busy_timeout=5000');
    } catch (Throwable) {
        // Ignore when the connection is not yet open / not sqlite.
    }
}

$app->make(IntakeTemplateSeeder::class)->run();

$password = 'password';
$user = User::factory()->create([
    'email' => 'playwright-n29-'.uniqid('', false).'@example.com',
    'password' => bcrypt($password),
]);

Storage::fake('local');
FakeAiClient::reset();

$intake = app(CreateIntake::class)->handle($user, [
    'template_key' => 'airco',
    'workflow_mode' => ContributionMode::Installer,
    'customer_name' => 'Playwright N29 Route',
    'customer_email' => 'playwright-n29@example.com',
    'address_line' => 'Routelaan 9',
    'address_postal_code' => '2011DD',
    'address_house_number' => 9,
    'address_city' => 'Haarlem',
    'is_demo' => false,
]);
$intake->update(['status' => IntakeStatus::InProgress]);
app(DossierManager::class)->initialize($intake->fresh());

$survey = app(AircoSurveyService::class);
$room = $survey->createRoom($intake, $user, [
    'name' => 'Woonkamer',
    'use_type' => 'living_room',
    'length_m' => 5.0,
    'width_m' => 4.0,
    'height_m' => 2.6,
]);
$indoor = $survey->createPlacement($intake, $user, [
    'airco_room_id' => $room->id,
    'type' => AircoPlacementType::IndoorUnit,
    'label' => 'Binnenunit woonkamer',
]);
$outdoor = $survey->createPlacement($intake, $user, [
    'type' => AircoPlacementType::OutdoorUnit,
    'label' => 'Buitenunit achtergevel',
]);
$power = $survey->createPlacement($intake, $user, [
    'type' => AircoPlacementType::PowerSource,
    'label' => 'Meterkast',
]);
$drain = $survey->createPlacement($intake, $user, [
    'type' => AircoPlacementType::DrainPoint,
    'label' => 'Afvoer',
]);

$okPath = "intakes/{$intake->id}/room.jpg";
Storage::disk('local')->put($okPath, 'ok-bytes');
Storage::disk('local')->put($okPath.'-a', 'ok-analysis');
$upload = IntakeUpload::query()->create([
    'intake_id' => $intake->id,
    'question_key' => 'room_photos',
    'section_instance_key' => $room->key,
    'disk' => 'local',
    'path' => $okPath,
    'analysis_path' => $okPath.'-a',
    'analysis_mime_type' => 'image/jpeg',
    'analysis_size_bytes' => 11,
    'analysis_checksum' => hash('sha256', 'ok-analysis'),
    'original_filename' => 'room.jpg',
    'mime_type' => 'image/jpeg',
    'size_bytes' => 8,
    'checksum' => hash('sha256', 'ok-bytes'),
    'sort_order' => 1,
    'assessment_status' => PhotoAssessmentStatus::Assessed,
    'content_assessment' => PhotoContentAssessment::ok(PhotoSubject::Room)->toArray(),
]);

$pass = 0;
FakeAiClient::respondUsing(function (AiCompletionRequest $request) use ($upload, $indoor, $outdoor, $power, $drain, &$pass): array {
    $pass++;
    $imageRef = 'dossier_image:'.$upload->id;
    $segment = $pass === 1
        ? 'Korte gevelroute'
        : 'Via de achtergevel naar het plat dak';

    return [
        'summary' => 'Single-split voorstel; herbouw '.$pass.'.',
        'placement_proposals' => [],
        'option_proposals' => [[
            'label' => $pass === 1 ? 'AI-voorstel eerste' : 'AI-voorstel herbouwd',
            'configuration_type' => AircoConfigurationType::SingleSplit->value,
            'summary' => 'Woonkamer single-split.',
            'cost_impact' => 'medium',
            'confidence' => 0.82,
            'placement_references' => [
                'placement:'.$indoor->id,
                'placement:'.$outdoor->id,
                'placement:'.$power->id,
                'placement:'.$drain->id,
            ],
            'connections' => [
                [
                    'type' => 'refrigerant',
                    'label' => 'Koelleiding woonkamer',
                    'from_placement_reference' => 'placement:'.$indoor->id,
                    'to_placement_reference' => 'placement:'.$outdoor->id,
                    'status' => 'proposed',
                    'length_class' => 'short',
                    'segments' => [$segment],
                    'obstacles' => [],
                    'uncertainties' => [],
                    'cost_impact' => 'low',
                    'confidence' => 0.85,
                    'evidence_references' => [$imageRef],
                ],
                [
                    'type' => 'condensate',
                    'label' => 'Condensafvoer woonkamer',
                    'from_placement_reference' => 'placement:'.$indoor->id,
                    'to_placement_reference' => 'placement:'.$drain->id,
                    'status' => 'proposed',
                    'length_class' => 'short',
                    'segments' => ['Op afschot'],
                    'obstacles' => [],
                    'uncertainties' => [],
                    'cost_impact' => 'low',
                    'confidence' => 0.8,
                    'evidence_references' => [$imageRef],
                ],
                [
                    'type' => 'power',
                    'label' => 'Stroomtoevoer buitenunit',
                    'from_placement_reference' => 'placement:'.$power->id,
                    'to_placement_reference' => 'placement:'.$outdoor->id,
                    'status' => 'needs_evidence',
                    'length_class' => 'medium',
                    'segments' => ['Nieuwe eindgroep'],
                    'obstacles' => [],
                    'uncertainties' => ['Groep nog te controleren'],
                    'cost_impact' => 'medium',
                    'confidence' => 0.55,
                    'evidence_references' => [$imageRef],
                ],
            ],
        ]],
        'exceptions' => [],
        'customer_tasks' => [],
    ];
});

app(SynthesizeSurveyDossier::class)->handle($intake->fresh() ?? $intake);

$option = AircoInstallationOption::query()
    ->where('intake_id', $intake->id)
    ->where('source_type', 'ai')
    ->where('status', AircoOptionStatus::Candidate)
    ->sole();

$survey->markInstallationOptionFeasible($intake, $user, $option);

$refrigerant = AircoConnection::query()
    ->where('airco_installation_option_id', $option->id)
    ->where('type', 'refrigerant')
    ->sole();

app(DecideAircoConnection::class)->handle($intake, $refrigerant, $user, 'approve');

$subject = DossierSubject::query()->findOrFail($refrigerant->dossier_subject_id);
$noteText = 'Testnotitie: achtergevelroute ter plaatse controleren.';
app(SaveInstallerObservation::class)->handle(
    $intake,
    $user,
    $subject,
    'installer_note.'.uniqid('', true),
    $noteText,
);

app(SynthesizeSurveyDossier::class)->handle($intake->fresh() ?? $intake);

$freshOption = AircoInstallationOption::query()
    ->where('intake_id', $intake->id)
    ->where('source_type', 'ai')
    ->where('status', AircoOptionStatus::Candidate)
    ->sole();
$freshRefrigerant = AircoConnection::query()
    ->where('airco_installation_option_id', $freshOption->id)
    ->where('type', 'refrigerant')
    ->sole();

if ($freshRefrigerant->status->value !== 'approved') {
    fwrite(STDERR, "N29 bootstrap: refrigerant status not approved after rebuild\n");
    exit(1);
}

$baseUrl = rtrim((string) (getenv('E2E_BASE_URL') ?: getenv('PLAYWRIGHT_BASE_URL') ?: config('app.url') ?: 'http://127.0.0.1:8000'), '/');

echo json_encode([
    'baseUrl' => $baseUrl,
    'email' => $user->email,
    'password' => $password,
    'intakeId' => $intake->id,
    'workspaceUrl' => $baseUrl.'/intakes/'.$intake->id.'/opname',
    'noteText' => $noteText,
    'connectionStatus' => $freshRefrigerant->status->value,
    'optionFeasibility' => $freshOption->feasibility->value,
], JSON_THROW_ON_ERROR).PHP_EOL;
