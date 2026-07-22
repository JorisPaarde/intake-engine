<?php

declare(strict_types=1);

namespace App\Domains\AI\Actions;

use App\Domains\AI\DTOs\AiImageInput;
use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiGateway;
use App\Domains\AI\Services\PromptVersionRepository;
use App\Domains\Intake\Models\PipeRouteSegment;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Beoordeelt de foto van één routesegment met het route-analysemodel (config `ai.route.model`,
 * standaard GPT-5.6 Terra). Schrijft de gestructureerde JSON terug op het segment en legt de
 * call vast als AiRun. Soft-fail: een storing laat het segment onbeoordeeld, niet de flow.
 */
final class AnalyzeRoutePhoto
{
    public function __construct(
        private readonly AiGateway $aiGateway,
        private readonly PromptVersionRepository $promptVersions,
    ) {}

    public function handle(PipeRouteSegment $segment): PipeRouteSegment
    {
        if (! (bool) config('ai.route.enabled', false)) {
            return $segment;
        }

        $upload = $segment->upload;

        if ($upload === null) {
            return $segment;
        }

        $promptName = (string) config('ai.route.analysis_prompt', 'route_photo_analysis');
        $promptVersion = $this->promptVersions->version($promptName);
        $promptBody = $this->promptVersions->body($promptName);
        $model = (string) config('ai.route.model', 'gpt-5.6-terra');

        $input = [
            'task' => 'analyze_route_photo',
            'segment_role' => $segment->label ?? 'onbekend',
            'sequence' => $segment->sequence,
            'image' => [
                'upload_id' => $upload->id,
                'checksum' => $upload->checksum,
                'mime_type' => $upload->mime_type,
            ],
        ];

        $run = AiRun::query()->create([
            'intake_id' => $segment->session->intake_id,
            'type' => AiRunType::RouteAnalysis,
            'provider' => (string) config('ai.provider', 'null'),
            'model' => $model,
            'prompt_version' => $promptVersion,
            'input_hash' => hash('sha256', (string) json_encode([
                'prompt_version' => $promptVersion,
                'model' => $model,
                'input' => $input,
            ], JSON_THROW_ON_ERROR)),
            'output' => null,
            'status' => AiRunStatus::Pending,
            'started_at' => now(),
        ]);

        try {
            $result = $this->aiGateway->complete(
                prompt: $promptBody,
                input: $input,
                promptVersion: $promptVersion,
                images: [$this->imageInput($upload)],
                model: $model,
            );

            $output = $this->validateOutput($result->output);

            $run->update([
                'status' => AiRunStatus::Succeeded,
                'provider' => $result->provider,
                'model' => $result->model ?? $model,
                'output' => $output,
                'error_message' => null,
                'finished_at' => now(),
            ]);

            $segment->update([
                'ai_run_id' => $run->id,
                'photo_usable' => $output['photo_usable'],
                'route_possible' => $output['route_possible'],
                'confidence' => $output['confidence'],
                'analysis' => $output,
            ]);

            return $segment->fresh() ?? $segment;
        } catch (Throwable $exception) {
            Log::warning('Route photo analysis failed', [
                'segment_id' => $segment->id,
                'ai_run_id' => $run->id,
                'exception' => $exception::class,
            ]);

            $run->update([
                'status' => AiRunStatus::Failed,
                'error_message' => Str::limit($exception->getMessage(), 1000, ''),
                'finished_at' => now(),
            ]);

            return $segment;
        }
    }

    private function imageInput($upload): AiImageInput
    {
        if (! in_array($upload->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new \RuntimeException('Opgeslagen foto heeft geen ondersteund formaat voor beeldanalyse.');
        }

        return new AiImageInput(
            mimeType: $upload->mime_type,
            binary: Storage::disk($upload->disk)->get($upload->path),
        );
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    private function validateOutput(array $output): array
    {
        $validator = Validator::make($output, [
            'photo_usable' => ['required', 'boolean'],
            'visible_elements' => ['present', 'array'],
            'visible_elements.*' => ['string', 'max:200'],
            'route_possible' => ['required', 'boolean'],
            'route_segments' => ['present', 'array'],
            'route_segments.*' => ['string', 'max:200'],
            'confidence' => ['required', 'numeric', 'between:0,1'],
            'missing_information' => ['present', 'array'],
            'missing_information.*' => ['string', 'max:200'],
            'next_photo_instruction' => ['present', 'string', 'max:300'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();
        $validated['photo_usable'] = (bool) $validated['photo_usable'];
        $validated['route_possible'] = (bool) $validated['route_possible'];
        $validated['confidence'] = round((float) $validated['confidence'], 3);
        $validated['next_photo_instruction'] = trim((string) $validated['next_photo_instruction']);

        return $validated;
    }
}
