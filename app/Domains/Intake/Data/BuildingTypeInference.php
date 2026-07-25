<?php

declare(strict_types=1);

namespace App\Domains\Intake\Data;

final readonly class BuildingTypeInference
{
    public function __construct(
        public string $option,
        public string $confidence,
        public string $reason,
    ) {}
}
