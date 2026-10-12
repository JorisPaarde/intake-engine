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
        private readonly ReportClaimsScorer $claimsScorer,
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
        $components = is_array($fixture['components'] ?? null)
            ? array_values(array_filter(
                $fixture['components'],
                static fn (mixed $v): bool => is_string($v) && $v !== '',
            ))
            : ['C7'];
        $image = is_string($fixture['image'] ?? null) ? $fixture['image'] : null;
        $prompt = is_string($fixture['prompt'] ?? null) ? $fixture['prompt'] : null;

        $rawFacts = $this->factsFromObservation($text, $modelFields, $slot, pipeline: false);
        $finalFacts = $this->factsFromObservation($text, $modelFields, $slot, pipeline: true);

        $scores = [
            'model_raw' => $this->scoreLayer($expected, $rawFacts, $disputed, layer: 'model_raw'),
            'pipeline_final' => $this->scoreLayer($expected, $finalFacts, $disputed, layer: 'pipeline_final'),
        ];

        // N44 / language claims: score free-text evidence (+ retake) via ReportClaimsScorer.
        $claimExpected = ['claims' => is_array($expected['claims'] ?? null) ? $expected['claims'] : []];
        if ($claimExpected['claims'] !== []) {
            $rawEvidenceCorpus = $this->evidenceCorpus($text, $modelFields, $rawFacts);
            $finalEvidenceCorpus = $this->evidenceCorpus($text, $modelFields, $finalFacts);
            // Empty attention points (not null) so attention_output stays present;
            // language claims are scored on the evidence corpus as summary text.
            $scores['model_raw'] = array_merge(
                $scores['model_raw'],
                $this->claimsScorer->scoreLayer(
                    $claimExpected,
                    ['summary' => $rawEvidenceCorpus, 'highlights' => []],
                    [],
                    $disputed,
                    $components,
                ),
            );
            $scores['pipeline_final'] = array_merge(
                $scores['pipeline_final'],
                $this->claimsScorer->scoreLayer(
                    $claimExpected,
                    ['summary' => $finalEvidenceCorpus, 'highlights' => []],
                    [],
                    $disputed,
                    $components,
                ),
            );
        }

        $hasImage = $this->imageFilePresent($image);

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
            'prompt' => $prompt,
            'image' => $image,
            'image_present' => $hasImage,
            'runnable' => true,
            'runnable_note' => $hasImage
                ? 'Stub/fake: observatietekst + model_fields als model_raw; image aanwezig voor latere vision-run op de box.'
                : 'Geen echte foto: observatietekst + model_fields uit trace als model_raw; post-processing als pipeline_final.',
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
     * @param  array<string, mixed>  $facts
     */
    private function evidenceCorpus(string $text, array $modelFields, array $facts): string
    {
        $parts = [];
        $evidence = $facts['evidence'] ?? ($modelFields['evidence'] ?? null);
        if (is_string($evidence) && trim($evidence) !== '') {
            $parts[] = trim($evidence);
        } elseif (trim($text) !== '') {
            $parts[] = trim($text);
        }
        $retake = $facts['retake_instruction'] ?? ($modelFields['retake_instruction'] ?? null);
        if (is_string($retake) && trim($retake) !== '') {
            $parts[] = trim($retake);
        }

        return implode("\n", $parts);
    }

    private function imageFilePresent(?string $image): bool
    {
        if (! is_string($image) || $image === '') {
            return false;
        }
        if (is_file($image)) {
            return true;
        }
        // Repo-relative paths from fixtures (unit tests may lack Application::basePath).
        $repoRoot = dirname(__DIR__, 4);
        $candidate = $repoRoot.DIRECTORY_SEPARATOR.ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $image), DIRECTORY_SEPARATOR);

        return is_file($candidate);
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
            // N36 / room_assessment: pass through from model_fields (eval measurement).
            'sun_exposure' => null,
            'glass_amount' => null,
            'glazing_type' => null,
            // N44: free-text language measurement (evidence / retake_instruction).
            'evidence' => null,
            'retake_instruction' => null,
        ];

        if (array_key_exists('subject_match', $modelFields)) {
            $sm = $modelFields['subject_match'];
            $facts['subject_match'] = $sm === 'yes' || $sm === true;
        } elseif (str_contains($lower, 'geen meterkast') || str_contains($lower, 'geen leefruimte') || str_contains($lower, 'verkeerde')) {
            $facts['subject_match'] = false;
        }

        foreach ([
            'sun_exposure',
            'glass_amount',
            'glazing_type',
            'evidence',
            'retake_instruction',
            'outdoor_location',
            'room_type',
        ] as $passThroughField) {
            if (! array_key_exists($passThroughField, $modelFields)) {
                continue;
            }
            $value = $modelFields[$passThroughField];
            if (is_string($value) && $value !== '') {
                $facts[$passThroughField] = $value;
            } elseif ($passThroughField === 'retake_instruction' && $value === null) {
                $facts[$passThroughField] = null;
            }
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

        // Pipeline: DerivedClaimConfidenceGuard (cap/mark, geen tekstherschrijving) (C5).
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
            'sun_exposure' => ['C7'],
            'glass_amount' => ['C7'],
            'glazing_type' => ['C7'],
        ];

        foreach ($expected as $key => $expVal) {
            $factKey = (string) $key;
            // Language claims are scored separately via ReportClaimsScorer.
            if ($factKey === 'claims') {
                continue;
            }
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
