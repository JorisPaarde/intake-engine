<?php

declare(strict_types=1);

namespace App\Domains\AI\Clients;

use App\Domains\AI\Contracts\AiClientInterface;
use App\Domains\AI\DTOs\AiCompletionRequest;
use App\Domains\AI\DTOs\AiCompletionResult;
use App\Domains\AI\Exceptions\AiClientException;
use Closure;

final class FakeAiClient implements AiClientInterface
{
    /** @var array<string, mixed>|null */
    private static ?array $forcedOutput = null;

    private static ?AiClientException $forcedException = null;

    private static ?AiCompletionRequest $lastRequest = null;

    /** @var (Closure(AiCompletionRequest): array<string, mixed>)|null */
    private static ?Closure $responseCallback = null;

    /**
     * @param  array<string, mixed>  $output
     */
    public static function alwaysReturn(array $output): void
    {
        self::$forcedOutput = $output;
        self::$forcedException = null;
        self::$responseCallback = null;
    }

    /** @param Closure(AiCompletionRequest): array<string, mixed> $callback */
    public static function respondUsing(Closure $callback): void
    {
        self::$responseCallback = $callback;
        self::$forcedOutput = null;
        self::$forcedException = null;
    }

    public static function alwaysFail(string $message = 'Fake AI failure'): void
    {
        self::$forcedException = new AiClientException($message);
        self::$forcedOutput = null;
    }

    public static function reset(): void
    {
        self::$forcedOutput = null;
        self::$forcedException = null;
        self::$lastRequest = null;
        self::$responseCallback = null;
    }

    public static function lastRequest(): ?AiCompletionRequest
    {
        return self::$lastRequest;
    }

    public function complete(AiCompletionRequest $request): AiCompletionResult
    {
        self::$lastRequest = $request;

        if (self::$forcedException instanceof AiClientException) {
            throw self::$forcedException;
        }

        $callbackOutput = self::$responseCallback === null
            ? null
            : (self::$responseCallback)($request);

        if ($callbackOutput !== null) {
            return $this->result($callbackOutput, 'fake-v1');
        }

        if (self::$forcedOutput === null && str_starts_with($request->promptVersion, 'fusebox-assessment')) {
            return $this->result([
                'free_group' => 'yes',
                'phase' => 'three_phase',
                'detected_subject' => 'fusebox',
                'subject_match' => 'yes',
                'confidence' => 'high',
                'evidence' => 'Fictieve testuitkomst voor de lokale fotoanalyse.',
                'retake_instruction' => null,
            ], 'fake-vision-v1');
        }

        if (self::$forcedOutput === null && str_starts_with($request->promptVersion, 'room-assessment')) {
            return $this->result([
                'room_type' => 'living_room',
                'room_size_indication' => 'medium',
                'sun_exposure' => 'high',
                'glass_amount' => 'much',
                'room_outlet_status' => 'present',
                'detected_subject' => 'room',
                'subject_match' => 'yes',
                'confidence' => 'high',
                'evidence' => 'Fictieve testuitkomst voor de lokale ruimteanalyse.',
                'retake_instruction' => null,
            ], 'fake-vision-v1');
        }

        if (self::$forcedOutput === null && str_starts_with($request->promptVersion, 'outdoor-assessment')) {
            return $this->result([
                'outdoor_location' => 'garden',
                'outdoor_mount_type' => 'wall',
                'outdoor_accessibility' => 'ladder',
                'detected_subject' => 'outdoor_location',
                'subject_match' => 'yes',
                'confidence' => 'high',
                'evidence' => 'Fictieve testuitkomst voor de lokale buitenunitanalyse.',
                'retake_instruction' => null,
            ], 'fake-vision-v1');
        }

        if (self::$forcedOutput === null && str_starts_with($request->promptVersion, 'pipe-route-assessment')) {
            return $this->result([
                'pipe_route_description' => 'along_facade',
                'pipe_distance_indication' => 'short',
                'drillings_needed' => 'unknown',
                'detected_subject' => 'pipe_route',
                'subject_match' => 'yes',
                'confidence' => 'medium',
                'evidence' => 'Fictieve testuitkomst voor de lokale leidingrouteanalyse.',
                'retake_instruction' => null,
            ], 'fake-vision-v1');
        }

        if (self::$forcedOutput === null && str_starts_with($request->promptVersion, 'follow-up-photo-subject')) {
            return $this->result([
                'detected_subject' => 'fusebox',
                'subject_match' => 'yes',
                'evidence' => 'Fictieve testuitkomst voor gerichte fotocategorie.',
            ], 'fake-vision-v1');
        }

        if (self::$forcedOutput === null && str_starts_with($request->promptVersion, 'installer-photo-observation')) {
            return $this->result([
                'observations' => [[
                    'text' => 'Gemetselde wand is vanaf de vloer bereikbaar.',
                    'impact' => 'installation',
                    'confidence' => 0.9,
                ]],
            ], 'fake-vision-v1');
        }

        if (self::$forcedOutput === null && str_starts_with($request->promptVersion, 'request-prefill')) {
            $reason = is_string($request->input['known_context']['request_reason'] ?? null)
                ? mb_strtolower((string) $request->input['known_context']['request_reason'])
                : '';

            $observationText = '';
            $observations = $request->input['known_context']['installer_observations'] ?? [];
            if (is_array($observations)) {
                foreach ($observations as $observation) {
                    if (is_array($observation) && is_string($observation['text'] ?? null)) {
                        $observationText .= ' '.mb_strtolower((string) $observation['text']);
                    }
                }
            }

            $corpus = trim($reason.' '.$observationText);
            $fills = [];

            if (str_contains($corpus, 'koud te krijgen') || str_contains($corpus, 'koelen') || str_contains($corpus, 'te warm')) {
                $fills[] = [
                    'question_key' => 'cooling_heating',
                    'section_instance_key' => null,
                    'confidence' => 'high',
                    'value' => ['value' => 'cooling'],
                    'evidence' => null,
                ];
            }

            if (str_contains($corpus, 'twee') && str_contains($corpus, 'slaapkamer')) {
                $fills[] = [
                    'question_key' => 'indoor_unit_count',
                    'section_instance_key' => null,
                    'confidence' => 'high',
                    'value' => ['number' => 2],
                    'evidence' => null,
                ];
                $fills[] = [
                    'question_key' => 'room_type',
                    'section_instance_key' => 'room-1',
                    'confidence' => 'high',
                    'value' => ['value' => 'bedroom'],
                    'evidence' => null,
                ];
                $fills[] = [
                    'question_key' => 'room_type',
                    'section_instance_key' => 'room-2',
                    'confidence' => 'high',
                    'value' => ['value' => 'bedroom'],
                    'evidence' => null,
                ];
            }

            if (str_contains($corpus, 'dakkapel')) {
                $fills[] = [
                    'question_key' => 'outdoor_location',
                    'section_instance_key' => null,
                    'confidence' => 'high',
                    'value' => ['value' => 'dormer'],
                    'evidence' => null,
                ];
                $fills[] = [
                    'question_key' => 'outdoor_mount_type',
                    'section_instance_key' => null,
                    'confidence' => 'high',
                    'value' => ['value' => 'roof'],
                    'evidence' => null,
                ];
            }

            return $this->result([
                'evidence' => 'Fictieve catalogusprefill op basis van bekende context.',
                'fills' => $fills,
            ], 'fake-v1');
        }

        if (self::$forcedOutput === null && str_starts_with($request->promptVersion, 'dossier-synthesis')) {
            return $this->result([
                'summary' => 'Fictieve integrale dossiersynthese voor testgebruik.',
                'placement_proposals' => [],
                'option_proposals' => [],
                'exceptions' => [],
                'customer_tasks' => [],
            ], 'fake-v1');
        }

        $output = self::$forcedOutput ?? [
            'summary' => 'Fictieve AI-samenvatting van de intake voor testgebruik.',
            'highlights' => [
                'Klantgegevens en antwoorden zijn beschikbaar voor beoordeling.',
                'Controleer foto’s en aandachtspunten handmatig.',
            ],
        ];

        return $this->result($output, 'fake-v1');
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function result(array $output, string $model): AiCompletionResult
    {
        $raw = (string) json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $request = self::$lastRequest;
        $promptChars = $request === null
            ? 0
            : strlen($request->prompt) + strlen((string) json_encode($request->input, JSON_UNESCAPED_UNICODE));
        $inputTokens = max(1, (int) ceil(max(1, $promptChars) / 4));
        $outputTokens = max(1, (int) ceil(strlen($raw) / 4));
        $imageCount = $request === null ? 0 : count($request->images);
        $totalTokens = $inputTokens + $outputTokens;
        // Rough fake budget units: 1 cent per 1k tokens + 2 cents per image.
        $estimatedCostCents = (int) max(1, (int) ceil($totalTokens / 1000) + ($imageCount * 2));

        return new AiCompletionResult(
            output: $output,
            provider: 'fake',
            model: $model,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            totalTokens: $totalTokens,
            imageCount: $imageCount,
            estimatedCostCents: $estimatedCostCents,
            finishReason: 'stop',
            rawResponse: $raw,
            providerMs: 1,
            modelParameters: [
                'temperature' => self::$lastRequest?->temperature
                    ?? (float) config('ai.temperature', 0.2),
                'response_format' => ['type' => 'json_object'],
                'model' => $request !== null && $request->model !== null ? $request->model : $model,
            ],
        );
    }
}
