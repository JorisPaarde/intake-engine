<?php

declare(strict_types=1);

use App\Domains\AI\Clients\OpenAiClient;
use App\Domains\AI\DTOs\AiCompletionRequest;
use App\Domains\AI\Exceptions\AiClientException;
use App\Domains\AI\Services\AiFailureAlertService;
use App\Domains\AI\Services\OpenRouterBalanceChecker;
use App\Enums\AiAlertErrorType;
use App\Mail\AiFailureAlertMail;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    config([
        'ai.provider' => 'openai',
        'ai.api_key' => fakeApiKey('sk-test-'),
        'ai.model' => 'gpt-test',
        'ai.budget.daily_cents' => 1000,
        'ai.budget.monthly_cents' => 10000,
        'ai.budget.reserve_cents_per_call' => 1,
        'ai.budget.input_cents_per_1k_tokens' => 1,
        'ai.budget.output_cents_per_1k_tokens' => 2,
        'ai.alerts.enabled' => true,
        'ai.alerts.mail_to' => 'info@jpwebcreation.nl',
        'ai.alerts.throttle_seconds' => 3600,
        'ai.alerts.balance_warn_usd' => 5.0,
        'ai.alerts.balance_throttle_seconds' => 86400,
        'app.name' => 'Digitale Opname',
    ]);
    Cache::flush();
});

function alertAiRequest(): AiCompletionRequest
{
    return new AiCompletionRequest(
        prompt: 'Vat samen als JSON.',
        input: ['answers' => ['note' => ['text' => 'test']]],
        promptVersion: 'summary-v1',
    );
}

test('openai client alerts once per hour per error type after retries', function () {
    Mail::fake();
    Http::fake(['*/chat/completions' => Http::response(['error' => ['message' => 'no credits']], 402)]);

    expect(fn () => app(OpenAiClient::class)->complete(alertAiRequest()))
        ->toThrow(AiClientException::class);

    $apiKey = (string) config('ai.api_key');
    Mail::assertSent(AiFailureAlertMail::class, function (AiFailureAlertMail $mail) use ($apiKey): bool {
        return $mail->hasTo('info@jpwebcreation.nl')
            && $mail->errorType === AiAlertErrorType::Payment
            && $mail->httpStatus === 402
            && ! str_contains($mail->sanitizedMessage, $apiKey);
    });

    Mail::fake();

    expect(fn () => app(OpenAiClient::class)->complete(alertAiRequest()))
        ->toThrow(AiClientException::class);

    Mail::assertNothingSent();
});

test('openai client retries 5xx then alerts with http status on exception', function () {
    Mail::fake();
    Http::fake(['*/chat/completions' => Http::response(['error' => 'boom'], 503)]);

    try {
        app(OpenAiClient::class)->complete(alertAiRequest());
        expect(false)->toBeTrue();
    } catch (AiClientException $exception) {
        expect($exception->httpStatus)->toBe(503)
            ->and($exception->getMessage())->toContain('503');
    }

    Http::assertSentCount(2);

    Mail::assertSent(AiFailureAlertMail::class, fn (AiFailureAlertMail $mail): bool => $mail->errorType === AiAlertErrorType::Server
        && $mail->httpStatus === 503);
});

test('openai client does not alert on 400 client errors', function () {
    Mail::fake();
    Http::fake(['*/chat/completions' => Http::response(['error' => 'bad request'], 400)]);

    expect(fn () => app(OpenAiClient::class)->complete(alertAiRequest()))
        ->toThrow(AiClientException::class);

    Mail::assertNothingSent();
});

test('alert service redacts secrets in mail body', function () {
    Mail::fake();
    $leakedKey = fakeApiKey('sk-proj-');
    $sent = app(AiFailureAlertService::class)->alertHttpFailure(
        401,
        'Unauthorized Bearer '.$leakedKey,
        $leakedKey,
        force: true,
    );

    expect($sent)->toBeTrue();

    Mail::assertSent(AiFailureAlertMail::class, function (AiFailureAlertMail $mail) use ($leakedKey): bool {
        return ! str_contains($mail->sanitizedMessage, substr($leakedKey, 8))
            && str_contains($mail->sanitizedMessage, '[redacted]');
    });
});

test('balance check warns below threshold and throttles daily', function () {
    Mail::fake();
    Http::fake([
        'https://openrouter.ai/api/v1/key' => Http::response([
            'data' => [
                'limit' => 10,
                'usage' => 6.5,
                'limit_remaining' => 3.5,
            ],
        ], 200),
    ]);

    $result = app(OpenRouterBalanceChecker::class)->check((string) config('ai.api_key'));

    expect($result['remaining_usd'])->toBe(3.5)
        ->and($result['source'])->toBe('key.limit_remaining');

    $alerts = app(AiFailureAlertService::class);
    expect($alerts->alertBalanceLow(3.5, 5.0))->toBeTrue();
    Mail::assertSent(AiFailureAlertMail::class, fn (AiFailureAlertMail $mail): bool => $mail->errorType === AiAlertErrorType::BalanceLow);

    Mail::fake();
    expect($alerts->alertBalanceLow(3.5, 5.0))->toBeFalse();
    Mail::assertNothingSent();
});

test('balance check falls back to credits endpoint', function () {
    Http::fake([
        'https://openrouter.ai/api/v1/key' => Http::response(['error' => 'nope'], 404),
        'https://openrouter.ai/api/v1/credits' => Http::response([
            'data' => [
                'total_credits' => 20,
                'total_usage' => 18.25,
            ],
        ], 200),
    ]);

    $result = app(OpenRouterBalanceChecker::class)->check((string) config('ai.api_key'));

    expect($result['remaining_usd'])->toBe(1.75)
        ->and($result['source'])->toBe('credits.total_credits_minus_usage');
});

test('balance check artisan command sends mail under threshold', function () {
    Mail::fake();
    Http::fake([
        'https://openrouter.ai/api/v1/key' => Http::response([
            'data' => ['limit_remaining' => 1.0],
        ], 200),
    ]);

    $exit = Artisan::call('ai:balance-check', ['--force' => true]);

    expect($exit)->toBe(0);
    Mail::assertSent(AiFailureAlertMail::class, fn (AiFailureAlertMail $mail): bool => $mail->errorType === AiAlertErrorType::BalanceLow
        && str_contains($mail->sanitizedMessage, '1.00'));
});

test('ai alert-test sends clearly marked test mail and bypasses throttle', function () {
    Mail::fake();
    Cache::put(AiAlertErrorType::Test->cacheKey(), 1, 3600);

    $exit = Artisan::call('ai:alert-test', ['--force' => true]);

    expect($exit)->toBe(0);
    Mail::assertSent(AiFailureAlertMail::class, function (AiFailureAlertMail $mail): bool {
        return $mail->isTest
            && $mail->errorType === AiAlertErrorType::Test
            && str_contains($mail->sanitizedMessage, 'testalert');
    });
});

test('ai failure alert service swallows mail exceptions', function () {
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('smtp down'));

    $sent = app(AiFailureAlertService::class)->alertHttpFailure(
        429,
        'rate limited',
        force: true,
    );

    expect($sent)->toBeFalse();
});

test('ai:balance-check is scheduled daily at 08:07', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'ai:balance-check'));

    expect($event)->toBeInstanceOf(Event::class)
        ->and($event->expression)->toBe('7 8 * * *');
});
