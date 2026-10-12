<?php

declare(strict_types=1);

namespace App\Domains\AI\Eval;

use App\Domains\AI\DTOs\AiCompletionResult;
use App\Domains\AI\DTOs\AiImageInput;
use App\Domains\AI\Services\AiGateway;
use App\Domains\AI\Support\PhotoDerivationProfile;
use App\Domains\AI\Support\PhotoSubject;
use Throwable;

/**
 * Generic eval-only vision call for photo assessment prompts.
 *
 * Starts with room_assessment; outdoor / fusebox / pipe_route can opt in later
 * via {@see self::SUPPORTED_PROMPTS} + profile map without changing the harness shape.
 */
final class EvalPhotoVisionInvoker
{
    /**
     * Prompts that may run live vision today.
     *
     * @var list<string>
     */
    public const SUPPORTED_PROMPTS = [
        'room_assessment',
    ];

    /**
     * prompt name → PhotoDerivationProfile name (product schema source).
     *
     * @var array<string, string>
     */
    private const PROMPT_TO_PROFILE = [
        'room_assessment' => 'room',
        'outdoor_assessment' => 'outdoor',
        'pipe_route_assessment' => 'pipe_route',
        // fusebox_assessment uses AssessFuseboxPhotos (separate schema) — wire later.
    ];

    /**
     * Fixture slot → expected PhotoSubject value.
     *
     * @var array<string, string>
     */
    private const SLOT_TO_SUBJECT = [
        'room_photos' => 'room',
        'outdoor_location_photos' => 'outdoor_location',
        'outdoor_unit_photos' => 'outdoor_unit',
        'fusebox_photo' => 'fusebox',
        'pipe_route_photos' => 'pipe_route',
    ];

    public function __construct(
        private readonly AiGateway $aiGateway,
        private readonly EvalPromptVersionResolver $promptResolver,
    ) {}

    public function supports(string $promptName): bool
    {
        return in_array($promptName, self::SUPPORTED_PROMPTS, true);
    }

    /**
     * @return list<string>
     */
    public function supportedPrompts(): array
    {
        return self::SUPPORTED_PROMPTS;
    }

    public function profileNameForPrompt(string $promptName): ?string
    {
        return self::PROMPT_TO_PROFILE[$promptName] ?? null;
    }

    public function expectedSubjectForSlot(?string $slot): string
    {
        if ($slot === null || $slot === '') {
            return PhotoSubject::Room->value;
        }

        return self::SLOT_TO_SUBJECT[$slot] ?? PhotoSubject::Other->value;
    }

    /**
     * @return array{
     *     status: 'ok'|'error',
     *     output: array<string, mixed>|null,
     *     provider: string|null,
     *     model: string|null,
     *     prompt_name: string,
     *     prompt_version: string|null,
     *     prompt_source: string|null,
     *     prompt_selector: string|null,
     *     token_usage: array{
     *         input_tokens: int|null,
     *         output_tokens: int|null,
     *         total_tokens: int|null,
     *         estimated_cost: string|null,
     *         estimated_cost_cents: int|null
     *     }|null,
     *     rejection: string|null,
     *     error: string|null,
     *     image_bytes: int,
     *     image_mime: string|null
     * }
     */
    public function invoke(
        string $promptName,
        string $absoluteImagePath,
        ?string $promptVersionSelector = null,
        ?string $slot = null,
    ): array {
        if (! $this->supports($promptName)) {
            return $this->errorResult(
                $promptName,
                'live_vision_not_enabled_for_prompt: '.$promptName
                    .' (supported: '.implode(', ', self::SUPPORTED_PROMPTS).')',
            );
        }

        $profileKey = $this->profileNameForPrompt($promptName);
        if ($profileKey === null) {
            return $this->errorResult($promptName, 'no_profile_mapping_for_prompt: '.$promptName);
        }

        try {
            $profile = PhotoDerivationProfile::require($profileKey);
        } catch (Throwable $e) {
            return $this->errorResult($promptName, 'profile_error: '.$e->getMessage());
        }

        if (! is_file($absoluteImagePath)) {
            return $this->errorResult($promptName, 'image_missing: '.$absoluteImagePath);
        }

        $binary = file_get_contents($absoluteImagePath);
        if (! is_string($binary) || $binary === '') {
            return $this->errorResult($promptName, 'image_unreadable: '.$absoluteImagePath);
        }

        $mime = $this->detectMime($absoluteImagePath, $binary);
        if ($mime === null) {
            return $this->errorResult($promptName, 'unsupported_image_mime: '.$absoluteImagePath);
        }

        try {
            $resolved = $this->promptResolver->resolve($promptName, $promptVersionSelector);
        } catch (Throwable $e) {
            return $this->errorResult($promptName, 'prompt_resolve_error: '.$e->getMessage());
        }

        $expectedSubject = $this->expectedSubjectForSlot($slot);
        $expectedFields = [];
        foreach ($profile->fields as $field) {
            $expectedFields[$field->outputKey] = $field->schemaValues();
        }

        $input = [
            'task' => 'derive_answers_from_photos',
            'profile' => $profile->name,
            'expected_fields' => $expectedFields,
            'expected_subject' => $expectedSubject,
            'images' => [[
                'checksum' => hash('sha256', $binary),
                'mime_type' => $mime,
                'variant' => 'eval_fixture',
            ]],
            'eval' => true,
        ];

        try {
            $result = $this->aiGateway->complete(
                prompt: $resolved['body'],
                input: $input,
                promptVersion: $resolved['version'],
                images: [new AiImageInput(mimeType: $mime, binary: $binary)],
                temperature: (float) config('ai.classification_temperature', 0),
            );
        } catch (Throwable $e) {
            return [
                'status' => 'error',
                'output' => null,
                'provider' => null,
                'model' => null,
                'prompt_name' => $promptName,
                'prompt_version' => $resolved['version'],
                'prompt_source' => $resolved['source'],
                'prompt_selector' => $resolved['selector'],
                'token_usage' => null,
                'rejection' => 'gateway_error: '.$e->getMessage(),
                'error' => $e->getMessage(),
                'image_bytes' => strlen($binary),
                'image_mime' => $mime,
            ];
        }

        if (! $this->looksLikePhotoAssessment($result->output, $profile)) {
            return [
                'status' => 'error',
                'output' => $result->output,
                'provider' => $result->provider,
                'model' => $result->model,
                'prompt_name' => $promptName,
                'prompt_version' => $resolved['version'],
                'prompt_source' => $resolved['source'],
                'prompt_selector' => $resolved['selector'],
                'token_usage' => $this->usageFromResult($result),
                'rejection' => 'gateway_output_not_photo_assessment_shape',
                'error' => 'gateway_output_not_photo_assessment_shape',
                'image_bytes' => strlen($binary),
                'image_mime' => $mime,
            ];
        }

        return [
            'status' => 'ok',
            'output' => $result->output,
            'provider' => $result->provider,
            'model' => $result->model,
            'prompt_name' => $promptName,
            'prompt_version' => $resolved['version'],
            'prompt_source' => $resolved['source'],
            'prompt_selector' => $resolved['selector'],
            'token_usage' => $this->usageFromResult($result),
            'rejection' => null,
            'error' => null,
            'image_bytes' => strlen($binary),
            'image_mime' => $mime,
        ];
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function looksLikePhotoAssessment(array $output, PhotoDerivationProfile $profile): bool
    {
        if (! is_string($output['evidence'] ?? null) || trim((string) $output['evidence']) === '') {
            return false;
        }
        if (! is_string($output['confidence'] ?? null)) {
            return false;
        }
        // At least one profile field present (or subject_match) so fake/empty JSON is rejected.
        if (array_key_exists('subject_match', $output) || array_key_exists('detected_subject', $output)) {
            return true;
        }
        foreach ($profile->fields as $field) {
            if (array_key_exists($field->outputKey, $output)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{input_tokens: int|null, output_tokens: int|null, total_tokens: int|null, estimated_cost: string|null, estimated_cost_cents: int|null}
     */
    private function usageFromResult(AiCompletionResult $result): array
    {
        return [
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'total_tokens' => $result->totalTokens,
            'estimated_cost' => $result->estimatedCost,
            'estimated_cost_cents' => $result->estimatedCostCents,
        ];
    }

    private function detectMime(string $path, string $binary): ?string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $byExt = match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => null,
        };

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_buffer($finfo, $binary);
                finfo_close($finfo);
                if (is_string($detected) && in_array($detected, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                    return $detected;
                }
            }
        }

        return $byExt;
    }

    /**
     * @return array{
     *     status: 'error',
     *     output: null,
     *     provider: null,
     *     model: null,
     *     prompt_name: string,
     *     prompt_version: null,
     *     prompt_source: null,
     *     prompt_selector: null,
     *     token_usage: null,
     *     rejection: string,
     *     error: string,
     *     image_bytes: int,
     *     image_mime: null
     * }
     */
    private function errorResult(string $promptName, string $reason): array
    {
        return [
            'status' => 'error',
            'output' => null,
            'provider' => null,
            'model' => null,
            'prompt_name' => $promptName,
            'prompt_version' => null,
            'prompt_source' => null,
            'prompt_selector' => null,
            'token_usage' => null,
            'rejection' => $reason,
            'error' => $reason,
            'image_bytes' => 0,
            'image_mime' => null,
        ];
    }
}
