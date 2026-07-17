<?php

declare(strict_types=1);

namespace App\Domains\AI\DTOs;

final readonly class AiCompletionResult
{
    /**
     * @param  array<string, mixed>  $output
     */
    public function __construct(
        public array $output,
        public string $provider,
        public ?string $model = null,
    ) {}
}
