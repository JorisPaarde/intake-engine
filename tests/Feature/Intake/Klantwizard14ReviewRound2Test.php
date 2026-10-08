<?php

declare(strict_types=1);

use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Support\CustomerAnswerBlocks;
use App\Enums\ContributionMode;
use App\Enums\IntakeStatus;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

function makeReview14R2Intake(): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::InProgress,
        'workflow_mode' => ContributionMode::Customer,
        'customer_name' => 'Review Round2',
        'customer_email' => 'review14r2@example.com',
        'access_token' => str_repeat('s', 64),
        'token_expires_at' => now()->addDays(14),
    ]);
}

test('R2-1: onvertrouwde m² toont nog controleren in werkplek en overzicht', function () {
    $user = User::factory()->create();
    $intake = makeReview14R2Intake();
    $intake->update(['created_by' => $user->id, 'company_id' => $user->company_id]);

    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom']);
    app(DossierManager::class)->initialize($intake->fresh());

    $room = AircoRoom::query()->where('intake_id', $intake->id)->where('key', 'room-1')->firstOrFail();
    $room->update([
        'dimensions' => [
            'area_m2' => 14.0,
            'area_source' => 'request_text',
            'area_confidence' => 'medium',
        ],
    ]);

    $caption = CustomerAnswerBlocks::roomDimensionsCaption($room->fresh()->dimensions);
    expect($caption)->toContain('nog controleren')
        ->and($caption)->toContain('14 m²')
        ->and($caption)->not->toMatch('/^14 m²$/');

    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('14 m² — nog controleren')
        ->assertDontSee('14 m² · van klant', false);

    $this->actingAs($user)
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee('14 m² — nog controleren')
        ->assertDontSeeText('14 m² ·');
});

test('R2-1: vloerconflict in overzicht en werkplek via dezelfde helper', function () {
    $user = User::factory()->create();
    $intake = makeReview14R2Intake();
    $intake->update(['created_by' => $user->id, 'company_id' => $user->company_id]);

    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom']);
    app(DossierManager::class)->initialize($intake->fresh());

    $room = AircoRoom::query()->where('intake_id', $intake->id)->where('key', 'room-1')->firstOrFail();
    $room->update([
        'dimensions' => [
            'length_m' => 5.0,
            'width_m' => 4.0,
            'area_m2' => 10.0, // conflict met 20 m²
            'dimensions_source' => 'customer',
        ],
    ]);

    $caption = CustomerAnswerBlocks::roomDimensionsCaption($room->fresh()->dimensions);
    expect($caption)->toBe('Controleer maten: L×B en m² komen niet overeen');

    $this->actingAs($user)
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee('Controleer maten: L×B en m² komen niet overeen');

    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('Controleer maten: L×B en m² komen niet overeen');
});

test('R2-1: hoogte krijgt · tussen m² en H', function () {
    $caption = CustomerAnswerBlocks::roomDimensionsCaption([
        'length_m' => 3.5,
        'width_m' => 3.0,
        'height_m' => 2.6,
        'dimensions_source' => 'customer',
    ]);

    expect($caption)->toContain('(10,5 m²) · H 2,6 m')
        ->and($caption)->toContain('van klant');
});

test('R2-2: alleen van klant; installer en AI zonder bronlabel', function () {
    $customer = CustomerAnswerBlocks::roomDimensionsCaption([
        'length_m' => 3.5,
        'width_m' => 3.0,
        'dimensions_source' => 'customer',
    ]);
    expect($customer)->toContain('van klant');

    $installer = CustomerAnswerBlocks::roomDimensionsCaption([
        'length_m' => 4.0,
        'width_m' => 3.0,
        'dimensions_source' => 'installer',
    ]);
    expect($installer)->not->toContain('van klant')
        ->and($installer)->not->toContain('van installateur')
        ->and($installer)->toContain('4 × 3 m');

    $legacyArea = CustomerAnswerBlocks::roomDimensionsCaption([
        'area_m2' => 12.0,
        'area_source' => 'installer',
        'area_confidence' => 'high',
    ]);
    expect($legacyArea)->not->toContain('van installateur')
        ->and($legacyArea)->not->toContain('van klant')
        ->and($legacyArea)->toContain('12 m²');

    $ai = CustomerAnswerBlocks::roomDimensionsCaption([
        'length_m' => 3.5,
        'width_m' => 3.0,
        'dimensions_source' => 'ai_text',
    ]);
    expect($ai)->not->toContain('van klant')
        ->and($ai)->not->toContain('van installateur');
});

test('R2-3: antwoorden gegroepeerd per sectietitel in templatesorteervolgorde', function () {
    $intake = makeReview14R2Intake();

    app(SaveIntakeAnswer::class)->handle($intake, 'ownership', null, ['value' => 'owned']);
    app(SaveIntakeAnswer::class)->handle($intake, 'drain_location', null, ['value' => 'outside_nearby']);
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom']);
    app(SaveIntakeAnswer::class)->handle($intake, 'preferred_indoor_location', 'room-1', ['text' => 'Bij het raam']);

    app(DossierManager::class)->initialize($intake->fresh());

    $blocks = CustomerAnswerBlocks::forIntake($intake->fresh());
    $headings = array_column($blocks, 'heading');

    expect($headings)->not->toContain('Algemeen');

    $version = $intake->fresh()->templateVersion()->with(['sections.questions'])->firstOrFail();
    $sectionTitles = $version->sections->sortBy('sort_order')->pluck('title')->filter()->values()->all();

    // Elke heading is een sectietitel of een ruimtenaam.
    $roomNames = $intake->fresh()->aircoRooms->pluck('name')->all();
    foreach ($headings as $heading) {
        expect(in_array($heading, $sectionTitles, true) || in_array($heading, $roomNames, true))->toBeTrue();
    }

    // Sectieblokken volgen templatesorteervolgorde (niet omgekeerd t.o.v. section sort_order).
    $sectionHeadingIndexes = [];
    foreach ($headings as $index => $heading) {
        if (in_array($heading, $sectionTitles, true)) {
            $sectionHeadingIndexes[] = array_search($heading, $sectionTitles, true);
        }
    }
    $sorted = $sectionHeadingIndexes;
    sort($sorted);
    expect($sectionHeadingIndexes)->toBe($sorted);
});

test('R2-4: reload prefereert drain_photo-composite op drain_nearby-groep', function () {
    $intake = makeReview14R2Intake();
    Storage::fake((string) config('filesystems.media', 'local'));
    Queue::fake([AssessUploadedPhotoJob::class]);

    $version = $intake->fresh()->templateVersion()->with(['sections.questions.rules'])->firstOrFail();
    $drainStep = collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version))
        ->firstWhere('group_key', 'drain_nearby');
    expect($drainStep)->not->toBeNull();

    // Cursor op afvoergroep vóór mount → recoverUnassessedUploads ziet de groepstap.
    $intake->update([
        'current_section_key' => $drainStep['section_key'],
        'current_question_key' => $drainStep['question_key'],
        'current_section_instance_key' => $drainStep['section_instance_key'],
    ]);

    // Outdoor eerst → zonder photoQuestionForStep-voorkeur zou outdoor winnen.
    app(StoreIntakeUpload::class)->handle(
        $intake,
        'outdoor_location_photos',
        null,
        UploadedFile::fake()->image('outdoor-pending.jpg', 1200, 900),
    );
    app(StoreIntakeUpload::class)->handle(
        $intake,
        'drain_photo',
        null,
        UploadedFile::fake()->image('drain-pending.jpg', 1210, 910),
    );

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertSet('uploadPhaseComposite', 'drain_photo');
});
