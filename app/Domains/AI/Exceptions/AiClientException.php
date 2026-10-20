<?php

declare(strict_types=1);

namespace App\Domains\AI\Exceptions;

use RuntimeException;
use Throwable;

final class AiClientException extends RuntimeException
{
    /**
     * @param  array{input_tokens?: int|null, output_tokens?: int|null, total_tokens?: int|null}|null  $usage
     * @param  array<string, mixed>  $modelParameters
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly ?int $providerMs = null,
        public readonly ?string $rawResponse = null,
        public readonly ?string $finishReason = null,
        public readonly ?array $usage = null,
        public readonly ?string $errorClass = null,
        public readonly ?string $model = null,
        public readonly array $modelParameters = [],
        public readonly ?int $httpStatus = null,
        public readonly ?string $providerErrorCode = null,
        public readonly ?string $providerMessage = null,
    ) {
        // N103: an AI failure must never carry an empty message, otherwise logs
        // and ai_runs.error_message lose the cause (e.g. a 402 for credits).
        if (trim($message) === '') {
            $message = $httpStatus !== null
                ? 'Externe AI-provider gaf status '.$httpStatus.' (geen melding).'
                : 'AI-aanroep mislukt (geen melding'.($previous !== null ? ': '.$previous::class : '').').';
        }

        parent::__construct($message, $code, $previous);
    }
}
