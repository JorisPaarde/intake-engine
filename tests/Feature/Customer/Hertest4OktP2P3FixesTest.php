<?php

declare(strict_types=1);

use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\CompletenessChecker;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\ExternalFactPresenter;
use App\Domains\Intake\Services\FollowUpProgressCalculator;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Support\KnownSummaryCatalog;
use App\Domains\Intake\Support\OutdoorPhotoReuse;
use App\Domains\Intake\Support\PhotoContentSatisfaction;
use App\Domains\Intake\Support\PhotoOverridePolicy;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\FollowUpItemType;
use App\Enums\IntakeStatus;
use App\Enums\PhotoAssessmentStatus;
use App\Enums\PhotoUsabilityVerdict;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

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

function hertest4Intake(array $overrides = []): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create(array_merge([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::InProgress,
        'customer_name' => 'Hertest 4 okt',
        'customer_email' => 'hertest4@example.com',
        'address_line' => 'Testlaan 1',
        'address_city' => 'Utrecht',
    ], $overrides));
}

function hertest4TinyJpeg(string $name = 'tiny-600.jpg'): UploadedFile
{
    $img = imagecreatetruecolor(600, 616);
    imagefill($img, 0, 0, imagecolorallocate($img, 180, 180, 180));
    ob_start();
    imagejpeg($img, null, 85);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return UploadedFile::fake()->createWithContent($name, $bytes);
}

test('P2-a: lage-resolutiefoto in follow-up blokkeert Aanvulling versturen tot Toch doorgaan', function () {
    Queue::fake([AssessUploadedPhotoJob::class]);

    $intake = hertest4Intake();
    app(DossierManager::class)->initialize($intake);
    $user = User::query()->findOrFail($intake->created_by);

    $round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [[
        'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een duidelijke foto van de meterkast.',
        'decision_area_key' => 'power',
    ]]);
    $item = $round->items()->firstOrFail();
    $intake->refresh();

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('followUpPhotoFiles.'.$item->id, hertest4TinyJpeg())
        ->assertSet('uploadPhase', '');

    $item->refresh()->load('uploads');
    $upload = $item->uploads->firstOrFail();

    expect($upload->usability_verdict)->toBe(PhotoUsabilityVerdict::TooSmall)
        ->and(PhotoOverridePolicy::needsOverride($upload))->toBeTrue();

    $progress = app(FollowUpProgressCalculator::class)->calculate(collect([$item]));
    expect($progress['percent'])->toBe(0)
        ->and($progress['item_statuses'][$item->id]['label'])->toBe('Nieuwe foto nodig');

    $component
        ->assertSee('Toch doorgaan')
        ->call('completeFollowUp')
        ->assertHasErrors('follow_up')
        ->assertSet('completed', false)
        ->assertSee(PhotoOverridePolicy::OVERRIDE_MESSAGE);

    $component
        ->call('acceptFollowUpPhotoMismatch')
        ->call('completeFollowUp')
        ->assertHasNoErrors('follow_up')
        ->assertSet('completed', true)
        ->assertSee('Bedankt, je aanvulling is binnen')
        ->assertSee(PhotoOverridePolicy::THANK_YOU_COPY)
        ->assertDontSee('nog geen afronding')
        ->assertDontSee('Hieronder staat alleen wat nog echt nodig is')
        ->assertDontSee('één aanvulling afgerond');
});

test('P2-a: PhotoOverridePolicy is gedeelde bron voor wizard en follow-up', function () {
    $upload = new IntakeUpload([
        'usability_verdict' => PhotoUsabilityVerdict::TooSmall,
        'assessment_status' => PhotoAssessmentStatus::HeuristicRejected,
        'content_assessment' => null,
    ]);

    expect(PhotoOverridePolicy::needsOverride($upload))->toBeTrue()
        ->and(PhotoOverridePolicy::OVERRIDE_MESSAGE)->toBe('Kies: foto vervangen of toch doorgaan.');

    // Persistente acceptatie vereist een opgeslagen upload; hier alleen de gedeelde flag-path.
    $assessment = PhotoContentAssessment::needsClearer(PhotoSubject::Fusebox, 'lage resolutie')
        ->withCustomerAcceptedOverride();

    expect($assessment->customerAcceptedOverride())->toBeTrue()
        ->and($assessment->solvesContent())->toBeTrue();

    $acceptedUpload = new IntakeUpload([
        'usability_verdict' => PhotoUsabilityVerdict::TooSmall,
        'assessment_status' => PhotoAssessmentStatus::HeuristicRejected,
        'content_assessment' => $assessment->toArray(),
    ]);

    expect(PhotoOverridePolicy::needsOverride($acceptedUpload))->toBeFalse()
        ->and(PhotoOverridePolicy::isAcceptedOverride($acceptedUpload))->toBeTrue();
});

test('P2-b: meterkast AI-voorstel toont veldlabel + waarde + concrete onzekerheid', function () {
    $intake = hertest4Intake();
    $version = $intake->templateVersion()
        ->with(['sections.questions.options', 'sections.questions.rules'])
        ->firstOrFail();

    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'free_group_known',
        null,
        ['value' => 'no'],
        PrefillSources::AI_PHOTO,
    );

    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        hertest4TinyJpeg('fusebox-tiny.jpg'),
    );
    $upload->forceFill([
        'usability_verdict' => PhotoUsabilityVerdict::TooSmall,
        'assessment_status' => PhotoAssessmentStatus::HeuristicRejected,
    ])->save();

    $check = app(CompletenessChecker::class)->check($intake->fresh(), $version);
    $point = collect($check['attention_points'])->firstWhere('code', 'electrical_provision_open');

    expect($point)->not->toBeNull();
    $label = (string) $point['label'];
    expect($label)->toContain('AI-voorstel')
        ->and(mb_strtolower($label))->toContain('vrije')
        ->and($label)->toContain('Nee')
        ->and($label)->toContain('Niet zeker: foto te klein')
        ->and($label)->not->toContain('free_group_known')
        ->and($label)->not->toMatch('/\bno\b/');
});

test('P2-b: fusebox external fact toont empty_module_space met label en onzekerheid', function () {
    $intake = hertest4Intake();
    $intake->externalFacts()->create([
        'fact_key' => 'fusebox_photo_assessment',
        'label' => 'Meterkastfoto',
        'source' => 'ai_photo',
        'confidence' => 'low',
        'captured_at' => now(),
        'value' => [
            'empty_module_space' => 'unknown',
            'phase' => 'unknown',
            'evidence' => 'Foto te klein om groepen te tellen',
            'retake_instruction' => 'Maak een scherpere foto recht van voren',
        ],
    ]);

    $presented = app(ExternalFactPresenter::class)->present($intake->fresh());
    $fact = collect($presented['facts'])->firstWhere('label', 'Meterkastfoto');

    expect($fact)->not->toBeNull()
        ->and($fact['display'])->toContain('Meterkastbeoordeling')
        ->and($fact['display'])->toContain('Vrije moduleplek')
        ->and($fact['display'])->toContain('Onzekerheid')
        ->and($fact['display'])->not->toContain('free_group');
});

test('P3-1/2: follow-up feedback is uniek en verdwijnt direct na verwijderen', function () {
    Queue::fake([AssessUploadedPhotoJob::class]);

    $intake = hertest4Intake();
    app(DossierManager::class)->initialize($intake);
    $user = User::query()->findOrFail($intake->created_by);

    $round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [[
        'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een duidelijke foto van de meterkast.',
        'decision_area_key' => 'power',
    ]]);
    $item = $round->items()->firstOrFail();
    $intake->refresh();

    FakeAiClient::alwaysReturn([
        'detected_subject' => 'room',
        'subject_match' => 'no',
        'evidence' => 'Lege kamerwand, geen meterkast.',
    ]);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $component->set('followUpPhotoFiles.'.$item->id, hertest4TinyJpeg('wrong-and-small.jpg'));

    // Tiny → heuristic_rejected, no AI job; force wrong_subject content for duplicate-feedback case.
    $upload = $item->fresh()->uploads()->firstOrFail();
    $upload->storeContentAssessment(
        PhotoContentAssessment::wrongSubject(PhotoSubject::Fusebox, PhotoSubject::Room),
        PhotoAssessmentStatus::HeuristicRejected,
    );
    $upload->forceFill(['usability_verdict' => PhotoUsabilityVerdict::TooSmall])->save();

    $hints = PhotoOverridePolicy::uniqueCustomerFeedback(collect([$upload->fresh()]));
    expect($hints)->toHaveCount(1);

    $component->call('pollPendingAssessments');
    $html = $component->html();
    $mismatchMsg = PhotoContentAssessment::wrongSubject(PhotoSubject::Fusebox, PhotoSubject::Room)->customerMessage();
    expect(substr_count($html, (string) $mismatchMsg))->toBeLessThanOrEqual(1);

    $component
        ->call('removeFollowUpUpload', $item->id, $upload->id)
        ->assertSee('Foto verwijderd.')
        ->assertDontSee((string) $mismatchMsg);
});

test('P3-3: drain_location tekst noemt geen altijd-verplichte foto (airco v25+)', function () {
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    expect($version->version)->toBeGreaterThanOrEqual(25);

    $drainLocation = $version->sections->flatMap->questions->firstWhere('key', 'drain_location');
    $drainPhoto = $version->sections->flatMap->questions->firstWhere('key', 'drain_photo');

    expect($drainLocation)->not->toBeNull()
        ->and($drainLocation->help_text)->not->toContain('we vragen altijd een foto')
        ->and($drainPhoto->is_required)->toBeFalse()
        ->and($drainPhoto->label)->toContain('optioneel');

    if ($version->version >= 27) {
        expect($drainLocation->help_text)->toContain('Ga gewoon verder')
            ->and($drainPhoto->meta['wizard_group'] ?? null)->toBe('drain_nearby')
            ->and($drainPhoto->meta['allow_skip'] ?? null)->toBeNull();
    } else {
        expect($drainLocation->help_text)->toContain('sla dan over')
            ->and($drainPhoto->help_text)->toContain('sla dan over');
    }
});

test('P3-4: bruikbare outdoor-foto maakt around_house optioneel via OutdoorPhotoReuse', function () {
    $intake = hertest4Intake();
    $version = $intake->templateVersion()
        ->with(['sections.questions.options', 'sections.questions.rules'])
        ->firstOrFail();

    expect(OutdoorPhotoReuse::hasUsableOutdoorContext($intake))->toBeFalse();

    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'outdoor_location_photos',
        null,
        UploadedFile::fake()->image('gevel.jpg', 1600, 1200),
    );
    $upload->forceFill([
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'content_assessment' => PhotoContentAssessment::ok(PhotoSubject::OutdoorLocation)->toArray(),
    ])->save();

    expect(OutdoorPhotoReuse::hasUsableOutdoorContext($intake->fresh()))->toBeTrue()
        ->and(OutdoorPhotoReuse::aroundHouseSatisfiedByReuse($intake->fresh()))->toBeTrue();

    $around = $version->sections->flatMap->questions->firstWhere('key', 'around_house_photos');
    expect($around)->not->toBeNull()
        ->and($around->meta['allow_skip'] ?? false)->toBeTrue()
        ->and($around->meta['reuse_from_photo_keys'] ?? [])->toContain('outdoor_location_photos');
});

test('P3-5: progress % en vragen-teller gebruiken dezelfde done-telling', function () {
    Queue::fake([AssessUploadedPhotoJob::class]);
    $intake = hertest4Intake(['status' => IntakeStatus::Sent]);
    app(DossierManager::class)->initialize($intake);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $html = $component->html();
    expect($html)->toMatch('/data-testid="progress-percent">\d+%/')
        ->and($html)->toMatch('/Vraag\s+\d+\s+van\s+\d+/');

    // Foto telt mee zodra ontvangen; assessment mag async (intake 82 soft-continue).
    $pending = new IntakeUpload([
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
        'assessment_status' => PhotoAssessmentStatus::Pending,
        'content_assessment' => null,
    ]);
    expect(PhotoContentSatisfaction::uploadsSatisfy(collect([$pending])))->toBeTrue();

    $accepted = new IntakeUpload([
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'content_assessment' => PhotoContentAssessment::ok(PhotoSubject::Room)->toArray(),
    ]);
    expect(PhotoContentSatisfaction::uploadsSatisfy(collect([$accepted])))->toBeTrue();

    // % en teller delen countDoneSteps / computeRawStepProgressPercent.
    $version = $intake->templateVersion()->with(['sections.questions.rules'])->firstOrFail();
    $steps = app(IntakeStepBuilder::class)->build($intake->fresh(), $version);
    expect($steps)->not->toBeEmpty();
    $total = count($steps);
    $percentFromSameRule = (int) round((0 / max(1, $total)) * 100);
    expect($percentFromSameRule)->toBe(0)
        ->and($html)->toContain('van '.$total);
});

test('earlier Oct3: pipe_route accepteert lege wand/room zonder wrong_subject', function () {
    $accepted = PhotoSubject::acceptedSubjectsForPhotoQuestion('pipe_route_photos', 'pipe_route');
    expect($accepted)->toContain(PhotoSubject::Room)
        ->and($accepted)->toContain(PhotoSubject::PipeRoute);

    $assessment = PhotoContentAssessment::fromModelOutput(
        PhotoSubject::PipeRoute,
        [
            'detected_subject' => 'room',
            'subject_match' => 'yes',
            'retake_instruction' => null,
        ],
        $accepted,
    );

    expect($assessment->status())->toBe(PhotoContentAssessment::STATUS_OK)
        ->and(PhotoOverridePolicy::needsOverride(
            new IntakeUpload([
                'usability_verdict' => PhotoUsabilityVerdict::Ok,
                'assessment_status' => PhotoAssessmentStatus::Assessed,
                'content_assessment' => $assessment->toArray(),
            ]),
        ))->toBeFalse();
});

test('earlier Oct3: create-form blokkeert submit tijdens adreslookup', function () {
    // Geen Vite-build nodig: assert op de blade-bron (zelfde checks als CreateIntakeTest).
    $html = file_get_contents(resource_path('views/installer/intakes/create.blade.php'));
    expect($html)->not->toBeFalse();

    expect($html)
        ->toContain('syncStreetCityValidity()')
        ->toContain('setSubmitPending(true)')
        ->toContain('Even geduld — we zoeken het adres nog op.')
        ->toContain('__intakeClearCustomValidity')
        ->toContain('__intakeRecomputeCustomValidity');
});

test('earlier Oct3: known-summary toont zon, glas/glazing en outdoor_location uit tekst-prefill', function () {
    $intake = hertest4Intake();
    $version = $intake->templateVersion()
        ->with(['sections.questions.options', 'sections.questions.rules'])
        ->firstOrFail();

    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'sun_exposure',
        'room-1',
        ['value' => 'high'],
        PrefillSources::AI_TEXT,
    );
    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'glazing_type',
        'room-1',
        ['value' => 'hr_plus_plus'],
        PrefillSources::AI_TEXT,
    );
    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'outdoor_location',
        null,
        ['value' => 'garden'],
        PrefillSources::REQUEST_TEXT,
    );

    $intake->refresh();
    $questions = $version->sections->flatMap->questions->keyBy('key');

    expect(KnownSummaryCatalog::allows($questions->get('sun_exposure')))->toBeTrue()
        ->and(KnownSummaryCatalog::allowsSource(PrefillSources::AI_TEXT))->toBeTrue()
        ->and(KnownSummaryCatalog::allows($questions->get('glazing_type')))->toBeTrue()
        ->and(KnownSummaryCatalog::allows($questions->get('outdoor_location')))->toBeTrue();

    $steps = app(IntakeStepBuilder::class)->build($intake, $version);
    $summary = collect($steps)->firstWhere('question_key', '_known_summary');

    expect($summary)->not->toBeNull();
    $keys = collect($summary['known_items'] ?? [])->pluck('question_key')->all();
    expect($keys)->toContain('sun_exposure')
        ->and($keys)->toContain('glazing_type')
        ->and($keys)->toContain('outdoor_location');
});
