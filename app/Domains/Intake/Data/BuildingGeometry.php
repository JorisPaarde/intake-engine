<?php

declare(strict_types=1);

namespace App\Domains\Intake\Data;

final readonly class BuildingGeometry
{
    /**
     * @param  string  $roofType  3DBAG `b3_dak_type`: slanted|horizontal|multiple horizontal|unknown|no points|no planes
     * @param  float|null  $heightAboveGroundM  nokhoogte minus maaiveld — de bruikbare gevelhoogte
     * @param  bool  $reliable  3DBAG `b3_kwaliteitsindicator`; false = reconstructie mogelijk onjuist
     */
    public function __construct(
        public string $bagBuildingId,
        public string $roofType,
        public ?float $heightAboveGroundM,
        public ?int $floorCount,
        public bool $reliable,
    ) {}

    /**
     * Het dossier toont alleen daktypen die een installateur iets zeggen; de
     * reconstructie-uitkomsten `no points` / `no planes` / `unknown` niet.
     */
    public function hasUsableRoofType(): bool
    {
        return in_array($this->roofType, ['slanted', 'horizontal', 'multiple horizontal'], true);
    }

    public function roofTypeLabel(): string
    {
        return match ($this->roofType) {
            'slanted' => 'Schuin dak',
            'horizontal' => 'Plat dak',
            'multiple horizontal' => 'Plat dak op meerdere niveaus',
            default => 'Onbekend',
        };
    }
}
