<?php

declare(strict_types=1);

namespace App\Domains\AI\Eval;

/**
 * Haalt evaluatiefeiten uit model_raw (fills-array) of pipeline_final (candidates/fills).
 */
final class PrefillFactExtractor
{
    /**
     * @param  array<string, mixed>  $output  model JSON of classified fills map
     * @return array<string, mixed>
     */
    public function fromModelRaw(array $output): array
    {
        $fills = $output['fills'] ?? null;
        if (! is_array($fills)) {
            return $this->emptyFacts();
        }

        return $this->fromFillsList($fills);
    }

    /**
     * @param  list<array<string, mixed>>  $candidates  RequestPrefillCandidate::toArray()
     * @return array<string, mixed>
     */
    public function fromCandidates(array $candidates): array
    {
        $fills = [];
        foreach ($candidates as $candidate) {
            $disposition = $candidate['disposition'] ?? null;
            if (! in_array($disposition, ['fill', 'suggestion'], true)) {
                continue;
            }
            $fills[] = [
                'question_key' => $candidate['question_key'] ?? null,
                'section_instance_key' => $candidate['section_instance_key'] ?? null,
                'value' => $candidate['value'] ?? null,
                'evidence' => $candidate['evidence'] ?? null,
            ];
        }

        return $this->fromFillsList($fills);
    }

    /**
     * @param  list<array<string, mixed>>  $fills
     * @return array<string, mixed>
     */
    private function fromFillsList(array $fills): array
    {
        $facts = $this->emptyFacts();
        $roomsByInstance = [];

        foreach ($fills as $fill) {
            $key = is_string($fill['question_key'] ?? null) ? $fill['question_key'] : null;
            if ($key === null) {
                continue;
            }
            $instance = is_string($fill['section_instance_key'] ?? null) ? $fill['section_instance_key'] : null;
            $value = is_array($fill['value'] ?? null) ? $fill['value'] : [];
            $scalar = $value['value'] ?? $value['number'] ?? $value['text'] ?? null;

            if ($instance !== null && str_starts_with($instance, 'room-')) {
                $roomsByInstance[$instance] ??= [
                    'name' => null,
                    'room_type' => null,
                    'floor_level' => null,
                    'length_m' => null,
                    'width_m' => null,
                    'area_m2' => null,
                    'ceiling_height_m' => null,
                ];
                match ($key) {
                    'room_name' => $roomsByInstance[$instance]['name'] = is_string($scalar) ? $scalar : null,
                    'room_type' => $roomsByInstance[$instance]['room_type'] = is_string($scalar) ? $scalar : null,
                    'floor_level' => $roomsByInstance[$instance]['floor_level'] = is_string($scalar) ? $scalar : null,
                    'room_length_m' => $roomsByInstance[$instance]['length_m'] = is_numeric($scalar) ? (float) $scalar : null,
                    'room_width_m' => $roomsByInstance[$instance]['width_m'] = is_numeric($scalar) ? (float) $scalar : null,
                    'room_area_m2' => $roomsByInstance[$instance]['area_m2'] = is_numeric($scalar) ? (float) $scalar : null,
                    'ceiling_height_m' => $roomsByInstance[$instance]['ceiling_height_m'] = is_numeric($scalar) ? (float) $scalar : null,
                    default => null,
                };

                continue;
            }

            match ($key) {
                'cooling_heating' => $facts['cooling_heating'] = is_string($scalar) ? $scalar : null,
                'indoor_unit_count' => $facts['indoor_unit_count'] = is_numeric($scalar) ? (int) $scalar : null,
                'ownership' => $facts['ownership'] = is_string($scalar) ? $scalar : null,
                default => null,
            };
        }

        ksort($roomsByInstance);
        $facts['rooms'] = array_values($roomsByInstance);

        // Bestaande installatie: geen catalogusveld op main → alleen signalen uit evidence.
        $evidenceBlob = '';
        foreach ($fills as $fill) {
            if (is_string($fill['evidence'] ?? null)) {
                $evidenceBlob .= ' '.$fill['evidence'];
            }
        }
        $facts['existing_installation'] = $this->inferExistingFromText($evidenceBlob);
        $facts['_existing_installation_supported'] = false;

        return $facts;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyFacts(): array
    {
        return [
            'cooling_heating' => null,
            'indoor_unit_count' => null,
            'ownership' => null,
            'rooms' => [],
            'existing_installation' => ['present' => null, 'room' => null, 'replace' => null],
            '_existing_installation_supported' => false,
        ];
    }

    /**
     * @return array{present: bool|null, room: string|null, replace: bool|null}
     */
    private function inferExistingFromText(string $text): array
    {
        $lower = mb_strtolower($text);
        if ($lower === '' || trim($lower) === '') {
            return ['present' => null, 'room' => null, 'replace' => null];
        }

        if (str_contains($lower, 'nog geen airco') || str_contains($lower, 'geen airco')) {
            return ['present' => false, 'room' => null, 'replace' => null];
        }

        if (str_contains($lower, 'vervang') || str_contains($lower, 'oude airco') || str_contains($lower, 'hangt al')) {
            return ['present' => true, 'room' => null, 'replace' => true];
        }

        return ['present' => null, 'room' => null, 'replace' => null];
    }
}
