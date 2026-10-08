<?php

declare(strict_types=1);

use App\Domains\AI\Actions\DeriveIntentFromRequest;
use App\Domains\AI\Actions\RecordExistingAircoFromRequest;
use App\Domains\AI\DTOs\RequestPrefillCandidate;
use App\Domains\AI\Services\LocalRequestIntentParser;
use App\Domains\AI\Services\RequestPrefillOutcomeClassifier;
use App\Domains\AI\Support\ExistingAircoExtractor;
use App\Domains\AI\Support\RoomFloorLevelExtractor;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Models\DossierRecord;
use App\Domains\Intake\Models\IntakeAttentionPoint;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Support\FactAcceptance;
use App\Domains\Intake\Support\FactProvenance;
use App\Domains\Intake\Support\FactSource;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\ContributionMode;
use App\Enums\DossierRecordStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

const DEMOTEST_INTAKE112 = 'Werkkamer op de eerste verdieping van 3 bij 3 meter en de woonkamer beneden van 6 bij 4 meter. Alleen koelen. Er hangt al een oude airco in de woonkamer die vervangen moet worden. Buitenunit liefst in de achtertuin.';

const DEMOTEST_INTAKE111 = 'Ik wil twee slaapkamers op zolder koelen en de woonkamer ook verwarmen.';

const DEMOTEST_REVERSED = 'De woonkamer beneden van 6 bij 4 meter en de werkkamer op de eerste verdieping van 3 bij 3 meter. Alleen koelen.';

function floorCatalog(): array
{
    return [
        'sections' => [[
            'key' => 'rooms',
            'is_repeatable' => true,
            'questions' => [
                [
                    'key' => 'room_type',
                    'type' => 'single_choice',
                    'label' => 'Type',
                    'options' => [
                        ['value' => 'living_room'],
                        ['value' => 'bedroom'],
                        ['value' => 'office'],
                        ['value' => 'attic'],
                    ],
                ],
                [
                    'key' => 'floor_level',
                    'type' => 'single_choice',
                    'label' => 'Verdieping',
                    'options' => [
                        ['value' => 'ground'],
                        ['value' => '1'],
                        ['value' => '2'],
                        ['value' => 'attic'],
                    ],
                ],
            ],
        ]],
    ];
}

test('extractor: intake112 werkkamer 1e, woonkamer begane grond; omgekeerde volgorde idem', function () {
    $extractor = new RoomFloorLevelExtractor;

    expect($extractor->floorsForRooms(DEMOTEST_INTAKE112, ['office', 'living_room']))
        ->toBe(['1', 'ground'])
        ->and($extractor->floorsForRooms(DEMOTEST_REVERSED, ['living_room', 'office']))
        ->toBe(['ground', '1']);
});

test('extractor: intake111 woonkamer krijgt geen zolder', function () {
    $floors = (new RoomFloorLevelExtractor)->floorsForRooms(
        DEMOTEST_INTAKE111,
        ['bedroom', 'bedroom', 'living_room'],
    );

    expect($floors)->toBe(['attic', 'attic', null]);
});

test('lokale parser: verdiepingen zonder lek; dubbele woonkamer-vermelding → catalogus-AI', function () {
    $parser = new LocalRequestIntentParser(new RoomFloorLevelExtractor);

    // Zonder tweede "woonkamer" (bestaande airco) is de lokale parser foutloos.
    $clean = 'Werkkamer op de eerste verdieping van 3 bij 3 meter en de woonkamer beneden van 6 bij 4 meter. Alleen koelen.';
    $parsed = $parser->parse($clean);
    expect($parsed)->not->toBeNull()
        ->and($parsed['rooms'])->toBe(['office', 'living_room'])
        ->and($parsed['room_floors'])->toBe(['1', 'ground']);

    // Intake112 noemt woonkamer twee keer (maten + oude airco) → bewust geen lokale high-confidence.
    expect($parser->parse(DEMOTEST_INTAKE112))->toBeNull();
});

test('classifier: verkeerde floor-fill naar woonkamer wordt gecorrigeerd + bron installateursaanvraag', function () {
    $text = DEMOTEST_INTAKE112;
    $result = app(RequestPrefillOutcomeClassifier::class)->classifyCatalogOutput([
        'evidence' => $text,
        'fills' => [
            ['question_key' => 'room_type', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'value' => ['value' => 'office'], 'evidence' => 'Werkkamer', 'provenance' => 'stated'],
            ['question_key' => 'floor_level', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'value' => ['value' => '1'], 'evidence' => 'eerste verdieping', 'provenance' => 'stated'],
            ['question_key' => 'room_type', 'section_instance_key' => 'room-2', 'confidence' => 'high', 'value' => ['value' => 'living_room'], 'evidence' => 'woonkamer', 'provenance' => 'stated'],
            ['question_key' => 'floor_level', 'section_instance_key' => 'room-2', 'confidence' => 'high', 'value' => ['value' => '1'], 'evidence' => 'eerste verdieping', 'provenance' => 'stated'],
        ],
    ], floorCatalog(), [], $text);

    $floors = collect($result['candidates'])
        ->where('questionKey', 'floor_level')
        ->where('disposition', RequestPrefillCandidate::DISPOSITION_FILL)
        ->mapWithKeys(fn (RequestPrefillCandidate $c): array => [
            (string) $c->sectionInstanceKey => [
                'value' => $c->value['value'] ?? null,
                'source' => $c->factSource?->value,
            ],
        ])
        ->all();

    expect($floors['room-1']['value'])->toBe('1')
        ->and($floors['room-2']['value'])->toBe('ground')
        ->and($floors['room-1']['source'])->toBe(FactSource::InstallerRequest->value)
        ->and($floors['room-2']['source'])->toBe(FactSource::InstallerRequest->value);
});

test('FactSource: request_text is aanvraag (installateur), niet klantantwoord', function () {
    expect(FactAcceptance::sourceFrom(PrefillSources::REQUEST_TEXT, FactProvenance::Stated))
        ->toBe(FactSource::InstallerRequest)
        ->and(FactSource::InstallerRequest->installerLabel())->toBe('aanvraag (installateur)')
        ->and(FactSource::InstallerRequest->mayCountAsKnown())->toBeTrue();
});

test('bestaande airco-extractie herkent vervanging in woonkamer', function () {
    $extracted = (new ExistingAircoExtractor)->extract(DEMOTEST_INTAKE112);

    expect($extracted)->not->toBeNull()
        ->and($extracted['present'])->toBeTrue()
        ->and($extracted['replacement'])->toBeTrue()
        ->and($extracted['room_type'])->toBe('living_room');
});

test('bestaande airco: vervangen in andere zin telt niet als replacement', function () {
    $text = 'Er hangt al een oude airco in de woonkamer. We willen de radiator later vervangen.';
    $extracted = (new ExistingAircoExtractor)->extract($text);

    expect($extracted)->not->toBeNull()
        ->and($extracted['present'])->toBeTrue()
        ->and($extracted['replacement'])->toBeFalse()
        ->and($extracted['room_type'])->toBe('living_room');
});

test('DeriveIntent legt bestaande airco vast als dossierfeit en aandachtspunt', function () {
    config(['ai.text_inference.enabled' => false]);

    $user = User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Airco Replace',
        'customer_email' => 'airco-replace@example.com',
        'address_line' => 'Testlaan 10',
        'address_postal_code' => '1000AA',
        'address_house_number' => 10,
        'address_city' => 'Amsterdam',
        'prefill' => [
            'request_reason' => DEMOTEST_INTAKE112,
        ],
    ]);

    app(DeriveIntentFromRequest::class)->handle($intake->fresh() ?? $intake, allowExternal: false);

    $record = DossierRecord::query()
        ->where('intake_id', $intake->id)
        ->where('key', RecordExistingAircoFromRequest::RECORD_KEY)
        ->whereNull('superseded_by_id')
        ->first();

    $attention = IntakeAttentionPoint::query()
        ->where('intake_id', $intake->id)
        ->where('code', RecordExistingAircoFromRequest::ATTENTION_CODE)
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->value['replacement'] ?? null)->toBeTrue()
        ->and($record->value['text'] ?? '')->toContain('vervangen')
        ->and($attention)->not->toBeNull()
        ->and($attention->label)->toContain('vervanging');
});

test('naam-edit zonder floor-wijziging markeert AI-floor niet als installateur', function () {
    $user = User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Floor Keep',
        'customer_email' => 'floor-keep@example.com',
        'address_line' => 'Testlaan 10',
        'address_postal_code' => '1000AA',
        'address_house_number' => 10,
        'address_city' => 'Amsterdam',
    ]);

    $room = app(AircoSurveyService::class)->createRoom($intake, $user, [
        'name' => 'Woonkamer',
        'use_type' => 'living_room',
        'length_m' => 6,
        'width_m' => 4,
    ]);
    $room->update([
        'dimensions' => array_merge($room->dimensions ?? [], [
            'floor_level' => '1',
            'floor_level_source' => 'ai',
        ]),
    ]);

    $this->actingAs($user)
        ->post(route('intakes.workspace.rooms.update', [$intake, $room]), [
            'name' => 'Woonkamer voor',
            'use_type' => 'living_room',
            'floor_level' => '1',
            'length_m' => 6,
            'width_m' => 4,
        ])
        ->assertRedirect();

    $fresh = $room->fresh();
    expect($fresh->name)->toBe('Woonkamer voor')
        ->and($fresh->dimensions['floor_level'] ?? null)->toBe('1')
        ->and($fresh->dimensions['floor_level_source'] ?? null)->toBe('ai');
});

test('verdieping is corrigeerbaar op werkplek en blijft in historie', function () {
    $user = User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Floor Fix',
        'customer_email' => 'floor-fix@example.com',
        'address_line' => 'Testlaan 10',
        'address_postal_code' => '1000AA',
        'address_house_number' => 10,
        'address_city' => 'Amsterdam',
    ]);

    $room = app(AircoSurveyService::class)->createRoom($intake, $user, [
        'name' => 'Woonkamer',
        'use_type' => 'living_room',
        'length_m' => 6,
        'width_m' => 4,
    ]);

    // Seed verkeerde AI-floor in dimensions (alsof prefill lekte).
    $room->update([
        'dimensions' => array_merge($room->dimensions ?? [], [
            'floor_level' => '1',
            'floor_level_source' => 'ai',
        ]),
    ]);

    $this->actingAs($user)
        ->post(route('intakes.workspace.rooms.update', [$intake, $room]), [
            'name' => 'Woonkamer',
            'use_type' => 'living_room',
            'floor_level' => 'ground',
            'length_m' => 6.1,
            'width_m' => 4,
        ])
        ->assertRedirect();

    $fresh = $room->fresh();
    expect($fresh->dimensions['floor_level'] ?? null)->toBe('ground')
        ->and($fresh->dimensions['floor_level_source'] ?? null)->toBe('installer')
        ->and((float) ($fresh->dimensions['length_m'] ?? 0))->toBe(6.1);

    $history = DossierRecord::query()
        ->where('intake_id', $intake->id)
        ->where('dossier_subject_id', $room->dossier_subject_id)
        ->where('key', 'floor_level')
        ->orderBy('id')
        ->get();

    expect($history->count())->toBeGreaterThanOrEqual(1)
        ->and($history->last()->status)->toBe(DossierRecordStatus::Established)
        ->and($history->last()->value['value'] ?? null)->toBe('ground')
        ->and($history->last()->value['_source_label'] ?? null)->toBe('installateur');

    // Reload workspace mag installateursfloor niet wissen.
    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('data-testid="room-floor-level"', false);

    expect($room->fresh()->dimensions['floor_level'] ?? null)->toBe('ground');
});
