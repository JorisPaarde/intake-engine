<?php

declare(strict_types=1);

use App\Domains\AI\Actions\PrefillAnswersFromKnownContext;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Clients\OpenAiClient;
use App\Domains\AI\DTOs\AiCompletionRequest;
use App\Domains\AI\Exceptions\AiClientException;
use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Support\AiFailureLogContext;
use App\Domains\Intake\Actions\CreateIntake;
use App\Enums\AiRunStatus;
use App\Enums\ContributionMode;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

afterEach(function () {
    FakeAiClient::reset();
});

function n103OpenRouter402Body(): array
{
    return [
        'error' => [
            'code' => 402,
            'message' => 'This request requires more credits, or fewer max_tokens. You requested up to 65536 tokens, but can only afford 25604. To increase, visit https://openrouter.ai/workspaces/default/keys/abc123 and create a key with a higher total limit',
        ],
    ];
}

test('AiClientException never has an empty message', function () {
    expect((new AiClientException(''))->getMessage())->not->toBe('')
        ->and((new AiClientException('   ', httpStatus: 402))->getMessage())->toContain('402')
        ->and((new AiClientException('', previous: new RuntimeException('x')))->getMessage())->toContain('RuntimeException')
        ->and((new AiClientException('echte melding'))->getMessage())->toBe('echte melding');
});

test('log context carries status, provider code and a capped message without the key', function () {
    config(['ai.api_key' => 'sk-or-v1-secretsecret']);

    $exception = new AiClientException(
        'Externe AI-provider gaf status 402. key sk-or-v1-secretsecret '.str_repeat('x', 400),
        errorClass: 'provider_error',
        httpStatus: 402,
        providerErrorCode: '402',
    );

    $context = AiFailureLogContext::from($exception);

    expect($context['exception'])->toBe(AiClientException::class)
        ->and($context['http_status'])->toBe(402)
        ->and($context['provider_error_code'])->toBe('402')
        ->and($context['error_class'])->toBe('provider_error')
        ->and($context['error_message'])->toStartWith('Externe AI-provider gaf status 402.')
        ->and($context['error_message'])->not->toContain('sk-or-v1-secretsecret')
        ->and(mb_strlen($context['error_message']))->toBeLessThanOrEqual(AiFailureLogContext::MESSAGE_LIMIT + 3);
});

test('log context for a non-AI throwable logs the class only, never its text', function () {
    $query = new QueryException(
        'sqlite',
        'insert into intake_answers (value) values (?)',
        ['Contact alice@example.com'],
        new RuntimeException('constraint failed'),
    );

    $context = AiFailureLogContext::from($query);

    expect($context['exception'])->toBe(QueryException::class)
        ->and($context['error_message'])->toContain('Geen AI-providerfout')
        ->and($context['error_message'])->not->toContain('alice@example.com')
        ->and($context['error_message'])->not->toContain('insert into')
        ->and($context)->not->toHaveKey('http_status');
});

test('provider code must look like a code and never carries the key', function () {
    config(['ai.api_key' => 'sk-test-secret-123']);

    expect(AiFailureLogContext::safeCode('402'))->toBe('402')
        ->and(AiFailureLogContext::safeCode('insufficient_quota'))->toBe('insufficient_quota')
        ->and(AiFailureLogContext::safeCode('sk-test-secret-123'))->toBeNull()
        ->and(AiFailureLogContext::safeCode('sk-or-v1-abcdefgh1234'))->toBeNull()
        ->and(AiFailureLogContext::safeCode('not a code with spaces'))->toBeNull()
        ->and(AiFailureLogContext::safeCode(str_repeat('a', 65)))->toBeNull()
        ->and(AiFailureLogContext::from(new AiClientException('x', providerErrorCode: 'sk-test-secret-123')))
        ->not->toHaveKey('provider_error_code');
});

test('provider message is scrubbed of key-shaped tokens, e-mail and phone numbers', function () {
    config(['ai.api_key' => '']);

    $context = AiFailureLogContext::from(new AiClientException(
        'Externe AI-provider gaf status 400. bad input jan@example.com 06-12345678 sk-or-v1-abcdefghijkl',
        httpStatus: 400,
    ));

    expect($context['error_message'])->toStartWith('Externe AI-provider gaf status 400.')
        ->and($context['error_message'])->not->toContain('jan@example.com')
        ->and($context['error_message'])->not->toContain('12345678')
        ->and($context['error_message'])->not->toContain('sk-or-v1-abcdefghijkl');
});

test('openai client maps an OpenRouter 402 to status, provider code and message', function () {
    config([
        'ai.provider' => 'openai',
        'ai.api_key' => 'test-key',
        'ai.model' => 'gpt-test',
        'ai.budget.daily_cents' => 1000,
        'ai.budget.monthly_cents' => 10000,
        'ai.budget.reserve_cents_per_call' => 1,
    ]);
    Http::fake(['*/chat/completions' => Http::response(n103OpenRouter402Body(), 402)]);

    try {
        app(OpenAiClient::class)->complete(new AiCompletionRequest(
            prompt: 'Vat samen als JSON.',
            input: ['a' => 'b'],
            promptVersion: 'summary-v1',
        ));
        $this->fail('expected AiClientException');
    } catch (AiClientException $e) {
        $context = AiFailureLogContext::from($e);

        expect($e->httpStatus)->toBe(402)
            ->and($e->providerErrorCode)->toBe('402')
            ->and($context['error_message'])->toContain('requires more credits')
            ->and($context['error_message'])->not->toContain('Vat samen');
    }
});

test('request prefill failure is logged with the provider message and stored on ai_runs', function () {
    $this->seed(IntakeTemplateSeeder::class);
    config(['ai.provider' => 'fake', 'ai.text_inference.enabled' => true]);
    FakeAiClient::reset();

    $intake = app(CreateIntake::class)->handle(User::factory()->create(), [
        'customer_name' => 'Log Test',
        'customer_email' => 'log@example.com',
        'address_line' => 'Damrak 1',
        'address_postal_code' => '1012LG',
        'address_house_number' => 1,
        'address_city' => 'Amsterdam',
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'prefill' => ['request_reason' => 'Airco in woonkamer en twee slaapkamers.'],
    ]);

    Log::spy();
    FakeAiClient::alwaysFail('Externe AI-provider gaf status 402. This request requires more credits');

    app(PrefillAnswersFromKnownContext::class)->handle($intake->fresh() ?? $intake);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []) => $message === 'AI known-context prefill failed'
            && $context['intake_id'] === $intake->id
            && str_contains($context['error_message'], 'status 402'))
        ->once();

    $run = AiRun::query()->where('intake_id', $intake->id)->latest('id')->first();

    expect($run?->status)->toBe(AiRunStatus::Failed)
        ->and($run?->error_message)->toContain('status 402');
});
