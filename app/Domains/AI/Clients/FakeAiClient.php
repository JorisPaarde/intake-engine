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

        if (self::$forcedOutput === null && str_starts_with($request->promptVersion, 'request-intent')) {
            return new AiCompletionResult(
                output: [
                    'cooling_heating' => 'cooling',
                    'rooms' => ['bedroom', 'living_room'],
                    'confidence' => 'high',
                    'evidence' => 'Fictieve testuitkomst voor de lokale intentie-analyse.',
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
