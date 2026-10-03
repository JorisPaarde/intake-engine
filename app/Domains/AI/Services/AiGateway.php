<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Contracts\AiClientInterface;
use App\Domains\AI\DTOs\AiCompletionRequest;
use App\Domains\AI\DTOs\AiCompletionResult;
use App\Domains\AI\DTOs\AiImageInput;
use App\Domains\AI\Exceptions\AiClientException;

final class AiGateway
{
    public function __construct(
        private readonly AiClientInterface $client,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  list<AiImageInput>  $images
     * @param  array<string, mixed>|null  $responseSchema
     */
    public function complete(
        string $prompt,
        array $input,
        string $promptVersion,
        ?string $system = null,
        array $images = [],
        ?string $model = null,
        ?float $temperature = null,
        ?array $responseSchema = null,
        ?int $timeoutSeconds = null,
    ): AiCompletionResult {
        try {
            return $this->client->complete(new AiCompletionRequest(
                prompt: $prompt,
                input: $input,
                promptVersion: $promptVersion,
                system: $system,
                images: $images,
                model: $model,
                temperature: $temperature,
                responseSchema: $responseSchema,
                timeoutSeconds: $timeoutSeconds,
            ));
        } catch (AiClientException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AiClientException('AI-aanroep mislukt: '.$e->getMessage(), previous: $e);
        }
    }
}
