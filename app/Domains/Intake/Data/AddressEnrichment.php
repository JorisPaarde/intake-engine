<?php

declare(strict_types=1);

namespace App\Domains\Intake\Data;

final readonly class AddressEnrichment
{
    /**
     * @param  list<string>  $usagePurposes
     * @param  list<string>  $parcelIds
     */
    public function __construct(
        public string $lookupId,
        public string $displayAddress,
        public string $addressLine,
        public string $postalCode,
        public string $city,
        public string $bagAddressableObjectId,
        public ?string $municipality,
        public ?string $province,
        public ?float $latitude,
        public ?float $longitude,
        public ?int $floorAreaM2,
        public array $usagePurposes,
        public array $parcelIds,
        public ?int $buildYear,
        public ?string $bagBuildingId,
        public int $buildingCount,
    ) {}
}
