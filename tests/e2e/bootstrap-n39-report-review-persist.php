<?php

declare(strict_types=1);

/**
 * Bootstrap for Playwright N39: report keeps last review after follow-up round.
 *
 * Flow: completed intake → NeedMoreInfo review → follow-up photo →
 * CompleteFollowUpRound (clears intake.reviewed_at) → stored report HTML.
 * Fake AI only — no real provider / no personal customer data.
 */

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\CompleteFollowUpRound;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\SubmitIntakeReview;
use App\Domains\Intake\Models\GeneratedReport;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\GenerateIntakeReportHtml;
use App\Enums\ContributionMode;
use App\Enums\FollowUpItemType;
use App\Enums\IntakeStatus;
use App\Enums\PhotoAssessmentStatus;
use App\Enums\PhotoUsabilityVerdict;
use App\Enums\ReviewDecision;
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

$password = 'password';
$user = User::factory()->create([
    'email' => 'playwright-n39-'.uniqid('', false).'@example.com',
    'password' => bcrypt($password),
    'name' => 'Playwright N39 Reviewer',
]);

$intake = app(CreateIntake::class)->handle($user, [
    'template_key' => 'airco',
    'workflow_mode' => ContributionMode::Hybrid,
    'customer_name' => 'Playwright N39 Review',
    'customer_email' => 'playwright-n39@example.com',
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

$version = $intake->fresh()->templateVersion()->firstOrFail();
$initialHtml = app(GenerateIntakeReportHtml::class)->handle($intake->fresh(), $version);
GeneratedReport::query()->updateOrCreate(
    ['intake_id' => $intake->id],
    [
        'html' => $initialHtml,
        'meta' => [
            'template_version' => $version->version,
            'e2e_bootstrap' => 'n39-report-review-persist',
        ],
        'generated_at' => now(),
    ],
);

$summary = 'Locatiebezoek nodig tot de geveldoorvoer duidelijk is.';
app(SubmitIntakeReview::class)->handle($intake->fresh(), $user, [
    'decision' => ReviewDecision::NeedMoreInfo,
    'site_visit_needed' => true,
    'enough_information' => false,
    'summary' => $summary,
    'follow_up_items' => [
        ['type' => FollowUpItemType::Photo, 'prompt' => 'Maak een foto van de geveldoorvoer.'],
    ],
]);

$round = $intake->fresh()->followUpRounds()->with('items')->firstOrFail();
$item = $round->items->firstOrFail();

$disk = (string) config('filesystems.media', 'local');
$fixture = base_path('tests/fixtures/klanttest-20261002/meterkast-flow.jpg');
$bytes = (string) file_get_contents($fixture);
$path = 'intakes/'.$intake->uuid.'/follow-up/n39-e2e-gevel.jpg';
Storage::disk($disk)->put($path, $bytes);

$upload = IntakeUpload::query()->create([
    'intake_id' => $intake->id,
    'question_key' => 'follow_up_'.$item->id,
    'intake_follow_up_item_id' => $item->id,
    'disk' => $disk,
    'path' => $path,
    'original_filename' => 'n39-e2e-gevel.jpg',
    'mime_type' => 'image/jpeg',
    'size_bytes' => strlen($bytes),
    'checksum' => hash('sha256', $bytes),
    'sort_order' => 0,
]);
$upload->forceFill([
    'usability_verdict' => PhotoUsabilityVerdict::Ok,
    'assessment_status' => PhotoAssessmentStatus::Assessed,
    'content_assessment' => PhotoContentAssessment::ok(PhotoSubject::Other)->toArray(),
])->save();

sleep(1);
app(CompleteFollowUpRound::class)->handle($intake->fresh(), $round->fresh(), []);

$fresh = $intake->fresh(['review.reviewer', 'report']);
$storedHtml = (string) ($fresh->report?->html ?? '');
$roundNumber = (int) $round->round_number;
$staleNotice = 'Beoordeeld vóór ronde '.$roundNumber.'. Bekijk de nieuwe aanvulling.';

$baseUrl = rtrim((string) (getenv('E2E_BASE_URL') ?: getenv('PLAYWRIGHT_BASE_URL') ?: config('app.url') ?: 'http://127.0.0.1:8000'), '/');

echo json_encode([
    'baseUrl' => $baseUrl,
    'email' => $user->email,
    'password' => $password,
    'intakeId' => $fresh->id,
    'reportUrl' => $baseUrl.'/intakes/'.$fresh->id.'/rapport',
    'showUrl' => $baseUrl.'/intakes/'.$fresh->id,
    'reviewedAtNull' => $fresh->reviewed_at === null,
    'decisionLabel' => 'Nog meer info nodig',
    'summary' => $summary,
    'staleNotice' => $staleNotice,
    'reportContainsBeoordeling' => str_contains($storedHtml, 'Beoordeling'),
    'reportContainsDecision' => str_contains($storedHtml, 'Nog meer info nodig'),
    'reportContainsSummary' => str_contains($storedHtml, $summary),
    'reportContainsStaleNotice' => str_contains($storedHtml, $staleNotice),
    'reportContainsNextStep' => str_contains($storedHtml, 'Beoordeel de aangeleverde aanvulling'),
], JSON_THROW_ON_ERROR).PHP_EOL;
