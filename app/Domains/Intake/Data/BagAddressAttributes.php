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
        public string $street = '',
        public ?int $houseNumber = null,
        public ?string $houseLetter = null,
        public ?string $addition = null,
        public string $postalCode = '',
        public string $city = '',
    ) {}

    public function buildingCount(): int
    {
        return count($this->buildingIds);
    }

    public function singleBuildingId(): ?string
    {
        return count($this->buildingIds) === 1 ? $this->buildingIds[0] : null;
    }

    /**
     * De schrijfwijze zoals de BAG hem kent. Hiermee wordt een rommelig ingetypt adres
     * ("Bernadottelaan, 273, 273") in het dossier alsnog rechtgezet.
     */
    public function addressLine(): string
    {
        if ($this->street === '' || $this->houseNumber === null) {
            return '';
        }

        $suffix = implode('-', array_values(array_filter(
            [trim((string) $this->houseLetter), trim((string) $this->addition)],
            static fn (string $part): bool => $part !== '',
        )));

        return trim($this->street.' '.$this->houseNumber.($suffix === '' ? '' : '-'.$suffix));
    }

    public function hasAddressLine(): bool
    {
        return $this->addressLine() !== '';
    }
}
