<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Data\BagAddressAttributes;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * BAG API Individuele Bevragingen van het Kadaster (v2).
 *
 * Twee redenen om dit boven de open PDOK-route te verkiezen voor de kenmerken van één adres:
 *
 *   1. **Exacte bevraging.** `/adressenuitgebreid?postcode=&huisnummer=&exacteMatch=true`
 *      levert precies dit adres. De PDOK-route zoekt op vrije tekst en filtert daarna met
 *      `matchesIntake()` de misgrepen eruit; die onzekerheid vervalt hier.
 *   2. **Verser.** Kadaster serveert near-realtime uit de LVBAG, PDOK een periodiek ververst
 *      extract. Merkbaar bij nieuwbouw, precies het geval waarin iemand een offerte wil.
 *
 * Deze dienst levert bewust alléén de BAG-kenmerken, niet de geo-context: de geometrie komt
 * hier in RD (EPSG:28992) terug en jullie luchtfoto en dossier rekenen op WGS84. Coördinaten,
 * gemeente en provincie blijven daarom van de PDOK Locatieserver komen — die is bovendien nog
 * steeds nodig voor de adres-autocomplete, want dit is geen geocoder.
 *
 * Zonder key of bij een storing geeft alles null terug en valt de aanroeper terug op PDOK.
 * De API is bedoeld voor losse bevragingen, niet voor bulk (zie de gebruikslimieten).
 */
final class KadasterBagService
{
    private const SOURCE = 'Kadaster BAG API';

    public static function sourceName(): string
    {
        return self::SOURCE;
    }

    public function enabled(): bool
    {
        return (bool) config('services.bag_api.enabled', false)
            && trim((string) config('services.bag_api.key', '')) !== '';
    }

    /**
     * @param  string|null  $houseLetter  losse huisletter, bv. 'A' in 'Damrak 1A'
     * @param  string|null  $addition  huisnummertoevoeging, bv. '2' in 'Damrak 1-2'
     */
    public function attributesFor(
        string $postalCode,
        int $houseNumber,
        ?string $houseLetter = null,
        ?string $addition = null,
    ): ?BagAddressAttributes {
        if (! $this->enabled()) {
            return null;
        }

        $normalizedPostalCode = strtoupper(preg_replace('/\s+/', '', $postalCode) ?? '');

        if (preg_match('/^\d{4}[A-Z]{2}$/', $normalizedPostalCode) !== 1) {
            return null;
        }

        $query = array_filter([
            'postcode' => $normalizedPostalCode,
            'huisnummer' => $houseNumber,
            'huisletter' => $this->blankToNull($houseLetter),
            'huisnummertoevoeging' => $this->blankToNull($addition),
            'exacteMatch' => 'true',
        ], static fn (mixed $value): bool => $value !== null);

        $response = $this->request()->get('/adressenuitgebreid', $query);

        if ($response->status() === 404) {
            return null;
        }

        $addresses = $response->throw()->json('_embedded.adressen');

        // Bij exacteMatch hoort er precies één adres te zijn; meer betekent dat het
        // huisnummer niet volledig genoeg is en we niet mogen gokken.
        if (! is_array($addresses) || count($addresses) !== 1) {
            return null;
        }

        $address = $addresses[0];

        if (! is_array($address)) {
            return null;
        }

        $addressableObjectId = $address['adresseerbaarObjectIdentificatie'] ?? null;

        if (! is_string($addressableObjectId) || preg_match('/^\d{16}$/', $addressableObjectId) !== 1) {
            return null;
        }

        return new BagAddressAttributes(
            addressableObjectId: $addressableObjectId,
            buildingIds: $this->buildingIds($address['pandIdentificaties'] ?? null),
            floorAreaM2: $this->positiveInt($address['oppervlakte'] ?? null),
            usagePurposes: $this->stringList($address['gebruiksdoelen'] ?? null),
            buildYear: $this->buildYear($address['oorspronkelijkBouwjaar'] ?? null),
        );
    }

    public function addressUrl(string $addressableObjectId): string
    {
        return rtrim((string) config('services.bag_api.base_url'), '/')
            .'/adressenuitgebreid?adresseerbaarObjectIdentificatie='.$addressableObjectId;
    }

    /**
     * `oorspronkelijkBouwjaar` is een array van jaarstrings — één per pand waar het
     * verblijfsobject deel van uitmaakt. Alleen een eenduidig jaar is bruikbaar.
     */
    private function buildYear(mixed $raw): ?int
    {
        $years = array_values(array_unique(array_filter(
            $this->stringList($raw),
            static fn (string $year): bool => preg_match('/^\d{4}$/', $year) === 1,
        )));

        return count($years) === 1 ? (int) $years[0] : null;
    }

    /** @return list<string> */
    private function buildingIds(mixed $raw): array
    {
        return array_values(array_filter(
            $this->stringList($raw),
            static fn (string $id): bool => preg_match('/^\d{16}$/', $id) === 1,
        ));
    }

    /** @return list<string> */
    private function stringList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $values = [];

        foreach ($raw as $item) {
            if (is_string($item) && trim($item) !== '') {
                $values[] = trim($item);
            }
        }

        return $values;
    }

    private function positiveInt(mixed $raw): ?int
    {
        return is_numeric($raw) && (int) $raw > 0 ? (int) $raw : null;
    }

    private function blankToNull(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.bag_api.base_url'), '/'))
            ->withHeaders(['X-Api-Key' => (string) config('services.bag_api.key')])
            ->acceptJson()
            ->timeout(max(1, (int) config('services.bag_api.timeout_seconds', 5)));
    }
}
