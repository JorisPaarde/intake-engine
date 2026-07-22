<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Data\BuildingGeometry;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Pandgeometrie uit de 3DBAG (TU Delft) — open data onder CC BY 4.0, dus anders dan
 * Street View mag dit wél worden opgeslagen en in het dossier worden getoond, mits de
 * bron vermeld blijft.
 *
 * Levert dakvorm en gevelhoogte bij een BAG-pand-id dat we al uit de PDOK-verrijking
 * kennen. Dit zijn context-feiten voor de installateur, geen antwoorden: de hoogte van
 * het pand zegt niet waar de buitenunit komt, dus er vervalt hier bewust geen vraag.
 */
final class ThreeDBagService
{
    private const SOURCE = '3DBAG (TU Delft)';

    public static function sourceName(): string
    {
        return self::SOURCE;
    }

    public function enabled(): bool
    {
        return (bool) config('services.threedbag.enabled', true);
    }

    public function featureUrl(string $bagBuildingId): string
    {
        return rtrim((string) config('services.threedbag.base_url'), '/')
            .'/collections/pand/items/'.$this->featureId($bagBuildingId);
    }

    public function fetch(string $bagBuildingId): ?BuildingGeometry
    {
        if (! $this->enabled() || preg_match('/^\d{16}$/', $bagBuildingId) !== 1) {
            return null;
        }

        $response = $this->request()->get('/collections/pand/items/'.$this->featureId($bagBuildingId));

        if ($response->status() === 404) {
            return null;
        }

        // Let op: de CityObjects-sleutel is 'NL.IMBAG.Pand.<id>' en bevat punten, dus
        // json() met dot-notatie loopt erin vast. Handmatig indexeren dus.
        $cityObjects = $response->throw()->json('feature.CityObjects');

        if (! is_array($cityObjects)) {
            return null;
        }

        $object = $cityObjects[$this->featureId($bagBuildingId)] ?? null;
        $attributes = is_array($object) ? ($object['attributes'] ?? null) : null;

        if (! is_array($attributes)) {
            return null;
        }

        return new BuildingGeometry(
            bagBuildingId: $bagBuildingId,
            roofType: is_string($attributes['b3_dak_type'] ?? null) ? $attributes['b3_dak_type'] : 'unknown',
            heightAboveGroundM: $this->heightAboveGround($attributes),
            floorCount: is_int($attributes['b3_bouwlagen'] ?? null) ? $attributes['b3_bouwlagen'] : null,
            reliable: ($attributes['b3_kwaliteitsindicator'] ?? null) === true,
        );
    }

    /**
     * Beide hoogtes staan in meters t.o.v. NAP; het verschil is de hoogte die een
     * installateur nodig heeft om ladder of steiger in te schatten.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function heightAboveGround(array $attributes): ?float
    {
        $ridge = $attributes['b3_h_dak_max'] ?? null;
        $ground = $attributes['b3_h_maaiveld'] ?? null;

        if (! is_numeric($ridge) || ! is_numeric($ground)) {
            return null;
        }

        $height = round((float) $ridge - (float) $ground, 1);

        // Een negatieve of absurde hoogte betekent een mislukte reconstructie, geen pand.
        return $height > 0.0 && $height < 200.0 ? $height : null;
    }

    private function featureId(string $bagBuildingId): string
    {
        return 'NL.IMBAG.Pand.'.$bagBuildingId;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.threedbag.base_url'), '/'))
            ->acceptJson()
            ->timeout(max(1, (int) config('services.threedbag.timeout_seconds', 5)));
    }
}
