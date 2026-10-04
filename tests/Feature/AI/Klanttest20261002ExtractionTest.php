<?php

declare(strict_types=1);

use App\Domains\AI\Actions\DeriveIntentFromRequest;
use App\Domains\AI\Actions\DerivePhotoAnswers;
use App\Domains\AI\Actions\PrefillAnswersFromKnownContext;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Support\PhotoDerivationProfile;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAttentionPoint;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Support\KnownSummaryCatalog;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\AttentionPointStatus;
use App\Enums\IntakeStatus;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    FakeAiClient::reset();
    config(['ai.provider' => 'fake', 'ai.text_inference.enabled' => true, 'ai.photo_inference.enabled' => true]);
});

afterEach(function () {
    FakeAiClient::reset();
});

function klanttestIntake(): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_email' => 'klanttest@example.com',
    ]);
}

function klanttestReason(Intake $intake, string $text): void
{
    app(SaveIntakeAnswer::class)->handle($intake, 'request_reason', null, ['text' => $text]);
}

/**
 * @return list<array{question_key: string, section_instance_key: string|null, title: string, section_title: string, kind: string}>
 */
function klanttestStepRefs(Intake $intake): array
{
    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();

    return collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version))
        ->map(static fn (array $step): array => [
            'question_key' => $step['question_key'],
            'section_instance_key' => $step['section_instance_key'],
            'title' => $step['title'],
            'section_title' => $step['section_title'],
            'kind' => $step['kind'] ?? 'question',
            'known_items' => $step['known_items'] ?? [],
        ])
        ->all();
}

const CASE_80_REASON = 'Woonkamer 6 bij 4 meter, plafond 2,6 meter. Alleen koelen. Binnenunit boven de bank bij de buitenmuur; buitenunit liefst op de grond in de achtertuin. Over de stroom weet ik niets.';

const CASE_81_REASON = 'Fictieve QA-test. Ik wil twee slaapkamers koelen en verwarmen. Slaapkamer ouders op de 1e verdieping: 4 bij 3 meter, plafond 2,5 meter. Kinderkamer op de 1e verdieping: 3 bij 3 meter, plafond 2,5 meter. Het is een goed geïsoleerde koopwoning met vloerisolatie en een kruipruimte. Ik heb geen merkvoorkeur en geen haast. Een buitenunit tegen de achtergevel; de buren zitten dichtbij. Zichtbare leidingen in een goot vind ik prima. Over de stroomvoorziening en condensafvoer weet ik niets. De installateur moet technische keuzes bepalen.';

test('case 80: bekende maten 6/4/2,6 niet opnieuw als losse invulvraag; foto vóór afleidbare vragen', function () {
    $intake = klanttestIntake();
    klanttestReason($intake, CASE_80_REASON);

    FakeAiClient::alwaysReturn([
        'evidence' => 'Woonkamer 6×4 m, plafond 2,6 m, alleen koelen; stroom onbekend.',
        'fills' => [
            [
                'question_key' => 'cooling_heating',
                'section_instance_key' => null,
                'confidence' => 'high',
                'value' => ['value' => 'cooling'],
                'evidence' => 'Alleen koelen',
            ],
            [
                'question_key' => 'indoor_unit_count',
                'section_instance_key' => null,
                'confidence' => 'high',
                'value' => ['number' => 1],
                'evidence' => 'Eén woonkamer',
            ],
            [
                'question_key' => 'room_name',
                'section_instance_key' => 'room-1',
                'confidence' => 'high',
                'value' => ['text' => 'Woonkamer'],
                'evidence' => null,
            ],
            [
                'question_key' => 'room_type',
                'section_instance_key' => 'room-1',
                'confidence' => 'high',
                'value' => ['value' => 'living_room'],
                'evidence' => null,
            ],
            [
                'question_key' => 'room_length_m',
                'section_instance_key' => 'room-1',
                'confidence' => 'high',
                'value' => ['number' => 6],
                'evidence' => '6 bij 4 meter',
            ],
            [
                'question_key' => 'room_width_m',
                'section_instance_key' => 'room-1',
                'confidence' => 'high',
                'value' => ['number' => 4],
                'evidence' => '6 bij 4 meter',
            ],
            [
                'question_key' => 'ceiling_height_m',
                'section_instance_key' => 'room-1',
                'confidence' => 'high',
                'value' => ['number' => 2.6],
                'evidence' => 'plafond 2,6 meter',
            ],
            [
                'question_key' => 'outdoor_location',
                'section_instance_key' => null,
                'confidence' => 'high',
                'value' => ['value' => 'garden'],
                'evidence' => 'achtertuin',
            ],
            [
                'question_key' => 'outdoor_mount_type',
                'section_instance_key' => null,
                'confidence' => 'high',
                'value' => ['value' => 'ground'],
                'evidence' => 'op de grond',
            ],
            [
                'question_key' => 'brand_preference',
                'section_instance_key' => null,
                'confidence' => 'high',
                'value' => ['values' => ['no_preference']],
                'evidence' => null,
            ],
            [
                'question_key' => 'free_group_known',
                'section_instance_key' => null,
                'confidence' => 'high',
                'value' => ['value' => 'unknown'],
                'evidence' => 'stroom weet ik niets',
            ],
        ],
    ]);

    app(DeriveIntentFromRequest::class)->handle($intake);

    expect($intake->answers()->where('question_key', 'room_length_m')->where('section_instance_key', 'room-1')->firstOrFail()->value)
        ->toBe(['number' => 6])
        ->and($intake->answers()->where('question_key', 'room_width_m')->where('section_instance_key', 'room-1')->firstOrFail()->value)
        ->toBe(['number' => 4])
        ->and($intake->answers()->where('question_key', 'ceiling_height_m')->where('section_instance_key', 'room-1')->firstOrFail()->value)
        ->toBe(['number' => 2.6])
        ->and($intake->answers()->where('question_key', 'room_length_m')->firstOrFail()->prefill_source)->toBe(PrefillSources::AI_TEXT)
        ->and($intake->answers()->where('question_key', 'room_area_m2')->where('section_instance_key', 'room-1')->firstOrFail()->value['number'])
        ->toEqual(24)
        ->and($intake->answers()->where('question_key', 'room_area_m2')->firstOrFail()->prefill_source)->toBe(PrefillSources::DERIVED_LXW)
        ->and($intake->answers()->where('question_key', 'free_group_known')->exists())->toBeFalse();

    $steps = klanttestStepRefs($intake);
    $keys = array_column($steps, 'question_key');

    expect($keys)->toContain('_known_summary')
        ->and($keys)->not->toContain('room_length_m')
        ->and($keys)->not->toContain('room_width_m')
        ->and($keys)->not->toContain('ceiling_height_m')
        ->and($keys)->not->toContain('room_area_m2')
        ->and($keys)->not->toContain('room_size_indication')
        ->and($keys)->not->toContain('brand_preference')
        ->and($keys)->toContain('room_photos');

    $reasonIndex = array_search('request_reason', $keys, true);
    $summaryIndex = array_search('_known_summary', $keys, true);
    $roomPhotoIndex = array_search('room_photos', $keys, true);
    $sunIndex = array_search('sun_exposure', $keys, true);
    $glassIndex = array_search('glass_amount', $keys, true);

    expect($summaryIndex)->toBeInt()
        ->and($roomPhotoIndex)->toBeInt()
        ->and($sunIndex)->toBeInt()
        ->and($glassIndex)->toBeInt()
        ->and($roomPhotoIndex)->toBeLessThan($sunIndex)
        ->and($roomPhotoIndex)->toBeLessThan($glassIndex);

    if ($reasonIndex !== false) {
        expect($summaryIndex)->toBeGreaterThan($reasonIndex);
    }

    $version = $intake->templateVersion()->with(['sections.questions'])->firstOrFail();
    $brand = $version->sections->firstWhere('key', 'closing')?->questions->firstWhere('key', 'brand_preference')
        ?? $version->sections->firstWhere('key', 'request')?->questions->firstWhere('key', 'brand_preference');
    expect($brand?->is_required)->toBeFalse();
});

test('case 80: tekstfeit grond achtertuin overleeft buitenfoto', function () {
    $intake = klanttestIntake();
    klanttestReason($intake, CASE_80_REASON);

    FakeAiClient::alwaysReturn([
        'evidence' => 'Grond achtertuin.',
        'fills' => [
            ['question_key' => 'cooling_heating', 'section_instance_key' => null, 'confidence' => 'high', 'value' => ['value' => 'cooling'], 'evidence' => null],
            ['question_key' => 'indoor_unit_count', 'section_instance_key' => null, 'confidence' => 'high', 'value' => ['number' => 1], 'evidence' => null],
            ['question_key' => 'room_type', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'value' => ['value' => 'living_room'], 'evidence' => null],
            ['question_key' => 'outdoor_location', 'section_instance_key' => null, 'confidence' => 'high', 'value' => ['value' => 'garden'], 'evidence' => 'achtertuin'],
            ['question_key' => 'outdoor_mount_type', 'section_instance_key' => null, 'confidence' => 'high', 'value' => ['value' => 'ground'], 'evidence' => 'op de grond'],
        ],
    ]);

    app(DeriveIntentFromRequest::class)->handle($intake);

    $fixture = base_path('tests/fixtures/klanttest-20261002/buitenunit-flow.jpeg');
    expect(is_file($fixture))->toBeTrue();

    FakeAiClient::reset();
    FakeAiClient::alwaysReturn([
        'outdoor_location' => 'facade',
        'outdoor_mount_type' => 'wall',
        'outdoor_accessibility' => 'ladder',
        'confidence' => 'high',
        'evidence' => 'Foto suggereert gevelbeugel.',
        'retake_instruction' => null,
    ]);

    app(StoreIntakeUpload::class)->handle(
        $intake,
        'outdoor_location_photos',
        null,
        new UploadedFile($fixture, 'buitenunit-leiding.jpeg', 'image/jpeg', null, true),
    );

    app(DerivePhotoAnswers::class)->handle(
        $intake,
        'outdoor_location_photos',
        null,
        PhotoDerivationProfile::require('outdoor'),
    );

    expect($intake->answers()->where('question_key', 'outdoor_location')->firstOrFail()->value)
        ->toBe(['value' => 'garden'])
        ->and($intake->answers()->where('question_key', 'outdoor_location')->firstOrFail()->prefill_source)
        ->toBe(PrefillSources::AI_TEXT)
        ->and($intake->answers()->where('question_key', 'outdoor_mount_type')->firstOrFail()->value)
        ->toBe(['value' => 'ground'])
        ->and($intake->answers()->where('question_key', 'outdoor_mount_type')->firstOrFail()->prefill_source)
        ->toBe(PrefillSources::AI_TEXT);

    $points = IntakeAttentionPoint::query()
        ->where('intake_id', $intake->id)
        ->where('status', AttentionPointStatus::Proposed)
        ->where('code', 'like', 'photo_text_conflict:%')
        ->get();

    expect($points->count())->toBeGreaterThan(0);
});

test('case 81: alle expliciete feiten opgeslagen met bron; stroom/condens niet uit tekst', function () {
    $intake = klanttestIntake();
    klanttestReason($intake, CASE_81_REASON);

    FakeAiClient::alwaysReturn([
        'evidence' => 'Twee slaapkamers koelen+verwarmen met namen, maten, woningfeiten en onbekende stroom/condens.',
        'fills' => [
            ['question_key' => 'cooling_heating', 'section_instance_key' => null, 'confidence' => 'high', 'value' => ['value' => 'both'], 'evidence' => 'koelen en verwarmen'],
            ['question_key' => 'indoor_unit_count', 'section_instance_key' => null, 'confidence' => 'high', 'value' => ['number' => 2], 'evidence' => 'twee slaapkamers'],
            ['question_key' => 'room_name', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'value' => ['text' => 'Slaapkamer ouders'], 'evidence' => null],
            ['question_key' => 'room_type', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'value' => ['value' => 'bedroom'], 'evidence' => null],
            ['question_key' => 'floor_level', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'value' => ['value' => '1'], 'evidence' => '1e verdieping'],
            ['question_key' => 'room_length_m', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'value' => ['number' => 4], 'evidence' => '4 bij 3'],
            ['question_key' => 'room_width_m', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'value' => ['number' => 3], 'evidence' => '4 bij 3'],
            ['question_key' => 'ceiling_height_m', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'value' => ['number' => 2.5], 'evidence' => 'plafond 2,5'],
            ['question_key' => 'room_name', 'section_instance_key' => 'room-2', 'confidence' => 'high', 'value' => ['text' => 'Kinderkamer'], 'evidence' => null],
            ['question_key' => 'room_type', 'section_instance_key' => 'room-2', 'confidence' => 'high', 'value' => ['value' => 'bedroom'], 'evidence' => null],
            ['question_key' => 'floor_level', 'section_instance_key' => 'room-2', 'confidence' => 'high', 'value' => ['value' => '1'], 'evidence' => '1e verdieping'],
            ['question_key' => 'room_length_m', 'section_instance_key' => 'room-2', 'confidence' => 'high', 'value' => ['number' => 3], 'evidence' => '3 bij 3'],
            ['question_key' => 'room_width_m', 'section_instance_key' => 'room-2', 'confidence' => 'high', 'value' => ['number' => 3], 'evidence' => '3 bij 3'],
            ['question_key' => 'ceiling_height_m', 'section_instance_key' => 'room-2', 'confidence' => 'high', 'value' => ['number' => 2.5], 'evidence' => 'plafond 2,5'],
            ['question_key' => 'ownership', 'section_instance_key' => null, 'confidence' => 'high', 'value' => ['value' => 'owned'], 'evidence' => 'koopwoning'],
            ['question_key' => 'insulation_indication', 'section_instance_key' => null, 'confidence' => 'high', 'value' => ['value' => 'good'], 'evidence' => 'goed geïsoleerd'],
            ['question_key' => 'floor_insulation', 'section_instance_key' => null, 'confidence' => 'high', 'value' => ['value' => 'yes'], 'evidence' => 'vloerisolatie'],
            ['question_key' => 'crawl_space_present', 'section_instance_key' => null, 'confidence' => 'high', 'value' => ['value' => 'yes'], 'evidence' => 'kruipruimte'],
            ['question_key' => 'brand_preference', 'section_instance_key' => null, 'confidence' => 'high', 'value' => ['values' => ['no_preference']], 'evidence' => 'geen merkvoorkeur'],
            ['question_key' => 'desired_planning', 'section_instance_key' => null, 'confidence' => 'high', 'value' => ['value' => 'no_rush'], 'evidence' => 'geen haast'],
            ['question_key' => 'outdoor_location', 'section_instance_key' => null, 'confidence' => 'high', 'value' => ['value' => 'facade'], 'evidence' => 'achtergevel'],
            ['question_key' => 'noise_sensitive', 'section_instance_key' => null, 'confidence' => 'high', 'value' => ['bool' => true], 'evidence' => 'buren dichtbij'],
            ['question_key' => 'pipe_visibility', 'section_instance_key' => null, 'confidence' => 'high', 'value' => ['value' => 'visible'], 'evidence' => 'leidinggoot prima'],
            // TechnicalDecisionKeys: mogen niet uit tekst komen.
            ['question_key' => 'free_group_known', 'section_instance_key' => null, 'confidence' => 'high', 'value' => ['value' => 'unknown'], 'evidence' => 'stroom weet ik niets'],
            ['question_key' => 'natural_fall_possible', 'section_instance_key' => null, 'confidence' => 'high', 'value' => ['bool' => true], 'evidence' => 'mag niet'],
        ],
    ]);

    app(DeriveIntentFromRequest::class)->handle($intake);

    $answer = static function (string $key, ?string $instance = null) use ($intake) {
        $query = $intake->answers()->where('question_key', $key);
        $instance === null
            ? $query->whereNull('section_instance_key')
            : $query->where('section_instance_key', $instance);

        return $query->firstOrFail();
    };

    expect($answer('cooling_heating')->value)->toBe(['value' => 'both'])
        ->and($answer('cooling_heating')->prefill_source)->toBe(PrefillSources::AI_TEXT)
        ->and($answer('room_name', 'room-1')->value)->toBe(['text' => 'Slaapkamer ouders'])
        ->and($answer('room_name', 'room-2')->value)->toBe(['text' => 'Kinderkamer'])
        ->and($answer('floor_level', 'room-1')->value)->toBe(['value' => '1'])
        ->and($answer('room_length_m', 'room-1')->value)->toBe(['number' => 4])
        ->and($answer('room_width_m', 'room-1')->value)->toBe(['number' => 3])
        ->and($answer('ceiling_height_m', 'room-1')->value)->toBe(['number' => 2.5])
        ->and($answer('room_length_m', 'room-2')->value)->toBe(['number' => 3])
        ->and($answer('ceiling_height_m', 'room-2')->value)->toBe(['number' => 2.5])
        ->and($answer('ownership')->value)->toBe(['value' => 'owned'])
        ->and($answer('insulation_indication')->value)->toBe(['value' => 'good'])
        ->and($answer('floor_insulation')->value)->toBe(['value' => 'yes'])
        ->and($answer('crawl_space_present')->value)->toBe(['value' => 'yes'])
        ->and($answer('brand_preference')->value)->toBe(['values' => ['no_preference']])
        ->and($answer('desired_planning')->value)->toBe(['value' => 'no_rush'])
        ->and($answer('outdoor_location')->value)->toBe(['value' => 'facade'])
        ->and($answer('noise_sensitive')->value)->toBe(['bool' => true])
        ->and($answer('pipe_visibility')->value)->toBe(['value' => 'visible'])
        ->and($intake->answers()->where('question_key', 'free_group_known')->exists())->toBeFalse()
        ->and($intake->answers()->where('question_key', 'natural_fall_possible')->exists())->toBeFalse();

    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);
    $rooms = $intake->fresh()->aircoRooms()->orderBy('sort_order')->get();

    expect($rooms)->toHaveCount(2)
        ->and($rooms[0]->name)->toBe('Slaapkamer ouders')
        ->and($rooms[1]->name)->toBe('Kinderkamer')
        ->and($rooms[0]->name)->not->toBe($rooms[1]->name)
        ->and($rooms->pluck('name')->all())->not->toContain('Ruimte 1');

    $steps = klanttestStepRefs($intake);
    $photoSteps = collect($steps)->where('question_key', 'room_photos')->values();
    $summary = collect($steps)->firstWhere('question_key', '_known_summary');
    $summaryKeys = collect($summary['known_items'] ?? [])->pluck('question_key')->all();

    expect($photoSteps)->toHaveCount(2)
        ->and($photoSteps[0]['title'])->toContain('Slaapkamer ouders')
        ->and($photoSteps[1]['title'])->toContain('Kinderkamer')
        ->and($photoSteps[0]['section_title'])->toBe('Slaapkamer ouders')
        ->and($photoSteps[1]['section_title'])->toBe('Kinderkamer')
        ->and($summaryKeys)->not->toContain('fusebox_clarity')
        ->and($summaryKeys)->not->toContain('drain_location')
        ->and($summaryKeys)->not->toContain('free_group_known');
});

test('case 81: meterkastfoto als kamerfoto daarna echte slaapkamerfoto corrigeert room_type', function () {
    $intake = klanttestIntake();
    klanttestReason($intake, CASE_81_REASON);

    FakeAiClient::alwaysReturn([
        'evidence' => 'Twee benoemde slaapkamers zonder type-prefill op room-2.',
        'fills' => [
            ['question_key' => 'cooling_heating', 'section_instance_key' => null, 'confidence' => 'high', 'value' => ['value' => 'both'], 'evidence' => null],
            ['question_key' => 'indoor_unit_count', 'section_instance_key' => null, 'confidence' => 'high', 'value' => ['number' => 2], 'evidence' => null],
            ['question_key' => 'room_name', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'value' => ['text' => 'Slaapkamer ouders'], 'evidence' => null],
            ['question_key' => 'room_type', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'value' => ['value' => 'bedroom'], 'evidence' => null],
            ['question_key' => 'ceiling_height_m', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'value' => ['number' => 2.5], 'evidence' => null],
            ['question_key' => 'room_length_m', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'value' => ['number' => 4], 'evidence' => null],
            ['question_key' => 'room_width_m', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'value' => ['number' => 3], 'evidence' => null],
            // Geen room_name/room_type voor room-2: foto zet type en syncRooms leidt naam af.
            ['question_key' => 'ceiling_height_m', 'section_instance_key' => 'room-2', 'confidence' => 'high', 'value' => ['number' => 2.5], 'evidence' => null],
            ['question_key' => 'room_length_m', 'section_instance_key' => 'room-2', 'confidence' => 'high', 'value' => ['number' => 3], 'evidence' => null],
            ['question_key' => 'room_width_m', 'section_instance_key' => 'room-2', 'confidence' => 'high', 'value' => ['number' => 3], 'evidence' => null],
        ],
    ]);

    app(DeriveIntentFromRequest::class)->handle($intake);
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    $meterkast = base_path('tests/fixtures/klanttest-20261002/meterkast-klein.jpg');
    $woonkamer = base_path('tests/fixtures/klanttest-20261002/woonkamer-720.jpg');
    expect(is_file($meterkast))->toBeTrue()->and(is_file($woonkamer))->toBeTrue();

    FakeAiClient::reset();
    FakeAiClient::alwaysReturn([
        'room_type' => 'office',
        'room_size_indication' => 'small',
        'sun_exposure' => 'low',
        'glass_amount' => 'little',
        'room_outlet_status' => 'needs_photo',
        'confidence' => 'high',
        'evidence' => 'Verkeerde meterkastfoto als kamerfoto.',
        'retake_instruction' => null,
    ]);

    app(StoreIntakeUpload::class)->handle(
        $intake,
        'room_photos',
        'room-2',
        new UploadedFile($meterkast, 'meterkast-klein.jpg', 'image/jpeg', null, true),
    );

    app(DerivePhotoAnswers::class)->handle(
        $intake,
        'room_photos',
        'room-2',
        PhotoDerivationProfile::require('room'),
    );

    expect($intake->answers()->where('question_key', 'room_type')->where('section_instance_key', 'room-2')->firstOrFail()->value)
        ->toBe(['value' => 'office'])
        ->and($intake->answers()->where('question_key', 'room_type')->where('section_instance_key', 'room-2')->firstOrFail()->prefill_source)
        ->toBe(PrefillSources::AI_PHOTO)
        ->and($intake->answers()->where('question_key', 'room_type')->where('section_instance_key', 'room-1')->firstOrFail()->value)
        ->toBe(['value' => 'bedroom'])
        ->and($intake->answers()->where('question_key', 'room_name')->where('section_instance_key', 'room-1')->firstOrFail()->value)
        ->toBe(['text' => 'Slaapkamer ouders']);

    FakeAiClient::reset();
    FakeAiClient::alwaysReturn([
        'room_type' => 'bedroom',
        'room_size_indication' => 'medium',
        'sun_exposure' => 'medium',
        'glass_amount' => 'average',
        'room_outlet_status' => 'present',
        'confidence' => 'high',
        'evidence' => 'Echte slaapkamerfoto.',
        'retake_instruction' => null,
    ]);

    app(StoreIntakeUpload::class)->handle(
        $intake,
        'room_photos',
        'room-2',
        new UploadedFile($woonkamer, 'woonkamer-720.jpg', 'image/jpeg', null, true),
    );

    app(DerivePhotoAnswers::class)->handle(
        $intake,
        'room_photos',
        'room-2',
        PhotoDerivationProfile::require('room'),
    );

    expect($intake->answers()->where('question_key', 'room_type')->where('section_instance_key', 'room-2')->firstOrFail()->value)
        ->toBe(['value' => 'bedroom'])
        ->and($intake->answers()->where('question_key', 'room_type')->where('section_instance_key', 'room-2')->firstOrFail()->prefill_source)
        ->toBe(PrefillSources::AI_PHOTO)
        ->and($intake->answers()->where('question_key', 'ceiling_height_m')->where('section_instance_key', 'room-1')->firstOrFail()->value)
        ->toBe(['number' => 2.5])
        ->and($intake->answers()->where('question_key', 'ceiling_height_m')->where('section_instance_key', 'room-2')->firstOrFail()->value)
        ->toBe(['number' => 2.5]);

    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);
    $room2 = $intake->fresh()->aircoRooms()->where('key', 'room-2')->firstOrFail();
    expect($room2->use_type)->toBe('bedroom')
        ->and($room2->name)->toStartWith('Slaapkamer');
});

test('case 81: foto-update op kamer B laat tekstfeiten kamer A intact', function () {
    $intake = klanttestIntake();
    klanttestReason($intake, CASE_81_REASON);

    FakeAiClient::alwaysReturn([
        'evidence' => 'Twee benoemde slaapkamers.',
        'fills' => [
            ['question_key' => 'cooling_heating', 'section_instance_key' => null, 'confidence' => 'high', 'value' => ['value' => 'both'], 'evidence' => null],
            ['question_key' => 'indoor_unit_count', 'section_instance_key' => null, 'confidence' => 'high', 'value' => ['number' => 2], 'evidence' => null],
            ['question_key' => 'room_name', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'value' => ['text' => 'Slaapkamer ouders'], 'evidence' => null],
            ['question_key' => 'room_type', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'value' => ['value' => 'bedroom'], 'evidence' => null],
            ['question_key' => 'ceiling_height_m', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'value' => ['number' => 2.5], 'evidence' => null],
            ['question_key' => 'room_length_m', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'value' => ['number' => 4], 'evidence' => null],
            ['question_key' => 'room_width_m', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'value' => ['number' => 3], 'evidence' => null],
            ['question_key' => 'room_name', 'section_instance_key' => 'room-2', 'confidence' => 'high', 'value' => ['text' => 'Kinderkamer'], 'evidence' => null],
            ['question_key' => 'room_type', 'section_instance_key' => 'room-2', 'confidence' => 'high', 'value' => ['value' => 'bedroom'], 'evidence' => null],
            ['question_key' => 'ceiling_height_m', 'section_instance_key' => 'room-2', 'confidence' => 'high', 'value' => ['number' => 2.5], 'evidence' => null],
            ['question_key' => 'room_length_m', 'section_instance_key' => 'room-2', 'confidence' => 'high', 'value' => ['number' => 3], 'evidence' => null],
            ['question_key' => 'room_width_m', 'section_instance_key' => 'room-2', 'confidence' => 'high', 'value' => ['number' => 3], 'evidence' => null],
        ],
    ]);

    app(DeriveIntentFromRequest::class)->handle($intake);
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    $fixture = base_path('tests/fixtures/klanttest-20261002/meterkast-klein.jpg');
    expect(is_file($fixture))->toBeTrue();

    FakeAiClient::reset();
    FakeAiClient::alwaysReturn([
        'room_type' => 'office',
        'room_size_indication' => 'small',
        'sun_exposure' => 'low',
        'glass_amount' => 'little',
        'room_outlet_status' => 'needs_photo',
        'confidence' => 'high',
        'evidence' => 'Verkeerde foto-analyse op kamer B.',
        'retake_instruction' => null,
    ]);

    app(StoreIntakeUpload::class)->handle(
        $intake,
        'room_photos',
        'room-2',
        new UploadedFile($fixture, 'meterkast-klein.jpg', 'image/jpeg', null, true),
    );

    app(DerivePhotoAnswers::class)->handle(
        $intake,
        'room_photos',
        'room-2',
        PhotoDerivationProfile::require('room'),
    );

    // Tekstfeiten blijven staan; room_type-conflict → geen klantvelden uit die run (alleen aandachtspunt).
    expect($intake->answers()->where('question_key', 'room_name')->where('section_instance_key', 'room-1')->firstOrFail()->value)
        ->toBe(['text' => 'Slaapkamer ouders'])
        ->and($intake->answers()->where('question_key', 'room_type')->where('section_instance_key', 'room-1')->firstOrFail()->value)
        ->toBe(['value' => 'bedroom'])
        ->and($intake->answers()->where('question_key', 'ceiling_height_m')->where('section_instance_key', 'room-1')->firstOrFail()->value)
        ->toBe(['number' => 2.5])
        ->and($intake->answers()->where('question_key', 'room_length_m')->where('section_instance_key', 'room-1')->firstOrFail()->value)
        ->toBe(['number' => 4])
        ->and($intake->answers()->where('question_key', 'room_name')->where('section_instance_key', 'room-2')->firstOrFail()->value)
        ->toBe(['text' => 'Kinderkamer'])
        ->and($intake->answers()->where('question_key', 'room_type')->where('section_instance_key', 'room-2')->firstOrFail()->value)
        ->toBe(['value' => 'bedroom'])
        ->and($intake->answers()->where('question_key', 'ceiling_height_m')->where('section_instance_key', 'room-2')->firstOrFail()->value)
        ->toBe(['number' => 2.5])
        ->and($intake->answers()->where('question_key', 'sun_exposure')->where('section_instance_key', 'room-2')->exists())->toBeFalse()
        ->and($intake->answers()->where('question_key', 'glass_amount')->where('section_instance_key', 'room-2')->exists())->toBeFalse()
        ->and($intake->answers()->where('question_key', 'room_size_indication')->where('section_instance_key', 'room-2')->whereIn('prefill_source', [PrefillSources::AI_PHOTO, PrefillSources::AI_PHOTO_SUGGESTION])->exists())->toBeFalse()
        ->and($intake->answers()->where('question_key', 'sun_exposure')->where('section_instance_key', 'room-1')->exists())->toBeFalse();

    expect(IntakeAttentionPoint::query()
        ->where('intake_id', $intake->id)
        ->where('code', 'photo_text_conflict:room_type:room-2')
        ->exists())->toBeTrue();

    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);
    $rooms = $intake->fresh()->aircoRooms()->orderBy('sort_order')->get();

    expect($rooms[0]->name)->toBe('Slaapkamer ouders')
        ->and($rooms[1]->name)->toBe('Kinderkamer')
        ->and((float) ($rooms[0]->dimensions['height_m'] ?? 0))->toBe(2.5)
        ->and((float) ($rooms[0]->dimensions['length_m'] ?? 0))->toBe(4.0);
});

test('known-summary weigert fusebox_clarity en editKnownAnswer guard', function () {
    $intake = klanttestIntake();
    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'cooling_heating',
        null,
        ['value' => 'cooling'],
        PrefillSources::AI_TEXT,
    );
    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'fusebox_clarity',
        null,
        ['value' => 'clear'],
        PrefillSources::AI_PHOTO,
    );

    $steps = klanttestStepRefs($intake);
    $summary = collect($steps)->firstWhere('question_key', '_known_summary');
    $keys = collect($summary['known_items'] ?? [])->pluck('question_key')->all();

    expect($keys)->toContain('cooling_heating')
        ->and($keys)->not->toContain('fusebox_clarity');

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->call('editKnownAnswer', 'fusebox_clarity', null);

    expect($component->get('forceShowKnown'))->not->toContain('fusebox_clarity');
});

test('TechnicalDecisionKeys weigert tekst-prefill op technische sleutels', function () {
    $intake = klanttestIntake();
    klanttestReason($intake, 'Ik wil een airco in de woonkamer. Over de stroom weet ik niets.');

    FakeAiClient::alwaysReturn([
        'evidence' => 'Fake probeert technische sleutels te forceren.',
        'fills' => [
            ['question_key' => 'cooling_heating', 'section_instance_key' => null, 'confidence' => 'high', 'value' => ['value' => 'cooling'], 'evidence' => null],
            ['question_key' => 'indoor_unit_count', 'section_instance_key' => null, 'confidence' => 'high', 'value' => ['number' => 1], 'evidence' => null],
            ['question_key' => 'room_type', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'value' => ['value' => 'living_room'], 'evidence' => null],
            ['question_key' => 'natural_fall_possible', 'section_instance_key' => null, 'confidence' => 'high', 'value' => ['bool' => true], 'evidence' => 'verboden'],
            ['question_key' => 'drillings_needed', 'section_instance_key' => null, 'confidence' => 'high', 'value' => ['bool' => false], 'evidence' => 'verboden'],
            ['question_key' => 'pipe_route_description', 'section_instance_key' => null, 'confidence' => 'high', 'value' => ['text' => 'via kruipruimte'], 'evidence' => 'verboden'],
            ['question_key' => 'free_group_known', 'section_instance_key' => null, 'confidence' => 'high', 'value' => ['value' => 'unknown'], 'evidence' => 'verboden'],
            ['question_key' => 'pipe_distance_indication', 'section_instance_key' => null, 'confidence' => 'high', 'value' => ['value' => 'short'], 'evidence' => 'verboden'],
        ],
    ]);

    app(PrefillAnswersFromKnownContext::class)->handle($intake);

    expect($intake->answers()->where('question_key', 'cooling_heating')->exists())->toBeTrue()
        ->and($intake->answers()->where('question_key', 'natural_fall_possible')->exists())->toBeFalse()
        ->and($intake->answers()->where('question_key', 'drillings_needed')->exists())->toBeFalse()
        ->and($intake->answers()->where('question_key', 'pipe_route_description')->exists())->toBeFalse()
        ->and($intake->answers()->where('question_key', 'free_group_known')->exists())->toBeFalse()
        ->and($intake->answers()->where('question_key', 'pipe_distance_indication')->exists())->toBeFalse();
});

test('installateur-naam wint van syncRooms', function () {
    $intake = klanttestIntake();
    klanttestReason($intake, 'Twee slaapkamers koelen.');

    FakeAiClient::alwaysReturn([
        'evidence' => 'Twee slaapkamers.',
        'fills' => [
            ['question_key' => 'cooling_heating', 'section_instance_key' => null, 'confidence' => 'high', 'value' => ['value' => 'cooling'], 'evidence' => null],
            ['question_key' => 'indoor_unit_count', 'section_instance_key' => null, 'confidence' => 'high', 'value' => ['number' => 2], 'evidence' => null],
            ['question_key' => 'room_type', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'value' => ['value' => 'bedroom'], 'evidence' => null],
            ['question_key' => 'room_name', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'value' => ['text' => 'Slaapkamer ouders'], 'evidence' => null],
            ['question_key' => 'room_type', 'section_instance_key' => 'room-2', 'confidence' => 'high', 'value' => ['value' => 'bedroom'], 'evidence' => null],
            ['question_key' => 'room_name', 'section_instance_key' => 'room-2', 'confidence' => 'high', 'value' => ['text' => 'Kinderkamer'], 'evidence' => null],
        ],
    ]);

    app(DeriveIntentFromRequest::class)->handle($intake);
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    $room = $intake->fresh()->aircoRooms()->where('key', 'room-1')->firstOrFail();
    $installer = User::factory()->create(['company_id' => $intake->company_id]);

    app(AircoSurveyService::class)->updateRoom($intake, $installer, $room, [
        'name' => 'Master bedroom',
        'length_m' => 4.0,
        'width_m' => 3.0,
        'height_m' => 2.5,
    ]);

    expect($room->fresh()->name)->toBe('Master bedroom')
        ->and($room->fresh()->name_source)->toBe('installer');

    // sync opnieuw met AI-naam — installateur wint.
    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'room_name',
        'room-1',
        ['text' => 'Slaapkamer ouders'],
        PrefillSources::AI_TEXT,
    );
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    expect($intake->fresh()->aircoRooms()->where('key', 'room-1')->firstOrFail()->name)
        ->toBe('Master bedroom')
        ->and($intake->fresh()->aircoRooms()->where('key', 'room-1')->firstOrFail()->name_source)
        ->toBe('installer');
});

test('v18 skip-lijst bevat ai_text/ai_photo zonder installer-dubbel', function () {
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $roomLength = $version->sections->firstWhere('key', 'rooms')?->questions->firstWhere('key', 'room_length_m');
    $skip = $roomLength?->meta['skip_when_prefilled_by'] ?? [];

    expect($skip)->toContain('ai')
        ->and($skip)->toContain('ai_text')
        ->and($skip)->toContain('ai_photo')
        ->and($skip)->toContain('request_text')
        ->and($skip)->toContain('installer')
        ->and(array_count_values($skip)['request_text'] ?? 0)->toBe(1);

    $closing = $version->sections->firstWhere('key', 'closing');
    expect($closing?->questions->firstWhere('key', 'brand_preference'))->not->toBeNull()
        ->and($closing?->questions->firstWhere('key', 'desired_planning'))->not->toBeNull();
});

test('v16 legacy skip ai matcht ai_text/ai_photo in stepbuilder en known-summary', function () {
    seedAircoTemplateVersion(16);
    $template = IntakeTemplate::query()->where('key', 'airco')->firstOrFail();
    $v16 = $template->versions()->where('version', 16)->whereNotNull('published_at')->firstOrFail();

    $intake = Intake::factory()->create([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $v16->id,
        'status' => IntakeStatus::Sent,
        'customer_email' => 'v16-skip@example.com',
    ]);

    $roomType = $v16->sections()->with('questions')->get()
        ->flatMap(static fn ($section) => $section->questions)
        ->firstWhere('key', 'room_type');
    $skip = $roomType?->meta['skip_when_prefilled_by'] ?? null;
    $skipList = is_array($skip) ? $skip : ($skip !== null ? [$skip] : []);

    expect($skipList)->toContain('ai')
        ->and($skipList)->not->toContain('ai_text')
        ->and($skipList)->not->toContain('ai_photo')
        ->and(PrefillSources::matchesSkipSource(PrefillSources::AI_TEXT, $skipList))->toBeTrue()
        ->and(PrefillSources::matchesSkipSource(PrefillSources::AI_PHOTO, $skipList))->toBeTrue()
        ->and(KnownSummaryCatalog::isSkipped(PrefillSources::AI_TEXT, $roomType))->toBeTrue()
        ->and(KnownSummaryCatalog::isSkipped(PrefillSources::AI_PHOTO, $roomType))->toBeTrue();

    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'indoor_unit_count',
        null,
        ['number' => 1],
        PrefillSources::AI_TEXT,
    );
    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'room_type',
        'room-1',
        ['value' => 'bedroom'],
        PrefillSources::AI_TEXT,
    );
    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'sun_exposure',
        'room-1',
        ['value' => 'medium'],
        PrefillSources::AI_PHOTO,
    );

    $steps = klanttestStepRefs($intake);
    $keys = array_column($steps, 'question_key');

    expect($keys)->not->toContain('room_type')
        ->and($keys)->not->toContain('sun_exposure');
});

test('known-summary toont derived_lxw als berekend uit L×B en natural instance-volgorde', function () {
    $intake = klanttestIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 10], PrefillSources::AI_TEXT);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_length_m', 'room-2', ['number' => 4], PrefillSources::AI_TEXT);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_width_m', 'room-2', ['number' => 3], PrefillSources::AI_TEXT);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_length_m', 'room-10', ['number' => 5], PrefillSources::AI_TEXT);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_width_m', 'room-10', ['number' => 4], PrefillSources::AI_TEXT);

    $steps = klanttestStepRefs($intake->fresh() ?? $intake);
    $summary = collect($steps)->firstWhere('question_key', '_known_summary');
    $items = collect($summary['known_items'] ?? []);

    $areaItems = $items->where('question_key', 'room_area_m2')->values();
    expect($areaItems)->not->toBeEmpty()
        ->and($areaItems->every(static fn (array $item): bool => $item['display_value'] === 'berekend uit L×B'))->toBeTrue();

    $instances = $items->pluck('section_instance_key')->filter()->unique()->values()->all();
    expect($instances)->toBe(['room-2', 'room-10']);
});
