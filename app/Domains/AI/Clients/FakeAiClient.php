<?php

declare(strict_types=1);

namespace App\Domains\AI\Clients;

use App\Domains\AI\Contracts\AiClientInterface;
use App\Domains\AI\DTOs\AiCompletionRequest;
use App\Domains\AI\DTOs\AiCompletionResult;
use App\Domains\AI\Exceptions\AiClientException;

final class FakeAiClient implements AiClientInterface
{
    /** @var array<string, mixed>|null */
    private static ?array $forcedOutput = null;

    private static ?AiClientException $forcedException = null;

    /**
     * @param  array<string, mixed>  $output
     */
    public static function alwaysReturn(array $output): void
    {
        self::$forcedOutput = $output;
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
    }

    public function complete(AiCompletionRequest $request): AiCompletionResult
    {
        if (self::$forcedException instanceof AiClientException) {
            throw self::$forcedException;
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
