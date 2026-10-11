<?php

declare(strict_types=1);

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAttentionPoint;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\CompletenessChecker;
use App\Domains\Intake\Services\GenerateIntakeReportHtml;
use App\Domains\Intake\Support\PhotoContinueAnywayAttention;
use App\Enums\AttentionPointSource;
use App\Enums\IntakeStatus;
use App\Enums\PhotoAssessmentStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
});

function meterkastDedupIntake(): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_name' => 'Testklant Meterkast',
        'address_line' => 'Teststraat 1',
        'address_postal_code' => '1234AB',
        'address_city' => 'Testdorp',
        'is_demo' => false,
    ]);
}

function meterkastRejectedUpload(
    Intake $intake,
    string $questionKey,
): IntakeUpload {
    static $n = 0;
    $n++;

    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        $questionKey,
        null,
        UploadedFile::fake()->image('meterkast-'.$n.'.jpg', 800 + ($n % 20), 600 + ($n % 15)),
    );

    $assessment = PhotoContentAssessment::needsClearer(
        PhotoSubject::Fusebox,
        'Maak een scherpere foto van de meterkast.',
    )->withCustomerAcceptedOverride();

    $upload->forceFill([
        'content_assessment' => $assessment->toArray(),
        'assessment_status' => PhotoAssessmentStatus::Assessed,
    ])->save();

    return $upload->fresh();
}

test('N21: twee afgekeurde meterkastvragen geven één Toch-doorgaan-punt bij afronden', function () {
    $intake = meterkastDedupIntake();
    meterkastRejectedUpload($intake, 'fusebox_photo');
    meterkastRejectedUpload($intake, 'fusebox_photo_extra');

    $version = $intake->templateVersion()->with(['sections.questions.options'])->firstOrFail();
    $points = collect(app(CompletenessChecker::class)->check($intake->fresh(), $version)['attention_points'])
        ->filter(fn (array $point): bool => PhotoContinueAnywayAttention::isContinueAnywayCode($point['code']))
        ->values();

    expect($points)->toHaveCount(1)
        ->and($points[0]['code'])->toBe('photo_continue_anyway__fusebox_photo__site')
        ->and($points[0]['label'])->toContain('Stroomtoevoer')
        ->and($points[0]['label'])->toContain('Toch doorgaan')
        ->and($points[0]['label'])->toContain('2 foto');
});

test('N21: detailpagina en rapport tonen precies één meterkast Toch-doorgaan-kaart', function () {
    $intake = meterkastDedupIntake();
    meterkastRejectedUpload($intake, 'fusebox_photo');
    meterkastRejectedUpload($intake, 'fusebox_photo_extra');

    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules', 'template'])->firstOrFail();
    $check = app(CompletenessChecker::class)->check($intake->fresh(), $version);
    $continuePoints = collect($check['attention_points'])
        ->filter(fn (array $point): bool => PhotoContinueAnywayAttention::isContinueAnywayCode($point['code']))
        ->values();

    foreach ($check['attention_points'] as $point) {
        IntakeAttentionPoint::query()->create([
            'intake_id' => $intake->id,
            'source' => AttentionPointSource::System,
            'code' => $point['code'],
            'label' => $point['label'],
            'is_resolved' => false,
        ]);
    }

    $html = app(GenerateIntakeReportHtml::class)->handle(
        $intake->fresh(),
        $version,
        $check['attention_points'],
    );

    $sentence = 'De klant koos ‘Toch doorgaan’';
    $owner = User::query()->findOrFail($intake->created_by);

    expect($continuePoints)->toHaveCount(1);

    $this->actingAs($owner)
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee($sentence, false);

    $showHtml = $this->actingAs($owner)
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->getContent();

    expect(substr_count($showHtml, $sentence))->toBe(1)
        ->and(substr_count($html, $sentence))->toBe(1)
        ->and(substr_count($html, 'de AI keurde'))->toBe(1);
});

test('N21: oude intakes met twee opgeslagen meterkastcodes renderen als één kaart', function () {
    $intake = meterkastDedupIntake();
    meterkastRejectedUpload($intake, 'fusebox_photo');
    meterkastRejectedUpload($intake, 'fusebox_photo_extra');

    IntakeAttentionPoint::query()->create([
        'intake_id' => $intake->id,
        'source' => AttentionPointSource::System,
        'code' => 'photo_continue_anyway__fusebox_photo__site',
        'label' => 'Stroomtoevoer · Foto van de meterkast: de AI keurde 1 foto af. De klant koos ‘Toch doorgaan’.',
        'is_resolved' => false,
    ]);
    IntakeAttentionPoint::query()->create([
        'intake_id' => $intake->id,
        'source' => AttentionPointSource::System,
        'code' => 'photo_continue_anyway__fusebox_photo_extra__site',
        'label' => 'Stroomtoevoer · Extra, scherpere foto van de meterkast: de AI keurde 1 foto af. De klant koos ‘Toch doorgaan’.',
        'is_resolved' => false,
    ]);

    $owner = User::query()->findOrFail($intake->created_by);
    $showHtml = $this->actingAs($owner)
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee('De klant koos ‘Toch doorgaan’', false)
        ->getContent();

    expect(substr_count($showHtml, 'De klant koos ‘Toch doorgaan’'))->toBe(1)
        ->and(substr_count($showHtml, 'Stroomtoevoer ·'))->toBe(1);

    $livePrimary = PhotoContinueAnywayAttention::liveLabel(
        $intake->fresh(['uploads', 'aircoRooms', 'templateVersion.sections.questions']),
        'photo_continue_anyway__fusebox_photo__site',
    );
    $liveExtra = PhotoContinueAnywayAttention::liveLabel(
        $intake->fresh(['uploads', 'aircoRooms', 'templateVersion.sections.questions']),
        'photo_continue_anyway__fusebox_photo_extra__site',
    );

    expect($livePrimary)->not->toBeNull()
        ->and($liveExtra)->toBe($livePrimary)
        ->and($livePrimary)->toContain('2 foto');
});
