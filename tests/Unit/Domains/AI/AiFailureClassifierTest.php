<?php

declare(strict_types=1);

use App\Domains\AI\Services\AiFailureClassifier;
use App\Enums\AiAlertErrorType;

test('classifies alertable OpenRouter HTTP statuses', function (int $status, AiAlertErrorType $expected) {
    $classifier = new AiFailureClassifier;

    expect($classifier->classify($status))->toBe($expected)
        ->and($classifier->isAlertable($status))->toBeTrue();
})->with([
    [401, AiAlertErrorType::Auth],
    [402, AiAlertErrorType::Payment],
    [429, AiAlertErrorType::RateLimit],
    [500, AiAlertErrorType::Server],
    [502, AiAlertErrorType::Server],
    [503, AiAlertErrorType::Server],
]);

test('ignores non-alertable statuses', function (?int $status) {
    $classifier = new AiFailureClassifier;

    expect($classifier->classify($status))->toBeNull()
        ->and($classifier->isAlertable($status))->toBeFalse();
})->with([null, 400, 403, 404, 422, 200]);

test('only 429 and 5xx are retryable', function () {
    $classifier = new AiFailureClassifier;

    expect($classifier->isRetryable(429))->toBeTrue()
        ->and($classifier->isRetryable(503))->toBeTrue()
        ->and($classifier->isRetryable(401))->toBeFalse()
        ->and($classifier->isRetryable(402))->toBeFalse()
        ->and($classifier->isRetryable(400))->toBeFalse();
});
