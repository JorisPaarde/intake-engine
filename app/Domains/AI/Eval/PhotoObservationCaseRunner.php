<?php

declare(strict_types=1);

namespace App\Domains\AI\Eval;

use App\Domains\AI\Actions\DerivePhotoAnswers;
use App\Domains\AI\Support\DerivedClaimConfidenceGuard;
use ReflectionMethod;

/**
 * Meet foto-observatiefeiten (C5/C7/C8) zonder echte foto's:
 * model_raw = fixture model_fields + observatietekst; pipeline_final = post-processing op main.
 */
final class PhotoObservationCaseRunner
{
    public function __construct(
        private readonly FactScorer $scorer,
        private readonly DerivedClaimConfidenceGuard $confidenceGuard,
    ) {}

    /**
     * @param  array<string, mixed>  $fixture
     * @return array<string, mixed>
     */
    public function run(array $fixture, int $repeatIndex): array
    {
        $text = is_string($fixture['text'] ?? null) ? $fixture['text'] : '';
        $slot = is_string($fixture['slot'] ?? null) ? $fixture['slot'] : null;
        $modelFields = is_array($fixture['model_fields'] ?? null) ? $fixture['model_fields'] : [];
        $expected = is_array($fixture['expected'] ?? null) ? $fixture['expected'] : [];
        $disputed = is_array($fixture['disputed_facts'] ?? null) ? $fixture['disputed_facts'] : [];

        $rawFacts = $this->factsFromObservation($text, $modelFields, $slot, pipeline: false);
        $finalFacts = $this->factsFromObservation($text, $modelFields, $slot, pipeline: true);

        $scores = [
            'model_raw' => $this->scoreLayer($expected, $rawFacts, $disputed, layer: 'model_raw'),
            'pipeline_final' => $this->scoreLayer($expected, $finalFacts, $disputed, layer: 'pipeline_final'),
        ];

        return [
            'id' => $fixture['id'] ?? null,
            'kind' => 'photo_observation',
            'repeat' => $repeatIndex,
            'source_kind' => $fixture['source_kind'] ?? null,
            'origin' => $fixture['origin'] ?? null,
            'status' => 'ok',
            'error' => null,
            'slot' => $slot,
            'room' => $fixture['room'] ?? null,
            'runnable' => true,
            'runnable_note' => 'Geen echte foto: observatietekst + model_fields uit trace als model_raw; post-processing als pipeline_final.',
            'model_raw' => ['text' => $text, 'model_fields' => $modelFields, 'facts' => $rawFacts],
            'pipeline_final' => $finalFacts,
            'facts' => [
                'model_raw' => $rawFacts,
                'pipeline_final' => $finalFacts,
            ],
            'scores' => $scores,
            'note' => $fixture['note'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $modelFields
     * @return array<string, mixed>
     */
    private function factsFromObservation(string $text, array $modelFields, ?string $slot, bool $pipeline): array
    {
        $lower = mb_strtolower($text);
        $facts = [
            'subject_match' => null,
            'route_to' => null,
            'outlet_present' => null,
            'room_type' => null,
            'existing_outdoor_unit_visible' => null,
            'existing_indoor_unit_visible' => null,
            'outdoor_location' => null,
            'mount' => null,
            'mount_position_known' => null,
            'pipe_route_visible' => null,
            'phase' => null,
            'free_group' => null,
            'certainty' => null,
            'needs_retake' => null,
            'conflict_with_request_text' => null,
        ];

        if (array_key_exists('subject_match', $modelFields)) {
            $sm = $modelFields['subject_match'];
            $facts['subject_match'] = $sm === 'yes' || $sm === true;
        } elseif (str_contains($lower, 'geen meterkast') || str_contains($lower, 'geen leefruimte') || str_contains($lower, 'verkeerde')) {
            $facts['subject_match'] = false;
        }

        if (str_contains($lower, 'stopcontact')) {
            $facts['outlet_present'] = true;
        }
        if (str_contains($lower, 'slaapkamer') || str_contains($lower, 'bed ')) {
            $facts['room_type'] = 'bedroom';
        }
        if (str_contains($lower, 'woonkamer')) {
            $facts['room_type'] = 'living_room';
        }
        if (str_contains($lower, 'buitenunit') && (str_contains($lower, 'reeds') || str_contains($lower, 'geïnstalleerd') || str_contains($lower, 'geinstalleerd'))) {
            $facts['existing_outdoor_unit_visible'] = true;
        }
        if (str_contains($lower, 'airco-unit is reeds aanwezig') || str_contains($lower, 'airco unit is reeds aanwezig')) {
            $facts['existing_indoor_unit_visible'] = true;
            $facts['conflict_with_request_text'] = true;
        }
        if (str_contains($lower, 'achtertuin') || str_contains($lower, 'tuin')) {
            $facts['outdoor_location'] = 'garden';
        }
        if (str_contains($lower, 'voorgevel') || str_contains($lower, 'achtergevel') || str_contains($lower, 'gevel')) {
            $facts['outdoor_location'] = 'facade';
        }
        if (str_contains($lower, 'montageplek') || str_contains($lower, 'bevestigingsvlak')) {
            $facts['mount_position_known'] = str_contains($lower, 'geen specifieke') ? false : true;
        }
        if (str_contains($lower, 'niet de leidingroute') || (str_contains($lower, 'leidingroute') && str_contains($lower, 'niet'))) {
            $facts['pipe_route_visible'] = false;
        }
        if (str_contains($lower, '3-fasen') || str_contains($lower, '3-fase') || str_contains($lower, 'drie hendels') || str_contains($lower, 'drie zekeringen')) {
            $facts['phase'] = 'three_phase';
        }
        if (str_contains($lower, 'alle groepsruimtes') || str_contains($lower, 'volledig gevuld') || str_contains($lower, 'lijken bezet')) {
            $facts['free_group'] = 'no';
        }
        if (str_contains($lower, 'te onscherp') || str_contains($lower, 'onscherp')) {
            $facts['needs_retake'] = true;
            $facts['phase'] = null;
            $facts['free_group'] = null;
        }
        if (str_contains($lower, 'lijken') || str_contains($lower, 'lijkt')) {
            $facts['certainty'] = "hedged ('lijken')";
        }
        if (str_contains($lower, 'bakstenen gevel') && str_contains($lower, 'buitenunit')) {
            $facts['mount'] = 'wall';
        }

        if ($slot === 'fusebox_photo' && ($facts['subject_match'] === false)) {
            $facts['route_to'] = 'none (verkeerde foto)';
            $facts['phase'] = null;
            $facts['free_group'] = null;
        }
        if ($slot === 'room_photos' && ($facts['subject_match'] === false)) {
            $facts['route_to'] = 'none (verkeerde foto)';
        }

        if (! $pipeline) {
            return $facts;
        }

        // Pipeline: DerivedClaimConfidenceGuard + pipe-route retake keyword routing (C5/C7).
        $ceiling = $this->confidenceGuard->ceilingFromObservationText($text);
        if ($facts['certainty'] !== null || $this->confidenceGuard->textLooksHedged($text)) {
            $facts['certainty'] = "hedged ('lijken')";
            // Free-group claims from hedged text moeten niet als hard "no" blijven zonder hedge-signaal.
            if ($ceiling === 'low' || $ceiling === 'medium') {
                $facts['_confidence_ceiling'] = $ceiling;
            }
        }

        if ($slot === 'pipe_route_photos') {
            try {
                $retake = $this->invokeSanitizePipeRouteRetake(
                    'Maak een foto van de buitenunitplek of de gevel.',
                );
                $facts['_pipe_route_retake_sanitized'] = $retake;
            } catch (\Throwable $e) {
                $facts['_pipe_route_retake_error'] = $e->getMessage();
            }
            if ($facts['pipe_route_visible'] === false) {
                $facts['route_to'] = 'retake_pipe_route';
            }
        }

        // Main vult free_group_known nooit uit foto (BL-133) — pipeline wist hard free_group uit foto-observatie.
        if ($slot === 'fusebox_photo' && $facts['free_group'] !== null) {
            $facts['free_group'] = null;
            $facts['_free_group_stripped_by_policy'] = true;
        }

        return $facts;
    }

    private function invokeSanitizePipeRouteRetake(string $retake): ?string
    {
        $method = new ReflectionMethod(
            DerivePhotoAnswers::class,
            'sanitizePipeRouteRetake',
        );
        $method->setAccessible(true);
        /** @var object $instance */
        $instance = app(DerivePhotoAnswers::class);
        $result = $method->invoke($instance, $retake);

        return is_string($result) ? $result : null;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $got
     * @param  array<string, mixed>  $disputed
     * @return array<string, array<string, mixed>>
     */
    private function scoreLayer(array $expected, array $got, array $disputed, string $layer): array
    {
        $out = [];
        $componentMap = [
            'subject_match' => ['C7'],
            'route_to' => ['C7'],
            'outlet_present' => ['C7'],
            'room_type' => ['C7'],
            'existing_outdoor_unit_visible' => ['C7', 'C8'],
            'existing_indoor_unit_visible' => ['C7', 'C8'],
            'outdoor_location' => ['C7'],
            'mount' => ['C7'],
            'mount_position_known' => ['C7'],
            'pipe_route_visible' => ['C7'],
            'phase' => ['C5', 'C7'],
            'free_group' => ['C5', 'C7'],
            'certainty' => ['C5'],
            'needs_retake' => ['C7'],
            'conflict_with_request_text' => ['C8'],
        ];

        foreach ($expected as $key => $expVal) {
            $factKey = (string) $key;
            // Soft string match for outdoor_location labels like "facade (achtergevel)"
            $gotVal = $got[$factKey] ?? null;
            $key = $factKey;
            if ($key === 'outdoor_location' && is_string($expVal) && is_string($gotVal)) {
                if (str_contains($expVal, $gotVal) || str_contains($gotVal, explode(' ', $expVal)[0])) {
                    $gotVal = $expVal;
                }
            }
            if ($key === 'route_to' && is_string($expVal) && is_string($gotVal)) {
                if (str_contains(mb_strtolower($expVal), 'verkeerde') && str_contains(mb_strtolower($gotVal), 'verkeerde')) {
                    $gotVal = $expVal;
                }
            }
            if ($key === 'certainty' && is_string($expVal) && is_string($gotVal)) {
                if (str_contains($expVal, 'hedged') && str_contains($gotVal, 'hedged')) {
                    $gotVal = $expVal;
                }
            }

            $meta = is_array($disputed[$key] ?? null) ? $disputed[$key] : null;
            $supported = true;
            // conflict_with_request_text heeft geen dedicated veld op main
            if ($key === 'conflict_with_request_text' && $layer === 'pipeline_final') {
                $supported = false;
            }

            $out[$key] = $this->scorer->score(
                expected: $expVal,
                got: $gotVal,
                supported: $supported,
                components: $componentMap[$key] ?? ['C7'],
                disputed: $meta !== null,
                disputedReason: is_string($meta['reason'] ?? null) ? $meta['reason'] : null,
                acceptableAlternates: is_array($meta['acceptable_alternates'] ?? null) ? $meta['acceptable_alternates'] : null,
            );
        }

        return $out;
    }
}
