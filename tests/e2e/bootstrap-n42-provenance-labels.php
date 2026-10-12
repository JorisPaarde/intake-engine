<?php

declare(strict_types=1);

/**
 * Bootstrap for Playwright N42 (rapport-herkomstlabels) + N43 (aanvullingsfoto-captions).
 * Fake data only — no real customer PII.
 */

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\GeneratedReport;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\GenerateIntakeReportHtml;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\ContributionMode;
use App\Enums\FollowUpItemType;
use App\Enums\FollowUpRoundStatus;
use App\Enums\PhotoAssessmentStatus;
use App\Enums\PhotoUsabilityVerdict;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
    'email' => 'playwright-n42-'.uniqid('', false).'@example.com',
    'password' => bcrypt($password),
]);

$intake = app(CreateIntake::class)->handle($user, [
    'template_key' => 'airco',
    'workflow_mode' => ContributionMode::Hybrid,
    'customer_name' => 'Playwright N42 Labels',
    'customer_email' => 'playwright-n42@example.com',
    'address_line' => 'Demostraat 1',
    'address_postal_code' => '1234AB',
    'address_house_number' => 1,
    'address_city' => 'Voorbeeldstad',
    'is_demo' => false,
]);

app(DossierManager::class)->initialize($intake);

IntakeAnswer::query()->create([
    'intake_id' => $intake->id,
    'question_key' => 'building_type',
    'section_instance_key' => null,
    'value' => ['value' => 'terraced'],
    'prefill_source' => 'pdok',
    'fact_source' => 'klantantwoord',
    'answered_at' => now(),
]);
IntakeAnswer::query()->create([
    'intake_id' => $intake->id,
    'question_key' => 'build_year',
    'section_instance_key' => null,
    'value' => ['number' => 1971],
    'prefill_source' => 'pdok',
    'fact_source' => 'klantantwoord',
    'answered_at' => now(),
]);
IntakeAnswer::query()->create([
    'intake_id' => $intake->id,
    'question_key' => 'cooling_heating',
    'section_instance_key' => null,
    'value' => ['value' => 'cooling'],
    'prefill_source' => PrefillSources::AI_TEXT,
    'fact_source' => 'aanvraag (installateur)',
    'answered_at' => now(),
]);

app(SaveIntakeAnswer::class)->handle($intake, 'drain_location', null, ['value' => 'outside_nearby']);

$outdoor = app(StoreIntakeUpload::class)->handle(
    $intake->fresh(),
    'outdoor_location_photos',
    null,
    UploadedFile::fake()->image('gevel.jpg', 1200, 900),
);
$outdoor->forceFill([
    'assessment_status' => PhotoAssessmentStatus::Assessed,
    'content_assessment' => PhotoContentAssessment::ok(PhotoSubject::OutdoorLocation)->toArray(),
    'usability_verdict' => PhotoUsabilityVerdict::Ok,
])->save();

$round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [[
    'type' => FollowUpItemType::Photo,
    'prompt' => 'Plek stroomkabel',
    'decision_area_key' => 'power',
    'dossier_subject_id' => app(DossierManager::class)->root($intake)->id,
]]);
$item = $round->items()->firstOrFail();
$disk = (string) config('filesystems.media', 'local');
$path = 'intakes/'.$intake->uuid.'/follow-up/stroom.jpg';
Storage::disk($disk)->put($path, 'fake-stroom-bytes');
$followUpUpload = IntakeUpload::query()->create([
    'intake_id' => $intake->id,
    'question_key' => 'follow_up_'.$item->id,
    'intake_follow_up_item_id' => $item->id,
    'disk' => $disk,
    'path' => $path,
    'original_filename' => 'stroom.jpg',
    'mime_type' => 'image/jpeg',
    'size_bytes' => 16,
    'checksum' => hash('sha256', 'fake-stroom-bytes'),
    'sort_order' => 0,
]);
$item->update(['answered_at' => now()]);
$round->update(['completed_at' => now(), 'status' => FollowUpRoundStatus::Completed]);

$version = $intake->fresh()->templateVersion()->with([
    'sections.questions.options',
    'sections.questions.rules',
])->firstOrFail();
$html = app(GenerateIntakeReportHtml::class)->handle($intake->fresh(), $version);

GeneratedReport::query()->updateOrCreate(
    ['intake_id' => $intake->id],
    [
        'html' => $html,
        'meta' => [
            'template_version' => $version->version,
            'e2e_bootstrap' => 'n42-provenance-labels',
        ],
        'generated_at' => now(),
    ],
);

$baseUrl = rtrim((string) (getenv('E2E_BASE_URL') ?: getenv('PLAYWRIGHT_BASE_URL') ?: config('app.url') ?: 'http://127.0.0.1:8000'), '/');

echo json_encode([
    'baseUrl' => $baseUrl,
    'email' => $user->email,
    'password' => $password,
    'intakeId' => $intake->id,
    'showUrl' => $baseUrl.'/intakes/'.$intake->id,
    'reportUrl' => $baseUrl.'/intakes/'.$intake->id.'/rapport',
    'followUpUploadId' => $followUpUpload->id,
    'expectedBagLabel' => 'Tussenwoning (Voorzet uit BAG)',
    'expectedYearLabel' => '1971 (Voorzet uit BAG)',
    'expectedRequestLabel' => 'Voorzet uit aanvraag, bevestigd door klant',
    'expectedCustomerDrain' => 'Regenpijp of putje buiten in de buurt (Klantantwoord)',
    'expectedGalleryCaption' => 'Ronde 1 · Stroomtoevoer · Plek stroomkabel',
    'reportOmitsAroundHouse' => ! str_contains($html, 'We zien de buitenkant nog niet goed'),
    'reportHasBag' => str_contains($html, 'Voorzet uit BAG'),
], JSON_THROW_ON_ERROR).PHP_EOL;
