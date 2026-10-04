<?php

declare(strict_types=1);

/**
 * Bootstrap for Playwright fix-bundle D checks (example dossier + bulk approval blockers).
 * Latest-template-only seed — same convention as phpunit (#156).
 */

use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\LoadDemoSurveyScenario;
use App\Domains\Intake\Services\DecisionReadinessService;
use App\Enums\ContributionMode;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(['intake.seed_latest_template_only' => true]);
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
$assessment = app(DecisionReadinessService::class)->bulkApprovalAssessment($example->fresh() ?? $example);

$baseUrl = rtrim((string) (getenv('E2E_BASE_URL') ?: getenv('PLAYWRIGHT_BASE_URL') ?: config('app.url') ?: 'http://127.0.0.1:8000'), '/');

echo json_encode([
    'baseUrl' => $baseUrl,
    'email' => $user->email,
    'password' => $password,
    'sourceIntakeId' => $source->id,
    'exampleIntakeId' => $example->id,
    'sourceWorkspaceUrl' => $baseUrl.'/intakes/'.$source->id.'/opname',
    'exampleWorkspaceUrl' => $baseUrl.'/intakes/'.$example->id.'/opname',
    'approvalAllowed' => $assessment['allowed'],
    'approvalBlockers' => $assessment['blockers'],
], JSON_THROW_ON_ERROR).PHP_EOL;
