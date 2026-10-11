<?php

declare(strict_types=1);

/**
 * Bootstrap for Playwright N23: installer gallery contain + lightbox.
 * Prints JSON with login credentials and show URL (portrait meterkast photo).
 */

use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Enums\ContributionMode;
use App\Enums\IntakeStatus;
use App\Enums\PhotoAssessmentStatus;
use App\Enums\PhotoUsabilityVerdict;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(['intake.seed_latest_template_only' => true]);
$app->make(IntakeTemplateSeeder::class)->run();

$password = 'password';
$user = User::factory()->create([
    'email' => 'playwright-lightbox-'.uniqid('', false).'@example.com',
    'password' => bcrypt($password),
]);

$intake = app(CreateIntake::class)->handle($user, [
    'template_key' => 'airco',
    'workflow_mode' => ContributionMode::Installer,
    'customer_name' => 'Demo',
    'customer_email' => 'demo-lightbox@example.com',
    'address_line' => 'Adres',
    'address_postal_code' => '1234AB',
    'address_house_number' => 1,
    'address_city' => 'Plaats',
]);
$intake->update(['status' => IntakeStatus::InProgress]);

$disk = (string) config('filesystems.media', 'local');
Storage::disk($disk)->makeDirectory('private/intakes/'.$intake->id);

// Portrait meterkast (taller than wide) — same crop issue as prod.
$upload = app(StoreIntakeUpload::class)->handle(
    $intake->fresh(),
    'fusebox_photo',
    null,
    UploadedFile::fake()->image('meterkast-portrait.jpg', 1086, 1448),
);
$upload->update([
    'assessment_status' => PhotoAssessmentStatus::Assessed,
    'usability_verdict' => PhotoUsabilityVerdict::Ok,
]);

$roomUpload = app(StoreIntakeUpload::class)->handle(
    $intake->fresh(),
    'room_photos',
    'room-1',
    UploadedFile::fake()->image('ruimte-landscape.jpg', 1600, 900),
);
$roomUpload->update([
    'assessment_status' => PhotoAssessmentStatus::Assessed,
    'usability_verdict' => PhotoUsabilityVerdict::Ok,
]);

$baseUrl = rtrim((string) (getenv('E2E_BASE_URL') ?: 'http://127.0.0.1:8000'), '/');

echo json_encode([
    'baseUrl' => $baseUrl,
    'email' => $user->email,
    'password' => $password,
    'showUrl' => $baseUrl.'/intakes/'.$intake->id,
    'intakeId' => $intake->id,
    'portraitUploadId' => $upload->id,
    'landscapeUploadId' => $roomUpload->id,
], JSON_THROW_ON_ERROR).PHP_EOL;
