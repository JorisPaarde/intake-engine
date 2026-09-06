<?php

declare(strict_types=1);

use App\Domains\AI\Actions\DeriveIntentFromRequest;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\DTOs\RequestPrefillCandidate;
use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\EvaluateRequestIntent;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\ContributionMode;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    FakeAiClient::reset();
    config([
        'devadmin.enabled' => true,
        'ai.provider' => 'fake',
        'ai.text_inference.enabled' => true,
        'devadmin.ai_input_test.throttle_per_minute' => 60,
    ]);
});

afterEach(function () {
    FakeAiClient::reset();
});

function forcedPrefillPayload(): array
{
    return [
        'evidence' => 'Drie slaapkamers en één woonkamer om te koelen.',
        'fills' => [
            [
                'question_key' => 'cooling_heating',
                'section_instance_key' => null,
                'confidence' => 'high',
                'value' => ['value' => 'cooling'],
                'evidence' => 'om te koelen',
            ],
            [
                'question_key' => 'indoor_unit_count',
                'section_instance_key' => null,
                'confidence' => 'high',
                'value' => ['number' => 4],
                'evidence' => null,
            ],
            [
                'question_key' => 'room_type',
                'section_instance_key' => 'room-1',
                'confidence' => 'high',
                'value' => ['value' => 'bedroom'],
                'evidence' => null,
            ],
            [
                'question_key' => 'room_type',
                'section_instance_key' => 'room-2',
                'confidence' => 'high',
                'value' => ['value' => 'bedroom'],
                'evidence' => null,
            ],
            [
                'question_key' => 'room_type',
                'section_instance_key' => 'room-3',
                'confidence' => 'high',
                'value' => ['value' => 'bedroom'],
                'evidence' => null,
            ],
            [
                'question_key' => 'room_type',
                'section_instance_key' => 'room-4',
                'confidence' => 'high',
                'value' => ['value' => 'living_room'],
                'evidence' => null,
            ],
            [
                'question_key' => 'outdoor_location',
                'section_instance_key' => null,
                'confidence' => 'medium',
                'value' => ['value' => 'garden'],
                'evidence' => 'niet expliciet',
            ],
            [
                'question_key' => 'additional_comments',
                'section_instance_key' => null,
                'confidence' => 'low',
                'value' => ['text' => 'Misschien Daikin'],
                'evidence' => null,
            ],
            [
                'question_key' => 'not_a_real_question',
                'section_instance_key' => null,
                'confidence' => 'high',
                'value' => ['value' => 'x'],
                'evidence' => null,
            ],
            [
                'question_key' => 'cooling_heating',
                'section_instance_key' => null,
                'confidence' => 'high',
                'value' => ['value' => 'not_a_valid_option'],
                'evidence' => null,
            ],
        ],
    ];
}

test('dev AI-invoer test page requires auth and is hard 404 when disabled', function () {
    $this->get(route('dev.ai-input-test'))->assertRedirect('/login');

    $this->actingAs(User::factory()->create());
    $this->get(route('dev.ai-input-test'))
        ->assertOk()
        ->assertSee('AI-invoer testen')
        ->assertSee('Proeftekst');

    config(['devadmin.enabled' => false]);
    $this->get(route('dev.ai-input-test'))->assertNotFound();
    $this->post(route('dev.ai-input-test.evaluate'), [
        'request_reason' => 'Ik wil twee slaapkamers koelen in de zomer.',
    ])->assertNotFound();
});

test('dry-run evaluation shows classified fills without side effects', function () {
    FakeAiClient::alwaysReturn(forcedPrefillPayload());
    Mail::fake();
    Queue::fake();

    $user = User::factory()->create();
    $before = [
        'intakes' => Intake::withTrashed()->count(),
        'answers' => IntakeAnswer::query()->count(),
        'ai_runs' => AiRun::query()->count(),
        'events' => IntakeActivityEvent::query()->count(),
        'uploads' => IntakeUpload::query()->count(),
    ];

    $text = 'Ik wil drie airco’s voor al mijn slaapkamers en één voor mijn woonkamer om te koelen';

    $this->actingAs($user)
        ->from(route('dev.ai-input-test'))
        ->post(route('dev.ai-input-test.evaluate'), ['request_reason' => $text])
        ->assertRedirect(route('dev.ai-input-test'));

    $this->actingAs($user)
        ->get(route('dev.ai-input-test'))
        ->assertOk()
        ->assertSee('Zou invullen')
        ->assertSee('Voorzet')
        ->assertSee('Afgewezen')
        ->assertSee('Hypothetisch formulierbeeld')
        ->assertSee('cooling_heating')
        ->assertSee('Onbekende vraagkey')
        ->assertSee('Ongeldige keuzewaarde')
        ->assertSee('Lage zekerheid');

    expect([
        'intakes' => Intake::withTrashed()->count(),
        'answers' => IntakeAnswer::query()->count(),
        'ai_runs' => AiRun::query()->count(),
        'events' => IntakeActivityEvent::query()->count(),
        'uploads' => IntakeUpload::query()->count(),
    ])->toBe($before);

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Queue::assertNothingPushed();
});

test('provider-off shows gate reason and still runs local path', function () {
    config(['ai.text_inference.enabled' => false]);

    $user = User::factory()->create();
    $text = 'Ik wil twee airco’s om m’n slaapkamers op zolder te koelen.';

    $this->actingAs($user)
        ->from(route('dev.ai-input-test'))
        ->post(route('dev.ai-input-test.evaluate'), ['request_reason' => $text])
        ->assertRedirect(route('dev.ai-input-test'));

    $this->actingAs($user)
        ->get(route('dev.ai-input-test'))
        ->assertOk()
        ->assertSee('AI_TEXT_INFERENCE_ENABLED=false')
        ->assertSee('Geen stille mock')
        ->assertSee('cooling_heating')
        ->assertSee('indoor_unit_count')
        ->assertDontSee('Catalogus-AI mislukt');

    expect(AiRun::query()->count())->toBe(0)
        ->and(Intake::query()->count())->toBe(0);
});

test('validation explains rejected candidates', function () {
    FakeAiClient::alwaysReturn([
        'evidence' => 'Testvalidatie voor afwijzingsredenen.',
        'fills' => [
            [
                'question_key' => 'fusebox_photo',
                'section_instance_key' => null,
                'confidence' => 'high',
                'value' => ['text' => 'n/a'],
                'evidence' => null,
            ],
            [
                'question_key' => 'room_type',
                'section_instance_key' => null,
                'confidence' => 'high',
                'value' => ['value' => 'bedroom'],
                'evidence' => null,
            ],
            [
                'question_key' => 'cooling_heating',
                'section_instance_key' => 'room-1',
                'confidence' => 'high',
                'value' => ['value' => 'cooling'],
                'evidence' => null,
            ],
        ],
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('dev.ai-input-test'))
        ->post(route('dev.ai-input-test.evaluate'), [
            'request_reason' => 'Woonkamer koelen aub met airco graag.',
        ])
        ->assertRedirect(route('dev.ai-input-test'));

    $this->actingAs($user)
        ->get(route('dev.ai-input-test'))
        ->assertOk()
        ->assertSee('Fotovragen worden niet automatisch ingevuld')
        ->assertSee('Herhaalbare vraag mist een sectie-instance')
        ->assertSee('Niet-herhaalbare vraag kreeg een sectie-instance');
});

test('rate limit blocks repeated AI-invoer evaluations', function () {
    config(['devadmin.ai_input_test.throttle_per_minute' => 2]);
    FakeAiClient::alwaysReturn(forcedPrefillPayload());

    $user = User::factory()->create();
    $payload = [
        'request_reason' => 'Ik wil twee slaapkamers koelen in de zomerperiode.',
    ];

    $this->actingAs($user)->post(route('dev.ai-input-test.evaluate'), $payload)->assertRedirect();
    $this->actingAs($user)->post(route('dev.ai-input-test.evaluate'), $payload)->assertRedirect();
    $this->actingAs($user)->post(route('dev.ai-input-test.evaluate'), $payload)->assertTooManyRequests();
});

test('contract: preview classified outcomes match CreateIntake DeriveIntent chain', function () {
    FakeAiClient::alwaysReturn(forcedPrefillPayload());

    $text = 'Ik wil drie airco’s voor al mijn slaapkamers en één voor mijn woonkamer om te koelen';

    $preview = app(EvaluateRequestIntent::class)->preview($text);
    $writable = $preview->classifiedWritable();

    expect($writable)->toHaveKey('cooling_heating')
        ->and($writable['cooling_heating']['disposition'])->toBe(RequestPrefillCandidate::DISPOSITION_FILL)
        ->and($writable)->toHaveKey('outdoor_location')
        ->and($writable['outdoor_location']['disposition'])->toBe(RequestPrefillCandidate::DISPOSITION_SUGGESTION)
        ->and($writable)->not->toHaveKey('additional_comments')
        ->and($writable)->not->toHaveKey('not_a_real_question');

    $rejectedKeys = collect($preview->rejected())->map->compositeKey()->all();
    expect($rejectedKeys)->toContain('not_a_real_question')
        ->and($rejectedKeys)->toContain('additional_comments');

    $user = User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'customer_name' => 'Contract Test',
        'customer_email' => 'contract@example.com',
        'address_line' => 'Damrak 1',
        'address_postal_code' => '1012LG',
        'address_house_number' => 1,
        'address_city' => 'Amsterdam',
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'prefill' => ['request_reason' => $text],
    ]);

    // Zelfde FakeAi-respons; geen adresverrijking nodig voor dit contract.
    FakeAiClient::alwaysReturn(forcedPrefillPayload());
    app(DeriveIntentFromRequest::class)->handle($intake->fresh() ?? $intake);

    $answers = IntakeAnswer::query()
        ->where('intake_id', $intake->id)
        ->where('question_key', '!=', 'request_reason')
        ->get();

    $applied = [];
    foreach ($answers as $answer) {
        $key = $answer->section_instance_key === null
            ? $answer->question_key
            : $answer->question_key.'@'.$answer->section_instance_key;
        $source = $answer->prefill_source;
        $disposition = match ($source) {
            DeriveIntentFromRequest::SOURCE_DERIVED,
            DeriveIntentFromRequest::SOURCE_REQUEST_TEXT => RequestPrefillCandidate::DISPOSITION_FILL,
            DeriveIntentFromRequest::SOURCE_SUGGESTED => RequestPrefillCandidate::DISPOSITION_SUGGESTION,
            default => null,
        };

        if ($disposition === null) {
            continue;
        }

        $applied[$key] = [
            'disposition' => $disposition,
            'value' => $answer->value,
        ];
    }

    expect(array_keys($applied))->toEqualCanonicalizing(array_keys($writable));

    foreach ($writable as $key => $expected) {
        expect($applied)->toHaveKey($key)
            ->and($applied[$key]['disposition'])->toBe($expected['disposition'])
            ->and($applied[$key]['value'])->toBe($expected['value']);
    }

    expect($answers->firstWhere('question_key', 'additional_comments'))->toBeNull()
        ->and($answers->firstWhere('question_key', 'not_a_real_question'))->toBeNull();
});

test('failed AI still returns local outcome without 500 or durable AI-run from preview', function () {
    FakeAiClient::alwaysFail('provider down');

    $evaluation = app(EvaluateRequestIntent::class)->preview(
        'Ik wil twee airco’s om m’n slaapkamers op zolder te koelen.',
    );

    expect($evaluation->aiAttempted)->toBeTrue()
        ->and($evaluation->aiError)->toContain('Catalogus-AI mislukt')
        ->and($evaluation->localOutput)->not->toBeNull()
        ->and($evaluation->fills())->not->toBeEmpty()
        ->and(AiRun::query()->count())->toBe(0)
        ->and(Intake::query()->count())->toBe(0);
});

test('input longer than max length is rejected', function () {
    config(['devadmin.ai_input_test.max_length' => 40]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('dev.ai-input-test'))
        ->post(route('dev.ai-input-test.evaluate'), [
            'request_reason' => str_repeat('a', 50),
        ])
        ->assertRedirect(route('dev.ai-input-test'))
        ->assertSessionHasErrors('request_reason');
});
