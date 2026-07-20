<?php

declare(strict_types=1);

namespace App\Domains\AI\DTOs;

final readonly class AiImageInput
{
    public function __construct(
        public string $mimeType,
        public string $binary,
        public string $detail = 'high',
    ) {}
}
