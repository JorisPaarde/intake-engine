<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Data\AddressEnrichment;
use App\Domains\Intake\Models\Intake;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class PdokAddressService
{
    private const SOURCE = 'PDOK / BAG';

    /**
     * @return list<array{id: string, label: string, address_line: string, postal_code: string, city: string}>
     */
    public function suggest(string $query): array
    {
        if (! $this->enabled() || mb_strlen(trim($query)) < 3) {
            return [];
        }

        $response = $this->searchRequest()->get('/suggest', [
            'q' => trim($query),
            'fq' => 'type:adres',
            'rows' => 7,
            'fl' => 'id,weergavenaam,type,straatnaam,huisnummer,huisletter,huisnummertoevoeging,postcode,woonplaatsnaam',
        ])->throw()->json('response.docs', []);

        if (! is_array($response)) {
            return [];
        }

        $suggestions = [];

        foreach ($response as $document) {
            if (! is_array($document)) {
                continue;
            }

            $id = $document['id'] ?? null;
            $label = $document['weergavenaam'] ?? null;

            if (! is_string($id) || ! str_starts_with($id, 'adr-') || ! is_string($label) || $label === '') {
                continue;
            }

            $addressLine = $this->addressLine($document);
            $postalCode = (string) ($document['postcode'] ?? '');
            $city = (string) ($document['woonplaatsnaam'] ?? '');

            if ($addressLine === '' || $city === '') {
                continue;
            }

            $suggestions[] = [
                'id' => $id,
                'label' => $label,
                'address_line' => $addressLine,
                'postal_code' => $postalCode,
                'city' => $city,
            ];
        }

        return $suggestions;
    }

    public function enrich(Intake $intake, ?string $lookupId = null): ?AddressEnrichment
    {
        if (! $this->enabled()) {
            return null;
        }

        $address = $lookupId !== null && str_starts_with($lookupId, 'adr-')
            ? $this->lookup($lookupId)
            : $this->findExactAddress($intake);

        if ($address === null) {
            return null;
        }

        if (! $this->matchesIntake($address, $intake)) {
            return null;
        }

        $addressableObjectId = $address['adresseerbaarobject_id'] ?? null;

        if (! is_string($addressableObjectId) || preg_match('/^\d{16}$/', $addressableObjectId) !== 1) {
            return null;
        }

        $residence = $this->bagRequest()
            ->get('/collections/verblijfsobject/items', [
                'f' => 'json',
                'identificatie' => $addressableObjectId,
                'limit' => 1,
            ])
            ->throw()
            ->json('features.0');

        $residence = is_array($residence) ? $residence : [];
        $residenceProperties = is_array($residence['properties'] ?? null) ? $residence['properties'] : [];
        $pandUrls = $this->stringList($residenceProperties['pand.href'] ?? []);
        $building = count($pandUrls) === 1 ? $this->building($pandUrls[0]) : [];
        $buildingProperties = is_array($building['properties'] ?? null) ? $building['properties'] : [];
        [$longitude, $latitude] = $this->coordinates($residence['geometry']['coordinates'] ?? null);

        return new AddressEnrichment(
            lookupId: (string) ($address['id'] ?? ''),
            displayAddress: (string) ($address['weergavenaam'] ?? ''),
            addressLine: $this->addressLine($address),
            postalCode: (string) ($address['postcode'] ?? ''),
            city: (string) ($address['woonplaatsnaam'] ?? ''),
            bagAddressableObjectId: $addressableObjectId,
            municipality: $this->nullableString($address['gemeentenaam'] ?? null),
            province: $this->nullableString($address['provincienaam'] ?? null),
            latitude: $latitude,
            longitude: $longitude,
            floorAreaM2: $this->nullableInt($residenceProperties['oppervlakte'] ?? null),
            usagePurposes: $this->commaSeparatedList($residenceProperties['gebruiksdoel'] ?? null),
            parcelIds: $this->stringList($address['gekoppeld_perceel'] ?? []),
            buildYear: $this->nullableInt($buildingProperties['bouwjaar'] ?? null),
            bagBuildingId: $this->nullableString($buildingProperties['identificatie'] ?? null),
            buildingCount: count($pandUrls),
        );
    }

    public static function sourceName(): string
    {
        return self::SOURCE;
    }

    /** @return array<string, mixed>|null */
    private function lookup(string $lookupId): ?array
    {
        $document = $this->searchRequest()->get('/lookup', [
            'id' => $lookupId,
            'fl' => '*',
        ])->throw()->json('response.docs.0');

        return is_array($document) ? $document : null;
    }

    /** @return array<string, mixed>|null */
    private function findExactAddress(Intake $intake): ?array
    {
        $documents = $this->searchRequest()->get('/free', [
            'q' => $intake->fullAddress(),
            'fq' => 'type:adres',
            'rows' => 5,
            'fl' => '*',
        ])->throw()->json('response.docs', []);

        if (! is_array($documents)) {
            return null;
        }

        foreach ($documents as $document) {
            if (! is_array($document) || ! $this->matchesIntake($document, $intake)) {
                continue;
            }

            return $document;
        }

        return null;
    }

    /** @param array<string, mixed> $document */
    private function matchesIntake(array $document, Intake $intake): bool
    {
        if ($this->normalize($this->addressLine($document)) !== $this->normalize($intake->address_line)) {
            return false;
        }

        if ($intake->address_postal_code !== null
            && $this->normalize((string) ($document['postcode'] ?? '')) !== $this->normalize($intake->address_postal_code)) {
            return false;
        }

        return $intake->address_city === null
            || $this->normalize((string) ($document['woonplaatsnaam'] ?? '')) === $this->normalize($intake->address_city);
    }

    /** @param array<string, mixed> $document */
    private function addressLine(array $document): string
    {
        $street = trim((string) ($document['straatnaam'] ?? ''));
        $number = trim((string) ($document['huisnummer'] ?? ''));
        $letter = trim((string) ($document['huisletter'] ?? ''));
        $addition = trim((string) ($document['huisnummertoevoeging'] ?? ''));
        $suffix = implode('-', array_values(array_filter([$letter, $addition], static fn (string $part): bool => $part !== '')));

        return trim($street.' '.$number.($suffix === '' ? '' : '-'.$suffix));
    }

    /** @return array<string, mixed> */
    private function building(string $url): array
    {
        $prefix = rtrim((string) config('services.pdok.bag_base_url'), '/').'/collections/pand/items/';

        if (! str_starts_with($url, $prefix)) {
            return [];
        }

        $building = Http::acceptJson()
            ->connectTimeout($this->timeout())
            ->timeout($this->timeout())
            ->get($url, ['f' => 'json'])
            ->throw()
            ->json();

        return is_array($building) ? $building : [];
    }

    private function searchRequest(): PendingRequest
    {
        return Http::acceptJson()
            ->baseUrl(rtrim((string) config('services.pdok.search_base_url'), '/'))
            ->connectTimeout($this->timeout())
            ->timeout($this->timeout());
    }

    private function bagRequest(): PendingRequest
    {
        return Http::acceptJson()
            ->baseUrl(rtrim((string) config('services.pdok.bag_base_url'), '/'))
            ->connectTimeout($this->timeout())
            ->timeout($this->timeout());
    }

    private function enabled(): bool
    {
        return (bool) config('services.pdok.enabled', true);
    }

    private function timeout(): int
    {
        return max(1, (int) config('services.pdok.timeout_seconds', 5));
    }

    private function normalize(string $value): string
    {
        return Str::lower((string) preg_replace('/[^\pL\pN]+/u', '', $value));
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_string($item) && $item !== ''));
    }

    /** @return list<string> */
    private function commaSeparatedList(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    /** @return array{0: float|null, 1: float|null} */
    private function coordinates(mixed $coordinates): array
    {
        if (! is_array($coordinates) || count($coordinates) < 2 || ! is_numeric($coordinates[0]) || ! is_numeric($coordinates[1])) {
            return [null, null];
        }

        return [(float) $coordinates[0], (float) $coordinates[1]];
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
