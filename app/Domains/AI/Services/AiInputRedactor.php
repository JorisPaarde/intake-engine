<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

/**
 * Redacts obvious PII from an AI payload before it leaves the app to an
 * external provider (ADR-0005). Structured identity (naam/e-mail/telefoon/
 * exact adres) hoort niet in de payload; dit vangt vrije tekst én precieze
 * locatiegegevens (lat/lng, GPS-paren) af als extra laag.
 *
 * Precise coordinates are removed (not rounded) unless a caller has already
 * excluded them. Call {@see redactions()} after {@see redact()} to record that
 * location redaction happened in the AI trace.
 */
final class AiInputRedactor
{
    private const EMAIL = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/';

    // NL/intl telefoonnummers: +31/0031/0 gevolgd door 8+ cijfers met optionele spaties/streepjes.
    private const PHONE = '/(?<!\d)(?:\+31|0031|0)[\s\-]?(?:\d[\s\-]?){8,11}\d(?!\d)/';

    private const GPS_COORD_PAIR = '/(?<!\d)(-?\d{1,2}\.\d{3,})\s*[,;\s]\s*(-?\d{1,3}\.\d{3,})(?!\d)/';

    /** @var list<array{field: string, action: string}> */
    private array $redactions = [];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function redact(array $input): array
    {
        $this->redactions = [];

        /** @var array<string, mixed> $result */
        $result = $this->walk($input);

        return $result;
    }

    /**
     * @return list<array{field: string, action: string}>
     */
    public function redactions(): array
    {
        return $this->redactions;
    }

    private function walk(mixed $value, string $path = ''): mixed
    {
        if (is_array($value)) {
            $out = [];

            foreach ($value as $key => $item) {
                $keyString = is_string($key) ? strtolower($key) : (string) $key;
                $childPath = $path === '' ? (string) $key : $path.'.'.$key;

                if ($this->isLocationKey($keyString)) {
                    $this->redactions[] = [
                        'field' => $childPath,
                        'action' => 'location_removed',
                    ];
                    $out[$key] = '[locatie verwijderd]';

                    continue;
                }

                $out[$key] = $this->walk($item, $childPath);
            }

            return $out;
        }

        if (is_string($value)) {
            return $this->scrub($value, $path);
        }

        return $value;
    }

    private function scrub(string $value, string $path): string
    {
        $scrubbed = (string) preg_replace(self::EMAIL, '[e-mail verwijderd]', $value);
        $scrubbed = (string) preg_replace(self::PHONE, '[telefoon verwijderd]', $scrubbed);

        $withCoordsRemoved = (string) preg_replace(self::GPS_COORD_PAIR, '[locatie verwijderd]', $scrubbed);
        if ($withCoordsRemoved !== $scrubbed) {
            $this->redactions[] = [
                'field' => $path === '' ? '(string)' : $path,
                'action' => 'coordinate_pair_removed',
            ];
            $scrubbed = $withCoordsRemoved;
        }

        return $scrubbed;
    }

    private function isLocationKey(string $key): bool
    {
        return in_array($key, [
            'latitude',
            'longitude',
            'lat',
            'lon',
            'lng',
            'altitude',
            'alt',
            'gps',
            'gps_latitude',
            'gps_longitude',
            'gps_altitude',
            'gpslatitude',
            'gpslongitude',
            'gpsaltitude',
            'coordinates',
            'coordinate',
            'coords',
            'geo',
            'geolocation',
            'center_latitude',
            'center_longitude',
            'exif_gps',
            'exif_location',
        ], true)
            || str_contains($key, 'gps')
            || str_contains($key, 'geolocation');
    }
}
