<?php

declare(strict_types=1);

/**
 * Staging-regressie klanttest 2 okt case 80/81 (demo intakes 67/68):
 * catalogus-AI dumpte álle fills wanneer top-level evidence > 500 tekens was
 * (plausibel bij lange multi-room openingszin). Lokale parser geeft null bij
 * herhaald kamertype, dus zonder soft-envelope bleef de opname leeg.
 */

use App\Domains\AI\Actions\DeriveIntentFromRequest;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Models\AiTrace;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\AiRunStatus;
use App\Enums\AiTraceCallType;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    FakeAiClient::reset();
    config(['ai.provider' => 'fake', 'ai.text_inference.enabled' => true, 'ai.tracing.enabled' => true]);
});

afterEach(function () {
    FakeAiClient::reset();
});

/** Exacte stagingtekst case 80 (werkt al; regressiebescherming). */
const STAGING_CASE_80 = 'Fictieve QA-test. Ik wil alleen koelen in de woonkamer. De woonkamer is 6 bij 4 meter en het plafond is 2,6 meter hoog. De binnenunit wil ik boven de bank aan de buitenmuur. De buitenunit het liefst op de grond in de achtertuin. Over de stroomvoorziening weet ik niets.';

/** Exacte stagingtekst case 81 (faalde: 0 kamers, vraag 1 opnieuw). */
const STAGING_CASE_81 = 'Fictieve QA-test. Ik wil twee slaapkamers koelen en verwarmen. Slaapkamer ouders op de 1e verdieping: 4 bij 3 meter, plafond 2,5 meter. Kinderkamer op de 1e verdieping: 3 bij 3 meter, plafond 2,5 meter. Het is een goed geïsoleerde koopwoning met vloerisolatie en een kruipruimte. Ik heb geen merkvoorkeur en geen haast. Een buitenunit tegen de achtergevel; de buren zitten dichtbij. Zichtbare leidingen in een goot vind ik prima. Over de stroomvoorziening en condensafvoer weet ik niets. De installateur moet technische keuzes bepalen.';

function stagingPrefillIntake(): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_email' => 'staging-case@example.com',
    ]);
}

test('staging case 80: catalogus-AI vult woonkamer + maten + koelen', function () {
    expect(mb_strlen(STAGING_CASE_80))->toBeLessThanOrEqual(500);

    $intake = stagingPrefillIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'request_reason', null, ['text' => STAGING_CASE_80]);

    // Plausibele modelrespons: evidence ≈ openingszin (past onder 500).
    FakeAiClient::alwaysReturn([
        'evidence' => STAGING_CASE_80,
        'fills' => [
            ['question_key' => 'cooling_heating', 'section_instance_key' => null, 'confidence' => 'high', 'value' => ['value' => 'cooling'], 'evidence' => 'alleen koelen'],
            ['question_key' => 'indoor_unit_count', 'section_instance_key' => null, 'confidence' => 'high', 'value' => ['number' => 1], 'evidence' => 'woonkamer'],
            ['question_key' => 'room_name', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'value' => ['text' => 'Woonkamer'], 'evidence' => null],
            ['question_key' => 'room_type', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'value' => ['value' => 'living_room'], 'evidence' => null],
            ['question_key' => 'room_length_m', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'value' => ['number' => 6], 'evidence' => '6 bij 4'],
            ['question_key' => 'room_width_m', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'value' => ['number' => 4], 'evidence' => '6 bij 4'],
            ['question_key' => 'ceiling_height_m', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'value' => ['number' => 2.6], 'evidence' => '2,6 meter'],
            ['question_key' => 'outdoor_location', 'section_instance_key' => null, 'confidence' => 'high', 'value' => ['value' => 'garden'], 'evidence' => 'achtertuin'],
            ['question_key' => 'outdoor_mount_type', 'section_instance_key' => null, 'confidence' => 'high', 'value' => ['value' => 'ground'], 'evidence' => 'op de grond'],
            ['question_key' => 'free_group_known', 'section_instance_key' => null, 'confidence' => 'high', 'value' => ['value' => 'unknown'], 'evidence' => 'stroom weet ik niets'],
        ],
    ]);

    $run = app(DeriveIntentFromRequest::class)->handle($intake);

    expect($run?->status)->toBe(AiRunStatus::Succeeded)
        ->and($intake->answers()->where('question_key', 'cooling_heating')->firstOrFail()->value)
        ->toBe(['value' => 'cooling'])
        ->and($intake->answers()->where('question_key', 'room_length_m')->where('section_instance_key', 'room-1')->firstOrFail()->value)
        ->toBe(['number' => 6])
        ->and($intake->answers()->where('question_key', 'ceiling_height_m')->firstOrFail()->value)
        ->toBe(['number' => 2.6])
        ->and($intake->answers()->where('question_key', 'outdoor_mount_type')->firstOrFail()->value)
        ->toBe(['value' => 'ground'])
        ->and($intake->answers()->where('question_key', 'free_group_known')->exists())->toBeFalse();

    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);
    expect($intake->fresh()->aircoRooms)->toHaveCount(1)
        ->and($intake->fresh()->aircoRooms->first()->name)->toBe('Woonkamer');
});

test('staging case 81: lange evidence + één kapotte fill dumpt niet de multi-room extractie', function () {
    expect(mb_strlen(STAGING_CASE_81))->toBeGreaterThan(500);

    $intake = stagingPrefillIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'request_reason', null, ['text' => STAGING_CASE_81]);

    // Plausibele echte-modelrespons: echo van de hele openingszin als evidence (>500)
    // plus één scalar-value glitch — vóór de fix: ValidationException → 0 fills.
    FakeAiClient::alwaysReturn([
        'evidence' => STAGING_CASE_81,
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
            // Kapotte fill: mag alleen deze verwerpen.
            ['question_key' => 'ownership', 'section_instance_key' => null, 'confidence' => 'high', 'value' => 'owned', 'evidence' => 'scalar glitch'],
            // Technische sleutels: niet als klantprefill opslaan.
            ['question_key' => 'free_group_known', 'section_instance_key' => null, 'confidence' => 'high', 'value' => ['value' => 'unknown'], 'evidence' => 'stroom onbekend'],
            ['question_key' => 'natural_fall_possible', 'section_instance_key' => null, 'confidence' => 'high', 'value' => ['bool' => false], 'evidence' => 'condens onbekend'],
        ],
    ]);

    $run = app(DeriveIntentFromRequest::class)->handle($intake);

    expect($run?->status)->toBe(AiRunStatus::Succeeded);

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
        ->and($answer('room_width_m', 'room-2')->value)->toBe(['number' => 3])
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
        ->and($rooms[1]->name)->toBe('Kinderkamer');

    $trace = AiTrace::query()
        ->where('intake_id', $intake->id)
        ->where('call_type', AiTraceCallType::TextExtraction)
        ->whereNotNull('ai_run_id')
        ->latest('id')
        ->first();

    expect($trace)->not->toBeNull()
        ->and($trace->validation_errors)->toBeArray()
        ->and($trace->validation_errors)->toHaveKey('evidence')
        ->and($trace->field_outcomes)->toBeArray();

    $rejectedOwnership = collect($trace->field_outcomes)
        ->first(static fn (array $row): bool => ($row['question_key'] ?? null) === 'ownership'
            && ($row['disposition'] ?? null) === 'rejected');

    expect($rejectedOwnership)->not->toBeNull()
        ->and((string) ($rejectedOwnership['reason'] ?? ''))->toContain('object');

    expect(AiRun::query()->whereKey($run->id)->firstOrFail()->status)->toBe(AiRunStatus::Succeeded);
});
