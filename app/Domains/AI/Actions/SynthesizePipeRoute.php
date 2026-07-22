<?php

declare(strict_types=1);

namespace App\Domains\AI\Actions;

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiGateway;
use App\Domains\AI\Services\PromptVersionRepository;
use App\Domains\Intake\Models\PipeRouteSegment;
use App\Domains\Intake\Models\PipeRouteSession;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\PipeRouteStatus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Vat de per-foto beoordelingen van een sessie samen tot één routebeoordeling met het
 * route-analysemodel (Terra). Bij lage zekerheid of een niet-doorlopende route wordt de
 * synthese opnieuw gedaan met het capabelere review-model (Sol); de installateur keurt de
 * uitkomst altijd zelf goed. Soft-fail: een storing laat de sessie ongewijzigd.
 */
final class SynthesizePipeRoute
{
    public function __construct(
        private readonly AiGateway $aiGateway,
        private readonly PromptVersionRepository $promptVersions,
    ) {}

    public function handle(PipeRouteSession $session): PipeRouteSession
    {
        if (! (bool) config('ai.route.enabled', false)) {
            return $session;
        }

        $segments = $session->segments()->whereNotNull('analysis')->get();

        if ($segments->isEmpty()) {
            return $session;
        }

        $promptName = (string) config('ai.route.synthesis_prompt', 'route_synthesis');
        $promptVersion = $this->promptVersions->version($promptName);
        $promptBody = $this->promptVersions->body($promptName);

        $input = [
            'task' => 'synthesize_pipe_route',
            'segments' => $segments->map(static fn (PipeRouteSegment $segment): array => [
                'sequence' => $segment->sequence,
                'role' => $segment->label ?? 'onbekend',
                'photo_usable' => $segment->photo_usable,
                'route_possible' => $segment->route_possible,
                'confidence' => $segment->confidence,
                'visible_elements' => $segment->analysis['visible_elements'] ?? [],
                'route_segments' => $segment->analysis['route_segments'] ?? [],
                'missing_information' => $segment->analysis['missing_information'] ?? [],
            ])->values()->all(),
        ];

        $threshold = (float) config('ai.route.escalate_below_confidence', 0.7);
        $primaryModel = (string) config('ai.route.model', 'gpt-5.6-terra');
        $reviewModel = (string) config('ai.route.review_model', 'gpt-5.6-sol');

        try {
            $output = $this->run($session, $promptBody, $input, $promptVersion, $primaryModel);

            // Onduidelijke of niet-doorlopende route → tweede beoordeling met het zwaardere model.
            if ($output['route_continuous'] === false || $output['confidence'] < $threshold) {
                $review = $this->run($session, $promptBody, $input, $promptVersion, $reviewModel);

                if ($review['confidence'] >= $output['confidence']) {
                    $output = $review;
                }
            }
        } catch (Throwable $exception) {
            Log::warning('Pipe route synthesis failed', [
                'session_id' => $session->id,
                'exception' => $exception::class,
            ]);

            return $session;
        }

        $session->update([
            'status' => PipeRouteStatus::Proposed,
            'confidence' => $output['confidence'],
            'proposed_route' => $output['proposed_route'],
            'alternative_route' => $output['alternative_route'],
            'uncertainties' => $output['uncertainties'],
            'missing_checks' => $output['missing_checks'],
            'next_photo_instruction' => $output['next_photo_instruction'] !== '' ? $output['next_photo_instruction'] : null,
        ]);

        return $session->fresh() ?? $session;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function run(
        PipeRouteSession $session,
        string $promptBody,
        array $input,
        string $promptVersion,
        string $model,
    ): array {
        $run = AiRun::query()->create([
            'intake_id' => $session->intake_id,
            'type' => AiRunType::RouteSynthesis,
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

            return $output;
        } catch (Throwable $exception) {
            $run->update([
                'status' => AiRunStatus::Failed,
                'error_message' => Str::limit($exception->getMessage(), 1000, ''),
                'finished_at' => now(),
            ]);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    private function validateOutput(array $output): array
    {
        $validator = Validator::make($output, [
            'route_continuous' => ['required', 'boolean'],
            'proposed_route' => ['present', 'array'],
            'proposed_route.*' => ['string', 'max:200'],
            'alternative_route' => ['present', 'array'],
            'alternative_route.*' => ['string', 'max:200'],
            'uncertainties' => ['present', 'array'],
            'uncertainties.*' => ['string', 'max:200'],
            'missing_checks' => ['present', 'array'],
            'missing_checks.*' => ['string', 'max:200'],
            'confidence' => ['required', 'numeric', 'between:0,1'],
            'next_photo_instruction' => ['present', 'string', 'max:300'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();
        $validated['route_continuous'] = (bool) $validated['route_continuous'];
        $validated['confidence'] = round((float) $validated['confidence'], 3);
        $validated['next_photo_instruction'] = trim((string) $validated['next_photo_instruction']);

        return $validated;
    }
}
