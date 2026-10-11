<?php

declare(strict_types=1);

/**
 * Bootstrap for Playwright N27: detail-page status signals after PrepareQuote review.
 *
 * Creates a hybrid-mode completed intake with open dossier points, then stores a
 * review decision "Offerte voorbereiden". Fake AI only — no real provider.
 */

use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\SubmitIntakeReview;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Enums\ContributionMode;
use App\Enums\IntakeStatus;
use App\Enums\ReviewDecision;
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
    'email' => 'playwright-n27-'.uniqid('', false).'@example.com',
    'password' => bcrypt($password),
]);

$intake = app(CreateIntake::class)->handle($user, [
    'template_key' => 'airco',
    'workflow_mode' => ContributionMode::Hybrid,
    'customer_name' => 'Playwright N27 Review',
    'customer_email' => 'playwright-n27@example.com',
    'address_line' => 'Demostraat 1',
    'address_postal_code' => '1234AB',
    'address_house_number' => 1,
    'address_city' => 'Voorbeeldstad',
    'is_demo' => false,
]);

app(AircoSurveyService::class)->createRoom($intake, $user, [
    'name' => 'Woonkamer',
    'use_type' => 'living_room',
    'length_m' => 5.0,
    'width_m' => 4.0,
    'height_m' => 2.6,
]);

$intake->update([
    'status' => IntakeStatus::Completed,
    'completed_at' => now(),
]);

app(SubmitIntakeReview::class)->handle($intake->fresh(), $user, [
    'decision' => ReviewDecision::PrepareQuote,
    'summary' => 'Eerste offerte kan, open punten blijven zichtbaar.',
    'enough_information' => true,
]);

$intake = $intake->fresh(['review']) ?? $intake;
$baseUrl = rtrim((string) (getenv('E2E_BASE_URL') ?: getenv('PLAYWRIGHT_BASE_URL') ?: config('app.url') ?: 'http://127.0.0.1:8000'), '/');

echo json_encode([
    'baseUrl' => $baseUrl,
    'email' => $user->email,
    'password' => $password,
    'intakeId' => $intake->id,
    'showUrl' => $baseUrl.'/intakes/'.$intake->id,
    'workspaceUrl' => $baseUrl.'/intakes/'.$intake->id.'/opname',
    'decision' => $intake->review?->decision?->value,
    'workflowMode' => $intake->workflow_mode->value,
    'status' => $intake->status->value,
], JSON_THROW_ON_ERROR).PHP_EOL;
