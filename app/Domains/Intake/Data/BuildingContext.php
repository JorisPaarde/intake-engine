<?php

declare(strict_types=1);

namespace App\Domains\Intake\Data;

final readonly class BuildingContext
{
    /** @param list<BuildingFootprint> $nearby */
    public function __construct(
        public BuildingFootprint $target,
        public array $nearby,
        public int $addressableObjectCount,
        public bool $complete = true,
        public bool $hasMixedUse = false,
    ) {}
}
