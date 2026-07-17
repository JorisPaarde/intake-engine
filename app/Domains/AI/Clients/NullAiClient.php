<?php

declare(strict_types=1);

namespace App\Domains\AI\Clients;

use App\Domains\AI\Contracts\AiClientInterface;
use App\Domains\AI\DTOs\AiCompletionRequest;
use App\Domains\AI\DTOs\AiCompletionResult;
use App\Domains\AI\Exceptions\AiClientException;

final class NullAiClient implements AiClientInterface
{
    public function complete(AiCompletionRequest $request): AiCompletionResult
    {
        throw new AiClientException('AI-provider is niet geconfigureerd.');
    }
}
