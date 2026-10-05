<?php

declare(strict_types=1);

/**
 * Bootstrap for Playwright fix-bundle D checks (example dossier + bulk approval blockers).
 *
 * - Example dossier: LoadDemoSurveyScenario (labelled separate intake).
 * - Bulk approval: FakeAi synthesis path that leaves options as Candidate
 *   (mirrors a real dossier — no auto-select like the demo scenario).
 *
 * Latest-template-only seed — same convention as phpunit (#156).
 */

use App\Domains\AI\Actions\SynthesizeSurveyDossier;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\DTOs\AiCompletionRequest;
use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\LoadDemoSurveyScenario;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\DecisionReadinessService;
use App\Domains\Intake\Services\DossierManager;
use App\Enums\AircoConfigurationType;
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
    'email' => 'playwright-bundle-d-'.uniqid('', false).'@example.com',
    'password' => bcrypt($password),
]);

$source = app(CreateIntake::class)->handle($user, [
    'template_key' => 'airco',
    'workflow_mode' => ContributionMode::Installer,
    'customer_name' => 'Playwright Bundle D Source',
    'customer_email' => 'playwright-bundle-d-source@example.com',
    'address_line' => 'Demostraat 12',
    'address_postal_code' => '2011AA',
    'address_house_number' => 12,
    'address_city' => 'Haarlem',
    'is_demo' => true,
]);

$example = app(LoadDemoSurveyScenario::class)->handle($source, $user);
$exampleAssessment = app(DecisionReadinessService::class)->bulkApprovalAssessment($example->fresh() ?? $example);

// --- Real synthesis path (Candidate options, uncertainty blockers, no auto-select) ---
Storage::fake('local');
FakeAiClient::reset();

$synthesis = app(CreateIntake::class)->handle($user, [
    'template_key' => 'airco',
    'workflow_mode' => ContributionMode::Installer,
    'customer_name' => 'Playwright Synthesis Approval',
    'customer_email' => 'playwright-synthesis-approval@example.com',
    'address_line' => 'Synthesestraat 5',
    'address_postal_code' => '2011BB',
    'address_house_number' => 5,
    'address_city' => 'Haarlem',
    'is_demo' => false,
]);
$synthesis->update(['status' => IntakeStatus::InProgress]);

$save = app(SaveIntakeAnswer::class);
$save->handle($synthesis, 'room_type', 'room-1', ['value' => 'living_room'], null);
$save->handle($synthesis, 'room_name', 'room-1', ['text' => 'woonkamer'], null);
$save->handle($synthesis, 'floor_level', 'room-1', ['value' => 'ground'], null);
$save->handle($synthesis, 'room_length_m', 'room-1', ['number' => 5], null);
$save->handle($synthesis, 'room_width_m', 'room-1', ['number' => 4], null);
$save->handle($synthesis, 'ceiling_height_m', 'room-1', ['number' => 2.5], null);
$save->handle($synthesis, 'room_type', 'room-2', ['value' => 'bedroom'], null);
$save->handle($synthesis, 'room_name', 'room-2', ['text' => 'slaapkamer'], null);
$save->handle($synthesis, 'floor_level', 'room-2', ['value' => '1'], null);
$save->handle($synthesis, 'room_length_m', 'room-2', ['number' => 4], null);
$save->handle($synthesis, 'room_width_m', 'room-2', ['number' => 3], null);
$save->handle($synthesis, 'ceiling_height_m', 'room-2', ['number' => 2.5], null);

app(DossierManager::class)->initialize($synthesis->fresh());
$synthesis = $synthesis->fresh(['aircoRooms']) ?? $synthesis;

$survey = app(AircoSurveyService::class);
$roomA = $synthesis->aircoRooms->first();
$indoor = $survey->createPlacement($synthesis, $user, [
    'airco_room_id' => $roomA->id,
    'type' => AircoPlacementType::IndoorUnit,
    'label' => 'Binnenunit woonkamer',
]);
$outdoor = $survey->createPlacement($synthesis, $user, [
    'type' => AircoPlacementType::OutdoorUnit,
    'label' => 'Buitenunit achtergevel',
]);
$power = $survey->createPlacement($synthesis, $user, [
    'type' => AircoPlacementType::PowerSource,
    'label' => 'Meterkast',
]);
$drain = $survey->createPlacement($synthesis, $user, [
    'type' => AircoPlacementType::DrainPoint,
    'label' => 'Afvoer',
]);

$okPath = "intakes/{$synthesis->id}/room.jpg";
Storage::disk('local')->put($okPath, 'ok-bytes');
Storage::disk('local')->put($okPath.'-a', 'ok-analysis');
$upload = IntakeUpload::query()->create([
    'intake_id' => $synthesis->id,
    'question_key' => 'room_photos',
    'section_instance_key' => $roomA->key,
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

FakeAiClient::respondUsing(function (AiCompletionRequest $request) use ($upload, $indoor, $outdoor, $power, $drain): array {
    $imageRef = 'dossier_image:'.$upload->id;

    return [
        'summary' => 'Eén single-splitvoorstel; stroomcapaciteit blijft onzeker.',
        'placement_proposals' => [],
        'option_proposals' => [[
            'label' => 'AI-voorstel single-split woonkamer',
            'configuration_type' => AircoConfigurationType::SingleSplit->value,
            'summary' => 'Alleen de woonkamer; slaapkamer nog niet bedekt.',
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
                    'segments' => ['Korte gevelroute'],
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
                    'uncertainties' => ['Controleer de deels onleesbare groepsaanduiding vóór de definitieve offerte.'],
                    'cost_impact' => 'medium',
                    'confidence' => 0.55,
                    'evidence_references' => [$imageRef],
                ],
            ],
        ]],
        'exceptions' => [[
            'code' => 'verify_power_capacity',
            'label' => 'Groepscapaciteit is nog niet leesbaar.',
            'decision_area_key' => 'power',
            'confidence' => 'medium',
            'evidence_references' => [$imageRef],
        ]],
        'customer_tasks' => [],
    ];
});

app(SynthesizeSurveyDossier::class)->handle($synthesis->fresh() ?? $synthesis);
$synthesis = $synthesis->fresh(['aircoInstallationOptions.connections']) ?? $synthesis;

$synthesisOption = $synthesis->aircoInstallationOptions->first();
if ($synthesisOption === null) {
    fwrite(STDERR, "Synthesis produced no installation options\n");
    exit(1);
}
if ($synthesisOption->status->value === 'selected') {
    fwrite(STDERR, "Unexpected: synthesis auto-selected an option\n");
    exit(1);
}

$synthesisAssessment = app(DecisionReadinessService::class)->bulkApprovalAssessment($synthesis);

$baseUrl = rtrim((string) (getenv('E2E_BASE_URL') ?: getenv('PLAYWRIGHT_BASE_URL') ?: config('app.url') ?: 'http://127.0.0.1:8000'), '/');

echo json_encode([
    'baseUrl' => $baseUrl,
    'email' => $user->email,
    'password' => $password,
    'sourceIntakeId' => $source->id,
    'exampleIntakeId' => $example->id,
    'synthesisIntakeId' => $synthesis->id,
    'sourceWorkspaceUrl' => $baseUrl.'/intakes/'.$source->id.'/opname',
    'exampleWorkspaceUrl' => $baseUrl.'/intakes/'.$example->id.'/opname',
    'synthesisWorkspaceUrl' => $baseUrl.'/intakes/'.$synthesis->id.'/opname',
    'synthesisShowUrl' => $baseUrl.'/intakes/'.$synthesis->id,
    'exampleApprovalAllowed' => $exampleAssessment['allowed'],
    'exampleApprovalBlockers' => $exampleAssessment['blockers'],
    'approvalAllowed' => $synthesisAssessment['allowed'],
    'approvalBlockers' => $synthesisAssessment['blockers'],
    'optionStatus' => $synthesisOption->status->value,
    'optionSource' => $synthesisOption->source_type,
], JSON_THROW_ON_ERROR).PHP_EOL;
