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
    ) {
        parent::__construct($message, $code, $previous);
    }
}
