<?php

declare(strict_types=1);

namespace App\Domains\Intake\Data;

use App\Domains\Intake\Services\KadasterBagService;

/**
 * De gezaghebbende BAG-kenmerken bij één adres, los van de geo-context.
 *
 * @see KadasterBagService
 */
final readonly class BagAddressAttributes
{
    /**
     * @param  list<string>  $usagePurposes
     * @param  list<string>  $buildingIds
     */
    public function __construct(
        public string $addressableObjectId,
        public array $buildingIds,
        public ?int $floorAreaM2,
        public array $usagePurposes,
        public ?int $buildYear,
    ) {}

    public function buildingCount(): int
    {
        return count($this->buildingIds);
    }

    public function singleBuildingId(): ?string
    {
        return count($this->buildingIds) === 1 ? $this->buildingIds[0] : null;
    }
}
