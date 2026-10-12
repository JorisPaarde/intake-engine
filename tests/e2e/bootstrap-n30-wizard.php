<?php

declare(strict_types=1);

/**
 * Bootstrap for Playwright N30 (accessibility catch-up) + N3 (drain choice + upload).
 * Latest-template-only seed — same convention as phpunit / dossier-approval bootstrap.
 */

use App\Support\E2e\E2eScenarioFactory;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config([
    'intake.seed_latest_template_only' => true,
    'ai.provider' => 'fake',
    'ai.photo_inference.enabled' => true,
    'ai.e2e_helpers_enabled' => true,
    'queue.default' => 'sync',
]);

app(IntakeTemplateSeeder::class)->run();

$factory = app(E2eScenarioFactory::class);
$n30 = $factory->create('n30-accessibility-catchup');
$n3 = $factory->create('n3-drain-choice-upload');

echo json_encode([
    'baseUrl' => rtrim((string) (getenv('E2E_BASE_URL') ?: config('app.url') ?: 'http://127.0.0.1:8000'), '/'),
    'n30' => $n30,
    'n3' => $n3,
], JSON_THROW_ON_ERROR).PHP_EOL;
