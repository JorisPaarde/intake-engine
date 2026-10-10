<?php

declare(strict_types=1);

use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\ApplyFollowUpTextContribution;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\DossierRecord;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\ContextualCustomerTaskBuilder;
use App\Domains\Intake\Services\DecisionReadinessService;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Support\FollowUpContributionPresenter;
use App\Domains\Intake\Support\FollowUpEvidenceReview;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\ContributionMode;
use App\Enums\DecisionAreaStatus;
use App\Enums\DossierNextAction;
use App\Enums\DossierRecordKind;
use App\Enums\DossierRecordStatus;
use App\Enums\FollowUpItemType;
use App\Enums\FollowUpRoundStatus;
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
        'ai.text_inference.enabled' => true,
        'ai.dossier.enabled' => false,
    ]);
});

afterEach(function () {
    FakeAiClient::reset();
});

function b1Fixture(string $name): UploadedFile
{
    // Map multi-megapixel fixtures to compact flow variants (≥640px) — same as #156.
    $mapped = match ($name) {
        'meterkast-groot.jpg' => 'meterkast-flow.jpg',
        'buitenunit-leiding.jpeg' => 'buitenunit-flow.jpeg',
        default => $name,
    };
    $path = base_path('tests/fixtures/klanttest-20261002/'.$mapped);
    expect(is_file($path))->toBeTrue("Fixture ontbreekt: {$name}");

    return UploadedFile::fake()->createWithContent($name, (string) file_get_contents($path));
}

function b1CreateIntake(User $user, array $overrides = []): Intake
{
    return app(CreateIntake::class)->handle($user, array_merge([
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'B1 Fixture',
        'customer_email' => 'b1@example.com',
        'address_line' => 'Bewijsstraat 99',
        'address_postal_code' => '1000AA',
        'address_house_number' => 99,
        'address_city' => 'Amsterdam',
    ], $overrides));
}

function b1SeedAtticRoom(Intake $intake, array $dimensions = ['area_m2' => 15.0, 'area_source' => 'request', 'area_confidence' => 'high']): AircoRoom
{
    app(DossierManager::class)->initialize($intake);
    $root = app(DossierManager::class)->root($intake);
    $subject = app(DossierManager::class)->subject(
        $intake,
        'airco.room.attic-b1',
        'airco_room',
        'Zolder 1',
        $root,
        ['use_type' => 'attic'],
    );

    return AircoRoom::query()->create([
        'intake_id' => $intake->id,
        'company_id' => $intake->company_id,
        'dossier_subject_id' => $subject->id,
        'key' => 'manual-attic-b1',
        'name' => 'Zolder 1',
        'use_type' => 'attic',
        'use_type_source' => 'installer',
        'sort_order' => 1,
        'status' => 'candidate',
        'source_type' => 'installer',
        'dimensions' => $dimensions,
    ]);
}

function b1MarkUploadOk(IntakeUpload $upload, PhotoSubject $subject = PhotoSubject::OutdoorLocation): void
{
    $upload->update([
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'content_assessment' => PhotoContentAssessment::ok($subject)->toArray(),
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
    ]);
}

function b1MarkUploadWrong(IntakeUpload $upload, PhotoSubject $expected, PhotoSubject $detected): void
{
    $upload->update([
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'content_assessment' => PhotoContentAssessment::wrongSubject($expected, $detected)->toArray(),
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
    ]);
}

test('P1 intake99: juiste gevel verwijdert identieke rondom-huis-vraag; verkeerde foto blijft historie', function () {
    Queue::fake([AssessUploadedPhotoJob::class]);

    $user = User::factory()->create();
    $intake = b1CreateIntake($user);
    app(DossierManager::class)->initialize($intake);

    $areas = app(DecisionReadinessService::class)->recalculate($intake->fresh());
    $placement = $areas->firstWhere('key', 'placement');
    expect($placement->blocker)->toContain('rondom')
        ->and($placement->next_action)->toBe(DossierNextAction::RequestContribution);

    $draft = app(ContextualCustomerTaskBuilder::class)->forDecisionArea($intake->fresh(), $placement);
    expect($draft)->not->toBeNull()
        ->and($draft['dossier_subject_id'])->not->toBeNull()
        ->and($draft['decision_area_key'])->toBe('placement');

    // Round 1: wrong indoor photo (history).
    $round1 = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [[
        'type' => FollowUpItemType::Photo,
        'prompt' => $draft['prompt'],
        'decision_area_key' => 'placement',
        'dossier_subject_id' => $draft['dossier_subject_id'],
    ]]);
    $item1 = $round1->items()->firstOrFail();
    $intake->refresh();

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $component->set('followUpPhotoFiles.'.$item1->id, b1Fixture('woonkamer-720.jpg'));
    $wrong = $item1->fresh()->uploads()->latest('id')->firstOrFail();
    b1MarkUploadWrong($wrong, PhotoSubject::OutdoorLocation, PhotoSubject::Room);
    $component->call('acceptFollowUpPhotoMismatch')->call('completeFollowUp')->assertSet('completed', true);

    // Still asks for around-house (wrong photo does not solve).
    $areas = app(DecisionReadinessService::class)->recalculate($intake->fresh());
    $placement = $areas->firstWhere('key', 'placement');
    expect($placement->blocker)->toContain('rondom');

    // Round 2: correct facade.
    $intake->update(['status' => IntakeStatus::InProgress, 'customer_access_enabled' => true]);
    app(DossierManager::class)->initialize($intake->fresh());
    $round2 = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [[
        'type' => FollowUpItemType::Photo,
        'prompt' => $draft['prompt'],
        'decision_area_key' => 'placement',
        'dossier_subject_id' => $draft['dossier_subject_id'],
    ]]);
    $item2 = $round2->items()->firstOrFail();
    $intake->refresh();

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $component->set('followUpPhotoFiles.'.$item2->id, b1Fixture('gevel-extra.jpg'));
    $good = $item2->fresh()->uploads()->latest('id')->firstOrFail();
    b1MarkUploadOk($good, PhotoSubject::OutdoorLocation);
    $component->call('completeFollowUp')->assertSet('completed', true);

    $intake = $intake->fresh()->load(['followUpRounds.items.uploads', 'contributionTasks']);
    $areas = app(DecisionReadinessService::class)->recalculate($intake);
    $placement = $areas->firstWhere('key', 'placement');

    expect($placement->blocker ?? '')->not->toContain('rondom het huis')
        ->and($placement->blocker ?? '')->not->toContain('Voeg foto');

    $review = app(FollowUpEvidenceReview::class)->present($intake, $intake->followUpRounds);
    $round1Uploads = $review['rounds'][0]['items'][0]['uploads'];
    $round2Uploads = $review['rounds'][1]['items'][0]['uploads'];

    expect($round1Uploads[0]['superseded'])->toBeTrue()
        ->and($round2Uploads[0]['superseded'])->toBeFalse()
        ->and($round2Uploads[0]['assessment']?->status())->toBe(PhotoContentAssessment::STATUS_OK);

    // Received evidence linked as dossier record on the subject.
    $record = DossierRecord::query()
        ->where('intake_id', $intake->id)
        ->where('source_type', 'intake_follow_up_item')
        ->where('source_id', $item2->id)
        ->first();
    expect($record)->not->toBeNull()
        ->and($record->key)->toBe('customer_contribution.'.$item2->id)
        ->and($record->dossier_subject_id)->toBe($draft['dossier_subject_id']);
});

test('P1 intake100: hoogteantwoord verschijnt bij zolder als voorstel; geen blind hoogste punt; geen identieke heruitvraag', function () {
    $user = User::factory()->create();
    $intake = b1CreateIntake($user, [
        'customer_name' => 'Hoogte Fixture',
        'customer_email' => 'hoogte@example.com',
        'address_house_number' => 100,
    ]);
    $room = b1SeedAtticRoom($intake);

    $draft = app(ContextualCustomerTaskBuilder::class)->forRoomWithIntake($intake->fresh(), $room);
    expect($draft)->not->toBeNull()
        ->and($draft['prompt'])->toContain('hoogste punt')
        ->and($draft['prompt'])->toContain('knieschotten')
        ->and($draft['meta']['requested_field'] ?? null)->toBe('height_m');

    $round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [[
        'type' => FollowUpItemType::Text,
        'prompt' => $draft['prompt'],
        'decision_area_key' => 'capacity',
        'dossier_subject_id' => $room->dossier_subject_id,
        'meta' => $draft['meta'] ?? [],
    ]]);
    $item = $round->items()->firstOrFail();
    $intake->refresh();

    $answer = 'hoogste punt 2,6m, knieschotten 1,2m, schuin dak';
    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('followUpResponses.'.$item->id, $answer)
        ->call('completeFollowUp')
        ->assertSet('completed', true);

    $intake = $intake->fresh()->load(['aircoRooms', 'contributionTasks']);
    $room = $room->fresh();

    // Effective height_m must NOT become 2.6 automatically.
    expect($room->dimensions['height_m'] ?? null)->toBeNull();

    $proposal = DossierRecord::query()
        ->where('intake_id', $intake->id)
        ->where('key', ApplyFollowUpTextContribution::RECORD_KEY_HEIGHT)
        ->where('status', DossierRecordStatus::Proposed)
        ->sole();

    expect($proposal->dossier_subject_id)->toBe($room->dossier_subject_id)
        ->and($proposal->value['text'])->toBe($answer)
        ->and($proposal->value['applied_as_average_height'])->toBeFalse()
        ->and((float) $proposal->value['peak_height_m'])->toBe(2.6)
        ->and((float) $proposal->value['knee_wall_height_m'])->toBe(1.2)
        ->and($proposal->value['mentions_sloped_roof'])->toBeTrue();

    $areas = app(DecisionReadinessService::class)->recalculate($intake);
    $capacity = $areas->firstWhere('key', 'capacity');
    expect($capacity->status)->toBe(DecisionAreaStatus::Review)
        ->and($capacity->blocker)->toContain('Beoordeel')
        ->and($capacity->blocker)->toContain('hoogste punt niet blind')
        ->and($capacity->next_action)->not->toBe(DossierNextAction::RequestContribution);

    // Identical height ask suppressed while proposal pending.
    expect(app(ContextualCustomerTaskBuilder::class)->forRoomWithIntake($intake, $room))->toBeNull();

    $html = $this->actingAs($user)->get(route('intakes.workspace', $intake))->assertOk()->getContent();
    expect($html)->toContain($answer)
        ->and($html)->toContain('Nieuwe aanvulling')
        ->and($html)->toContain('data-testid="new-contribution-banner"');
});

test('P2: nieuwe aanvulling ontvangen toont bewijs + AI-feiten + wat installateur beslist bij onderdeel', function () {
    Queue::fake([AssessUploadedPhotoJob::class]);

    $user = User::factory()->create();
    $intake = b1CreateIntake($user, ['address_house_number' => 98]);
    app(DossierManager::class)->initialize($intake);

    $round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [[
        'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een duidelijke foto van de meterkast.',
        'decision_area_key' => 'power',
        'dossier_subject_id' => app(DossierManager::class)->root($intake)->id,
    ]]);
    $item = $round->items()->firstOrFail();
    $intake->refresh();

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $component->set('followUpPhotoFiles.'.$item->id, b1Fixture('meterkast-groot.jpg'));
    $upload = $item->fresh()->uploads()->latest('id')->firstOrFail();
    b1MarkUploadOk($upload, PhotoSubject::Fusebox);
    $component->call('completeFollowUp')->assertSet('completed', true);

    $presented = app(FollowUpContributionPresenter::class)->present($intake->fresh());
    expect($presented['has_new'])->toBeTrue()
        ->and($presented['banner_label'])->toBe('Nieuwe aanvulling ontvangen')
        ->and($presented['items'])->toHaveCount(1)
        ->and($presented['items'][0]['installer_decides'])->toContain('Jij beslist')
        ->and($presented['items'][0]['ai_facts'])->not->toBeEmpty();

    $html = $this->actingAs($user)->get(route('intakes.workspace', $intake->fresh()))->assertOk()->getContent();
    expect($html)->toContain('Nieuwe aanvulling ontvangen')
        ->and($html)->toContain('data-testid="new-contribution-banner"')
        ->and($html)->toContain('data-testid="contribution-review-action"')
        ->and($html)->toContain('data-testid="area-contribution-power"');

    // Gallery captions no longer repeat the full task prompt twice.
    expect(substr_count($html, 'Maak een duidelijke foto van de meterkast.'))->toBeLessThanOrEqual(1);
});

test('P2: oude foto-mismatch is superseded en niet dominant na juiste aanvulling', function () {
    Queue::fake([AssessUploadedPhotoJob::class]);

    $user = User::factory()->create();
    $intake = b1CreateIntake($user);
    app(DossierManager::class)->initialize($intake);

    $round1 = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [[
        'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een duidelijke foto van de meterkast.',
        'decision_area_key' => 'power',
    ]]);
    $item1 = $round1->items()->firstOrFail();
    $intake->refresh();

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $component->set('followUpPhotoFiles.'.$item1->id, b1Fixture('buitenunit-leiding.jpeg'));
    $wrong = $item1->fresh()->uploads()->latest('id')->firstOrFail();
    b1MarkUploadWrong($wrong, PhotoSubject::Fusebox, PhotoSubject::OutdoorUnit);
    $component->call('acceptFollowUpPhotoMismatch')->call('completeFollowUp');

    $intake->update(['status' => IntakeStatus::InProgress, 'customer_access_enabled' => true]);
    app(DossierManager::class)->initialize($intake->fresh());

    $round2 = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [[
        'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een nieuwe, duidelijke foto van je meterkast',
        'decision_area_key' => 'power',
    ]]);
    $item2 = $round2->items()->firstOrFail();
    $intake->refresh();

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $component->set('followUpPhotoFiles.'.$item2->id, b1Fixture('meterkast-groot.jpg'));
    $good = $item2->fresh()->uploads()->latest('id')->firstOrFail();
    b1MarkUploadOk($good, PhotoSubject::Fusebox);
    $component->call('completeFollowUp');

    $intake = $intake->fresh()->load(['followUpRounds.items.uploads', 'contributionTasks']);
    $review = app(FollowUpEvidenceReview::class)->present($intake, $intake->followUpRounds);
    $old = $review['rounds'][0]['items'][0]['uploads'][0];
    $new = $review['rounds'][1]['items'][0]['uploads'][0];

    expect($old['superseded'])->toBeTrue()
        ->and($old['installer_label'])->toBeNull()
        ->and($new['superseded'])->toBeFalse();

    $power = app(DecisionReadinessService::class)->recalculate($intake)->firstWhere('key', 'power');
    expect($power->blocker ?? '')->not->toContain('handmatig controleren')
        ->and($power->blocker ?? '')->not->toContain('Ontvangen foto lijkt');
});

test('P3: oude AI-maten worden superseded na installateurscorrectie 6→6,2', function () {
    $user = User::factory()->create();
    $intake = b1CreateIntake($user);
    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'room_length_m',
        'room-1',
        ['number' => 6],
        PrefillSources::AI_TEXT,
    );
    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'room_width_m',
        'room-1',
        ['number' => 4],
        PrefillSources::AI_TEXT,
    );
    app(DossierManager::class)->initialize($intake->fresh());

    $room = $intake->fresh()->aircoRooms->first(
        static fn (AircoRoom $candidate): bool => str_contains((string) $candidate->key, 'room')
            || ($candidate->dimensions['length_m'] ?? null) == 6,
    );
    if ($room === null) {
        $room = b1SeedAtticRoom($intake, ['length_m' => 6, 'width_m' => 4]);
        // Also create AI proposal records on that subject.
        DossierRecord::query()->create([
            'intake_id' => $intake->id,
            'company_id' => $intake->company_id,
            'dossier_subject_id' => $room->dossier_subject_id,
            'kind' => DossierRecordKind::Observation,
            'key' => 'answer.room-1.room_length_m',
            'value' => ['number' => 6, '_field_label' => 'Lengte (m)', '_display_value' => '6'],
            'actor_type' => PrefillSources::AI_TEXT,
            'source_type' => 'intake_answer',
            'source_id' => 1,
            'method' => 'ai_proposal',
            'confidence' => 0.9,
            'status' => DossierRecordStatus::Proposed,
            'observed_at' => now(),
        ]);
    }

    $aiLength = DossierRecord::query()
        ->where('intake_id', $intake->id)
        ->where('dossier_subject_id', $room->dossier_subject_id)
        ->where('key', 'like', '%room_length_m')
        ->whereIn('status', [DossierRecordStatus::Proposed, DossierRecordStatus::Established])
        ->first();

    expect($aiLength)->not->toBeNull();

    app(AircoSurveyService::class)->updateRoom($intake->fresh(), $user, $room->fresh(), [
        'name' => $room->name,
        'use_type' => $room->use_type,
        'length_m' => 6.2,
        'width_m' => 4.0,
        'height_m' => $room->dimensions['height_m'] ?? null,
        'area_m2' => null,
    ]);

    $aiLength = $aiLength->fresh();
    expect($aiLength->status)->toBe(DossierRecordStatus::Superseded)
        ->and($aiLength->superseded_by_id)->not->toBeNull();

    $effective = DossierRecord::query()
        ->where('intake_id', $intake->id)
        ->where('dossier_subject_id', $room->dossier_subject_id)
        ->where('key', 'dimensions.length_m')
        ->where('status', DossierRecordStatus::Established)
        ->whereNull('superseded_by_id')
        ->sole();

    expect((float) $effective->value['number'])->toBe(6.2);

    // Legacy bridge must not revive the superseded AI proposal.
    app(DossierManager::class)->initialize($intake->fresh());
    expect($aiLength->fresh()->status)->toBe(DossierRecordStatus::Superseded)
        ->and($aiLength->fresh()->superseded_by_id)->not->toBeNull();
});

test('P3: customer_contribution keys blijven uniek per follow-up item (geen dubbele .N)', function () {
    $user = User::factory()->create();
    $intake = b1CreateIntake($user);
    app(DossierManager::class)->initialize($intake);
    $rootId = app(DossierManager::class)->root($intake)->id;

    $round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [
        [
            'type' => FollowUpItemType::Text,
            'prompt' => 'Noteer de hoogte van Zolder 1.',
            'decision_area_key' => 'capacity',
            'dossier_subject_id' => $rootId,
        ],
        [
            'type' => FollowUpItemType::Text,
            'prompt' => 'Noteer iets over de tweede ruimte.',
            'decision_area_key' => 'capacity',
            'dossier_subject_id' => $rootId,
        ],
    ]);
    $items = $round->items()->orderBy('id')->get();
    expect($items)->toHaveCount(2);

    foreach ($items as $index => $item) {
        $item->update([
            'response_text' => 'Antwoord '.($index + 1),
            'answered_at' => now(),
        ]);
    }
    $round->update(['status' => FollowUpRoundStatus::Completed, 'completed_at' => now()]);
    $intake->update(['status' => IntakeStatus::InProgress, 'customer_access_enabled' => false]);

    app(DossierManager::class)->initialize($intake->fresh());

    $keys = DossierRecord::query()
        ->where('intake_id', $intake->id)
        ->where('key', 'like', 'customer_contribution.%')
        ->whereIn('status', [DossierRecordStatus::Established, DossierRecordStatus::Proposed])
        ->pluck('key')
        ->all();

    expect($keys)->toHaveCount(2)
        ->and($keys)->toContain('customer_contribution.'.$items[0]->id)
        ->and($keys)->toContain('customer_contribution.'.$items[1]->id)
        ->and(count(array_unique($keys)))->toBe(2);

    // Simulate accidental duplicate key on same subject → dedupe on next sync.
    DossierRecord::query()->create([
        'intake_id' => $intake->id,
        'company_id' => $intake->company_id,
        'dossier_subject_id' => $rootId,
        'kind' => DossierRecordKind::Observation,
        'key' => 'customer_contribution.'.$items[0]->id,
        'value' => ['prompt' => 'dup', 'response_text' => 'dup'],
        'actor_type' => 'customer',
        'source_type' => 'manual_duplicate',
        'source_id' => 99999,
        'method' => 'targeted_customer_task',
        'confidence' => 1.0,
        'status' => DossierRecordStatus::Established,
        'observed_at' => now(),
    ]);

    app(DossierManager::class)->initialize($intake->fresh());

    $activeSameKey = DossierRecord::query()
        ->where('intake_id', $intake->id)
        ->where('key', 'customer_contribution.'.$items[0]->id)
        ->whereIn('status', [DossierRecordStatus::Established, DossierRecordStatus::Proposed])
        ->whereNull('superseded_by_id')
        ->count();

    expect($activeSameKey)->toBe(1);
});
