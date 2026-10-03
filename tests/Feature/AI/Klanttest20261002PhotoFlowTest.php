<?php

declare(strict_types=1);

use App\Domains\AI\Actions\AssessFuseboxPhotos;
use App\Domains\AI\Actions\DerivePhotoAnswers;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoDerivationProfile;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeExternalFact;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\CompletenessChecker;
use App\Domains\Intake\Services\DecisionReadinessService;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\ExternalFactPresenter;
use App\Domains\Intake\Services\FollowUpProgressCalculator;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Services\ProgressCalculator;
use App\Domains\Intake\Services\WorkspacePrimaryActionResolver;
use App\Domains\Intake\Support\InternalCustomerQuestions;
use App\Domains\Intake\Support\TechnicalDecisionKeys;
use App\Enums\DecisionAreaStatus;
use App\Enums\FollowUpItemType;
use App\Enums\IntakeStatus;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
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

function klanttestFixture(string $name): UploadedFile
{
    $path = base_path('tests/fixtures/klanttest-20261002/'.$name);

    expect(is_file($path))->toBeTrue("Fixture ontbreekt: {$name}");

    return new UploadedFile($path, $name, mime_content_type($path) ?: 'image/jpeg', null, true);
}

/** Livewire-compatible upload built from the same fixture bytes. */
function klanttestLivewireUpload(string $name): UploadedFile
{
    $path = base_path('tests/fixtures/klanttest-20261002/'.$name);

    expect(is_file($path))->toBeTrue("Fixture ontbreekt: {$name}");

    return UploadedFile::fake()->createWithContent($name, (string) file_get_contents($path));
}

function makeKlanttestIntake(array $overrides = []): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create(array_merge([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_name' => 'Klanttest Fixture',
        'customer_email' => 'klanttest@example.com',
        'access_token' => 'klanttest'.str_repeat('a', 56),
    ], $overrides));
}

test('P1 case 80: buitenunitfoto leidt nooit tot zeker geen doorboring', function () {
    $intake = makeKlanttestIntake();

    FakeAiClient::alwaysReturn([
        'pipe_route_description' => 'along_facade',
        'pipe_distance_indication' => 'short',
        'drillings_needed' => 'no',
        'detected_subject' => 'outdoor_unit',
        'subject_match' => 'yes',
        'confidence' => 'high',
        'evidence' => 'Zichtbare leiding langs bakstenen gevel bij bestaande buitenunit; geen doorboring zichtbaar.',
        'retake_instruction' => null,
    ]);

    app(StoreIntakeUpload::class)->handle(
        $intake,
        'pipe_route_photos',
        null,
        klanttestFixture('buitenunit-leiding.jpeg'),
    );

    app(DerivePhotoAnswers::class)->handle(
        $intake,
        'pipe_route_photos',
        null,
        PhotoDerivationProfile::require('pipe_route'),
    );

    expect($intake->answers()->where('question_key', 'drillings_needed')->exists())->toBeFalse()
        ->and($intake->answers()->where('question_key', 'pipe_route_description')->exists())->toBeFalse();

    $fact = $intake->externalFacts()->where('fact_key', 'pipe_route_photos_derivation')->firstOrFail();

    expect($fact->value['drillings_needed'])->toBe('unknown')
        ->and($fact->value['drillings_proposal_note'])->toBe('geen bewijs voor doorboring zichtbaar')
        ->and($fact->confidence)->toBe('low')
        ->and($fact->value)->toHaveKey('uncertainty_note')
        ->and($fact->value['proposal_fields'])->toContain('drillings_needed');

    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    $stepKeys = collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version))->pluck('question_key');

    // Na TechnicalDecisionKeys horen routevelden niet in de klantflow.
    expect($stepKeys)->not->toContain('drillings_needed')
        ->and($stepKeys)->not->toContain('pipe_route_description')
        ->and(TechnicalDecisionKeys::isRouteProposal('drillings_needed'))->toBeTrue();
});

test('P1 case 80: fusebox_clarity verschijnt nooit als klantvraag na fotostatus-wijziging', function () {
    $intake = makeKlanttestIntake();

    FakeAiClient::alwaysReturn([
        'free_group' => 'unknown',
        'phase' => 'unknown',
        'detected_subject' => 'fusebox',
        'subject_match' => 'yes',
        'confidence' => 'low',
        'evidence' => 'Te kleine meterkastfoto.',
        'retake_instruction' => 'Maak een scherpere foto dichterbij.',
    ]);

    app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        klanttestFixture('meterkast-klein.jpg'),
    );
    app(AssessFuseboxPhotos::class)->handle($intake);

    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    $stepsAfterSmall = collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version));

    expect($stepsAfterSmall->pluck('question_key'))->not->toContain('fusebox_clarity')
        ->and(InternalCustomerQuestions::isInternalKey('fusebox_clarity'))->toBeTrue()
        ->and($stepsAfterSmall->pluck('question_key'))->toContain('fusebox_photo_extra');

    $intake->answers()->where('question_key', 'fusebox_clarity')->delete();
    $intake->unsetRelation('answers');

    expect(collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version))->pluck('question_key'))
        ->not->toContain('fusebox_clarity');

    FakeAiClient::alwaysReturn([
        'free_group' => 'yes',
        'phase' => 'three_phase',
        'detected_subject' => 'fusebox',
        'subject_match' => 'yes',
        'confidence' => 'high',
        'evidence' => 'Scherpe meterkastfoto met leesbare groepen.',
        'retake_instruction' => null,
    ]);

    app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo_extra',
        null,
        klanttestFixture('meterkast-groot.jpg'),
    );
    app(AssessFuseboxPhotos::class)->handle($intake->fresh());

    $stepsAfterLarge = collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version));

    expect($stepsAfterLarge->pluck('question_key'))
        ->not->toContain('fusebox_clarity')
        ->not->toContain('fusebox_photo_extra');

    // Extra scherpe upload via Livewire: stap verdwijnt, cursor op eerstvolgende echte taak.
    $intakeForWizard = makeKlanttestIntake([
        'access_token' => 'klanttest'.str_repeat('b', 56),
    ]);

    FakeAiClient::alwaysReturn([
        'free_group' => 'unknown',
        'phase' => 'unknown',
        'detected_subject' => 'fusebox',
        'subject_match' => 'yes',
        'confidence' => 'low',
        'evidence' => 'Te kleine meterkastfoto.',
        'retake_instruction' => 'Maak een scherpere foto dichterbij.',
    ]);

    app(StoreIntakeUpload::class)->handle(
        $intakeForWizard,
        'fusebox_photo',
        null,
        klanttestFixture('meterkast-klein.jpg'),
    );
    app(AssessFuseboxPhotos::class)->handle($intakeForWizard);

    FakeAiClient::alwaysReturn([
        'free_group' => 'yes',
        'phase' => 'three_phase',
        'detected_subject' => 'fusebox',
        'subject_match' => 'yes',
        'confidence' => 'high',
        'evidence' => 'Scherpe meterkastfoto met leesbare groepen.',
        'retake_instruction' => null,
    ]);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intakeForWizard->access_token])
        ->set('activeStepKey', 'electrical::fusebox_photo_extra')
        ->set('photoFiles.fusebox_photo_extra', klanttestLivewireUpload('meterkast-groot.jpg'))
        ->call('assessPendingUploads');

    $wizard = $component->instance();
    $ref = new ReflectionClass($wizard);
    $stepsMethod = $ref->getMethod('steps');
    $stepsMethod->setAccessible(true);
    /** @var list<array{key: string, question_key: string}> $liveSteps */
    $liveSteps = $stepsMethod->invoke($wizard);
    $liveKeys = collect($liveSteps)->pluck('question_key');

    expect($liveKeys)->not->toContain('fusebox_photo_extra')
        ->and($liveKeys)->not->toContain('fusebox_clarity')
        ->and($wizard->activeStepKey)->not->toContain('fusebox_photo_extra')
        ->and($wizard->activeStepKey)->not->toContain('fusebox_clarity')
        ->and($wizard->activeStepKey)->not->toBe('');
});

test('P1 case 81: meterkastfoto als kamerfoto geeft gerichte terugkoppeling zonder herplaatsing', function () {
    $intake = makeKlanttestIntake();

    FakeAiClient::alwaysReturn([
        'room_type' => 'unknown',
        'room_size_indication' => 'unknown',
        'sun_exposure' => 'unknown',
        'glass_amount' => 'unknown',
        'room_outlet_status' => 'needs_photo',
        'detected_subject' => 'fusebox',
        'subject_match' => 'no',
        'confidence' => 'low',
        'evidence' => 'Foto toont een meterkast, geen ruimte.',
        'retake_instruction' => 'Dit is een meterkast; we hebben een foto van de hele ruimte vanuit de deuropening nodig.',
    ]);

    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'indoor_unit_count',
        null,
        ['number' => 1],
    );
    $intake->update([
        'current_section_key' => 'rooms',
        'current_question_key' => 'room_photos',
        'current_section_instance_key' => 'room-1',
    ]);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('photoFiles.room-1__room_photos', klanttestLivewireUpload('meterkast-groot.jpg'))
        ->call('assessPendingUploads');

    $hints = $component->get('photoHint');
    $hintText = is_array($hints) ? implode(' ', array_filter($hints)) : (string) $hints;

    expect($hintText)->toContain('meterkast')
        ->and($hintText)->toContain('ruimte');

    // Verkeerde foto blijft bij de kamer-vraag — geen herplaatsing naar meterkast.
    expect(IntakeUpload::query()
        ->where('intake_id', $intake->id)
        ->where('question_key', 'room_photos')
        ->exists())->toBeTrue()
        ->and(IntakeUpload::query()
            ->where('intake_id', $intake->id)
            ->where('question_key', 'fusebox_photo')
            ->exists())->toBeFalse();

    $upload = IntakeUpload::query()
        ->where('intake_id', $intake->id)
        ->where('question_key', 'room_photos')
        ->firstOrFail();

    expect($upload->contentAssessment()?->status())->toBe(PhotoContentAssessment::STATUS_WRONG_SUBJECT);

    $version = $intake->templateVersion()->with(['sections.questions.rules'])->firstOrFail();
    $progress = app(ProgressCalculator::class)->calculate($intake->fresh(), $version);
    $missingKeys = collect($progress['missing_required'])->pluck('question_key');

    expect($missingKeys)->toContain('room_photos');

    // Toch doorgaan: telt als beantwoord; aandachtspunt pas via CompletenessChecker (bij afronden).
    $component
        ->set('activeStepKey', 'rooms::room-1::room_photos')
        ->call('acceptPhotoMismatch');

    $upload->refresh();
    expect($upload->contentAssessment()?->customerAcceptedMismatch())->toBeTrue()
        ->and($upload->contentAssessment()?->installerLabel())->toContain('meterkast')
        ->and($intake->fresh()->attentionPoints()->where('code', 'photo_subject_mismatch_'.$upload->id)->exists())
        ->toBeFalse();

    $progressAfter = app(ProgressCalculator::class)->calculate($intake->fresh(), $version);
    expect(collect($progressAfter['missing_required'])->pluck('question_key'))
        ->not->toContain('room_photos');

    $check = app(CompletenessChecker::class)->check($intake->fresh(), $version);
    expect(collect($check['attention_points'])->pluck('code'))
        ->toContain('photo_subject_mismatch_'.$upload->id)
        ->and(collect($check['attention_points'])->firstWhere('code', 'photo_subject_mismatch_'.$upload->id)['label'])
        ->toBe('Foto lijkt meterkast, controleer');
});

test('acceptatie blijft na goede foto toevoegen en een foto verwijderen; geen mismatch-banner', function () {
    $intake = makeKlanttestIntake();

    FakeAiClient::alwaysReturn([
        'room_type' => 'unknown',
        'room_size_indication' => 'unknown',
        'sun_exposure' => 'unknown',
        'glass_amount' => 'unknown',
        'room_outlet_status' => 'needs_photo',
        'detected_subject' => 'fusebox',
        'subject_match' => 'no',
        'confidence' => 'low',
        'evidence' => 'Foto toont een meterkast, geen ruimte.',
        'retake_instruction' => null,
    ]);

    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    $intake->update([
        'current_section_key' => 'rooms',
        'current_question_key' => 'room_photos',
        'current_section_instance_key' => 'room-1',
    ]);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('photoFiles.room-1__room_photos', klanttestLivewireUpload('meterkast-groot.jpg'))
        ->call('assessPendingUploads')
        ->set('activeStepKey', 'rooms::room-1::room_photos')
        ->call('acceptPhotoMismatch');

    $wrong = IntakeUpload::query()
        ->where('intake_id', $intake->id)
        ->where('question_key', 'room_photos')
        ->firstOrFail();

    expect($wrong->contentAssessment()?->customerAcceptedMismatch())->toBeTrue();

    FakeAiClient::alwaysReturn([
        'room_type' => 'living_room',
        'room_size_indication' => 'medium',
        'sun_exposure' => 'medium',
        'glass_amount' => 'average',
        'room_outlet_status' => 'present',
        'detected_subject' => 'room',
        'subject_match' => 'yes',
        'confidence' => 'high',
        'evidence' => 'Duidelijke ruimtefoto vanuit de deuropening.',
        'retake_instruction' => null,
    ]);

    $component
        ->set('activeStepKey', 'rooms::room-1::room_photos')
        ->set('photoFiles.room-1__room_photos', klanttestLivewireUpload('buitenunit-leiding.jpeg'))
        ->call('assessPendingUploads');

    $wrong->refresh();
    expect($wrong->contentAssessment()?->customerAcceptedMismatch())->toBeTrue();

    $good = IntakeUpload::query()
        ->where('intake_id', $intake->id)
        ->where('question_key', 'room_photos')
        ->where('id', '!=', $wrong->id)
        ->firstOrFail();

    // Verwijder de goede foto — acceptatie op de verkeerde blijft; geen banner.
    $component
        ->set('activeStepKey', 'rooms::room-1::room_photos')
        ->call('removePhoto', $good->id)
        ->assertDontSee('Toch doorgaan');

    $wrong->refresh();
    expect($wrong->contentAssessment()?->customerAcceptedMismatch())->toBeTrue()
        ->and(IntakeUpload::query()->whereKey($good->id)->exists())->toBeFalse();
});

test('Vervang foto verwijdert wrong_subject-upload; banner verdwijnt na satisfactie', function () {
    $intake = makeKlanttestIntake();

    FakeAiClient::alwaysReturn([
        'room_type' => 'unknown',
        'room_size_indication' => 'unknown',
        'sun_exposure' => 'unknown',
        'glass_amount' => 'unknown',
        'room_outlet_status' => 'needs_photo',
        'detected_subject' => 'fusebox',
        'subject_match' => 'no',
        'confidence' => 'low',
        'evidence' => 'Meterkast i.p.v. ruimte.',
        'retake_instruction' => null,
    ]);

    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    $intake->update([
        'current_section_key' => 'rooms',
        'current_question_key' => 'room_photos',
        'current_section_instance_key' => 'room-1',
    ]);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('photoFiles.room-1__room_photos', klanttestLivewireUpload('meterkast-groot.jpg'))
        ->call('assessPendingUploads')
        ->set('activeStepKey', 'rooms::room-1::room_photos')
        ->assertSee('Vervang foto')
        ->assertSee('Toch doorgaan');

    $wrongId = IntakeUpload::query()
        ->where('intake_id', $intake->id)
        ->where('question_key', 'room_photos')
        ->value('id');

    $component->call('replaceMismatchedPhoto');

    expect(IntakeUpload::query()->whereKey($wrongId)->exists())->toBeFalse();

    FakeAiClient::alwaysReturn([
        'room_type' => 'living_room',
        'room_size_indication' => 'medium',
        'sun_exposure' => 'medium',
        'glass_amount' => 'average',
        'room_outlet_status' => 'present',
        'detected_subject' => 'room',
        'subject_match' => 'yes',
        'confidence' => 'high',
        'evidence' => 'Ruimtefoto ok.',
        'retake_instruction' => null,
    ]);

    $component
        ->set('activeStepKey', 'rooms::room-1::room_photos')
        ->set('photoFiles.room-1__room_photos', klanttestLivewireUpload('buitenunit-leiding.jpeg'))
        ->call('assessPendingUploads')
        ->assertDontSee('Toch doorgaan');
});

test('follow-up refrigerant accepteert outdoor_unit (gevel met leidingen) zonder wrong_subject', function () {
    $user = User::factory()->create();
    $intake = makeKlanttestIntake([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'status' => IntakeStatus::InProgress,
        'access_token' => 'klanttest'.str_repeat('r', 56),
    ]);

    app(DossierManager::class)->initialize($intake);

    $round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [[
        'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een foto van de leidingroute langs de gevel.',
        'decision_area_key' => 'refrigerant',
    ]]);

    $intake->refresh();
    expect($intake->status)->toBe(IntakeStatus::AwaitingCustomer);

    $item = $round->items()->firstOrFail();

    FakeAiClient::alwaysReturn([
        'detected_subject' => 'outdoor_unit',
        'subject_match' => 'no',
        'evidence' => 'Gevel met buitenunit en zichtbare leidingen.',
    ]);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertSet('followUpMode', true)
        ->set('followUpPhotoFiles.'.$item->id, klanttestLivewireUpload('buitenunit-leiding.jpeg'))
        ->call('assessPendingUploads')
        ->assertHasNoErrors('followUpPhotoFiles.'.$item->id);

    $upload = $item->uploads()->first();

    expect($upload)->not->toBeNull()
        ->and($upload->contentAssessment()?->status())->toBe(PhotoContentAssessment::STATUS_OK)
        ->and($upload->contentAssessment()?->detectedSubject()?->value)->toBe('outdoor_unit');
});

test('P1 case 81 Stroomtoevoer: buitenunitfoto waarschuwt maar blokkeert afronden niet', function () {
    $user = User::factory()->create();
    $intake = makeKlanttestIntake([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'status' => IntakeStatus::InProgress,
    ]);

    app(DossierManager::class)->initialize($intake);

    $round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [[
        'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een duidelijke foto van de meterkast voor de stroomtoevoer.',
        'decision_area_key' => 'power',
    ]]);

    $intake->refresh();
    $item = $round->items()->firstOrFail();

    FakeAiClient::alwaysReturn([
        'detected_subject' => 'outdoor_unit',
        'subject_match' => 'no',
        'evidence' => 'Foto toont een buitenunit met leiding, geen meterkast.',
    ]);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertSet('followUpMode', true)
        ->set('followUpPhotoFiles.'.$item->id, klanttestLivewireUpload('buitenunit-leiding.jpeg'))
        ->call('assessPendingUploads')
        ->assertHasErrors('followUpPhotoFiles.'.$item->id)
        ->assertSee('buitenunit')
        ->assertSee('meterkast');

    $item->refresh()->load('uploads');
    $progress = app(FollowUpProgressCalculator::class)
        ->calculate(collect([$item]));

    expect($progress['percent'])->toBe(0)
        ->and($progress['item_statuses'][$item->id]['status'])->toBe('mismatch')
        ->and($progress['item_statuses'][$item->id]['label'])->toBe('Nog te vervangen');

    $component
        ->assertSeeHtml('data-testid="follow-up-progress-percent">0%')
        ->assertSee('Nog te vervangen')
        ->call('completeFollowUp')
        ->assertHasNoErrors('follow_up')
        ->assertSet('completed', true);

    $upload = $item->uploads()->first();

    expect($upload)->not->toBeNull()
        ->and($upload->contentAssessment()?->status())->toBe(PhotoContentAssessment::STATUS_WRONG_SUBJECT)
        ->and($upload->contentAssessment()?->installerLabel())->toContain('AI: lijkt')
        ->and($upload->contentAssessment()?->installerLabel())->toContain('controleer')
        ->and($upload->contentAssessment()?->followUpMismatchReason())
        ->toBe('Ontvangen foto lijkt een buitenunit, geen meterkast — handmatig controleren');

    $areas = app(DecisionReadinessService::class)
        ->recalculate($intake->fresh());
    $power = $areas->firstWhere('key', 'power');

    expect($power)->not->toBeNull()
        ->and($power->status)->toBe(DecisionAreaStatus::Blocked)
        ->and($power->blocker)->toBe(
            'Ontvangen foto lijkt een buitenunit, geen meterkast — handmatig controleren',
        );

    $overview = app(WorkspacePrimaryActionResolver::class)
        ->overviewItem($intake->fresh(), $power);

    expect($overview['is_open'])->toBeTrue()
        ->and($overview['detail'])->toBe(
            'Ontvangen foto lijkt een buitenunit, geen meterkast — handmatig controleren',
        );
});

test('Volgende zonder Toch doorgaan bij wrong_subject toont waarschuwing en blijft staan', function () {
    $intake = makeKlanttestIntake();

    FakeAiClient::alwaysReturn([
        'room_type' => 'unknown',
        'room_size_indication' => 'unknown',
        'sun_exposure' => 'unknown',
        'glass_amount' => 'unknown',
        'room_outlet_status' => 'needs_photo',
        'detected_subject' => 'fusebox',
        'subject_match' => 'no',
        'confidence' => 'low',
        'evidence' => 'Foto toont een meterkast, geen ruimte.',
        'retake_instruction' => 'Dit is een meterkast; we hebben een foto van de hele ruimte vanuit de deuropening nodig.',
    ]);

    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    $intake->update([
        'current_section_key' => 'rooms',
        'current_question_key' => 'room_photos',
        'current_section_instance_key' => 'room-1',
    ]);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('photoFiles.room-1__room_photos', klanttestLivewireUpload('meterkast-groot.jpg'))
        ->call('assessPendingUploads')
        ->set('activeStepKey', 'rooms::room-1::room_photos')
        ->assertSee('Dit is een meterkastfoto')
        ->assertSee('Vervang foto')
        ->assertSee('Toch doorgaan');

    $stepBefore = $component->get('activeStepKey');
    $indexBefore = $component->get('stepIndex');

    $component
        ->call('next')
        ->assertSet('showMissing', true)
        ->assertSet('activeStepKey', $stepBefore)
        ->assertSet('stepIndex', $indexBefore)
        ->assertSee('Vervang de foto of kies expliciet “Toch doorgaan”')
        ->assertSeeHtml('data-testid="mismatch-next-warning"')
        ->assertSeeHtml('data-testid="footer-mismatch-warning"');
});

test('ExternalFactPresenter toont pipe_route-voorstel met bron en onzekerheid', function () {
    $intake = makeKlanttestIntake();

    IntakeExternalFact::query()->create([
        'intake_id' => $intake->id,
        'fact_key' => 'pipe_route_photos_derivation',
        'label' => 'Voorstel leidingroute uit foto',
        'value' => [
            'pipe_route_description' => 'along_facade',
            'pipe_distance_indication' => 'short',
            'drillings_needed' => 'unknown',
            'detected_subject' => 'outdoor_unit',
            'subject_match' => 'yes',
            'confidence' => 'high',
            'evidence' => 'Leiding langs gevel zichtbaar.',
            'upload_ids' => [42],
            'proposal_fields' => TechnicalDecisionKeys::ROUTE_PROPOSAL_KEYS,
            'drillings_proposal_note' => 'geen bewijs voor doorboring zichtbaar',
            'uncertainty_note' => 'Technische route-/doorboringconclusies zijn voorstellen voor de installateur; afwezigheid van een zichtbaar gat bewijst geen “geen doorboring”.',
            'reason' => 'Leiding langs gevel zichtbaar.',
        ],
        'source' => DerivePhotoAnswers::SOURCE,
        'source_reference' => 'ai-run:1',
        'confidence' => 'low',
        'captured_at' => now(),
    ]);

    $presented = app(ExternalFactPresenter::class)->present($intake->fresh());

    expect(collect($presented['facts'])->pluck('label')->all())->toContain('Voorstel leidingroute uit foto')
        ->and(collect($presented['facts'])->firstWhere('label', 'Voorstel leidingroute uit foto')['display'])
        ->toContain('geen bewijs voor doorboring zichtbaar')
        ->and($presented['uncertainties'])->toContain(
            'Technische route-/doorboringconclusies zijn voorstellen voor de installateur; afwezigheid van een zichtbaar gat bewijst geen “geen doorboring”.',
        );
});

test('realignToActiveStep landt op eerstvolgende echte taak wanneer eerdere stappen verdwijnen', function () {
    $intake = makeKlanttestIntake();

    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $wizard = $component->instance();

    $ref = new ReflectionClass($wizard);
    $stepsMethod = $ref->getMethod('steps');
    $stepsMethod->setAccessible(true);
    /** @var list<array{key: string}> $steps */
    $steps = $stepsMethod->invoke($wizard);
    $remaining = array_values(array_map(static fn (array $step): string => $step['key'], $steps));

    expect($remaining)->not->toBeEmpty();

    $realign = $ref->getMethod('realignToActiveStep');
    $realign->setAccessible(true);

    // Vorige lijst had twee ghost-stappen vóór de echte taken; actieve stap was de eerste ghost.
    $component->set('knownStepKeys', array_merge(
        ['ghost::vanished-a', 'ghost::vanished-b'],
        $remaining,
    ));
    $component->set('activeStepKey', 'ghost::vanished-a');
    $component->set('stepIndex', 0);

    $realign->invoke($wizard);

    expect($wizard->activeStepKey)->toBe($remaining[0])
        ->and($wizard->activeStepKey)->not->toStartWith('ghost::');
});
