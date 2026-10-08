<?php

declare(strict_types=1);

namespace App\Domains\AI\Eval;

use App\Domains\AI\DTOs\RequestPrefillCandidate;
use App\Domains\AI\Services\AiGateway;
use App\Domains\AI\Services\LocalRequestIntentParser;
use App\Domains\AI\Services\PromptVersionRepository;
use App\Domains\AI\Services\RequestPrefillOutcomeClassifier;
use App\Domains\AI\Services\TemplateQuestionCatalogBuilder;
use App\Domains\AI\Support\OwnershipNormalizer;
use App\Domains\AI\Support\RoomFloorLevelExtractor;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Enums\TemplateVersionStatus;
use Throwable;

/**
 * Draait één request_text-case: model_raw + pipeline_final (C1–C4, C8).
 */
final class RequestTextCaseRunner
{
    public function __construct(
        private readonly AiGateway $aiGateway,
        private readonly PromptVersionRepository $promptVersions,
        private readonly TemplateQuestionCatalogBuilder $catalogBuilder,
        private readonly RequestPrefillOutcomeClassifier $classifier,
        private readonly LocalRequestIntentParser $localParser,
        private readonly PrefillFactExtractor $factExtractor,
        private readonly FactScorer $scorer,
        private readonly OwnershipNormalizer $ownershipNormalizer,
        private readonly RoomFloorLevelExtractor $floorExtractor,
    ) {}

    /**
     * @param  array<string, mixed>  $fixture
     * @return array<string, mixed>
     */
    public function run(array $fixture, int $repeatIndex): array
    {
        $text = is_string($fixture['text'] ?? null) ? $fixture['text'] : '';
        $expected = is_array($fixture['expected'] ?? null) ? $fixture['expected'] : [];
        $disputed = is_array($fixture['disputed_facts'] ?? null) ? $fixture['disputed_facts'] : [];

        $version = $this->publishedAircoVersion();
        $catalog = $this->catalogBuilder->fromVersion($version);
        $context = [
            'request_reason' => $text,
            'answers' => [[
                'question_key' => 'request_reason',
                'section_instance_key' => null,
                'prefill_source' => 'installer',
                'value' => ['text' => $text],
            ]],
            'external_facts' => [],
            'installer_observations' => [],
        ];

        $promptName = (string) config('ai.request_prefill_prompt', 'request_prefill');
        $promptVersion = $this->promptVersions->version($promptName);
        $promptBody = $this->promptVersions->body($promptName);

        $modelRaw = null;
        $modelError = null;
        $provider = null;
        $model = null;
        $temperature = null;

        $localOutput = $this->localParser->parse($text);
        $localCandidates = $localOutput === null
            ? []
            : array_map(
                static fn (RequestPrefillCandidate $c): array => $c->toArray(),
                $this->classifier->classifyLocalOutput($localOutput, $catalog),
            );

        try {
            $result = $this->aiGateway->complete(
                prompt: $promptBody,
                input: [
                    'task' => 'prefill_from_known_context',
                    'known_context' => $context,
                    'question_catalog' => $catalog,
                ],
                promptVersion: $promptVersion,
            );
            $modelRaw = $result->output;
            $provider = $result->provider;
            $model = $result->model;
            $temperature = is_numeric($result->modelParameters['temperature'] ?? null)
                ? (float) $result->modelParameters['temperature']
                : (float) config('ai.temperature', 0.2);
        } catch (Throwable $e) {
            $modelError = $e->getMessage();
        }

        $pipelineCandidates = [];
        $pipelineNormalizations = [];
        $classifiedFills = [];
        if (is_array($modelRaw)) {
            try {
                $classified = $this->classifier->classifyCatalogOutput(
                    $modelRaw,
                    $catalog,
                    [],
                    $text,
                );
                $classifiedFills = $classified['fills'];
                $pipelineNormalizations = $classified['normalizations'];
                $pipelineCandidates = array_map(
                    static fn (RequestPrefillCandidate $c): array => $c->toArray(),
                    $classified['candidates'],
                );
            } catch (Throwable $e) {
                $modelError = ($modelError !== null ? $modelError.'; ' : '').$e->getMessage();
            }
        }

        // Merge local writable + catalog pipeline (zelfde idee als EvaluateRequestIntent).
        $merged = $this->mergeCandidateArrays($localCandidates, $pipelineCandidates);

        $rawFacts = is_array($modelRaw)
            ? $this->factExtractor->fromModelRaw($modelRaw)
            : $this->factExtractor->fromModelRaw(['fills' => []]);
        $finalFacts = $this->factExtractor->fromCandidates($merged);

        // Code-interpretatie signalen (meting, geen nieuwe logica): ownership/floor extractors.
        $ownershipCue = $this->ownershipNormalizer->matchedEvidenceQuote($text);
        $roomTypes = array_values(array_filter(array_map(
            static fn (array $r): ?string => is_string($r['room_type'] ?? null) ? $r['room_type'] : null,
            is_array($expected['rooms'] ?? null) ? $expected['rooms'] : [],
        )));
        $floorLinks = $roomTypes === [] ? [] : $this->floorExtractor->floorsForRooms($text, $roomTypes);

        $scores = [
            'model_raw' => $this->scoreFacts($expected, $rawFacts, $disputed, supportedExisting: false),
            'pipeline_final' => $this->scoreFacts($expected, $finalFacts, $disputed, supportedExisting: false),
        ];

        return [
            'id' => $fixture['id'] ?? null,
            'kind' => 'request_text',
            'repeat' => $repeatIndex,
            'source_kind' => $fixture['source_kind'] ?? null,
            'origin' => $fixture['origin'] ?? null,
            'status' => $modelError !== null && $modelRaw === null ? 'error' : 'ok',
            'error' => $modelError,
            'provider' => $provider,
            'model' => $model,
            'temperature' => $temperature,
            'local_parser_version' => LocalRequestIntentParser::VERSION,
            'local_output' => $localOutput,
            'model_raw' => $modelRaw,
            'pipeline_fills' => $classifiedFills,
            'pipeline_normalizations' => $pipelineNormalizations,
            'pipeline_candidates' => $merged,
            'code_signals' => [
                'ownership_evidence_quote' => $ownershipCue,
                'floor_links_for_expected_room_types' => $floorLinks,
            ],
            'facts' => [
                'model_raw' => $rawFacts,
                'pipeline_final' => $finalFacts,
            ],
            'scores' => $scores,
            'note' => $fixture['note'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $got
     * @param  array<string, mixed>  $disputed
     * @return array<string, array<string, mixed>>
     */
    private function scoreFacts(array $expected, array $got, array $disputed, bool $supportedExisting): array
    {
        $out = [];

        foreach (['cooling_heating' => ['C1', 'C3'], 'indoor_unit_count' => ['C1'], 'ownership' => ['C4', 'C3']] as $key => $components) {
            if (! array_key_exists($key, $expected)) {
                continue;
            }
            $meta = is_array($disputed[$key] ?? null) ? $disputed[$key] : null;
            $out[$key] = $this->scorer->score(
                expected: $expected[$key],
                got: $got[$key] ?? null,
                supported: true,
                components: $components,
                disputed: $meta !== null,
                disputedReason: is_string($meta['reason'] ?? null) ? $meta['reason'] : null,
                acceptableAlternates: is_array($meta['acceptable_alternates'] ?? null) ? $meta['acceptable_alternates'] : null,
            );
        }

        if (array_key_exists('existing_installation', $expected)) {
            $exp = is_array($expected['existing_installation']) ? $expected['existing_installation'] : [];
            $gotEx = is_array($got['existing_installation'] ?? null) ? $got['existing_installation'] : [];
            foreach (['present', 'room', 'replace'] as $sub) {
                if (! array_key_exists($sub, $exp)) {
                    continue;
                }
                $factKey = 'existing_installation.'.$sub;
                $meta = is_array($disputed[$factKey] ?? null) ? $disputed[$factKey] : null;
                $out[$factKey] = $this->scorer->score(
                    expected: $exp[$sub],
                    got: $gotEx[$sub] ?? null,
                    supported: $supportedExisting,
                    components: ['C8', 'C3'],
                    disputed: $meta !== null,
                    disputedReason: is_string($meta['reason'] ?? null) ? $meta['reason'] : null,
                    acceptableAlternates: is_array($meta['acceptable_alternates'] ?? null) ? $meta['acceptable_alternates'] : null,
                );
            }
        }

        $expectedRooms = is_array($expected['rooms'] ?? null) ? $expected['rooms'] : [];
        $gotRooms = is_array($got['rooms'] ?? null) ? $got['rooms'] : [];

        foreach ($expectedRooms as $index => $expRoom) {
            if (! is_array($expRoom)) {
                continue;
            }
            $name = is_string($expRoom['name'] ?? null) ? $expRoom['name'] : ('room-'.($index + 1));
            $matched = $this->matchRoom($gotRooms, $name, $index);
            foreach (['room_type' => ['C1'], 'floor_level' => ['C2', 'C3'], 'length_m' => ['C1'], 'width_m' => ['C1'], 'area_m2' => ['C1'], 'ceiling_height_m' => ['C1']] as $field => $components) {
                if (! array_key_exists($field, $expRoom)) {
                    continue;
                }
                $factKey = 'rooms.'.$name.'.'.$field;
                $meta = is_array($disputed[$factKey] ?? null) ? $disputed[$factKey] : null;
                $out[$factKey] = $this->scorer->score(
                    expected: $expRoom[$field],
                    got: is_array($matched) ? ($matched[$field] ?? null) : null,
                    supported: true,
                    components: $components,
                    disputed: $meta !== null,
                    disputedReason: is_string($meta['reason'] ?? null) ? $meta['reason'] : null,
                    acceptableAlternates: is_array($meta['acceptable_alternates'] ?? null) ? $meta['acceptable_alternates'] : null,
                );
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rooms
     * @return array<string, mixed>|null
     */
    private function matchRoom(array $rooms, string $name, int $index): ?array
    {
        $needle = mb_strtolower($name);
        foreach ($rooms as $room) {
            $gotName = is_string($room['name'] ?? null) ? mb_strtolower($room['name']) : '';
            if ($gotName !== '' && ($gotName === $needle || str_contains($gotName, $needle) || str_contains($needle, $gotName))) {
                return $room;
            }
        }

        return $rooms[$index] ?? null;
    }

    /**
     * @param  list<array<string, mixed>>  $local
     * @param  list<array<string, mixed>>  $ai
     * @return list<array<string, mixed>>
     */
    private function mergeCandidateArrays(array $local, array $ai): array
    {
        $merged = [];
        foreach ($local as $candidate) {
            $key = ($candidate['question_key'] ?? '').'@'.($candidate['section_instance_key'] ?? '');
            $merged[$key] = $candidate;
        }
        foreach ($ai as $candidate) {
            $disposition = $candidate['disposition'] ?? null;
            $key = ($candidate['question_key'] ?? '').'@'.($candidate['section_instance_key'] ?? '');
            if (in_array($disposition, ['fill', 'suggestion'], true)) {
                $merged[$key] = $candidate;
            } elseif (! isset($merged[$key]) || ($merged[$key]['disposition'] ?? null) === 'rejected') {
                $merged[$key] = $candidate;
            }
        }

        return array_values($merged);
    }

    private function publishedAircoVersion(): IntakeTemplateVersion
    {
        $template = IntakeTemplate::query()->where('key', 'airco')->firstOrFail();

        return IntakeTemplateVersion::query()
            ->where('intake_template_id', $template->id)
            ->where('status', TemplateVersionStatus::Published)
            ->with(['template', 'sections.questions.options', 'sections.questions.rules'])
            ->orderByDesc('version')
            ->firstOrFail();
    }
}
