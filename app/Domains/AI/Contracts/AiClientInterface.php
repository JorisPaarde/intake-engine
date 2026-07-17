<?php

declare(strict_types=1);

namespace App\Domains\AI\Contracts;

use App\Domains\AI\DTOs\AiCompletionRequest;
use App\Domains\AI\DTOs\AiCompletionResult;

interface AiClientInterface
{
    public function complete(AiCompletionRequest $request): AiCompletionResult;
}
