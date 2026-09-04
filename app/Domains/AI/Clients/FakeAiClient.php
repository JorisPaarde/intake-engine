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
            return new AiCompletionResult(
                output: $callbackOutput,
                provider: 'fake',
                model: 'fake-v1',
            );
        }

        if (self::$forcedOutput === null && str_starts_with($request->promptVersion, 'fusebox-assessment')) {
            return new AiCompletionResult(
                output: [
                    'free_group' => 'yes',
                    'phase' => 'three_phase',
                    'confidence' => 'high',
                    'evidence' => 'Fictieve testuitkomst voor de lokale fotoanalyse.',
                    'retake_instruction' => null,
                ],
                provider: 'fake',
                model: 'fake-vision-v1',
            );
        }

        if (self::$forcedOutput === null && str_starts_with($request->promptVersion, 'room-assessment')) {
            return new AiCompletionResult(
                output: [
                    'room_type' => 'living_room',
                    'room_size_indication' => 'medium',
                    'sun_exposure' => 'high',
                    'glass_amount' => 'much',
                    'room_outlet_status' => 'present',
                    'confidence' => 'high',
                    'evidence' => 'Fictieve testuitkomst voor de lokale ruimteanalyse.',
                    'retake_instruction' => null,
                ],
                provider: 'fake',
                model: 'fake-vision-v1',
            );
        }

        if (self::$forcedOutput === null && str_starts_with($request->promptVersion, 'outdoor-assessment')) {
            return new AiCompletionResult(
                output: [
                    'outdoor_location' => 'garden',
                    'outdoor_mount_type' => 'wall',
                    'outdoor_accessibility' => 'ladder',
                    'confidence' => 'high',
                    'evidence' => 'Fictieve testuitkomst voor de lokale buitenunitanalyse.',
                    'retake_instruction' => null,
                ],
                provider: 'fake',
                model: 'fake-vision-v1',
            );
        }

        if (self::$forcedOutput === null && str_starts_with($request->promptVersion, 'pipe-route-assessment')) {
            return new AiCompletionResult(
                output: [
                    'pipe_route_description' => 'along_facade',
                    'pipe_distance_indication' => 'short',
                    'drillings_needed' => 'yes',
                    'confidence' => 'high',
                    'evidence' => 'Fictieve testuitkomst voor de lokale leidingrouteanalyse.',
                    'retake_instruction' => null,
                ],
                provider: 'fake',
                model: 'fake-vision-v1',
            );
        }

        if (self::$forcedOutput === null && str_starts_with($request->promptVersion, 'installer-photo-observation')) {
            return new AiCompletionResult(
                output: [
                    'observations' => [[
                        'text' => 'Gemetselde wand is vanaf de vloer bereikbaar.',
                        'impact' => 'installation',
                        'confidence' => 0.9,
                    ]],
                ],
                provider: 'fake',
                model: 'fake-vision-v1',
            );
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

            return new AiCompletionResult(
                output: [
                    'evidence' => 'Fictieve catalogusprefill op basis van bekende context.',
                    'fills' => $fills,
                ],
                provider: 'fake',
                model: 'fake-v1',
            );
        }

        if (self::$forcedOutput === null && str_starts_with($request->promptVersion, 'dossier-synthesis')) {
            return new AiCompletionResult(
                output: [
                    'summary' => 'Fictieve integrale dossiersynthese voor testgebruik.',
                    'placement_proposals' => [],
                    'option_proposals' => [],
                    'exceptions' => [],
                    'customer_tasks' => [],
                ],
                provider: 'fake',
                model: 'fake-v1',
            );
        }

        $output = self::$forcedOutput ?? [
            'summary' => 'Fictieve AI-samenvatting van de intake voor testgebruik.',
            'highlights' => [
                'Klantgegevens en antwoorden zijn beschikbaar voor beoordeling.',
                'Controleer foto’s en aandachtspunten handmatig.',
            ],
        ];

        return new AiCompletionResult(
            output: $output,
            provider: 'fake',
            model: 'fake-v1',
        );
    }
}
