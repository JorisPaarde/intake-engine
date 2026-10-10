<?php

declare(strict_types=1);

use App\Domains\AI\Actions\SuggestAttentionPoints;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\AI\Jobs\SynthesizeSurveyDossierJob;
use App\Domains\AI\Services\IntakeAttentionContextBuilder;
use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\CompleteFollowUpRound;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreFollowUpUpload;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\GeneratedReport;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAttentionPoint;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\CompletenessChecker;
use App\Domains\Intake\Services\DecisionReadinessService;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\InstallerPhotoGalleryBuilder;
use App\Domains\Intake\Support\AttentionProposalVisibility;
use App\Domains\Intake\Support\InstallerEvidencePresenter;
use App\Domains\Intake\Support\PhotoContinueAnywayAttention;
use App\Enums\AiRunStatus;
use App\Enums\AttentionPointSource;
use App\Enums\AttentionPointStatus;
use App\Enums\FollowUpItemType;
use App\Enums\IntakeStatus;
use App\Enums\PhotoAssessmentStatus;
use App\Enums\PhotoUsabilityVerdict;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
});

function clarificationIntake(array $overrides = []): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create(array_merge([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::InProgress,
        'customer_name' => 'Testgezin Opname',
        'is_demo' => false,
    ], $overrides));
}

function clarificationRoom(Intake $intake, string $key, string $name, int $sort = 1): AircoRoom
{
    $manager = app(DossierManager::class);
    $manager->initialize($intake);
    $root = $manager->root($intake);
    $subject = $manager->subject(
        $intake,
        'airco.room.'.$key,
        'airco_room',
        $name,
        $root,
        ['legacy_section_instance_key' => $key],
    );

    return AircoRoom::query()->updateOrCreate(
        [
            'intake_id' => $intake->id,
            'key' => $key,
        ],
        [
            'company_id' => $intake->company_id,
            'dossier_subject_id' => $subject->id,
            'name' => $name,
            'name_source' => 'customer',
            'sort_order' => $sort,
            'status' => 'desired',
            'source_type' => 'customer',
        ],
    );
}

function clarificationFakeImage(string $name = 'foto.jpg'): UploadedFile
{
    // Unieke pixels zodat StoreIntakeUpload geen checksum-duplicaat teruggeeft.
    static $n = 0;
    $n++;

    return UploadedFile::fake()->image($name, 640 + ($n % 40), 480 + ($n % 30));
}

function clarificationOverrideUpload(
    Intake $intake,
    string $questionKey,
    ?string $instanceKey,
    PhotoContentAssessment $assessment,
): IntakeUpload {
    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        $questionKey,
        $instanceKey,
        clarificationFakeImage('foto-'.uniqid('', true).'.jpg'),
    );

    $upload->forceFill([
        'content_assessment' => $assessment->withCustomerAcceptedOverride()->toArray(),
        'assessment_status' => PhotoAssessmentStatus::Assessed,
    ])->save();

    return $upload->fresh();
}

test('groepeert Toch-doorgaan-foto’s per vraag en plek met ruimtenaam en aantal', function () {
    $intake = clarificationIntake();
    clarificationRoom($intake, 'room-1', 'Woonkamer', 1);
    $version = $intake->templateVersion()->with(['sections.questions.options'])->firstOrFail();

    $rejected = PhotoContentAssessment::wrongSubject(PhotoSubject::Room, PhotoSubject::OutdoorUnit);
    clarificationOverrideUpload($intake, 'room_photos', 'room-1', $rejected);
    clarificationOverrideUpload($intake, 'room_photos', 'room-1', $rejected);
    clarificationOverrideUpload($intake, 'room_photos', 'room-1', $rejected);
    clarificationOverrideUpload(
        $intake,
        'fusebox_photo',
        null,
        PhotoContentAssessment::wrongSubject(PhotoSubject::Fusebox, PhotoSubject::Room),
    );

    $check = app(CompletenessChecker::class)->check($intake->fresh(), $version);
    $photoPoints = collect($check['attention_points'])
        ->filter(fn (array $point): bool => PhotoContinueAnywayAttention::isContinueAnywayCode($point['code']))
        ->values();

    expect($photoPoints)->toHaveCount(2)
        ->and($photoPoints[0]['code'])->toBe('photo_continue_anyway__room_photos__room-1')
        ->and($photoPoints[0]['label'])->toBe(
            'Woonkamer · Foto’s van de ruimte: de AI keurde 3 foto’s af. De klant koos ‘Toch doorgaan’.',
        )
        ->and($photoPoints[1]['code'])->toBe('photo_continue_anyway__fusebox_photo__site')
        ->and($photoPoints[1]['label'])->toBe(
            'Stroomtoevoer · Foto van de meterkast: de AI keurde 1 foto af. De klant koos ‘Toch doorgaan’.',
        )
        ->and(collect($check['attention_points'])->pluck('label')->implode(' '))
        ->not->toContain('Foto lijkt andere categorie');
});

test('twee ruimtes met dezelfde fotovraag geven twee regels met opgeslagen ruimtenamen', function () {
    $intake = clarificationIntake();
    // DossierManager plakt verdieping al in de opgeslagen naam — hier de enige bron.
    clarificationRoom($intake, 'room-1', 'Slaapkamer 2, kelder', 1);
    clarificationRoom($intake, 'room-2', 'Slaapkamer 2, 1e verdieping', 2);

    $rejected = PhotoContentAssessment::wrongSubject(PhotoSubject::Room, PhotoSubject::Fusebox);
    clarificationOverrideUpload($intake, 'room_photos', 'room-1', $rejected);
    clarificationOverrideUpload($intake, 'room_photos', 'room-2', $rejected);

    $version = $intake->templateVersion()->with(['sections.questions.options'])->firstOrFail();
    $check = app(CompletenessChecker::class)->check($intake->fresh(['answers', 'aircoRooms', 'uploads']), $version);
    $labels = collect($check['attention_points'])
        ->filter(fn (array $point): bool => PhotoContinueAnywayAttention::isContinueAnywayCode($point['code']))
        ->pluck('label')
        ->all();

    expect($labels)->toHaveCount(2)
        ->and($labels[0])->toContain('Slaapkamer 2, kelder')
        ->and($labels[1])->toContain('Slaapkamer 2, 1e verdieping');
});

test('deels afgekeurd en not_assessed formuleren aparte zinnen', function () {
    $intake = clarificationIntake();
    clarificationRoom($intake, 'room-1', 'Woonkamer', 1);
    $version = $intake->templateVersion()->with(['sections.questions.options'])->firstOrFail();

    clarificationOverrideUpload(
        $intake,
        'room_photos',
        'room-1',
        PhotoContentAssessment::wrongSubject(PhotoSubject::Room, PhotoSubject::OutdoorUnit),
    );
    $ok = app(StoreIntakeUpload::class)->handle(
        $intake,
        'room_photos',
        'room-1',
        clarificationFakeImage('ok.jpg'),
    );
    $ok->forceFill([
        'content_assessment' => PhotoContentAssessment::ok(PhotoSubject::Room)->toArray(),
        'assessment_status' => PhotoAssessmentStatus::Assessed,
    ])->save();

    $partial = app(CompletenessChecker::class)->check($intake->fresh(), $version);
    $partialLabel = collect($partial['attention_points'])
        ->firstWhere('code', 'photo_continue_anyway__room_photos__room-1')['label'];

    expect($partialLabel)->toBe(
        'Woonkamer · Foto’s van de ruimte: de AI keurde 1 van de 2 foto’s af. De klant koos ‘Toch doorgaan’.',
    );

    $intake2 = clarificationIntake();
    clarificationRoom($intake2, 'room-1', 'Woonkamer', 1);
    clarificationOverrideUpload(
        $intake2,
        'room_photos',
        'room-1',
        PhotoContentAssessment::notAssessed(PhotoSubject::Room),
    );
    $version2 = $intake2->templateVersion()->with(['sections.questions.options'])->firstOrFail();
    $notAssessedLabel = collect(app(CompletenessChecker::class)->check($intake2->fresh(), $version2)['attention_points'])
        ->firstWhere('code', 'photo_continue_anyway__room_photos__room-1')['label'];

    expect($notAssessedLabel)->toBe(
        'Woonkamer · Foto’s van de ruimte: De AI kon 1 foto niet beoordelen. De klant koos ‘Toch doorgaan’.',
    )
        ->and($notAssessedLabel)->not->toContain('keurde');
});

test('AI-voorstel dat alleen een systeempunt herhaalt wordt verborgen; extra bewijs blijft zichtbaar', function () {
    config(['ai.provider' => 'fake']);
    $intake = clarificationIntake();
    $owner = User::query()->findOrFail($intake->created_by);

    IntakeAttentionPoint::query()->create([
        'intake_id' => $intake->id,
        'source' => AttentionPointSource::System,
        'code' => 'electrical_provision_open',
        'label' => 'Open technisch punt: stroomvoorziening / vrije groep nog te beoordelen',
        'is_resolved' => false,
    ]);

    app(SaveIntakeAnswer::class)->handle($intake, 'free_group_known', null, ['value' => 'unknown'], 'customer');
    $payload = app(IntakeAttentionContextBuilder::class)->build($intake->fresh());
    $answerRef = collect($payload['answer_context'] ?? [])
        ->first(fn (array $row): bool => ($row['question_key'] ?? null) === 'free_group_known');
    expect($answerRef)->not->toBeNull();

    FakeAiClient::reset();
    FakeAiClient::alwaysReturn(['points' => [
        [
            'code' => 'electrical_provision_open',
            'label' => 'Herhaald systeempunt over stroom',
            'confidence' => 'high',
            'evidence' => [[
                'source_type' => 'system_attention_point',
                'reference' => 'electrical_provision_open',
            ]],
        ],
        [
            'code' => 'power_check_with_answer',
            'label' => 'Controleer de meterkast samen met het klantantwoord',
            'confidence' => 'medium',
            'evidence' => [
                [
                    'source_type' => 'system_attention_point',
                    'reference' => 'electrical_provision_open',
                ],
                [
                    'source_type' => 'answer',
                    'reference' => $answerRef['reference'],
                ],
            ],
        ],
        [
            'code' => 'only_system_refs',
            'label' => 'Alleen systeembewijs',
            'confidence' => 'high',
            'evidence' => [[
                'source_type' => 'system_attention_point',
                'reference' => 'electrical_provision_open',
            ]],
        ],
    ]]);

    app(SuggestAttentionPoints::class)->handle($intake->fresh());

    $visible = AttentionProposalVisibility::visibleProposed($intake->fresh()->attentionPoints);
    expect($visible->pluck('code')->all())
        ->toContain('power_check_with_answer')
        ->and($visible->pluck('code')->all())
        ->not->toContain('electrical_provision_open')
        ->and($visible->pluck('code')->all())
        ->not->toContain('only_system_refs');

    $this->actingAs($owner)
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee('Controleer de meterkast samen met het klantantwoord', false)
        ->assertSee('Automatische controle:', false)
        ->assertDontSee('Systeemsignaal', false)
        ->assertDontSee('Herhaald systeempunt over stroom', false)
        ->assertDontSee('Alleen systeembewijs', false);
});

test('context bevat fototellingen voor afgekeurde uploads zonder option_label', function () {
    $intake = clarificationIntake();
    clarificationRoom($intake, 'room-1', 'Woonkamer', 1);
    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'floor_level',
        'room-1',
        ['value' => 'basement'],
        'customer',
    );
    clarificationOverrideUpload(
        $intake,
        'room_photos',
        'room-1',
        PhotoContentAssessment::wrongSubject(PhotoSubject::Room, PhotoSubject::Fusebox),
    );
    clarificationOverrideUpload(
        $intake,
        'room_photos',
        'room-1',
        PhotoContentAssessment::notAssessed(PhotoSubject::Room),
    );

    $payload = app(IntakeAttentionContextBuilder::class)->build($intake->fresh());
    $stats = collect($payload['photo_question_stats'] ?? [])
        ->first(fn (array $row): bool => ($row['question_key'] ?? null) === 'room_photos'
            && ($row['section_instance_key'] ?? null) === 'room-1');

    expect($stats)->not->toBeNull()
        ->and($stats['photo_count'])->toBe(2)
        ->and($stats['rejected_count'])->toBe(1)
        ->and($stats['not_assessed_count'])->toBe(1)
        ->and($stats['customer_continued_anyway'])->toBeTrue()
        ->and(array_key_exists('reference', $stats))->toBeFalse();

    $floorAnswer = collect($payload['answer_context'] ?? [])
        ->first(fn (array $row): bool => ($row['question_key'] ?? null) === 'floor_level');

    expect($floorAnswer)->not->toBeNull()
        ->and(array_key_exists('option_label', $floorAnswer))->toBeFalse();
});

test('alle foto’s afgekeurd met Toch doorgaan: attention-points-run slaagt met geldige citaties', function () {
    config(['ai.provider' => 'fake']);
    $intake = clarificationIntake();
    clarificationRoom($intake, 'room-1', 'Woonkamer', 1);

    clarificationOverrideUpload(
        $intake,
        'room_photos',
        'room-1',
        PhotoContentAssessment::wrongSubject(PhotoSubject::Room, PhotoSubject::OutdoorUnit),
    );
    clarificationOverrideUpload(
        $intake,
        'room_photos',
        'room-1',
        PhotoContentAssessment::wrongSubject(PhotoSubject::Room, PhotoSubject::Fusebox),
    );

    IntakeAttentionPoint::query()->create([
        'intake_id' => $intake->id,
        'source' => AttentionPointSource::System,
        'code' => 'photo_continue_anyway__room_photos__room-1',
        'label' => 'Woonkamer · Foto’s van de ruimte: de AI keurde 2 foto’s af. De klant koos ‘Toch doorgaan’.',
        'is_resolved' => false,
    ]);

    app(SaveIntakeAnswer::class)->handle($intake, 'free_group_known', null, ['value' => 'unknown'], 'customer');

    $payload = app(IntakeAttentionContextBuilder::class)->build($intake->fresh());
    $stats = collect($payload['photo_question_stats'] ?? []);
    expect($stats)->not->toBeEmpty()
        ->and($stats->every(fn (array $row): bool => ! array_key_exists('reference', $row)))->toBeTrue()
        ->and($stats->sum('rejected_count'))->toBeGreaterThan(0);

    $answerRef = collect($payload['answer_context'] ?? [])
        ->first(fn (array $row): bool => ($row['question_key'] ?? null) === 'free_group_known');
    expect($answerRef)->not->toBeNull();

    FakeAiClient::reset();
    FakeAiClient::alwaysReturn(['points' => [[
        'code' => 'check_rejected_room_photos',
        'label' => 'Foto van de ruimte ontvangen, maar de AI vindt die niet bruikbaar. Controleer de foto zelf.',
        'confidence' => 'high',
        'evidence' => [
            [
                'source_type' => 'answer',
                'reference' => $answerRef['reference'],
            ],
            [
                'source_type' => 'system_attention_point',
                'reference' => 'photo_continue_anyway__room_photos__room-1',
            ],
        ],
    ]]]);

    $run = app(SuggestAttentionPoints::class)->handle($intake->fresh());
    $point = $intake->fresh()->attentionPoints()->where('code', 'check_rejected_room_photos')->first();

    expect($run?->status)->toBe(AiRunStatus::Succeeded)
        ->and($run?->error_message)->toBeNull()
        ->and($point)->not->toBeNull()
        ->and($point->evidence)->toHaveCount(2);
});

test('verborgen herhaal-voorstellen tellen niet mee in hasOpenAiProposals', function () {
    $intake = clarificationIntake();

    IntakeAttentionPoint::query()->create([
        'intake_id' => $intake->id,
        'source' => AttentionPointSource::System,
        'code' => 'electrical_provision_open',
        'label' => 'Open technisch punt: stroomvoorziening / vrije groep nog te beoordelen',
        'is_resolved' => false,
    ]);
    IntakeAttentionPoint::query()->create([
        'intake_id' => $intake->id,
        'source' => AttentionPointSource::Ai,
        'code' => 'electrical_provision_open',
        'label' => 'Herhaald systeempunt',
        'status' => AttentionPointStatus::Proposed,
        'ai_confidence' => 'high',
        'evidence' => [[
            'source_type' => 'system_attention_point',
            'reference' => 'electrical_provision_open',
        ]],
    ]);

    expect(app(DecisionReadinessService::class)->hasOpenAiProposals($intake->fresh()))
        ->toBeFalse();
});

test('galerij toont ruimtenamen en samenvattingstelling; demo-klantlink zonder auto-mail', function () {
    $intake = clarificationIntake(['is_demo' => true, 'customer_access_enabled' => true]);
    clarificationRoom($intake, 'room-1', 'Woonkamer', 1);
    clarificationOverrideUpload(
        $intake,
        'room_photos',
        'room-1',
        PhotoContentAssessment::wrongSubject(PhotoSubject::Room, PhotoSubject::OutdoorUnit),
    );
    $ok = app(StoreIntakeUpload::class)->handle(
        $intake,
        'room_photos',
        'room-1',
        clarificationFakeImage('ok.jpg'),
    );
    $ok->forceFill([
        'content_assessment' => PhotoContentAssessment::ok(PhotoSubject::Room)->toArray(),
        'assessment_status' => PhotoAssessmentStatus::Assessed,
    ])->save();

    $groups = app(InstallerPhotoGalleryBuilder::class)->handle($intake->fresh());
    expect($groups[0]['heading'])->toBe('Woonkamer')
        ->and($groups[0]['anchor'])->toBe('gallery-room-1');

    $summary = app(InstallerPhotoGalleryBuilder::class)->summaryLine($intake->fresh());
    expect($summary)->toBe('2 foto’s · 1 afgekeurd door de AI · tik om te openen');

    $owner = User::query()->findOrFail($intake->created_by);
    $this->actingAs($owner)
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee('Woonkamer', false)
        ->assertSee('2 foto’s · 1 afgekeurd door de AI · tik om te openen', false)
        ->assertSee('In de demo mailen we de klant niet.', false)
        ->assertDontSee('mailen we de klant automatisch', false)
        ->assertDontSee('fallback', false);
});

test('gewone opname toont kopieerzin zonder fallback-jargon', function () {
    $intake = clarificationIntake([
        'is_demo' => false,
        'customer_access_enabled' => true,
    ]);
    $owner = User::query()->findOrFail($intake->created_by);

    $this->actingAs($owner)
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee('Je kunt de link ook kopiëren en zelf sturen.', false)
        ->assertDontSee('fallback', false)
        ->assertSee('Nog geen foto’s · tik om te openen', false);
});

test('bestaande oude photo_subject_mismatch-punten laden foutloos op de show-pagina', function () {
    $intake = clarificationIntake();
    $owner = User::query()->findOrFail($intake->created_by);

    IntakeAttentionPoint::query()->create([
        'intake_id' => $intake->id,
        'source' => AttentionPointSource::System,
        'code' => 'photo_subject_mismatch_999',
        'label' => 'Foto lijkt andere categorie, controleer',
        'is_resolved' => false,
    ]);

    $this->actingAs($owner)
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee('Foto lijkt andere categorie, controleer', false);
});

test('Accepteren-knop is omlijnd en niet gevuld groen', function () {
    $intake = clarificationIntake();
    $owner = User::query()->findOrFail($intake->created_by);

    IntakeAttentionPoint::query()->create([
        'intake_id' => $intake->id,
        'source' => AttentionPointSource::Ai,
        'code' => 'building_type_check',
        'label' => 'Controleer het gebouwtype',
        'status' => AttentionPointStatus::Proposed,
        'ai_confidence' => 'high',
        'evidence' => [[
            'source_type' => 'external_fact',
            'reference' => 'building_type_inference',
        ]],
    ]);

    $html = $this->actingAs($owner)
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee('Accepteren', false)
        ->assertSee('Zekerheid: hoog', false)
        ->getContent();

    expect($html)->toContain('border-emerald-800')
        ->and($html)->not->toContain('bg-emerald-600');
});

test('attention_points prompt blijft v3 met alleen citeer-verbod voor photo_question_stats', function () {
    $meta = require app_path('Domains/AI/Prompts/attention_points/meta.php');
    $prompt = file_get_contents(app_path('Domains/AI/Prompts/attention_points/prompt.md'));

    expect($meta['version'])->toBe('attention_points-v3')
        ->and($prompt)->toContain('Citeer nooit `photo_question_stats`')
        ->and($prompt)->not->toContain('option_label')
        ->and($prompt)->not->toContain('4 m²')
        ->and($prompt)->not->toContain('customer_continued_anyway');
});

test('hernoemde ruimte toont nieuwe naam in Actueel-fotomelding (live label)', function () {
    $intake = clarificationIntake();
    $room = clarificationRoom($intake, 'room-1', 'Woonkamer', 1);
    clarificationOverrideUpload(
        $intake,
        'room_photos',
        'room-1',
        PhotoContentAssessment::wrongSubject(PhotoSubject::Room, PhotoSubject::OutdoorUnit),
    );

    IntakeAttentionPoint::query()->create([
        'intake_id' => $intake->id,
        'source' => AttentionPointSource::System,
        'code' => 'photo_continue_anyway__room_photos__room-1',
        'label' => 'Woonkamer · Foto’s van de ruimte: de AI keurde 1 foto af. De klant koos ‘Toch doorgaan’.',
        'is_resolved' => false,
    ]);

    $room->update(['name' => 'Hobbykamer, begane grond']);
    $owner = User::query()->findOrFail($intake->created_by);

    $live = PhotoContinueAnywayAttention::liveLabel(
        $intake->fresh(['uploads', 'aircoRooms', 'templateVersion.sections.questions']),
        'photo_continue_anyway__room_photos__room-1',
    );
    expect($live)->toContain('Hobbykamer, begane grond')
        ->and($live)->not->toContain('Woonkamer ·');

    $this->actingAs($owner)
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee('Hobbykamer, begane grond', false)
        ->assertDontSee('Woonkamer · Foto’s van de ruimte', false);
});

test('follow-up die foto’s vervangt verbergt de Toch-doorgaan-melding', function () {
    Queue::fake([AssessUploadedPhotoJob::class, SynthesizeSurveyDossierJob::class]);

    $intake = clarificationIntake();
    clarificationRoom($intake, 'room-1', 'Woonkamer', 1);
    $owner = User::query()->findOrFail($intake->created_by);
    clarificationOverrideUpload(
        $intake,
        'room_photos',
        'room-1',
        PhotoContentAssessment::wrongSubject(PhotoSubject::Room, PhotoSubject::OutdoorUnit),
    );

    $staleLabel = 'Woonkamer · Foto’s van de ruimte: de AI keurde 1 foto af. De klant koos ‘Toch doorgaan’.';
    IntakeAttentionPoint::query()->create([
        'intake_id' => $intake->id,
        'source' => AttentionPointSource::System,
        'code' => 'photo_continue_anyway__room_photos__room-1',
        'label' => $staleLabel,
        'is_resolved' => false,
    ]);
    GeneratedReport::query()->create([
        'intake_id' => $intake->id,
        'html' => '<html><body><ul><li>'.$staleLabel.'</li></ul></body></html>',
        'meta' => ['attention_point_codes' => ['photo_continue_anyway__room_photos__room-1']],
        'generated_at' => now(),
    ]);

    expect(PhotoContinueAnywayAttention::liveLabel(
        $intake->fresh(['uploads', 'aircoRooms', 'templateVersion.sections.questions', 'followUpRounds.items.uploads', 'contributionTasks']),
        'photo_continue_anyway__room_photos__room-1',
    ))->not->toBeNull();

    $round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $owner, [[
        'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een duidelijke foto van de hele ruimte.',
        'decision_area_key' => 'capacity',
        'dossier_subject_id' => null,
    ]]);
    $item = $round->items()->firstOrFail();

    $replacement = app(StoreFollowUpUpload::class)->handle(
        $intake->fresh(),
        $item,
        clarificationFakeImage('ruimte-nieuw.jpg'),
    );
    expect($replacement->question_key)->toBe('follow_up_'.$item->id);

    $replacement->forceFill([
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'content_assessment' => PhotoContentAssessment::ok(PhotoSubject::Room)->toArray(),
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
    ])->save();

    app(CompleteFollowUpRound::class)->handle($intake->fresh(), $round->fresh(), []);

    $fresh = $intake->fresh([
        'uploads.followUpItem.round',
        'aircoRooms',
        'templateVersion.sections.questions',
        'followUpRounds.items.uploads',
        'contributionTasks',
        'attentionPoints',
        'report',
    ]);
    expect(PhotoContinueAnywayAttention::liveLabel(
        $fresh,
        'photo_continue_anyway__room_photos__room-1',
    ))->toBeNull()
        ->and(PhotoContinueAnywayAttention::linkForPoint(
            $fresh,
            'photo_continue_anyway__room_photos__room-1',
        ))->toBeNull();

    expect($fresh->report?->html)->not->toBeNull()
        ->and($fresh->report->html)->not->toContain('Toch doorgaan')
        ->and($fresh->report->html)->not->toContain($staleLabel);

    $payload = app(IntakeAttentionContextBuilder::class)->build($fresh);
    $systemCodes = collect($payload['system_attention_points'] ?? [])->pluck('code')->all();
    expect($systemCodes)->not->toContain('photo_continue_anyway__room_photos__room-1')
        ->and(json_encode($payload['system_attention_points'] ?? []))->not->toContain('Toch doorgaan');

    $this->actingAs($owner)
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertDontSee('De klant koos ‘Toch doorgaan’', false)
        ->assertDontSee('Woonkamer · Foto’s van de ruimte', false);
});

test('twee geciteerde foto’s van dezelfde vraag en plek worden één link', function () {
    $intake = clarificationIntake();
    clarificationRoom($intake, 'room-1', 'Woonkamer', 1);
    clarificationOverrideUpload(
        $intake,
        'room_photos',
        'room-1',
        PhotoContentAssessment::wrongSubject(PhotoSubject::Room, PhotoSubject::OutdoorUnit),
    );
    clarificationOverrideUpload(
        $intake,
        'room_photos',
        'room-1',
        PhotoContentAssessment::wrongSubject(PhotoSubject::Room, PhotoSubject::Fusebox),
    );

    $payload = app(IntakeAttentionContextBuilder::class)->build($intake->fresh());
    $uploadRefs = collect($payload['uploads'] ?? [])
        ->filter(fn (array $row): bool => ($row['question_key'] ?? null) === 'room_photos'
            && ($row['section_instance_key'] ?? null) === 'room-1')
        ->pluck('reference')
        ->values()
        ->all();

    expect($uploadRefs)->toHaveCount(2);

    $citations = app(InstallerEvidencePresenter::class)->presentAttentionEvidence($intake->fresh(), [
        ['source_type' => 'upload', 'reference' => $uploadRefs[0]],
        ['source_type' => 'upload', 'reference' => $uploadRefs[1]],
    ]);

    expect($citations)->toHaveCount(1)
        ->and($citations[0]['label'])->toContain('Woonkamer')
        ->and($citations[0]['url'])->not->toBeNull();
});

test('galerijanker voor niet-ruimte komt uit sectie, gelijk aan Actueel-link', function () {
    $intake = clarificationIntake();
    clarificationOverrideUpload(
        $intake,
        'fusebox_photo',
        null,
        PhotoContentAssessment::wrongSubject(PhotoSubject::Fusebox, PhotoSubject::Room),
    );

    $groups = app(InstallerPhotoGalleryBuilder::class)->handle($intake->fresh());
    $electrical = collect($groups)->first(
        fn (array $group): bool => ($group['heading'] ?? null) === 'Elektrische installatie',
    );
    expect($electrical)->not->toBeNull()
        ->and($electrical['anchor'])->toBe('gallery-electrical');

    $link = PhotoContinueAnywayAttention::linkForPoint(
        $intake->fresh(['uploads', 'templateVersion.sections.questions']),
        'photo_continue_anyway__fusebox_photo__site',
    );
    expect($link)->not->toBeNull()
        ->and($link['anchor'])->toBe('gallery-electrical')
        ->and($link['link_label'])->toBe('Bekijk foto');
});
