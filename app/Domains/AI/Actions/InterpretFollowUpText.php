<?php

declare(strict_types=1);

namespace App\Domains\AI\Actions;

use App\Domains\AI\Exceptions\AiClientException;
use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiGateway;
use App\Domains\AI\Services\AiTraceRecorder;
use App\Domains\AI\Services\PromptVersionRepository;
use App\Domains\Intake\Models\Intake;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\AiTraceCallType;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Structured height hints from a follow-up text answer (ADR-0016).
 *
 * Code does not parse meaning of "nok" / "knieschot"; the model returns the
 * numbers. A number is kept only when it appears literally as a standalone
 * number in the source text (digit-boundary quote check).
 */
final class InterpretFollowUpText
{
    /** Hard ceiling so follow-up text never blocks the customer flow long. */
    public const TIMEOUT_SECONDS = 8;

    /**
     * @return array{
     *     peak_height_m: float|null,
     *     knee_wall_height_m: float|null,
     *     mentions_sloped_roof: bool
     * }
     */
    public const EMPTY_HEIGHT_HINTS = [
        'peak_height_m' => null,
        'knee_wall_height_m' => null,
        'mentions_sloped_roof' => false,
    ];

    public function __construct(
        private readonly AiGateway $aiGateway,
        private readonly PromptVersionRepository $promptVersions,
        private readonly AiTraceRecorder $traceRecorder,
    ) {}

    /**
     * @return array{
     *     peak_height_m: float|null,
     *     knee_wall_height_m: float|null,
     *     mentions_sloped_roof: bool
     * }
     */
    public function extractHeightHints(string $customerText, ?Intake $intake = null): array
    {
        $empty = self::EMPTY_HEIGHT_HINTS;
        $text = trim($customerText);

        if ($text === '') {
            return $empty;
        }

        if (! (bool) config('ai.text_inference.enabled', false)) {
            return $empty;
        }

        $provider = (string) config('ai.provider', 'null');
        if (in_array($provider, ['null', ''], true)) {
            return $empty;
        }

        $promptName = (string) config('ai.follow_up_text_prompt', 'follow_up_text');
        $promptVersion = $this->promptVersions->version($promptName);
        $promptBody = $this->promptVersions->body($promptName);
        $model = (string) config('ai.model', 'gpt-4o-mini');
        $temperature = (float) config('ai.classification_temperature', 0);
        $timeoutSeconds = self::TIMEOUT_SECONDS;
        $input = [
            'task' => 'follow_up_height_hints',
            'customer_text' => $text,
        ];
        $inputHash = hash('sha256', (string) json_encode($input, JSON_THROW_ON_ERROR));

        $run = null;
        $trace = null;

        if ($intake instanceof Intake) {
            $run = AiRun::query()->create([
                'intake_id' => $intake->id,
                'type' => AiRunType::FollowUpText,
                'provider' => $provider,
                'model' => $model,
                'prompt_version' => $promptVersion,
                'input_hash' => $inputHash,
                'output' => null,
                'status' => AiRunStatus::Pending,
                'started_at' => now(),
            ]);

            $trace = $this->traceRecorder->start($intake, AiTraceCallType::FollowUpText, [
                'ai_run_id' => $run->id,
                'provider' => $provider,
                'model' => $model,
                'prompt_version' => $promptVersion,
                'model_parameters' => [
                    'model' => $model,
                    'temperature' => $temperature,
                    'timeout_seconds' => $timeoutSeconds,
                ],
            ]);

            $trace->recordRequest(
                systemAndUser: [
                    'system' => $promptBody,
                    'user' => $input,
                ],
                promptVersion: $promptVersion,
                modelParameters: [
                    'model' => $model,
                    'temperature' => $temperature,
                    'timeout_seconds' => $timeoutSeconds,
                ],
            );
        }

        try {
            $result = $this->aiGateway->complete(
                prompt: $promptBody,
                input: $input,
                promptVersion: $promptVersion,
                model: $model,
                temperature: $temperature,
                timeoutSeconds: $timeoutSeconds,
            );
            $trace?->recordProviderResult($result);

            $output = $result->output;
            $hints = [
                'peak_height_m' => $this->acceptedNumber($output['peak_height_m'] ?? null, $text),
                'knee_wall_height_m' => $this->acceptedNumber($output['knee_wall_height_m'] ?? null, $text),
                'mentions_sloped_roof' => ($output['mentions_sloped_roof'] ?? false) === true,
            ];

            $trace?->recordParsed($hints);

            if ($run !== null) {
                $run->update($run->completionResultAttributes($result, $model) + [
                    'status' => AiRunStatus::Succeeded,
                    'output' => $hints,
                    'finished_at' => now(),
                    'error_message' => null,
                ]);
                // $run en $trace worden altijd samen aangemaakt.
                $trace->linkAiRun($run->fresh() ?? $run);
                $trace->stopProcessTimer();
                $trace->succeed();
            }

            return $hints;
        } catch (Throwable $exception) {
            Log::info('follow_up_text.extract_failed', [
                'intake_id' => $intake?->id,
                'ai_run_id' => $run?->id,
                'message' => $exception->getMessage(),
            ]);

            if ($run !== null) {
                $failAttributes = [
                    'status' => AiRunStatus::Failed,
                    'model' => $model,
                    'error_message' => Str::limit($exception->getMessage(), 1000, ''),
                    'finished_at' => now(),
                ];
                if ($exception instanceof AiClientException) {
                    if (is_string($exception->model) && $exception->model !== '') {
                        $failAttributes['model'] = $exception->model;
                    }
                    if (is_array($exception->usage)) {
                        $failAttributes['input_tokens'] = $exception->usage['input_tokens'] ?? null;
                        $failAttributes['output_tokens'] = $exception->usage['output_tokens'] ?? null;
                        $failAttributes['total_tokens'] = $exception->usage['total_tokens'] ?? null;
                    }
                }
                $run->update($failAttributes);
                $trace->linkAiRun($run->fresh() ?? $run);
                $trace->fail($exception->getMessage(), $exception);
            }

            return $empty;
        }
    }

    private function acceptedNumber(mixed $raw, string $source): ?float
    {
        if (! is_numeric($raw)) {
            return null;
        }

        $number = round((float) $raw, 2);
        if ($number <= 0 || $number > 20) {
            return null;
        }

        return $this->numberAppearsInSource($number, $source) ? $number : null;
    }

    /**
     * Pure quote check: the formatted number must appear as a standalone token
     * (digit/decimal boundaries), not as a substring of a larger number.
     */
    private function numberAppearsInSource(float $number, string $source): bool
    {
        $variants = array_unique([
            (string) $number,
            number_format($number, 1, '.', ''),
            number_format($number, 1, ',', ''),
            number_format($number, 2, '.', ''),
            number_format($number, 2, ',', ''),
        ]);

        // Digit or either decimal separator — so "2" does not match inside "2,6" / "2.6".
        $boundary = '0-9.,';

        foreach ($variants as $variant) {
            if ($variant === '') {
                continue;
            }

            $escaped = preg_quote($variant, '/');
            $pattern = '/(?<!['.$boundary.'])'.$escaped.'(?!['.$boundary.'])/u';

            if (preg_match($pattern, $source) === 1) {
                return true;
            }

            // Also accept the other decimal separator in the source text.
            $alt = str_contains($variant, ',')
                ? str_replace(',', '.', $variant)
                : str_replace('.', ',', $variant);
            if ($alt !== $variant) {
                $altEscaped = preg_quote($alt, '/');
                $altPattern = '/(?<!['.$boundary.'])'.$altEscaped.'(?!['.$boundary.'])/u';
                if (preg_match($altPattern, $source) === 1) {
                    return true;
                }
            }
        }

        return false;
    }
}
