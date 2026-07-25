<?php

declare(strict_types=1);

namespace App\Domains\Intake\Data;

final readonly class BuildingFootprint
{
    /** @param list<array{0: float, 1: float}> $outline */
    public function __construct(
        public string $id,
        public array $outline,
    ) {}
}
