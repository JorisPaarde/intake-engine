<?php

declare(strict_types=1);

/**
 * Bootstrap for Playwright N28: power_route follow-up must NOT mark the wizard
 * meterkastfoto as "Vervangen door ronde 1".
 */

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\IntakeExternalFact;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\ContributionMode;
use App\Enums\FollowUpItemType;
use App\Enums\FollowUpRoundStatus;
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
    'email' => 'playwright-n28-'.uniqid('', false).'@example.com',
    'password' => bcrypt($password),
]);

$intake = app(CreateIntake::class)->handle($user, [
    'template_key' => 'airco',
    'workflow_mode' => ContributionMode::Installer,
    'customer_name' => 'Playwright N28',
    'customer_email' => 'playwright-n28@example.com',
    'address_line' => 'Scopestraat 28',
    'address_postal_code' => '1000AA',
    'address_house_number' => 28,
    'address_city' => 'Amsterdam',
]);
$intake->update(['status' => IntakeStatus::InProgress]);

$disk = (string) config('filesystems.media', 'local');
Storage::disk($disk)->makeDirectory('private/intakes/'.$intake->id);

$fusebox = app(StoreIntakeUpload::class)->handle(
    $intake->fresh(),
    'fusebox_photo',
    null,
    UploadedFile::fake()->image('meterkast.jpg', 800, 600),
);
$fusebox->update([
    'assessment_status' => PhotoAssessmentStatus::Assessed,
    'content_assessment' => PhotoContentAssessment::ok(PhotoSubject::Fusebox)->toArray(),
    'usability_verdict' => PhotoUsabilityVerdict::Ok,
]);

IntakeExternalFact::query()->create([
    'intake_id' => $intake->id,
    'fact_key' => 'fusebox_photo_assessment',
    'label' => 'Automatische beoordeling meterkastfoto',
    'value' => [
        'empty_module_space' => 'visible',
        'phase' => 'one_phase',
        'upload_ids' => [$fusebox->id],
    ],
    'source' => 'ai_photo',
    'confidence' => 'medium',
    'captured_at' => now(),
]);

$meta = PhotoSubject::photoTaskMetaForSubtype('power_route');
$round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [[
    'type' => FollowUpItemType::Photo,
    'prompt' => 'Maak een foto van de plek waar de stroomkabel naar de buitenunit kan lopen.',
    'decision_area_key' => 'power',
    'dossier_subject_id' => null,
    'photo_task_subtype' => 'power_route',
    'meta' => $meta,
]]);

$item = $round->items()->firstOrFail();
$path = 'private/intakes/'.$intake->id.'/stroomroute.jpg';
Storage::disk($disk)->put($path, 'fake-image');
$route = IntakeUpload::query()->create([
    'intake_id' => $intake->id,
    'question_key' => 'follow_up_upload',
    'section_instance_key' => null,
    'intake_follow_up_item_id' => $item->id,
    'disk' => $disk,
    'path' => $path,
    'original_filename' => 'stroomroute.jpg',
    'mime_type' => 'image/jpeg',
    'size_bytes' => 1200,
    'sort_order' => 0,
    'assessment_status' => PhotoAssessmentStatus::Assessed,
    'content_assessment' => PhotoContentAssessment::ok(PhotoSubject::PipeRoute, PhotoSubject::PipeRoute)->toArray(),
    'usability_verdict' => PhotoUsabilityVerdict::Ok,
]);

$item->update(['answered_at' => now()]);
$round->update([
    'status' => FollowUpRoundStatus::Completed,
    'completed_at' => now(),
]);
$intake->update(['status' => IntakeStatus::InProgress]);

$baseUrl = rtrim((string) (getenv('E2E_BASE_URL') ?: getenv('PLAYWRIGHT_BASE_URL') ?: 'http://127.0.0.1:8000'), '/');

echo json_encode([
    'baseUrl' => $baseUrl,
    'email' => $user->email,
    'password' => $password,
    'showUrl' => $baseUrl.'/intakes/'.$intake->id,
    'workspaceUrl' => $baseUrl.'/intakes/'.$intake->id.'/opname',
    'intakeId' => (int) $intake->id,
    'fuseboxUploadId' => (int) $fusebox->id,
    'routeUploadId' => (int) $route->id,
], JSON_THROW_ON_ERROR).PHP_EOL;
