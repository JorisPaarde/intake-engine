<?php

declare(strict_types=1);

/**
 * Bootstrap for Playwright: superseded fusebox photo + readable evidence labels.
 * Prints JSON with login + show URL (installer detail page with gallery + aandachtspunten).
 */

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\IntakeAttentionPoint;
use App\Domains\Intake\Models\IntakeExternalFact;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\AttentionPointSource;
use App\Enums\AttentionPointStatus;
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
    'email' => 'playwright-evidence-'.uniqid('', false).'@example.com',
    'password' => bcrypt($password),
]);

$intake = app(CreateIntake::class)->handle($user, [
    'template_key' => 'airco',
    'workflow_mode' => ContributionMode::Installer,
    'customer_name' => 'Playwright Bewijs',
    'customer_email' => 'playwright-bewijs@example.com',
    'address_line' => 'Bewijsstraat 10',
    'address_postal_code' => '1000AA',
    'address_house_number' => 10,
    'address_city' => 'Amsterdam',
]);
$intake->update(['status' => IntakeStatus::InProgress]);

$disk = (string) config('filesystems.media', 'local');
Storage::disk($disk)->makeDirectory('private/intakes/'.$intake->id);

$old = app(StoreIntakeUpload::class)->handle(
    $intake->fresh(),
    'fusebox_photo',
    null,
    UploadedFile::fake()->image('meterkast-oud.jpg', 800, 600),
);
$old->update([
    'assessment_status' => PhotoAssessmentStatus::Assessed,
    'content_assessment' => PhotoContentAssessment::ok(PhotoSubject::Fusebox)->toArray(),
    'usability_verdict' => PhotoUsabilityVerdict::Ok,
]);

$fact = IntakeExternalFact::query()->create([
    'intake_id' => $intake->id,
    'fact_key' => 'fusebox_photo_assessment',
    'label' => 'Automatische beoordeling meterkastfoto',
    'value' => [
        'empty_module_space' => 'visible',
        'phase' => 'one_phase',
        'upload_ids' => [$old->id],
    ],
    'source' => 'ai_photo',
    'confidence' => 'medium',
    'captured_at' => now(),
]);

$opaque = 'fact_'.substr(hash_hmac('sha256', (string) $fact->id, (string) config('app.key')), 0, 16);
$rawReference = 'fusebox_photo_assessment@fact:'.$opaque;

IntakeAttentionPoint::query()->create([
    'intake_id' => $intake->id,
    'source' => AttentionPointSource::Ai,
    'code' => 'check_fusebox_evidence',
    'label' => 'Controleer of er nog vrije moduleplek is.',
    'status' => AttentionPointStatus::Proposed,
    'ai_confidence' => 'medium',
    'evidence' => [
        ['source_type' => 'external_fact', 'reference' => $rawReference],
    ],
]);

$round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [[
    'type' => FollowUpItemType::Photo,
    'prompt' => 'Maak een leesbare foto van de volledige meterkast.',
    'decision_area_key' => 'power',
    'dossier_subject_id' => null,
]]);
$item = $round->items()->firstOrFail();

$path = 'private/intakes/'.$intake->id.'/follow-up-meterkast.jpg';
Storage::disk($disk)->put($path, (string) file_get_contents(
    base_path('tests/fixtures/klanttest-20261002/meterkast-flow.jpg'),
) ?: 'fake-jpeg');

$new = IntakeUpload::query()->create([
    'intake_id' => $intake->id,
    'question_key' => 'follow_up_upload',
    'section_instance_key' => null,
    'intake_follow_up_item_id' => $item->id,
    'disk' => $disk,
    'path' => $path,
    'original_filename' => 'meterkast-nieuw.jpg',
    'mime_type' => 'image/jpeg',
    'size_bytes' => 2400,
    'sort_order' => 0,
]);
$new->update([
    'assessment_status' => PhotoAssessmentStatus::Assessed,
    'content_assessment' => PhotoContentAssessment::ok(PhotoSubject::Fusebox)->toArray(),
    'usability_verdict' => PhotoUsabilityVerdict::Ok,
]);

$item->update(['answered_at' => now()]);
$round->update([
    'status' => FollowUpRoundStatus::Completed,
    'completed_at' => now(),
]);
$intake->update([
    'status' => IntakeStatus::InProgress,
    'customer_access_enabled' => false,
]);

// Point the stored fact at the new upload so citations prefer the current photo.
$fact->update([
    'value' => array_merge($fact->value, ['upload_ids' => [$new->id, $old->id]]),
]);

$baseUrl = rtrim((string) (getenv('E2E_BASE_URL') ?: getenv('PLAYWRIGHT_BASE_URL') ?: 'http://127.0.0.1:8000'), '/');

echo json_encode([
    'baseUrl' => $baseUrl,
    'email' => $user->email,
    'password' => $password,
    'showUrl' => $baseUrl.'/intakes/'.$intake->id,
    'workspaceUrl' => $baseUrl.'/intakes/'.$intake->id.'/opname',
    'intakeId' => $intake->id,
    'oldUploadId' => $old->id,
    'newUploadId' => $new->id,
    'rawReference' => $rawReference,
], JSON_THROW_ON_ERROR).PHP_EOL;
