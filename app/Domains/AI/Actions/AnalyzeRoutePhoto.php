<?php

declare(strict_types=1);

namespace App\Domains\AI\Actions;

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiGateway;
use App\Domains\AI\Services\AiImageResolver;
use App\Domains\AI\Services\AiTracePhotoRefBuilder;
use App\Domains\AI\Services\AiTraceRecorder;
use App\Domains\AI\Services\PromptVersionRepository;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\PipeRouteSegment;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\AiTraceCallType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        private readonly AiImageResolver $aiImageResolver,
        private readonly PromptVersionRepository $promptVersions,
        private readonly AiTraceRecorder $traceRecorder,
        private readonly AiTracePhotoRefBuilder $photoRefBuilder,
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

        $segment->loadMissing('session');
        $intake = Intake::query()->find($segment->session->intake_id);

        if (! $intake instanceof Intake) {
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
            'image' => $this->aiImageResolver->identity($upload),
        ];

        $run = AiRun::query()->create([
            'intake_id' => $intake->id,
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

        $trace = $this->traceRecorder->start($intake, AiTraceCallType::PhotoAnalysis, [
            'ai_run_id' => $run->id,
            'upload_id' => $upload->id,
            'subject_type' => 'pipe_route_segment',
            'subject_id' => (string) $segment->id,
            'provider' => (string) config('ai.provider', 'null'),
            'prompt_version' => $promptVersion,
        ]);
        $trace->linkUpload($upload);

        try {
            $photoRefs = $trace->isNoop() ? [] : [$this->photoRefBuilder->fromUpload($upload, 'route')];
            $trace->recordRequest(
                systemAndUser: [
                    'system' => $promptBody,
                    'user' => $input,
                ],
                photoRefs: $photoRefs,
                promptVersion: $promptVersion,
            );

            $result = $this->aiGateway->complete(
                prompt: $promptBody,
                input: $input,
                promptVersion: $promptVersion,
                images: [$this->aiImageResolver->input($upload)],
                model: $model,
            );
            $trace->recordProviderResult($result);

            try {
                [$output, $normalizations] = $this->validateOutput($result->output);
                $trace->recordParsed($output, [], $normalizations);
            } catch (ValidationException $exception) {
                $trace->recordParsed([], $exception->errors());
                throw $exception;
            }

            $run->update($run->completionResultAttributes($result, $model) + [
                'status' => AiRunStatus::Succeeded,
                'output' => $output,
                'error_message' => null,
                'finished_at' => now(),
            ]);

            try {
                $trace->beginBuffer();
                $updated = DB::transaction(function () use ($segment, $run, $output, $input, $trace): PipeRouteSegment {
                    $intakeId = $segment->session()->value('intake_id');
                    Intake::query()->whereKey($intakeId)->lockForUpdate()->firstOrFail();
                    $segment = PipeRouteSegment::query()->with('upload')->whereKey($segment->id)->lockForUpdate()->firstOrFail();

                    if ($segment->sequence !== $input['sequence']
                        || ($segment->label ?? 'onbekend') !== $input['segment_role']
                        || $segment->upload === null
                        || $this->aiImageResolver->identity($segment->upload) !== $input['image']) {
                        throw new \RuntimeException('Routefoto gewijzigd tijdens AI-analyse; resultaat niet toegepast.');
                    }

                    $segment->update([
                        'ai_run_id' => $run->id,
                        'photo_usable' => $output['photo_usable'],
                        'route_possible' => $output['route_possible'],
                        'confidence' => $output['confidence'],
                        'analysis' => $output,
                    ]);
                    $trace->step('apply', [
                        'segment_id' => $segment->id,
                        'photo_usable' => $output['photo_usable'],
                        'route_possible' => $output['route_possible'],
                        'confidence' => $output['confidence'],
                    ]);

                    return $segment->fresh() ?? $segment;
                }, 3);
                $trace->flushBuffer();
            } catch (Throwable $transactionException) {
                $trace->discardBuffer();
                throw $transactionException;
            }

            $trace->linkAiRun($run->fresh() ?? $run);
            $trace->stopProcessTimer();
            $trace->succeed();

            return $updated;
        } catch (Throwable $exception) {
            Log::warning('Route photo analysis failed', [
                'segment_id' => $segment->id,
                'ai_run_id' => $run->id,
                'ai_trace_id' => $trace->traceId(),
                'exception' => $exception::class,
            ]);

            $run->update([
                'status' => AiRunStatus::Failed,
                'error_message' => Str::limit($exception->getMessage(), 1000, ''),
                'finished_at' => now(),
            ]);
            $trace->linkAiRun($run->fresh() ?? $run);
            $trace->discardBuffer();
            $trace->fail($exception->getMessage(), $exception);

            return $segment;
        }
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array{0: array<string, mixed>, 1: list<array{field: string, from: mixed, to: mixed, rule: string}>}
     */
    private function validateOutput(array $output): array
    {
        /** @var list<array{field: string, from: mixed, to: mixed, rule: string}> $normalizations */
        $normalizations = [];
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

        foreach (['photo_usable', 'route_possible'] as $boolField) {
            $from = $validated[$boolField];
            $to = (bool) $validated[$boolField];
            if ($from !== $to) {
                $normalizations[] = [
                    'field' => $boolField,
                    'from' => $from,
                    'to' => $to,
                    'rule' => 'boolean_cast',
                ];
            }
            $validated[$boolField] = $to;
        }

        $confidenceFrom = $validated['confidence'];
        $validated['confidence'] = round((float) $validated['confidence'], 3);
        if ($validated['confidence'] !== $confidenceFrom) {
            $normalizations[] = [
                'field' => 'confidence',
                'from' => $confidenceFrom,
                'to' => $validated['confidence'],
                'rule' => 'round_3',
            ];
        }

        $instructionFrom = $validated['next_photo_instruction'];
        $validated['next_photo_instruction'] = trim((string) $validated['next_photo_instruction']);
        if ($validated['next_photo_instruction'] !== $instructionFrom) {
            $normalizations[] = [
                'field' => 'next_photo_instruction',
                'from' => $instructionFrom,
                'to' => $validated['next_photo_instruction'],
                'rule' => 'trim',
            ];
        }

        return [$validated, $normalizations];
    }
}
