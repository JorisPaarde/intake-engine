<?php

declare(strict_types=1);

namespace App\Domains\AI\Eval;

use App\Domains\AI\Contracts\AiClientInterface;
use App\Domains\AI\DTOs\AiCompletionRequest;
use App\Domains\AI\DTOs\AiCompletionResult;

/**
 * Eval-only decorator: records each completed call in {@see EvalCostMeter}.
 * Bound only by eval:interpretation; product code never sees it.
 */
final class MeteredAiClient implements AiClientInterface
{
    public function __construct(
        private readonly AiClientInterface $inner,
        private readonly EvalCostMeter $meter,
    ) {}

    public function complete(AiCompletionRequest $request): AiCompletionResult
    {
        $result = $this->inner->complete($request);
        $this->meter->record($request->promptVersion, $result);

        return $result;
    }
}
