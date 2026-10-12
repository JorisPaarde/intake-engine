<?php

declare(strict_types=1);

use App\Domains\AI\Actions\AssessFuseboxPhotos;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\CompletenessChecker;
use App\Domains\Intake\Services\GenerateIntakeReportHtml;
use App\Domains\Intake\Support\PhotoContinueAnywayAttention;
use App\Domains\Intake\Support\PrefillSources;
use App\Domains\Intake\Support\UploadSupersessionResolver;
use App\Enums\IntakeStatus;
use App\Enums\PhotoAssessmentStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
    FakeAiClient::reset();
    config([
        'ai.provider' => 'fake',
        'ai.photo_inference.enabled' => true,
    ]);
});

afterEach(function () {
    FakeAiClient::reset();
});

function n41FuseboxIntake(): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_name' => 'Testklant N41',
        'address_line' => 'Teststraat 1',
        'address_postal_code' => '1234AB',
        'address_city' => 'Testdorp',
        'is_demo' => false,
    ]);
}

function n41MarkNeedsClearer(IntakeUpload $upload, bool $withOverride = false): IntakeUpload
{
    $assessment = PhotoContentAssessment::needsClearer(
        PhotoSubject::Fusebox,
        'Maak een scherpere foto van de meterkast.',
    );
    if ($withOverride) {
        $assessment = $assessment->withCustomerAcceptedOverride();
    }

    $upload->forceFill([
        'content_assessment' => $assessment->toArray(),
        'assessment_status' => PhotoAssessmentStatus::Assessed,
    ])->save();

    return $upload->fresh();
}

function n41MarkOk(IntakeUpload $upload): IntakeUpload
{
    $upload->forceFill([
        'content_assessment' => PhotoContentAssessment::ok(PhotoSubject::Fusebox)->toArray(),
        'assessment_status' => PhotoAssessmentStatus::Assessed,
    ])->save();

    return $upload->fresh();
}

test('N41: OK hoofdfoto supersedeert extra-upload en wist Toch-doorgaan-aandachtspunt', function () {
    $intake = n41FuseboxIntake();
    $store = app(StoreIntakeUpload::class);
    $save = app(SaveIntakeAnswer::class);

    $blurry = $store->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('meterkast-blur.jpg', 640, 480),
    );
    n41MarkNeedsClearer($blurry, withOverride: true);
    $save->handle($intake, 'fusebox_clarity', null, ['value' => 'needs_clearer_photo'], PrefillSources::AI_PHOTO);

    $extra = $store->handle(
        $intake,
        'fusebox_photo_extra',
        null,
        UploadedFile::fake()->image('meterkast-extra.jpg', 800, 600),
    );
    n41MarkNeedsClearer($extra, withOverride: true);

    // Klant verwijdert blurry hoofdfoto (soft) en uploadt scherpe.
    $blurry->delete();
    $sharp = $store->handle(
        $intake->fresh(),
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('meterkast-sharp.jpg', 1200, 900),
    );
    n41MarkOk($sharp);

    FakeAiClient::alwaysReturn([
        'empty_module_space' => 'visible',
        'phase' => 'three_phase',
        'detected_subject' => 'fusebox',
        'subject_match' => 'yes',
        'confidence' => 'high',
        'evidence' => 'Scherpe meterkastfoto met leesbare groepen.',
        'retake_instruction' => null,
    ]);

    app(AssessFuseboxPhotos::class)->handle($intake->fresh());

    $supersessions = app(UploadSupersessionResolver::class)->resolve($intake->fresh());
    expect($supersessions[(int) $extra->id]['superseded'] ?? false)->toBeTrue()
        ->and($supersessions[(int) $extra->id]['supersession_label'] ?? null)
        ->toBe('Vervallen — meterkastfoto is duidelijk')
        ->and($supersessions[(int) $sharp->id]['superseded'] ?? false)->toBeFalse();

    $extraFresh = $extra->fresh();
    expect($extraFresh->contentAssessment()?->customerAcceptedOverride())->toBeFalse();

    $version = $intake->fresh()->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    $points = collect(app(CompletenessChecker::class)->check($intake->fresh(), $version)['attention_points'])
        ->filter(fn (array $point): bool => PhotoContinueAnywayAttention::isContinueAnywayCode($point['code']))
        ->values();

    expect($points)->toHaveCount(0);

    $current = PhotoContinueAnywayAttention::currentWizardUploadsForTopic(
        $intake->fresh(),
        $version,
        'fusebox_photo',
        null,
    );
    expect(PhotoContinueAnywayAttention::fuseboxClarityReportLabel($current))->toBeNull()
        ->and(PhotoContinueAnywayAttention::countStatuses($current)['rejected'])->toBe(0);

    $clarity = $intake->fresh()->answers()->where('question_key', 'fusebox_clarity')->first();
    expect($clarity)->not->toBeNull()
        ->and($clarity?->value['value'] ?? null)->toBe('clear');
});

test('N41: zonder OK-hoofdfoto blijven extra + Toch-doorgaan zichtbaar (N31-regressie)', function () {
    $intake = n41FuseboxIntake();
    $store = app(StoreIntakeUpload::class);

    n41MarkNeedsClearer(
        $store->handle($intake, 'fusebox_photo', null, UploadedFile::fake()->image('a.jpg', 640, 480)),
        withOverride: true,
    );
    n41MarkNeedsClearer(
        $store->handle($intake, 'fusebox_photo_extra', null, UploadedFile::fake()->image('b.jpg', 640, 480)),
        withOverride: true,
    );

    $supersessions = app(UploadSupersessionResolver::class)->resolve($intake->fresh());
    $extra = $intake->fresh()->uploads()->where('question_key', 'fusebox_photo_extra')->firstOrFail();
    expect($supersessions[(int) $extra->id]['superseded'] ?? false)->toBeFalse();

    $version = $intake->fresh()->templateVersion()->with(['sections.questions.options'])->firstOrFail();
    $points = collect(app(CompletenessChecker::class)->check($intake->fresh(), $version)['attention_points'])
        ->filter(fn (array $point): bool => PhotoContinueAnywayAttention::isContinueAnywayCode($point['code']))
        ->values();

    expect($points)->toHaveCount(1);

    $check = app(CompletenessChecker::class)->check($intake->fresh(), $version);
    $html = app(GenerateIntakeReportHtml::class)->handle(
        $intake->fresh(),
        $version,
        $check['attention_points'],
    );
    expect($html)->toContain('Toch doorgaan');
});
