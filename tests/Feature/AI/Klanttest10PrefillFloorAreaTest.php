<?php

declare(strict_types=1);

/**
 * Klanttest 10 okt P1: model mag verdieping niet naar alle kamers kopiëren;
 * letterlijke m² (stated/high) landt als ai_text zodat de vraag kan vervallen.
 */

use App\Domains\AI\Actions\DeriveIntentFromRequest;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\AiRunStatus;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    FakeAiClient::reset();
    config(['ai.provider' => 'fake', 'ai.text_inference.enabled' => true]);
});

afterEach(function () {
    FakeAiClient::reset();
});

function kt10PrefillIntake(): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_email' => 'kt10-prefill@example.com',
    ]);
}

test('model floor_level op één slaapkamer wordt niet naar andere ruimtes gekopieerd', function () {
    $text = 'De klant wil airco voor de woonkamer en twee slaapkamers, waarvan één op de eerste verdieping. Vooral koelen en eventueel verwarmen.';
    $intake = kt10PrefillIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'request_reason', null, ['text' => $text]);

    FakeAiClient::alwaysReturn([
        'evidence' => 'waarvan één op de eerste verdieping',
        'fills' => [
            ['question_key' => 'cooling_heating', 'section_instance_key' => null, 'confidence' => 'high', 'provenance' => 'stated', 'value' => ['value' => 'both'], 'evidence' => 'koelen'],
            ['question_key' => 'indoor_unit_count', 'section_instance_key' => null, 'confidence' => 'high', 'provenance' => 'stated', 'value' => ['number' => 3], 'evidence' => 'twee slaapkamers'],
            ['question_key' => 'room_name', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'provenance' => 'stated', 'value' => ['text' => 'Woonkamer'], 'evidence' => 'woonkamer'],
            ['question_key' => 'room_type', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'provenance' => 'stated', 'value' => ['value' => 'living_room'], 'evidence' => 'woonkamer'],
            ['question_key' => 'room_name', 'section_instance_key' => 'room-2', 'confidence' => 'high', 'provenance' => 'stated', 'value' => ['text' => 'Slaapkamer'], 'evidence' => 'slaapkamers'],
            ['question_key' => 'room_type', 'section_instance_key' => 'room-2', 'confidence' => 'high', 'provenance' => 'stated', 'value' => ['value' => 'bedroom'], 'evidence' => 'slaapkamers'],
            ['question_key' => 'floor_level', 'section_instance_key' => 'room-2', 'confidence' => 'high', 'provenance' => 'stated', 'value' => ['value' => '1'], 'evidence' => 'eerste verdieping'],
            ['question_key' => 'room_name', 'section_instance_key' => 'room-3', 'confidence' => 'high', 'provenance' => 'stated', 'value' => ['text' => 'Slaapkamer'], 'evidence' => 'slaapkamers'],
            ['question_key' => 'room_type', 'section_instance_key' => 'room-3', 'confidence' => 'high', 'provenance' => 'stated', 'value' => ['value' => 'bedroom'], 'evidence' => 'slaapkamers'],
        ],
    ]);

    $run = app(DeriveIntentFromRequest::class)->handle($intake);

    expect($run?->status)->toBe(AiRunStatus::Succeeded);

    $floors = $intake->answers()
        ->where('question_key', 'floor_level')
        ->get()
        ->mapWithKeys(fn ($a) => [(string) $a->section_instance_key => $a->value['value'] ?? null])
        ->all();

    expect($floors)->toBe(['room-2' => '1'])
        ->and($intake->answers()->where('question_key', 'floor_level')->where('section_instance_key', 'room-1')->exists())->toBeFalse()
        ->and($intake->answers()->where('question_key', 'floor_level')->where('section_instance_key', 'room-3')->exists())->toBeFalse();
});

test('letterlijke room_area_m2 high/stated landt als ai_text zonder L×B', function () {
    $text = 'Hallo, wij zoeken een airco voor onze woonkamer. Alleen koelen. Woonkamer is ongeveer 25 m2.';
    $intake = kt10PrefillIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'request_reason', null, ['text' => $text]);

    FakeAiClient::alwaysReturn([
        'evidence' => 'Woonkamer is ongeveer 25 m2',
        'fills' => [
            ['question_key' => 'cooling_heating', 'section_instance_key' => null, 'confidence' => 'high', 'provenance' => 'stated', 'value' => ['value' => 'cooling'], 'evidence' => 'Alleen koelen'],
            ['question_key' => 'indoor_unit_count', 'section_instance_key' => null, 'confidence' => 'high', 'provenance' => 'stated', 'value' => ['number' => 1], 'evidence' => 'woonkamer'],
            ['question_key' => 'room_name', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'provenance' => 'stated', 'value' => ['text' => 'Woonkamer'], 'evidence' => 'woonkamer'],
            ['question_key' => 'room_type', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'provenance' => 'stated', 'value' => ['value' => 'living_room'], 'evidence' => 'woonkamer'],
            ['question_key' => 'room_area_m2', 'section_instance_key' => 'room-1', 'confidence' => 'high', 'provenance' => 'stated', 'value' => ['number' => 25], 'evidence' => '25 m2'],
        ],
    ]);

    $run = app(DeriveIntentFromRequest::class)->handle($intake);

    expect($run?->status)->toBe(AiRunStatus::Succeeded);

    $area = $intake->answers()
        ->where('question_key', 'room_area_m2')
        ->where('section_instance_key', 'room-1')
        ->firstOrFail();

    expect($area->value)->toBe(['number' => 25])
        ->and($area->prefill_source)->toBe(PrefillSources::AI_TEXT)
        ->and($intake->answers()->where('question_key', 'room_length_m')->exists())->toBeFalse()
        ->and($intake->answers()->where('question_key', 'room_width_m')->exists())->toBeFalse();
});
