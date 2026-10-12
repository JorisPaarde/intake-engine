<?php

declare(strict_types=1);

/**
 * Bootstrap for Playwright N33 + N35 (brief D report/labels).
 *
 * - N33: AI synthesis exception with Vraag de klant → prepare draft
 * - N35: selected option with one approved + one site-visit route → report HTML
 *
 * Fake AI only — no real provider / no personal customer data.
 */

use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\DecideAircoConnection;
use App\Domains\Intake\Models\DossierRecord;
use App\Domains\Intake\Models\DossierSubject;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\GenerateIntakeReportHtml;
use App\Domains\Intake\Services\RebuildIntakeReportHtml;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoConnectionType;
use App\Enums\AircoPlacementType;
use App\Enums\ContributionMode;
use App\Enums\DossierRecordKind;
use App\Enums\DossierRecordStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config([
    'intake.seed_latest_template_only' => true,
    'ai.provider' => 'fake',
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
    'email' => 'playwright-n35-'.uniqid('', false).'@example.com',
    'password' => bcrypt($password),
]);

$intake = app(CreateIntake::class)->handle($user, [
    'template_key' => 'airco',
    'workflow_mode' => ContributionMode::Installer,
    'customer_name' => 'Playwright N35 Labels',
    'customer_email' => 'playwright-n35@example.com',
    'address_line' => 'Demostraat 1',
    'address_postal_code' => '1234AB',
    'address_house_number' => 1,
    'address_city' => 'Voorbeeldstad',
    'is_demo' => false,
]);

$survey = app(AircoSurveyService::class);
$living = $survey->createRoom($intake, $user, [
    'name' => 'Woonkamer',
    'use_type' => 'living_room',
    'length_m' => 5.0,
    'width_m' => 4.0,
    'height_m' => 2.6,
]);
$attic = $survey->createRoom($intake, $user, [
    'name' => 'Zolder',
    'use_type' => 'bedroom',
    'length_m' => 4.0,
    'width_m' => 3.0,
    'height_m' => 2.4,
]);
$indoorLiving = $survey->createPlacement($intake, $user, [
    'airco_room_id' => $living->id,
    'type' => AircoPlacementType::IndoorUnit,
    'label' => 'Binnenunit woonkamer',
]);
$indoorAttic = $survey->createPlacement($intake, $user, [
    'airco_room_id' => $attic->id,
    'type' => AircoPlacementType::IndoorUnit,
    'label' => 'Binnenunit zolder',
]);
$outdoor = $survey->createPlacement($intake, $user, [
    'type' => AircoPlacementType::OutdoorUnit,
    'label' => 'Buitenunit',
]);
$option = $survey->createInstallationOption($intake, $user, [
    'label' => 'Multi-split demo',
    'configuration_type' => AircoConfigurationType::MultiSplit,
    'placement_ids' => [$indoorLiving->id, $indoorAttic->id, $outdoor->id],
]);
$survey->markInstallationOptionFeasible($intake, $user, $option);
$survey->selectInstallationOption($intake, $user, $option);

$approved = $survey->createConnection($intake, $user, $option->fresh(), [
    'type' => AircoConnectionType::Refrigerant,
    'label' => 'Koelroute woonkamer',
    'from_placement_id' => $indoorLiving->id,
    'to_placement_id' => $outdoor->id,
    'status' => AircoConnectionStatus::Proposed,
    'segments' => ['Via gevel'],
]);
app(DecideAircoConnection::class)->handle($intake, $approved, $user, 'approve');

$siteVisit = $survey->createConnection($intake, $user, $option->fresh(), [
    'type' => AircoConnectionType::Condensate,
    'label' => 'Condensroute zolder',
    'from_placement_id' => $indoorAttic->id,
    'to_placement_id' => $outdoor->id,
    'status' => AircoConnectionStatus::Proposed,
    'segments' => ['Nog open'],
    'uncertainties' => ['Afschot onzeker'],
]);
app(DecideAircoConnection::class)->handle($intake, $siteVisit, $user, 'site_visit');

$root = DossierSubject::query()
    ->where('intake_id', $intake->id)
    ->where('key', 'survey')
    ->firstOrFail();

$exceptionLabel = 'Natuurlijk afschot voor condensafvoer op verdiepingen is onzeker.';
DossierRecord::query()->create([
    'intake_id' => $intake->id,
    'company_id' => $intake->company_id,
    'dossier_subject_id' => $root->id,
    'kind' => DossierRecordKind::Conclusion,
    'key' => 'ai_dossier_synthesis',
    'value' => [
        'summary' => 'Voorstel beschikbaar.',
        'exceptions' => [[
            'label' => $exceptionLabel,
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

app(RebuildIntakeReportHtml::class)->handle($intake->fresh());
$version = $intake->fresh()->templateVersion()->firstOrFail();
$reportHtml = app(GenerateIntakeReportHtml::class)->handle($intake->fresh(), $version);

$baseUrl = rtrim((string) (getenv('E2E_BASE_URL') ?: getenv('PLAYWRIGHT_BASE_URL') ?: config('app.url') ?: 'http://127.0.0.1:8000'), '/');

echo json_encode([
    'baseUrl' => $baseUrl,
    'email' => $user->email,
    'password' => $password,
    'intakeId' => $intake->id,
    'workspaceUrl' => $baseUrl.'/intakes/'.$intake->id.'/opname',
    'reportUrl' => $baseUrl.'/intakes/'.$intake->id.'/rapport',
    'exceptionLabel' => $exceptionLabel,
    'reportContainsSiteVisit' => str_contains($reportHtml, 'Alleen te zien op locatie'),
    'reportContainsApproved' => str_contains($reportHtml, 'Goedgekeurd'),
], JSON_THROW_ON_ERROR).PHP_EOL;
