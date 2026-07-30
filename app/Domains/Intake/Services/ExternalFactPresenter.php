<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
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
        $intake->loadMissing(['externalFacts', 'answers']);
        $facts = [];
        $uncertainties = [];
        $aerialImage = null;
        $insulation = $this->insulationFact($intake);
        $insulationAdded = false;

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

            if ($display !== null) {
                $facts[] = [
                    'label' => $fact->label,
                    'display' => $display,
                    'source' => $fact->source,
                    'source_url' => $fact->source_url,
                    'confidence' => $fact->confidence === 'high' ? 'hoge zekerheid' : 'te controleren',
                ];
            }

            if (! $insulationAdded
                && $insulation !== null
                && in_array($fact->fact_key, ['energy_label', 'energy_demand'], true)) {
                $facts[] = $insulation;
                $insulationAdded = true;
            }
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
            'building_year' => isset($value['number']) ? (string) $value['number'] : null,
            'energy_label' => is_string($value['value'] ?? null) ? $value['value'] : null,
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

    /**
     * @return array{label: string, display: string, source: string, source_url: string|null, confidence: string}|null
     */
    private function insulationFact(Intake $intake): ?array
    {
        $answer = $intake->answers->first(
            static fn (IntakeAnswer $answer): bool => $answer->question_key === 'insulation_indication'
                && $answer->section_instance_key === null
                && $answer->prefill_source === 'epo',
        );
        $value = is_array($answer?->value) ? ($answer->value['value'] ?? null) : null;
        $label = match ($value) {
            'good' => 'Goed',
            'average' => 'Gemiddeld',
            'poor' => 'Matig',
            default => null,
        };

        if ($label === null) {
            return null;
        }

        $demand = $intake->externalFacts->first(
            static fn (IntakeExternalFact $fact): bool => $fact->fact_key === 'energy_demand',
        );
        $energyLabel = $intake->externalFacts->first(
            static fn (IntakeExternalFact $fact): bool => $fact->fact_key === 'energy_label',
        );
        $sourceFact = $demand ?? $energyLabel;

        if (! $sourceFact instanceof IntakeExternalFact) {
            return null;
        }

        $demandValue = $demand instanceof IntakeExternalFact ? $demand->value : [];
        $number = $demandValue['number'] ?? null;
        $display = $label;

        if (is_numeric($number)) {
            $display .= ' · '.number_format((float) $number, 1, ',', '.').' kWh/m²·jaar';
        }

        return [
            'label' => 'Isolatie-indicatie',
            'display' => $display,
            'source' => $sourceFact->source,
            'source_url' => $sourceFact->source_url,
            'confidence' => $sourceFact->confidence === 'high' ? 'hoge zekerheid' : 'te controleren',
        ];
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
            'energy_label',
            'energy_demand',
            'building_year',
            'building_match',
            'roof_type',
            'building_height_m',
            'floor_count',
            'floor_area_m2',
            'usage_purposes',
            'location',
            'parcel_ids',
            'fusebox_photo_assessment',
            'aerial_image',
            'aerial_image_status',
        ], true);

        return $index === false ? 99 : $index;
    }
}
