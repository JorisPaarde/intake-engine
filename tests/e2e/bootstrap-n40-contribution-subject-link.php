<?php

declare(strict_types=1);

/**
 * Bootstrap for Playwright N40: stroom-concept → condens-area rematch → aanvulling
 * zichtbaar onder Condensafvoer, niet onder Stroomtoevoer.
 */

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\CompleteFollowUpRound;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\DossierManager;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoConnectionType;
use App\Enums\AircoPlacementType;
use App\Enums\ContributionMode;
use App\Enums\FollowUpItemType;
use App\Enums\FollowUpRoundStatus;
use App\Enums\IntakeStatus;
use App\Enums\PhotoAssessmentStatus;
use App\Enums\PhotoUsabilityVerdict;
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
    'ai.photo_inference.enabled' => true,
    'ai.dossier.enabled' => false,
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

$disk = (string) config('filesystems.media', 'local');
Storage::disk($disk)->makeDirectory('n40-e2e');

$password = 'password';
$user = User::factory()->create([
    'email' => 'playwright-n40-'.uniqid('', false).'@example.com',
    'password' => bcrypt($password),
]);

$intake = app(CreateIntake::class)->handle($user, [
    'template_key' => 'airco',
    'workflow_mode' => ContributionMode::Installer,
    'customer_name' => 'Playwright N40',
    'customer_email' => 'playwright-n40-customer@example.com',
    'address_line' => 'N40laan 40',
    'address_postal_code' => '1000AA',
    'address_house_number' => 40,
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

// Stroom-subject + condens-onderdeel → linker moet rematchen naar condens.
$round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [[
    'type' => FollowUpItemType::Photo,
    'prompt' => 'Maak een foto van de plek waar de condensafvoer kan lozen, zoals een regenpijp, putje of goot.',
    'decision_area_key' => 'condensate',
    'dossier_subject_id' => $power->dossier_subject_id,
    'photo_task_subtype' => 'power_route',
]]);

$task = ContributionTask::query()->where('intake_id', $intake->id)->latest('id')->firstOrFail();
$item = $round->items()->firstOrFail();

$fixture = base_path('tests/fixtures/klanttest-20261002/buitenunit-flow.jpeg');
$path = 'n40-e2e/gevel-'.uniqid('', false).'.jpeg';
Storage::disk($disk)->put($path, (string) file_get_contents($fixture));

$upload = IntakeUpload::query()->create([
    'intake_id' => $intake->id,
    'intake_follow_up_item_id' => $item->id,
    'question_key' => 'follow_up_photo',
    'section_instance_key' => null,
    'disk' => $disk,
    'path' => $path,
    'original_filename' => 'gevel.jpeg',
    'mime_type' => 'image/jpeg',
    'size_bytes' => Storage::disk($disk)->size($path),
    'checksum' => hash('sha256', (string) Storage::disk($disk)->get($path)),
    'sort_order' => 1,
    'assessment_status' => PhotoAssessmentStatus::Assessed,
    'content_assessment' => PhotoContentAssessment::ok(PhotoSubject::OutdoorLocation)->toArray(),
    'usability_verdict' => PhotoUsabilityVerdict::Ok,
]);

$item->update(['answered_at' => now()]);
app(CompleteFollowUpRound::class)->handle($intake->fresh(), $round->fresh(), []);

$intake->refresh();
$task->refresh();

$baseUrl = getenv('E2E_BASE_URL') ?: getenv('PLAYWRIGHT_BASE_URL') ?: rtrim((string) config('app.url'), '/');
if ($baseUrl === '' || (! str_contains($baseUrl, '127.0.0.1') && ! str_contains($baseUrl, 'localhost'))) {
    $baseUrl = 'http://127.0.0.1:8000';
}
$baseUrl = rtrim($baseUrl, '/');

echo json_encode([
    'baseUrl' => $baseUrl,
    'email' => $user->email,
    'password' => $password,
    'intakeId' => $intake->id,
    'workspaceUrl' => $baseUrl.'/intakes/'.$intake->id.'/opname',
    'powerConnectionId' => $power->id,
    'condensateConnectionId' => $condensate->id,
    'taskSubjectId' => (int) $task->dossier_subject_id,
    'condensateSubjectId' => (int) $condensate->dossier_subject_id,
    'powerSubjectId' => (int) $power->dossier_subject_id,
    'uploadId' => $upload->id,
    'roundStatus' => $round->fresh()->status instanceof FollowUpRoundStatus
        ? $round->fresh()->status->value
        : (string) $round->fresh()->status,
    'intakeStatus' => $intake->status instanceof IntakeStatus
        ? $intake->status->value
        : (string) $intake->status,
], JSON_THROW_ON_ERROR).PHP_EOL;
