<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Support\PrefillSources;
use App\Domains\Intake\Support\RoomDimensions;
use App\Enums\IntakeStatus;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

function knownSummaryIntake(): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_email' => 'known-summary@example.com',
    ]);
}

/**
 * @return list<array<string, mixed>>
 */
function knownSummarySteps(Intake $intake): array
{
    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();

    return app(IntakeStepBuilder::class)->build($intake->fresh() ?? $intake, $version);
}

function seedKnownSummaryPrefills(Intake $intake): void
{
    app(SaveIntakeAnswer::class)->handle($intake, 'cooling_heating', null, ['value' => 'cooling'], PrefillSources::AI_TEXT);
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1], PrefillSources::AI_TEXT);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom'], PrefillSources::AI_TEXT);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_length_m', 'room-1', ['number' => 4], PrefillSources::AI_TEXT);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_width_m', 'room-1', ['number' => 3], PrefillSources::AI_TEXT);
    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'brand_preference',
        null,
        ['values' => ['no_preference']],
        PrefillSources::AI_TEXT,
    );
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);
}

test('na request_reason landt Volgende op known-summary', function () {
    $intake = knownSummaryIntake();
    seedKnownSummaryPrefills($intake);

    $steps = knownSummarySteps($intake);
    $reasonIndex = collect($steps)->search(fn (array $s): bool => ($s['question_key'] ?? '') === 'request_reason');
    expect($reasonIndex)->toBeInt();

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', $reasonIndex)
        ->set('form.request_reason.text', 'Ik wil een airco in de slaapkamer koelen.')
        ->call('next');

    $stepsAfter = knownSummarySteps($intake);
    $summaryIndex = collect($stepsAfter)->search(fn (array $s): bool => ($s['kind'] ?? '') === 'known_summary');
    expect($summaryIndex)->toBeInt()
        ->and($component->get('stepIndex'))->toBe($summaryIndex);
});

test('Wijzigen getal: blur houdt bewerkbaar, Volgende terug naar summary', function () {
    $intake = knownSummaryIntake();
    seedKnownSummaryPrefills($intake);

    $steps = knownSummarySteps($intake);
    $summaryIndex = collect($steps)->search(fn (array $s): bool => ($s['kind'] ?? '') === 'known_summary');
    expect($summaryIndex)->toBeInt();

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', $summaryIndex)
        ->call('editKnownAnswer', 'room_length_m', 'room-1');

    expect($component->get('forceShowKnown'))->toContain('room-1__room_length_m');

    $composite = 'room-1__room_length_m';
    $component->set("form.{$composite}.number", '5');

    expect($component->get('forceShowKnown'))->toContain($composite);

    $component->call('next');
    $stepsAfter = knownSummarySteps($intake);
    $summaryAfter = collect($stepsAfter)->search(fn (array $s): bool => ($s['kind'] ?? '') === 'known_summary');

    expect($component->get('stepIndex'))->toBe($summaryAfter)
        ->and($intake->fresh()->answers()->where('question_key', 'room_length_m')->where('section_instance_key', 'room-1')->firstOrFail()->value['number'])
        ->toEqual(5);
});

test('Wijzigen multi-choice: twee checkboxen, Volgende terug met beide waarden', function () {
    $intake = knownSummaryIntake();
    seedKnownSummaryPrefills($intake);

    $steps = knownSummarySteps($intake);
    $summaryIndex = collect($steps)->search(fn (array $s): bool => ($s['kind'] ?? '') === 'known_summary');

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', $summaryIndex)
        ->call('editKnownAnswer', 'brand_preference', null);

    $composite = 'brand_preference';
    $component->set("form.{$composite}.values", ['daikin']);
    expect($component->get('forceShowKnown'))->toContain($composite);

    $component->set("form.{$composite}.values", ['daikin', 'mitsubishi']);
    expect($component->get('forceShowKnown'))->toContain($composite);

    $component->call('next');
    $stepsAfter = knownSummarySteps($intake);
    $summaryAfter = collect($stepsAfter)->search(fn (array $s): bool => ($s['kind'] ?? '') === 'known_summary');

    expect($component->get('stepIndex'))->toBe($summaryAfter);

    $values = $intake->fresh()->answers()->where('question_key', 'brand_preference')->firstOrFail()->value['values'] ?? [];
    expect($values)->toEqualCanonicalizing(['daikin', 'mitsubishi']);
});

test('use_type_source: installateur wint van AI', function () {
    $intake = knownSummaryIntake();
    seedKnownSummaryPrefills($intake);
    $room = $intake->fresh()->aircoRooms()->where('key', 'room-1')->firstOrFail();
    $installer = User::factory()->create(['company_id' => $intake->company_id]);

    app(AircoSurveyService::class)->updateRoom($intake, $installer, $room, [
        'name' => $room->name,
        'use_type' => 'office',
        'length_m' => 4,
        'width_m' => 3,
        'height_m' => 2.5,
    ]);

    expect($room->fresh()->use_type)->toBe('office')
        ->and($room->fresh()->use_type_source)->toBe('installer');

    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'room_type',
        'room-1',
        ['value' => 'bedroom'],
        PrefillSources::AI_PHOTO,
    );
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    expect($intake->fresh()->aircoRooms()->where('key', 'room-1')->firstOrFail()->use_type)->toBe('office')
        ->and($intake->fresh()->aircoRooms()->where('key', 'room-1')->firstOrFail()->use_type_source)->toBe('installer');
});

test('use_type_source: klantcorrectie werkt use_type en naam bij', function () {
    $intake = knownSummaryIntake();
    seedKnownSummaryPrefills($intake);

    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'room_type',
        'room-1',
        ['value' => 'living_room'],
        null,
    );
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    $room = $intake->fresh()->aircoRooms()->where('key', 'room-1')->firstOrFail();
    expect($room->use_type)->toBe('living_room')
        ->and($room->use_type_source)->toBe('customer')
        ->and($room->name)->toStartWith('Woonkamer');
});

test('use_type_source: AI werkt bij als niet installer/klant', function () {
    $intake = knownSummaryIntake();
    seedKnownSummaryPrefills($intake);

    $room = $intake->fresh()->aircoRooms()->where('key', 'room-1')->firstOrFail();
    expect($room->use_type)->toBe('bedroom');

    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'room_type',
        'room-1',
        ['value' => 'office'],
        PrefillSources::AI_PHOTO,
    );
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    $room = $intake->fresh()->aircoRooms()->where('key', 'room-1')->firstOrFail();
    expect($room->use_type)->toBe('office')
        ->and($room->use_type_source)->toBe('ai')
        ->and($room->name)->toStartWith('Werkkamer');
});

test('installateursmaten L/B/H zonder m² blijven na klantsave met andere antwoorden', function () {
    $intake = knownSummaryIntake();
    seedKnownSummaryPrefills($intake);
    $room = $intake->fresh()->aircoRooms()->where('key', 'room-1')->firstOrFail();
    $installer = User::factory()->create(['company_id' => $intake->company_id]);

    // Antwoorden staan op 4×3; installateur zet andere L/B/H zonder m².
    app(AircoSurveyService::class)->updateRoom($intake, $installer, $room, [
        'name' => $room->name,
        'use_type' => $room->use_type,
        'length_m' => 5.5,
        'width_m' => 4.5,
        'height_m' => 2.7,
    ]);

    // Klant/antwoord-sync met andere maten mag geen area_*-gaten vullen vanuit antwoorden.
    app(SaveIntakeAnswer::class)->handle($intake, 'room_length_m', 'room-1', ['number' => 4], PrefillSources::AI_TEXT);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_width_m', 'room-1', ['number' => 3], PrefillSources::AI_TEXT);
    app(SaveIntakeAnswer::class)->handle($intake, 'ceiling_height_m', 'room-1', ['number' => 2.5], PrefillSources::AI_TEXT);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_area_m2', 'room-1', ['number' => 12], PrefillSources::AI_TEXT);
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    $room = $intake->fresh()->aircoRooms()->where('key', 'room-1')->firstOrFail();
    $dims = RoomDimensions::from($room->dimensions);

    expect($room->dimensions)->not->toHaveKey('area_m2')
        ->and($room->dimensions)->not->toHaveKey('area_source')
        ->and($room->dimensions)->not->toHaveKey('area_confidence')
        ->and($room->dimensions)->not->toHaveKey('area_evidence')
        ->and((float) ($room->dimensions['length_m'] ?? 0))->toBe(5.5)
        ->and((float) ($room->dimensions['width_m'] ?? 0))->toBe(4.5)
        ->and((float) ($room->dimensions['height_m'] ?? 0))->toBe(2.7)
        ->and($room->dimensions['dimensions_source'] ?? null)->toBe('installer')
        ->and($dims->hasFloorAreaConflict())->toBeFalse()
        ->and($dims->floorBasis())->not->toBe('conflict')
        ->and($dims->effectiveFloorAreaM2())->toBe(24.75);
});

test('hernoemen zonder maatwijziging bevriest maten niet; echte maatwijziging wel', function () {
    $intake = knownSummaryIntake();
    seedKnownSummaryPrefills($intake);
    $room = $intake->fresh()->aircoRooms()->where('key', 'room-1')->firstOrFail();
    $installer = User::factory()->create(['company_id' => $intake->company_id]);

    // AI-prefill zet dimensions_source op de prefill-bron (niet installer-bevroren).
    expect($room->dimensions['dimensions_source'] ?? null)->toBe('ai_text')
        ->and((float) ($room->dimensions['length_m'] ?? 0))->toBe(4.0);

    // Pure hernoeming met dezelfde maten → bron ongewijzigd (geen installer-freeze).
    app(AircoSurveyService::class)->updateRoom($intake, $installer, $room, [
        'name' => 'Slaapkamer hernoemd',
        'use_type' => $room->use_type,
        'length_m' => $room->dimensions['length_m'] ?? null,
        'width_m' => $room->dimensions['width_m'] ?? null,
        'height_m' => $room->dimensions['height_m'] ?? null,
        'area_m2' => $room->dimensions['area_m2'] ?? null,
    ]);

    $room->refresh();
    expect($room->name)->toBe('Slaapkamer hernoemd')
        ->and($room->dimensions['dimensions_source'] ?? null)->toBe('ai_text');

    // Klantcorrectie van alleen de lengte: breedte blijft AI-prefill → bron blijft prefill.
    app(SaveIntakeAnswer::class)->handle($intake, 'room_length_m', 'room-1', ['number' => 6], null);
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    $room = $intake->fresh()->aircoRooms()->where('key', 'room-1')->firstOrFail();
    expect((float) ($room->dimensions['length_m'] ?? 0))->toBe(6.0)
        ->and($room->dimensions['dimensions_source'] ?? null)->toBe('ai_text');

    // Beide maten zonder prefill → écht klantantwoord → «van klant».
    app(SaveIntakeAnswer::class)->handle($intake, 'room_width_m', 'room-1', ['number' => 3], null);
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    $room = $intake->fresh()->aircoRooms()->where('key', 'room-1')->firstOrFail();
    expect($room->dimensions['dimensions_source'] ?? null)->toBe('customer');

    // Echte maatwijziging zet wel de marker.
    app(AircoSurveyService::class)->updateRoom($intake, $installer, $room, [
        'name' => $room->name,
        'use_type' => $room->use_type,
        'length_m' => 7.0,
        'width_m' => 3.0,
    ]);

    expect($room->fresh()->dimensions['dimensions_source'] ?? null)->toBe('installer')
        ->and((float) ($room->fresh()->dimensions['length_m'] ?? 0))->toBe(7.0);
});

test('Vorige vanaf geforceerde known-edit gaat terug naar known-summary', function () {
    $intake = knownSummaryIntake();
    seedKnownSummaryPrefills($intake);

    $steps = knownSummarySteps($intake);
    $summaryIndex = collect($steps)->search(fn (array $s): bool => ($s['kind'] ?? '') === 'known_summary');
    expect($summaryIndex)->toBeInt();

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', $summaryIndex)
        ->call('editKnownAnswer', 'room_length_m', 'room-1');

    $composite = 'room-1__room_length_m';
    expect($component->get('forceShowKnown'))->toContain($composite)
        ->and($component->get('stepIndex'))->not->toBe($summaryIndex);

    $component->call('previous');

    $stepsAfter = knownSummarySteps($intake);
    $summaryAfter = collect($stepsAfter)->search(fn (array $s): bool => ($s['kind'] ?? '') === 'known_summary');

    expect($component->get('forceShowKnown'))->not->toContain($composite)
        ->and($component->get('pendingKnownEdits'))->not->toHaveKey($composite)
        ->and($component->get('stepIndex'))->toBe($summaryAfter);
});

test('goToStep vanaf geforceerde known-edit finaliseert forced edit', function () {
    $intake = knownSummaryIntake();
    seedKnownSummaryPrefills($intake);

    $steps = knownSummarySteps($intake);
    $summaryIndex = collect($steps)->search(fn (array $s): bool => ($s['kind'] ?? '') === 'known_summary');

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', $summaryIndex)
        ->call('editKnownAnswer', 'room_length_m', 'room-1');

    $composite = 'room-1__room_length_m';
    expect($component->get('forceShowKnown'))->toContain($composite);

    $component->call('goToStep', $summaryIndex);

    expect($component->get('forceShowKnown'))->not->toContain($composite)
        ->and($component->get('stepIndex'))->toBe($summaryIndex);
});
