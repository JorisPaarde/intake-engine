<?php

declare(strict_types=1);

/**
 * One-shot bootstrap for Playwright BL-146 UI checks (follow-up evidence linking).
 * Uses the app sqlite/mysql from .env and prints JSON credentials + workspace URL.
 */

use App\Domains\Intake\Actions\ApplyFollowUpTextContribution;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Services\DossierManager;
use App\Enums\ContributionMode;
use App\Enums\FollowUpItemType;
use App\Enums\FollowUpRoundStatus;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Latest published airco template only — same convention as phpunit (#156).
config(['intake.seed_latest_template_only' => true]);
$app->make(IntakeTemplateSeeder::class)->run();

$password = 'password';
$user = User::factory()->create([
    'email' => 'playwright-b1-'.uniqid('', false).'@example.com',
    'password' => bcrypt($password),
]);

$intake = app(CreateIntake::class)->handle($user, [
    'template_key' => 'airco',
    'workflow_mode' => ContributionMode::Installer,
    'customer_name' => 'Playwright B1',
    'customer_email' => 'playwright-b1-customer@example.com',
    'address_line' => 'Playwrightlaan 100',
    'address_postal_code' => '1000AA',
    'address_house_number' => 100,
    'address_city' => 'Amsterdam',
]);

app(DossierManager::class)->initialize($intake);
$root = app(DossierManager::class)->root($intake);
$subject = app(DossierManager::class)->subject(
    $intake,
    'airco.room.playwright-attic',
    'airco_room',
    'Zolder 1',
    $root,
    ['use_type' => 'attic'],
);

$room = AircoRoom::query()->create([
    'intake_id' => $intake->id,
    'company_id' => $intake->company_id,
    'dossier_subject_id' => $subject->id,
    'key' => 'manual-playwright-attic',
    'name' => 'Zolder 1',
    'use_type' => 'attic',
    'use_type_source' => 'installer',
    'sort_order' => 1,
    'status' => 'candidate',
    'source_type' => 'installer',
    'dimensions' => [
        'area_m2' => 15.0,
        'area_source' => 'request',
        'area_confidence' => 'high',
    ],
]);

$round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [[
    'type' => FollowUpItemType::Text,
    'prompt' => 'Meet of noteer de hoogte van Zolder 1: hoogste punt onder de nok én de hoogte bij de knieschotten (schuin dak). Schrijf beide maten op.',
    'decision_area_key' => 'capacity',
    'dossier_subject_id' => $room->dossier_subject_id,
    'meta' => [
        ApplyFollowUpTextContribution::META_REQUESTED_FIELD => ApplyFollowUpTextContribution::FIELD_HEIGHT,
    ],
]]);

$item = $round->items()->firstOrFail();
$item->update([
    'response_text' => 'hoogste punt 2,6m, knieschotten 1,2m, schuin dak',
    'answered_at' => now(),
]);
$round->update([
    'status' => FollowUpRoundStatus::Completed,
    'completed_at' => now(),
]);
$intake->update([
    'status' => IntakeStatus::InProgress,
    'customer_access_enabled' => false,
]);

app(DossierManager::class)->initialize($intake->fresh());
$task = $intake->fresh()->contributionTasks()->where('intake_follow_up_item_id', $item->id)->firstOrFail();
app(ApplyFollowUpTextContribution::class)->handle($intake->fresh(), $item->fresh(), $task);

$baseUrl = getenv('E2E_BASE_URL') ?: getenv('PLAYWRIGHT_BASE_URL') ?: rtrim((string) config('app.url'), '/');
if ($baseUrl === '' || (! str_contains($baseUrl, '127.0.0.1') && ! str_contains($baseUrl, 'localhost'))) {
    $baseUrl = 'http://127.0.0.1:8000';
}
$baseUrl = rtrim($baseUrl, '/');

echo json_encode([
    'baseUrl' => $baseUrl,
    'email' => $user->email,
    'password' => $password,
    'workspaceUrl' => $baseUrl.'/intakes/'.$intake->id.'/opname',
    'intakeId' => $intake->id,
], JSON_THROW_ON_ERROR).PHP_EOL;
