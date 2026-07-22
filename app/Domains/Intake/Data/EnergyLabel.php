<?php

declare(strict_types=1);

namespace App\Domains\Intake\Data;

use App\Domains\Intake\Services\EpOnlineService;

/**
 * Een geregistreerd energielabel uit EP-Online (RVO).
 *
 * @see EpOnlineService
 */
final readonly class EnergyLabel
{
    public function __construct(
        public ?string $energyClass,
        public ?float $energyDemandKwhM2,
        public ?string $buildingType,
        public ?string $buildingClass,
        public ?string $registeredAt,
        public ?string $validUntil,
    ) {}

    /**
     * De envelopkwaliteit, afgeleid van de energiebehoefte.
     *
     * Bewust niet van de labelletter: die verrekent ook installaties, dus een matig
     * geïsoleerd huis met zonnepanelen scoort een mooie letter terwijl de warmtevraag
     * hoog blijft. `Energiebehoefte` (NTA 8800) is juist de vraag vóór installaties en
     * dus de betere maat voor wat een airco moet leveren.
     *
     * Zonder energiebehoefte valt het terug op de labelletter — oudere en vereenvoudigde
     * labels hebben dat getal niet.
     */
    public function insulationIndication(): ?string
    {
        if ($this->energyDemandKwhM2 !== null) {
            return match (true) {
                $this->energyDemandKwhM2 <= 50.0 => 'good',
                $this->energyDemandKwhM2 <= 100.0 => 'average',
                default => 'poor',
            };
        }

        $letter = strtoupper(trim((string) $this->energyClass));

        if ($letter === '') {
            return null;
        }

        return match (true) {
            str_starts_with($letter, 'A'), $letter === 'B' => 'good',
            in_array($letter, ['C', 'D'], true) => 'average',
            in_array($letter, ['E', 'F', 'G'], true) => 'poor',
            default => null,
        };
    }

    /**
     * Het woningtype zoals RVO het registreerde, vertaald naar de template-opties.
     *
     * EP-Online legt de waarden niet vast in de OpenAPI-spec, dus we matchen op
     * herkenbare woorden en geven `null` terug zodra we het niet zeker weten — dan
     * blijft de vraag gewoon staan.
     */
    public function buildingTypeOption(): ?string
    {
        if ($this->isUtility()) {
            return 'commercial';
        }

        $type = mb_strtolower(trim((string) $this->buildingType));

        if ($type === '') {
            return null;
        }

        return match (true) {
            str_contains($type, 'vrijstaand') => 'detached',
            str_contains($type, '2 onder 1 kap'), str_contains($type, 'twee-onder'), str_contains($type, '2-onder') => 'semi_detached',
            str_contains($type, 'hoek') => 'corner',
            str_contains($type, 'tussen'), str_contains($type, 'rijwoning') => 'terraced',
            str_contains($type, 'galerij'), str_contains($type, 'portiek'), str_contains($type, 'maisonnette'), str_contains($type, 'flat'), str_contains($type, 'appartement') => 'apartment',
            default => null,
        };
    }

    private function isUtility(): bool
    {
        $class = mb_strtolower(trim((string) $this->buildingClass));

        return $class !== '' && (str_starts_with($class, 'u') || str_contains($class, 'utilit'));
    }

    public function hasAnything(): bool
    {
        return $this->energyClass !== null || $this->energyDemandKwhM2 !== null || $this->buildingType !== null;
    }
}
