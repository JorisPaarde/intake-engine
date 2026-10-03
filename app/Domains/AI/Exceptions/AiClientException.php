<?php

declare(strict_types=1);

namespace App\Domains\AI\Exceptions;

use RuntimeException;
use Throwable;

final class AiClientException extends RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly ?int $providerMs = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
