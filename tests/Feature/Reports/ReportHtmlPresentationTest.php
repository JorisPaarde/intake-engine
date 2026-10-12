<?php

declare(strict_types=1);

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\GenerateIntakeReportHtml;
use App\Domains\Intake\Support\PhotoContinueAnywayAttention;
use App\Enums\ContributionMode;
use App\Enums\FollowUpItemType;
use App\Enums\FollowUpRoundStatus;
use App\Enums\IntakeStatus;
use App\Enums\PhotoAssessmentStatus;
use App\Enums\PhotoUsabilityVerdict;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
});

function reportPresentationIntake(User $user): Intake
{
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Rapport Presentatie',
        'customer_email' => 'rapport-presentatie-'.uniqid('', true).'@example.com',
        'address_line' => 'Rapportstraat 1',
        'address_postal_code' => '1000AA',
        'address_house_number' => 1,
        'address_city' => 'Amsterdam',
    ]);

    $intake->update(['status' => IntakeStatus::InProgress]);

    return $intake->fresh() ?? $intake;
}

function markReportUploadOk(IntakeUpload $upload, PhotoSubject $subject = PhotoSubject::Fusebox): void
{
    $upload->update([
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'content_assessment' => PhotoContentAssessment::ok($subject)->toArray(),
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
    ]);
}

test('P2: rapport-HTML bevat viewport meta', function () {
    $user = User::factory()->create();
    $intake = reportPresentationIntake($user);
    $version = $intake->templateVersion()->with([
        'sections.questions.options',
        'sections.questions.rules',
        'template',
    ])->firstOrFail();

    $html = app(GenerateIntakeReportHtml::class)->handle($intake->fresh(), $version);

    expect($html)->toContain('<meta name="viewport" content="width=device-width, initial-scale=1">');
});

test('vervangen of vervallen foto’s tonen supersession-label in het rapport', function () {
    $user = User::factory()->create();
    $intake = reportPresentationIntake($user);

    $old = app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('meterkast-oud.jpg', 640, 480),
    );
    markReportUploadOk($old);

    $round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [[
        'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een leesbare foto van de volledige meterkast.',
        'decision_area_key' => 'power',
        'dossier_subject_id' => null,
    ]]);

    $item = $round->items()->firstOrFail();
    $path = 'private/intakes/'.$intake->id.'/follow-up-meterkast-rapport.jpg';
    Storage::disk((string) config('filesystems.media', 'local'))->put($path, 'fake-image');
    $new = IntakeUpload::query()->create([
        'intake_id' => $intake->id,
        'question_key' => 'follow_up_upload',
        'section_instance_key' => null,
        'intake_follow_up_item_id' => $item->id,
        'disk' => (string) config('filesystems.media', 'local'),
        'path' => $path,
        'original_filename' => 'meterkast-nieuw.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 1200,
        'sort_order' => 0,
    ]);
    markReportUploadOk($new);

    $item->update(['answered_at' => now()]);
    $round->update([
        'status' => FollowUpRoundStatus::Completed,
        'completed_at' => now(),
    ]);

    $version = $intake->fresh()->templateVersion()->with([
        'sections.questions.options',
        'sections.questions.rules',
        'template',
    ])->firstOrFail();
    $html = app(GenerateIntakeReportHtml::class)->handle($intake->fresh(), $version);

    expect($html)->toContain('Vervangen door ronde 1')
        ->and($html)->toContain('data-testid="report-photo-superseded"');
});

test('rapport toont meterkast-aandachtspunt één keer ondanks vijf duplicate codes', function () {
    $user = User::factory()->create();
    $intake = reportPresentationIntake($user);
    $version = $intake->templateVersion()->with([
        'sections.questions.options',
        'sections.questions.rules',
        'template',
    ])->firstOrFail();

    $label = 'Meterkastfoto: niet duidelijk genoeg — klant ging toch door.';
    $duplicates = [];
    for ($i = 0; $i < 5; $i++) {
        $duplicates[] = [
            'code' => PhotoContinueAnywayAttention::buildCode(
                $i % 2 === 0 ? 'fusebox_photo' : 'fusebox_photo_extra',
                null,
            ),
            'label' => $label,
        ];
    }

    $html = app(GenerateIntakeReportHtml::class)->handle(
        $intake->fresh(),
        $version,
        $duplicates,
    );

    expect(substr_count($html, $label))->toBe(1)
        ->and($html)->toContain('Aandachtspunten');
});

test('rapport collapse fusebox_photo en fusebox_photo_extra tot één groep', function () {
    $user = User::factory()->create();
    $intake = reportPresentationIntake($user);
    $version = $intake->templateVersion()->with([
        'sections.questions.options',
        'sections.questions.rules',
        'template',
    ])->firstOrFail();

    $html = app(GenerateIntakeReportHtml::class)->handle(
        $intake->fresh(),
        $version,
        [
            [
                'code' => PhotoContinueAnywayAttention::buildCode('fusebox_photo', null),
                'label' => 'Meterkast: eerste formulering.',
            ],
            [
                'code' => PhotoContinueAnywayAttention::buildCode('fusebox_photo_extra', null),
                'label' => 'Meterkast: tweede formulering.',
            ],
        ],
    );

    // Zelfde display-groep → alleen de eerste blijft, ook bij verschillende labels.
    expect(substr_count($html, 'Meterkast:'))->toBe(1)
        ->and($html)->toContain('Meterkast: eerste formulering.')
        ->and($html)->not->toContain('Meterkast: tweede formulering.');
});
