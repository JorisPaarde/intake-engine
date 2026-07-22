<?php

declare(strict_types=1);

namespace App\Domains\AI\DTOs;

final readonly class AiCompletionRequest
{
    /**
     * @param  array<string, mixed>  $input
     * @param  list<AiImageInput>  $images
     */
    public function __construct(
        public string $prompt,
        public array $input,
        public string $promptVersion,
        public ?string $system = null,
        public array $images = [],
        public ?string $model = null,
    ) {}
}
