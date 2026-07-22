<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Data\EnergyLabel;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * EP-Online (RVO) — het landelijke register van geregistreerde energielabels.
 *
 * Bevraagd op het BAG-verblijfsobject-id dat de adresverrijking toch al oplevert, dus
 * zonder opnieuw op adres te hoeven matchen. Levert twee dingen die we anders zouden
 * vragen: de envelopkwaliteit (via de energiebehoefte) en het geregistreerde woningtype.
 *
 * Niet elk adres heeft een label — registratie is verplicht bij verkoop, verhuur en
 * oplevering, dus de dekking is hoog maar niet volledig. Geen label betekent simpelweg
 * dat de vragen blijven staan.
 *
 * De API-key is persoonlijk en mag niet aan derden worden verstrekt; hij hoort in de
 * shared .env per omgeving en wordt alleen server-side gebruikt.
 */
final class EpOnlineService
{
    private const SOURCE = 'EP-Online (RVO)';

    public static function sourceName(): string
    {
        return self::SOURCE;
    }

    public function enabled(): bool
    {
        return (bool) config('services.ep_online.enabled', false)
            && trim((string) config('services.ep_online.key', '')) !== '';
    }

    public function labelUrl(string $addressableObjectId): string
    {
        return rtrim((string) config('services.ep_online.base_url'), '/')
            .'/api/v5/PandEnergielabel/AdresseerbaarObject/'.$addressableObjectId;
    }

    public function forAddressableObject(string $addressableObjectId): ?EnergyLabel
    {
        if (! $this->enabled() || preg_match('/^\d{16}$/', $addressableObjectId) !== 1) {
            return null;
        }

        $response = $this->request()
            ->get('/api/v5/PandEnergielabel/AdresseerbaarObject/'.$addressableObjectId);

        if (in_array($response->status(), [204, 404], true)) {
            return null;
        }

        $labels = $response->throw()->json();

        if (! is_array($labels) || $labels === []) {
            return null;
        }

        $label = $this->mostRecent($labels);

        if ($label === null) {
            return null;
        }

        $energyLabel = new EnergyLabel(
            energyClass: $this->nullableString($label['Energieklasse'] ?? null),
            energyDemandKwhM2: $this->nullableFloat($label['Energiebehoefte'] ?? null),
            buildingType: $this->nullableString($label['Gebouwtype'] ?? null),
            buildingClass: $this->nullableString($label['Gebouwklasse'] ?? null),
            registeredAt: $this->nullableString($label['Registratiedatum'] ?? null),
            validUntil: $this->nullableString($label['Geldig_tot'] ?? null),
        );

        return $energyLabel->hasAnything() ? $energyLabel : null;
    }

    /**
     * Eén verblijfsobject kan meerdere registraties hebben (herlabeling, correcties).
     * De meest recente registratiedatum wint; zonder datum de eerste.
     *
     * @param  array<int, mixed>  $labels
     * @return array<string, mixed>|null
     */
    private function mostRecent(array $labels): ?array
    {
        $best = null;
        $bestDate = null;

        foreach ($labels as $label) {
            if (! is_array($label)) {
                continue;
            }

            $date = $this->nullableString($label['Registratiedatum'] ?? null);

            if ($best === null || ($date !== null && ($bestDate === null || $date > $bestDate))) {
                $best = $label;
                $bestDate = $date;
            }
        }

        return $best;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.ep_online.base_url'), '/'))
            ->withHeaders(['Authorization' => (string) config('services.ep_online.key')])
            ->acceptJson()
            ->timeout(max(1, (int) config('services.ep_online.timeout_seconds', 5)));
    }
}
