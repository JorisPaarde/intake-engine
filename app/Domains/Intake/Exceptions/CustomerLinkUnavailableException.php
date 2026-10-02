<?php

declare(strict_types=1);

namespace App\Domains\Intake\Exceptions;

use App\Domains\Intake\Models\Intake;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Customer /o/{token} link exists but is no longer usable (used, revoked, expired).
 */
final class CustomerLinkUnavailableException extends HttpException
{
    public function __construct(
        public readonly string $reason,
        public readonly ?Intake $intake = null,
        string $message = 'Deze link is niet meer geldig.',
        ?Throwable $previous = null,
    ) {
        parent::__construct(410, $message, $previous);
    }
}
