<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeExternalFact;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ExternalFactPresenter
{
    /**
     * @return array{
     *     facts: list<array{label: string, display: string, source: string, source_url: string|null, confidence: string}>,
     *     uncertainties: list<string>,
     *     aerial_image: array{label: string, data_uri: string, source: string, source_url: string|null, confidence: string, ground_width_meters: int|null, ground_height_meters: int|null}|null
     * }
     */
    public function present(Intake $intake): array
    {
        $intake->loadMissing('externalFacts');
        $facts = [];
        $uncertainties = [];
        $aerialImage = null;

        if ($intake->externalFacts->isEmpty()) {
            return [
                'facts' => [],
                'uncertainties' => ['Adres- en gebouwgegevens zijn nog niet automatisch gecontroleerd.'],
                'aerial_image' => null,
            ];
        }

        foreach ($intake->externalFacts->sortBy(fn (IntakeExternalFact $fact): int => $this->order($fact->fact_key)) as $fact) {
            $uncertainty = $this->uncertainty($fact);

            if ($uncertainty !== null) {
                $uncertainties[] = $uncertainty;
            }

            if ($fact->fact_key === 'aerial_image') {
                $aerialImage = $this->aerialImage($fact);

                if ($aerialImage === null) {
                    $uncertainties[] = 'De opgeslagen luchtfoto kon niet worden geladen; gebruik de klantfoto’s en controleer de omgeving.';
                }

                continue;
            }

            $display = $this->display($fact);

            if ($display === null) {
                continue;
            }

            $facts[] = [
                'label' => $fact->label,
                'display' => $display,
                'source' => $fact->source,
                'source_url' => $fact->source_url,
                'confidence' => $fact->confidence === 'high' ? 'hoge zekerheid' : 'te controleren',
            ];
        }

        return [
            'facts' => $facts,
            'uncertainties' => array_values(array_unique($uncertainties)),
            'aerial_image' => $aerialImage,
        ];
    }

    /**
     * @return array{label: string, data_uri: string, source: string, source_url: string|null, confidence: string, ground_width_meters: int|null, ground_height_meters: int|null}|null
     */
    private function aerialImage(IntakeExternalFact $fact): ?array
    {
        $disk = $fact->value['media_disk'] ?? null;
        $path = $fact->value['media_path'] ?? null;
        $mimeType = $fact->value['mime_type'] ?? null;

        if (! is_string($disk) || $disk === ''
            || ! is_string($path) || $path === ''
            || $mimeType !== 'image/jpeg') {
            return null;
        }

        try {
            if (! Storage::disk($disk)->exists($path)) {
                return null;
            }

            $binary = Storage::disk($disk)->get($path);
        } catch (Throwable) {
            return null;
        }

        return [
            'label' => $fact->label,
            'data_uri' => 'data:image/jpeg;base64,'.base64_encode($binary),
            'source' => $fact->source,
            'source_url' => $fact->source_url,
            'confidence' => $fact->confidence === 'high' ? 'hoge zekerheid' : 'te controleren',
            'ground_width_meters' => is_numeric($fact->value['ground_width_meters'] ?? null)
                ? (int) $fact->value['ground_width_meters']
                : null,
            'ground_height_meters' => is_numeric($fact->value['ground_height_meters'] ?? null)
                ? (int) $fact->value['ground_height_meters']
                : null,
        ];
    }

    private function display(IntakeExternalFact $fact): ?string
    {
        $value = $fact->value;

        return match ($fact->fact_key) {
            'address_verification' => ($value['status'] ?? null) === 'matched' ? 'Adres gevonden en genormaliseerd' : null,
            'location' => $this->locationDisplay($value),
            'floor_area_m2' => isset($value['number']) ? (string) $value['number'].' '.($value['unit'] ?? 'm²') : null,
            'usage_purposes' => $this->listDisplay($value['values'] ?? null),
            'parcel_ids' => $this->listDisplay($value['values'] ?? null),
            'building_year' => isset($value['number']) ? (string) $value['number'] : null,
            'energy_label' => is_string($value['value'] ?? null) ? $value['value'] : null,
            'energy_demand' => isset($value['number']) ? (string) $value['number'].' '.($value['unit'] ?? 'kWh/m²·jr') : null,
            'building_height_m' => isset($value['number']) ? (string) $value['number'].' '.($value['unit'] ?? 'm') : null,
            'roof_type' => is_string($value['label'] ?? null) ? $value['label'] : null,
            'floor_count' => isset($value['number']) ? (string) $value['number'] : null,
            'fusebox_photo_assessment' => $this->fuseboxAssessmentDisplay($value),
            default => null,
        };
    }

    /** @param array<string, mixed> $value */
    private function fuseboxAssessmentDisplay(array $value): string
    {
        $freeGroup = match ($value['free_group'] ?? null) {
            'yes' => 'vrije groep lijkt beschikbaar',
            'no' => 'geen vrije groep zichtbaar',
            default => 'vrije groep niet betrouwbaar te bepalen',
        };
        $phase = match ($value['phase'] ?? null) {
            'one_phase' => '1-fase lijkt zichtbaar',
            'three_phase' => '3-fase lijkt zichtbaar',
            default => 'fase niet betrouwbaar te bepalen',
        };
        $evidence = is_string($value['evidence'] ?? null) ? trim($value['evidence']) : '';

        return implode(' · ', array_filter([$freeGroup, $phase, $evidence]));
    }

    /** @param array<string, mixed> $value */
    private function locationDisplay(array $value): ?string
    {
        $parts = array_values(array_filter([
            is_string($value['municipality'] ?? null) ? $value['municipality'] : null,
            is_string($value['province'] ?? null) ? $value['province'] : null,
        ]));

        if (is_numeric($value['latitude'] ?? null) && is_numeric($value['longitude'] ?? null)) {
            $parts[] = number_format((float) $value['latitude'], 6, '.', '')
                .', '.number_format((float) $value['longitude'], 6, '.', '');
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private function listDisplay(mixed $values): ?string
    {
        if (! is_array($values)) {
            return null;
        }

        $strings = array_values(array_filter($values, static fn (mixed $value): bool => is_string($value) && $value !== ''));

        return $strings === [] ? null : implode(', ', $strings);
    }

    private function uncertainty(IntakeExternalFact $fact): ?string
    {
        if ($fact->fact_key === 'address_verification') {
            return match ($fact->value['status'] ?? null) {
                'not_found' => 'Het ingevoerde adres kon niet eenduidig in de BAG worden gevonden; controleer adres en gebouwgegevens.',
                'unavailable' => 'PDOK/BAG was tijdelijk niet beschikbaar; controleer adres en gebouwgegevens.',
                default => null,
            };
        }

        if ($fact->fact_key === 'building_match' && ($fact->value['status'] ?? null) === 'ambiguous') {
            $count = is_numeric($fact->value['building_count'] ?? null) ? (int) $fact->value['building_count'] : 0;

            return "Het adres is aan {$count} panden gekoppeld; bouwjaar moet handmatig worden gecontroleerd.";
        }

        if ($fact->fact_key === 'aerial_image') {
            return 'De luchtfoto geeft alleen bovenaanzicht en omgevingscontext; gevel, actuele obstakels en exacte plaatsing moeten via klantfoto’s of op locatie worden bevestigd.';
        }

        if ($fact->fact_key === 'aerial_image_status') {
            return match ($fact->value['status'] ?? null) {
                'no_location' => 'Er was geen betrouwbare BAG-locatie beschikbaar voor een luchtfoto; controleer ligging en omgeving handmatig.',
                'unavailable' => 'De PDOK-luchtfoto was tijdelijk niet beschikbaar; gebruik de klantfoto’s en controleer de omgeving.',
                default => null,
            };
        }

        // 3DBAG markeert zelf wanneer de 3D-reconstructie mogelijk onjuist is; dat mag de
        // installateur niet ontgaan, want hoogte stuurt de keuze tussen ladder en steiger.
        if (in_array($fact->fact_key, ['building_height_m', 'roof_type', 'floor_count'], true)
            && $fact->confidence === 'low') {
            return 'De 3D-reconstructie van dit pand is door 3DBAG als mogelijk onjuist gemarkeerd; gebruik hoogte en dakvorm alleen als indicatie.';
        }

        if ($fact->fact_key === 'fusebox_photo_assessment') {
            $instruction = $fact->value['retake_instruction'] ?? null;

            if (is_string($instruction) && trim($instruction) !== '') {
                return 'De meterkastfoto was niet eenduidig voor automatische beoordeling. Nieuwe foto: '.trim($instruction);
            }

            return 'De automatische beoordeling van de meterkastfoto is een voorzet; controleer vrije groep en fase op de foto of op locatie.';
        }

        return null;
    }

    private function order(string $key): int
    {
        $index = array_search($key, [
            'address_verification',
            'building_year',
            'usage_purposes',
            'floor_area_m2',
            'location',
            'parcel_ids',
            'building_match',
            'energy_label',
            'energy_demand',
            'roof_type',
            'building_height_m',
            'floor_count',
            'fusebox_photo_assessment',
            'aerial_image',
            'aerial_image_status',
        ], true);

        return $index === false ? 99 : $index;
    }
}
