<?php

declare(strict_types=1);

use App\Domains\AI\Actions\PrefillAnswersFromKnownContext;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Clients\OpenAiClient;
use App\Domains\AI\DTOs\AiCompletionRequest;
use App\Domains\AI\Models\AiTrace;
use App\Domains\AI\Models\AiTraceStep;
use App\Domains\AI\Services\AiInputRedactor;
use App\Domains\AI\Services\AiTraceExporter;
use App\Domains\AI\Services\AiTraceRedactor;
use App\Domains\AI\Services\RequestPrefillContextBuilder;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Enums\AiTraceCallType;
use App\Enums\AiTraceStatus;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    FakeAiClient::reset();
    config([
        'ai.tracing.enabled' => true,
        'ai.provider' => 'fake',
        'ai.text_inference.enabled' => true,
        'ai.budget.daily_cents' => 1000,
        'ai.budget.monthly_cents' => 10000,
        'ai.budget.reserve_cents_per_call' => 1,
        'ai.budget.input_cents_per_1k_tokens' => 1,
        'ai.budget.output_cents_per_1k_tokens' => 2,
    ]);
});

afterEach(function () {
    FakeAiClient::reset();
});

function makeExportPrivacyIntake(array $overrides = []): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create(array_merge([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_name' => 'Sophie Vermeer',
        'customer_email' => 'sophie@example.com',
        'customer_phone' => '0612345678',
        'address_line' => 'Lindenlaan 22b',
    ], $overrides));
}

test('export fixture houdt technische antwoorden leesbaar en maskeert alleen echte PII', function () {
    $intake = makeExportPrivacyIntake();

    $trace = AiTrace::query()->create([
        'trace_id' => (string) Str::uuid(),
        'correlation_id' => (string) Str::uuid(),
        'request_id' => (string) Str::uuid(),
        'intake_id' => $intake->id,
        'intake_ref_id' => $intake->id,
        'is_demo' => false,
        'call_type' => AiTraceCallType::TextExtraction,
        'status' => AiTraceStatus::Succeeded,
        'provider' => 'fake',
        'model' => 'fake-v1',
        'prompt_version' => 'request-prefill-v10',
        'request_snapshot' => [
            'system' => 'prefill',
            'user' => [
                'customer_name' => 'Sophie Vermeer',
                'customer_email' => 'sophie@example.com',
                'address_line' => 'Lindenlaan 22b',
                'known_context' => [
                    'answers' => [
                        [
                            'question_key' => 'room_name',
                            'value' => ['text' => 'Slaapkamer ouders'],
                        ],
                        [
                            'question_key' => 'brand_preference',
                            'value' => ['values' => ['mitsubishi']],
                        ],
                        [
                            'question_key' => 'drain_location',
                            'value' => ['value' => 'unknown', 'label' => 'Weet ik niet'],
                        ],
                        [
                            'question_key' => 'brand_label',
                            'value' => ['text' => 'Mitsubishi Electric'],
                        ],
                    ],
                    'external_facts' => [
                        [
                            'fact_key' => 'building_year',
                            'value' => ['number' => 1985],
                        ],
                        [
                            'fact_key' => 'floor_area_m2',
                            'value' => ['number' => 20.5],
                        ],
                    ],
                ],
                'note' => 'Bel Sophie Vermeer op 0612345678 of mail sophie@example.com bij Lindenlaan 22b.',
            ],
        ],
        'photo_refs' => [],
        'raw_response' => '{"ok":true}',
        'parsed_response' => [
            'fills' => [
                ['question_key' => 'room_name', 'value' => ['text' => 'Slaapkamer ouders']],
                ['question_key' => 'brand_preference', 'value' => ['values' => ['mitsubishi']]],
            ],
        ],
        'validation_errors' => [],
        'normalizations' => [],
        'input_tokens' => 10,
        'output_tokens' => 5,
        'total_tokens' => 15,
        'provider_ms' => 3,
        'process_ms' => 4,
        'estimated_cost_cents' => 1,
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    $payload = app(AiTraceExporter::class)->callPayload($trace);
    $encoded = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);

    expect($encoded)->not->toContain('Sophie Vermeer')
        ->and($encoded)->not->toContain('sophie@example.com')
        ->and($encoded)->not->toContain('0612345678')
        ->and($encoded)->not->toContain('Lindenlaan 22b')
        ->and($encoded)->toContain('[naam verwijderd]')
        ->and($encoded)->toContain('[e-mail verwijderd]')
        ->and($encoded)->toContain('[telefoon verwijderd]')
        ->and($encoded)->toContain('[adres verwijderd]')
        // Technical / answer content stays readable for debugging.
        ->and($encoded)->toContain('Slaapkamer ouders')
        ->and($encoded)->toContain('mitsubishi')
        ->and($encoded)->toContain('Mitsubishi Electric')
        ->and($encoded)->toContain('Weet ik niet')
        ->and($encoded)->toContain('building_year')
        ->and($encoded)->toContain('1985')
        ->and($encoded)->toContain('20.5');
});

test('AiTraceRedactor maskeert geen merk of Weet ik niet als persoonsnaam', function () {
    $redactor = app(AiTraceRedactor::class);

    $redacted = $redactor->redact([
        'room_name' => 'Slaapkamer ouders',
        'brand' => 'Mitsubishi Electric',
        'answer' => 'Weet Ik Niet',
        'free_text' => 'Merk Mitsubishi Electric, anders Weet Ik Niet.',
    ]);

    $json = (string) json_encode($redacted, JSON_UNESCAPED_UNICODE);

    expect($redacted['room_name'])->toBe('Slaapkamer ouders')
        ->and($redacted['brand'])->toBe('Mitsubishi Electric')
        ->and($redacted['answer'])->toBe('Weet Ik Niet')
        ->and($json)->toContain('Mitsubishi Electric')
        ->and($json)->toContain('Weet Ik Niet')
        ->and($json)->not->toContain('[naam verwijderd]');
});

test('prefill context en OpenAI-payload bevatten geen precieze coördinaten', function () {
    $intake = makeExportPrivacyIntake();

    $intake->externalFacts()->create([
        'fact_key' => 'location',
        'label' => 'Locatie',
        'value' => [
            'latitude' => 52.37714446,
            'longitude' => 4.89803846,
            'municipality' => 'Amsterdam',
            'province' => 'Noord-Holland',
        ],
        'source' => 'PDOK / BAG',
        'confidence' => 'high',
        'captured_at' => now(),
    ]);
    $intake->externalFacts()->create([
        'fact_key' => 'parcel_ids',
        'label' => 'Perceelreferentie',
        'value' => ['values' => ['NL.IMKAD.KadastraalObject.123456']],
        'source' => 'PDOK / BAG',
        'confidence' => 'high',
        'captured_at' => now(),
    ]);
    $intake->externalFacts()->create([
        'fact_key' => 'building_year',
        'label' => 'Bouwjaar',
        'value' => ['number' => 1985],
        'source' => 'PDOK / BAG',
        'confidence' => 'high',
        'captured_at' => now(),
    ]);
    $intake->answers()->create([
        'question_key' => 'request_reason',
        'section_instance_key' => null,
        'value' => ['text' => 'Twee slaapkamers koelen omdat het te warm wordt in huis.'],
        'prefill_source' => 'installer',
        'answered_at' => now(),
    ]);

    $builder = app(RequestPrefillContextBuilder::class);
    $context = $builder->build($intake);
    $contextJson = (string) json_encode($context, JSON_UNESCAPED_UNICODE);

    expect($contextJson)->not->toContain('52.37714446')
        ->and($contextJson)->not->toContain('4.89803846')
        ->and($contextJson)->not->toContain('NL.IMKAD.KadastraalObject')
        ->and(collect($context['external_facts'])->pluck('fact_key')->all())
        ->toContain('building_year')
        ->and(collect($context['external_facts'])->pluck('fact_key')->all())
        ->not->toContain('location')
        ->and(collect($context['external_facts'])->pluck('fact_key')->all())
        ->not->toContain('parcel_ids')
        ->and($builder->lastPrivacyRedactions())->not->toBeEmpty();

    FakeAiClient::reset();

    app(PrefillAnswersFromKnownContext::class)->handle($intake);

    $sent = FakeAiClient::lastRequest();
    expect($sent)->not->toBeNull();
    $sentJson = (string) json_encode($sent?->input, JSON_UNESCAPED_UNICODE);
    expect($sentJson)->not->toContain('52.37714446')
        ->and($sentJson)->not->toContain('4.89803846')
        ->and($sentJson)->not->toContain('NL.IMKAD.KadastraalObject');

    $privacyStep = AiTraceStep::query()
        ->where('step_key', 'privacy_redaction')
        ->whereHas('trace', fn ($q) => $q->where('intake_id', $intake->id))
        ->latest('id')
        ->first();

    expect($privacyStep)->not->toBeNull()
        ->and($privacyStep?->payload['applied'] ?? null)->toBeTrue()
        ->and($privacyStep?->payload['count'] ?? 0)->toBeGreaterThan(0);
});

test('OpenAiClient verwijdert lat/lng uit providerpayload en noteert privacy_redaction', function () {
    config(['ai.provider' => 'openai', 'ai.api_key' => 'test-key', 'ai.model' => 'gpt-test']);

    Http::fake([
        '*/chat/completions' => Http::response([
            'id' => 'gen-privacy-1',
            'model' => 'gpt-test',
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 8, 'total_tokens' => 20],
            'choices' => [['message' => ['content' => json_encode(['summary' => 'ok', 'highlights' => ['x']])]]],
        ], 200),
    ]);

    $result = app(OpenAiClient::class)->complete(new AiCompletionRequest(
        prompt: 'Vat samen als JSON.',
        input: [
            'known_context' => [
                'external_facts' => [
                    [
                        'fact_key' => 'location',
                        'value' => [
                            'latitude' => 52.37714446,
                            'longitude' => 4.89803846,
                        ],
                    ],
                ],
                'note' => 'Positie 52.37714446, 4.89803846 bij de gevel.',
            ],
        ],
        promptVersion: 'summary-v1',
    ));

    Http::assertSent(function ($request) {
        $body = (string) json_encode($request->data());

        return ! str_contains($body, '52.37714446')
            && ! str_contains($body, '4.89803846')
            && str_contains($body, '[locatie verwijderd]');
    });

    expect($result->modelParameters['privacy_redaction']['applied'] ?? false)->toBeTrue()
        ->and($result->modelParameters['privacy_redaction']['count'] ?? 0)->toBeGreaterThan(0);
});

test('AiInputRedactor verwijdert coördinaatparen en houdt overige tekst', function () {
    $redactor = app(AiInputRedactor::class);
    $out = $redactor->redact([
        'note' => ['text' => 'Mail jan@example.com of bel 06 1234 5678, kamer is 20 m2 bij 52.37714446, 4.89803846'],
        'location' => ['latitude' => 52.37, 'longitude' => 4.89],
    ]);

    $text = $out['note']['text'];

    expect($text)->not->toContain('jan@example.com')
        ->and($text)->not->toContain('52.37714446')
        ->and($text)->toContain('kamer is 20 m2')
        ->and($out['location']['latitude'])->toBe('[locatie verwijderd]')
        ->and($out['location']['longitude'])->toBe('[locatie verwijderd]')
        ->and($redactor->redactions())->not->toBeEmpty();
});
