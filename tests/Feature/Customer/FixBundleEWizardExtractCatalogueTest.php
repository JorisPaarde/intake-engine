<?php

declare(strict_types=1);

/**
 * Fix bundle E — klantwizard / extractie / catalogus (Notion hertest 4 okt).
 * Eén Pest-test per bevinding; zichtbare wizard-opties ook via Livewire HTML.
 */

use App\Domains\AI\Actions\DerivePhotoAnswers;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\DTOs\RequestPrefillCandidate;
use App\Domains\AI\Services\LocalRequestIntentParser;
use App\Domains\AI\Services\RequestPrefillOutcomeClassifier;
use App\Domains\AI\Support\OwnershipNormalizer;
use App\Domains\AI\Support\PhotoDerivationProfile;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAttentionPoint;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Support\FactProvenance;
use App\Domains\Intake\Support\PrefillSources;
use App\Domains\Intake\Support\RoomAreaAcceptance;
use App\Enums\AttentionPointSource;
use App\Enums\AttentionPointStatus;
use App\Enums\IntakeStatus;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    FakeAiClient::reset();
    config([
        'ai.provider' => 'fake',
        'ai.photo_inference.enabled' => true,
        'ai.text_inference.enabled' => true,
    ]);
});

afterEach(function () {
    FakeAiClient::reset();
});

function bundleEIntake(array $overrides = []): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create(array_merge([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::InProgress,
        'customer_name' => 'Bundle E Klant',
        'customer_email' => 'bundle-e@example.com',
        'address_line' => 'Testlaan 1',
        'address_city' => 'Utrecht',
    ], $overrides));
}

function bundleECatalog(): array
{
    $choice = static fn (string $key, string $label, array $values, bool $repeatable = false): array => [
        'key' => $key,
        'label' => $label,
        'type' => 'single_choice',
        'options' => array_map(
            static fn (string $value): array => ['value' => $value, 'label' => $value],
            $values,
        ),
        'is_repeatable' => $repeatable,
    ];

    return [
        'sections' => [
            [
                'key' => 'building',
                'title' => 'Woning',
                'is_repeatable' => false,
                'questions' => [
                    $choice('ownership', 'Is het een koop- of huurwoning?', ['owned', 'rented']),
                    $choice('cooling_heating', 'Koelen of verwarmen?', ['cooling', 'heating', 'both']),
                ],
            ],
            [
                'key' => 'rooms',
                'title' => 'Ruimtes',
                'is_repeatable' => true,
                'questions' => [
                    $choice('floor_level', 'Op welke verdieping?', ['basement', 'ground', '1', '2', '3_plus', 'attic'], true),
                    $choice('room_type', 'Type ruimte', ['living_room', 'bedroom', 'office', 'attic', 'other', 'unknown'], true),
                    $choice('glazing_type', 'Welk type glas?', ['single', 'double', 'hr_plus_plus', 'unknown'], true),
                    $choice('glass_amount', 'Hoeveel glas?', ['little', 'average', 'much', 'unknown'], true),
                    $choice('sun_exposure', 'Zonbelasting', ['low', 'medium', 'high', 'unknown'], true),
                ],
            ],
        ],
    ];
}

test('P2 bedankscherm: alleen Nederlandse klanttekst, geen interne keys of Engels', function () {
    $intake = bundleEIntake(['is_demo' => true]);

    IntakeAttentionPoint::query()->create([
        'intake_id' => $intake->id,
        'source' => AttentionPointSource::Ai,
        'code' => 'outdoor_location',
        'label' => 'outdoor_location outdoor_mount_type (wall) room_type (room-1) bedroom sun_exposure medium glass_amount average',
        'status' => AttentionPointStatus::Proposed,
        'ai_confidence' => 'medium',
        'evidence' => [],
        'is_resolved' => false,
    ]);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('completed', true)
        ->assertSeeHtml('data-testid="customer-thank-you"')
        ->assertSee('Bedankt')
        ->assertSee('Jouw deel is compleet')
        ->assertDontSee('outdoor_location')
        ->assertDontSee('outdoor_mount_type')
        ->assertDontSee('sun_exposure')
        ->assertDontSee('glass_amount')
        ->assertDontSee('(wall)')
        ->assertDontSee('Voorgestelde aandachtspunten')
        ->assertDontSee('outdoor_location outdoor_mount_type');
});

test('P2 koopwoning uit openingszin wordt stated FILL en niet opnieuw gevraagd', function () {
    $reason = 'Nieuwe installatie: slaapkamer koelen, 15 m². Het is een koopwoning zonder haast.';

    $result = app(RequestPrefillOutcomeClassifier::class)->classifyCatalogOutput([
        'evidence' => 'Openingszin met koopwoning.',
        'fills' => [
            [
                'question_key' => 'cooling_heating',
                'section_instance_key' => null,
                'confidence' => 'high',
                'value' => ['value' => 'cooling'],
                'evidence' => 'koelen',
                'provenance' => 'stated',
            ],
            [
                'question_key' => 'ownership',
                'section_instance_key' => null,
                'confidence' => 'medium',
                'value' => ['value' => 'koop'],
                'evidence' => 'afgeleid uit woning',
                'provenance' => 'inferred',
            ],
        ],
    ], bundleECatalog(), [], $reason);

    $ownership = collect($result['candidates'])->first(
        static fn (RequestPrefillCandidate $c): bool => $c->questionKey === 'ownership',
    );

    expect($ownership)->not->toBeNull()
        ->and($ownership->value)->toBe(['value' => 'owned'])
        ->and($ownership->provenance?->value)->toBe('inferred')
        ->and($ownership->disposition)->toBe(RequestPrefillCandidate::DISPOSITION_SUGGESTION);

    $stated = app(RequestPrefillOutcomeClassifier::class)->classifyCatalogOutput([
        'evidence' => $reason,
        'fills' => [[
            'question_key' => 'ownership',
            'section_instance_key' => null,
            'confidence' => 'high',
            'value' => ['value' => 'owned'],
            'evidence' => 'koopwoning',
            'provenance' => 'stated',
        ]],
    ], bundleECatalog(), [], $reason);

    $ownershipStated = collect($stated['candidates'])->first(
        static fn (RequestPrefillCandidate $c): bool => $c->questionKey === 'ownership',
    );
    expect($ownershipStated)->not->toBeNull()
        ->and($ownershipStated->disposition)->toBe(RequestPrefillCandidate::DISPOSITION_FILL)
        ->and($ownershipStated->provenance?->value)->toBe('stated')
        ->and($ownershipStated->evidence)->toBe('koopwoning');

    $injected = app(RequestPrefillOutcomeClassifier::class)->classifyCatalogOutput([
        'evidence' => 'Alleen koelen.',
        'fills' => [[
            'question_key' => 'cooling_heating',
            'section_instance_key' => null,
            'confidence' => 'high',
            'value' => ['value' => 'cooling'],
            'evidence' => 'koelen',
            'provenance' => 'stated',
        ]],
    ], bundleECatalog(), [], $reason);

    $ownership2 = collect($injected['candidates'])->first(
        static fn (RequestPrefillCandidate $c): bool => $c->questionKey === 'ownership',
    );
    expect($ownership2)->toBeNull();

    expect((new OwnershipNormalizer)->normalize('koopwoning'))->toBe('owned')
        ->and((new OwnershipNormalizer)->normalize($reason))->toBeNull();

    $intake = bundleEIntake();
    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'ownership',
        null,
        ['value' => 'owned'],
        PrefillSources::AI_TEXT,
        FactProvenance::Stated,
    );
    $version = $intake->fresh()->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    $keys = collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version))->pluck('question_key')->all();
    expect($keys)->not->toContain('ownership');
});

test('P3 preferred_indoor_location heeft expliciete optie laat installateur kiezen', function () {
    $intake = bundleEIntake();
    app(DossierManager::class)->initialize($intake);
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1], PrefillSources::REQUEST_TEXT);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom'], PrefillSources::REQUEST_TEXT);

    $version = $intake->fresh()->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    expect($version->version)->toBeGreaterThanOrEqual(26);

    $preferred = null;
    foreach ($version->sections as $section) {
        foreach ($section->questions as $question) {
            if ($question->key === 'preferred_indoor_location') {
                $preferred = $question;
                break 2;
            }
        }
    }
    expect($preferred)->not->toBeNull()
        ->and($preferred->meta['allow_skip'] ?? false)->toBeTrue()
        ->and($preferred->meta['skip_label'] ?? null)->toBe('Geen voorkeur — laat installateur kiezen');

    $steps = collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version));
    $idx = $steps->search(static fn (array $s): bool => ($s['question_key'] ?? null) === 'preferred_indoor_location');
    expect($idx)->not->toBeFalse();

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('activeStepKey', $steps[$idx]['key'] ?? null)
        ->set('stepIndex', $idx)
        ->assertSee('Geen voorkeur — laat installateur kiezen')
        ->assertSeeHtml('data-testid="text-skip"')
        ->call('skipOptionalPhoto');

    $answer = $intake->fresh()->answers()->where('question_key', 'preferred_indoor_location')->first();
    expect($answer)->not->toBeNull()
        ->and($answer->value['text'] ?? null)->toBe('Laat installateur kiezen');
});

test('P3 genummerde verdieping wint van zolder in samenvatting', function () {
    $text = 'Ik wil de slaapkamer op zolder op de 2e verdieping koelen. Nieuwe installatie.';
    $local = app(LocalRequestIntentParser::class)->parse($text);
    expect($local)->not->toBeNull()
        ->and($local['floor_level'])->toBe('2')
        ->and($local['rooms'])->toBe(['bedroom']);

    $classified = app(RequestPrefillOutcomeClassifier::class)->classifyCatalogOutput([
        'evidence' => 'Zolderkamer op 2e.',
        'fills' => [[
            'question_key' => 'floor_level',
            'section_instance_key' => 'room-1',
            'confidence' => 'high',
            'value' => ['value' => 'attic'],
            'evidence' => 'zolder',
            'provenance' => 'stated',
        ]],
    ], bundleECatalog(), [], $text);

    $floor = collect($classified['candidates'])->first(
        static fn (RequestPrefillCandidate $c): bool => $c->questionKey === 'floor_level',
    );
    expect($floor)->not->toBeNull()
        ->and($floor->value)->toBe(['value' => 'attic'])
        ->and(collect($classified['normalizations'])->pluck('rule')->all())
        ->not->toContain('floor_level_prefer_numbered');

    $intake = bundleEIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1], PrefillSources::REQUEST_TEXT);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom'], PrefillSources::REQUEST_TEXT);
    app(SaveIntakeAnswer::class)->handle($intake, 'floor_level', 'room-1', ['value' => '2'], PrefillSources::AI_TEXT);

    $version = $intake->fresh()->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    $steps = collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version));
    $known = $steps->firstWhere('kind', 'known_summary');
    $displayBlob = json_encode($known ?? [], JSON_THROW_ON_ERROR);
    expect($displayBlob)->toContain('2e verdieping')
        ->and($displayBlob)->not->toContain('"display_value":"Zolder"');
});

test('P3 catalogus bevat unknown-opties en glazing_type', function () {
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    expect($version->version)->toBeGreaterThanOrEqual(26);
    $version->load(['sections.questions.options']);

    $byKey = [];
    foreach ($version->sections as $section) {
        foreach ($section->questions as $question) {
            $byKey[$question->key] = $question;
        }
    }

    expect($byKey)->toHaveKey('glazing_type')
        ->and(collect($byKey['glazing_type']->options)->pluck('value')->all())->toContain('unknown')
        ->and(collect($byKey['glass_amount']->options)->pluck('value')->all())->toContain('unknown')
        ->and(collect($byKey['sun_exposure']->options)->pluck('value')->all())->toContain('unknown')
        ->and(collect($byKey['room_type']->options)->pluck('value')->all())->toContain('unknown')
        ->and(collect($byKey['outdoor_location']->options)->pluck('value')->all())->toContain('unknown');

    $room = PhotoDerivationProfile::require('room');
    $roomKeys = collect($room->fields)->pluck('questionKey')->all();
    expect($roomKeys)->toContain('glazing_type')
        ->and(collect($room->fields)->firstWhere('questionKey', 'room_type')->allowedValues)->toContain('unknown')
        ->and(collect($room->fields)->firstWhere('questionKey', 'glazing_type')->allowedValues)->toContain('unknown')
        ->and(collect($room->fields)->firstWhere('questionKey', 'glass_amount')->allowedValues)->toContain('unknown');

    // Outdoor: unknown zit in de templatecatalogus, maar foto-unknown blijft een skip (geen auto-antwoord).
    $outdoorLocation = collect(PhotoDerivationProfile::require('outdoor')->fields)
        ->firstWhere('questionKey', 'outdoor_location');
    expect($outdoorLocation)->not->toBeNull()
        ->and($outdoorLocation->schemaValues())->toContain('unknown')
        ->and($outdoorLocation->allowsPersistingUnknown())->toBeFalse();
    $result = app(RequestPrefillOutcomeClassifier::class)->classifyCatalogOutput([
        'evidence' => 'Grote ramen, glas onbekend.',
        'fills' => [
            [
                'question_key' => 'glass_amount',
                'section_instance_key' => 'room-1',
                'confidence' => 'high',
                'value' => ['value' => 'much'],
                'evidence' => 'grote ramen',
                'provenance' => 'stated',
            ],
            [
                'question_key' => 'glazing_type',
                'section_instance_key' => 'room-1',
                'confidence' => 'high',
                'value' => ['value' => 'unknown'],
                'evidence' => 'type niet zichtbaar',
                'provenance' => 'stated',
            ],
        ],
    ], bundleECatalog(), [], 'Grote ramen, type glas weet ik niet.');

    $glazing = collect($result['candidates'])->first(
        static fn (RequestPrefillCandidate $c): bool => $c->questionKey === 'glazing_type',
    );
    expect($glazing)->not->toBeNull()
        ->and($glazing->disposition)->not->toBe(RequestPrefillCandidate::DISPOSITION_REJECTED)
        ->and($glazing->value)->toBe(['value' => 'unknown']);
});

test('P3 kamergrootte foto vs antwoord markeert conflict', function () {
    $intake = bundleEIntake(['status' => IntakeStatus::Sent]);
    app(DossierManager::class)->initialize($intake);
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1], PrefillSources::REQUEST_TEXT);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom'], PrefillSources::REQUEST_TEXT);
    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'room_area_m2',
        'room-1',
        ['number' => 24, 'confidence' => 'high', 'evidence' => '24 m²'],
        PrefillSources::REQUEST_TEXT,
    );
    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'room_size_indication',
        'room-1',
        ['value' => 'large'],
        PrefillSources::REQUEST_TEXT,
    );

    expect(RoomAreaAcceptance::sizeIndicationFromArea(24.0))->toBe('large')
        ->and($intake->answers()->where('question_key', 'room_size_indication')->value('value'))
        ->toBe(['value' => 'large']);

    $derive = app(DerivePhotoAnswers::class);
    $ref = new ReflectionClass($derive);
    $detect = $ref->getMethod('roomSizeIndicationConflict');
    $detect->setAccessible(true);
    $conflict = $detect->invoke($derive, $intake->fresh(), 'room-1', 'medium');

    expect($conflict)->toBeArray()
        ->and($conflict['value']['value'] ?? null)->toBe('large');

    $record = $ref->getMethod('recordPhotoTextConflict');
    $record->setAccessible(true);
    $record->invoke($derive, $intake->fresh(), 'room_size_indication', 'room-1', $conflict, 'medium');

    $point = $intake->fresh()->attentionPoints()
        ->where('code', 'photo_text_conflict:room_size_indication:room-1')
        ->first();

    expect($point)->not->toBeNull()
        ->and($point->label)->toContain('Foto wijkt af')
        ->and($point->label)->not->toContain('room_size_indication')
        ->and($point->label)->toContain('Groot')
        ->and($point->label)->toContain('Gemiddeld');

    // Via applyDerivedAnswers: conflict voorkomt overschrijven van large → medium.
    $apply = $ref->getMethod('applyDerivedAnswers');
    $apply->setAccessible(true);
    $apply->invoke($derive, $intake->fresh(), [
        'room_type' => 'bedroom',
        'room_size_indication' => 'medium',
        'sun_exposure' => 'unknown',
        'glass_amount' => 'unknown',
        'glazing_type' => 'unknown',
        'room_outlet_status' => 'unknown',
        'extra_overview_needed' => 'complete',
        'detected_subject' => 'room',
        'subject_match' => 'yes',
        'confidence' => 'high',
        'evidence' => 'medium formaat',
        'retake_instruction' => null,
    ], 'room-1', PhotoDerivationProfile::require('room'));

    expect($intake->fresh()->answers()->where('question_key', 'room_size_indication')->where('section_instance_key', 'room-1')->value('value'))
        ->toBe(['value' => 'large']);
});
