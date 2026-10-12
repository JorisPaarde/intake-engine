<?php

declare(strict_types=1);

namespace App\Domains\AI\Eval;

/**
 * Haalt evaluatiefeiten uit model_raw (fills + top-level existing_installation) of pipeline fills.
 *
 * existing_installation zit niet in de vraagcatalogus; het model levert het als top-level
 * object naast fills (request-prefill-v14+). Geen regex/keyword-afleiding (ADR-0013).
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
        $facts = is_array($fills) ? $this->fromFillsList($fills) : $this->emptyFacts();

        return $this->applyExistingInstallationFromModel($facts, $output);
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
     * Pipeline heeft geen catalogusveld voor existing_installation: erf model_raw.
     *
     * @param  array<string, mixed>  $pipelineFacts
     * @param  array<string, mixed>  $modelRawFacts
     * @return array<string, mixed>
     */
    public function mergeNonCatalogFromModelRaw(array $pipelineFacts, array $modelRawFacts): array
    {
        $pipelineFacts['existing_installation'] = $modelRawFacts['existing_installation']
            ?? ['present' => null, 'room' => null, 'replace' => null];
        $pipelineFacts['_existing_installation_supported'] = (bool) ($modelRawFacts['_existing_installation_supported'] ?? false);

        return $pipelineFacts;
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
                'building_type' => $facts['building_type'] = is_string($scalar) ? $scalar : null,
                default => null,
            };
        }

        ksort($roomsByInstance);
        $facts['rooms'] = array_values($roomsByInstance);

        return $facts;
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    private function applyExistingInstallationFromModel(array $facts, array $output): array
    {
        // Schema (request-prefill-v14+) always scores this object — absence → nulls/missing, not not_supported.
        $facts['_existing_installation_supported'] = true;

        if (! array_key_exists('existing_installation', $output) || ! is_array($output['existing_installation'])) {
            $facts['existing_installation'] = ['present' => null, 'room' => null, 'replace' => null];

            return $facts;
        }

        $raw = $output['existing_installation'];

        $present = $raw['present'] ?? null;
        if (! is_bool($present) && $present !== null) {
            $present = null;
        }

        $room = $raw['room'] ?? null;
        if (! is_string($room) || trim($room) === '') {
            $room = null;
        }

        $replace = $raw['replace'] ?? null;
        if (! is_bool($replace) && $replace !== null) {
            $replace = null;
        }

        $facts['existing_installation'] = [
            'present' => $present,
            'room' => $room,
            'replace' => $replace,
        ];

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
            'building_type' => null,
            'rooms' => [],
            'existing_installation' => ['present' => null, 'room' => null, 'replace' => null],
            '_existing_installation_supported' => false,
        ];
    }
}
