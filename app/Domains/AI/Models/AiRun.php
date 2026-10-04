<?php

declare(strict_types=1);

namespace App\Domains\AI\Models;

use App\Domains\AI\DTOs\AiCompletionResult;
use App\Domains\AI\Services\AiBudgetGuard;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property AiRunType $type
 * @property AiRunStatus $status
 * @property array<string, mixed>|null $output
 * @property int|null $upload_id
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property int|null $total_tokens
 * @property int $image_count
 * @property string|null $provider_request_id
 * @property int|null $estimated_cost_cents
 * @property int|null $estimated_cost_microcents
 */
class AiRun extends Model
{
    protected $fillable = [
        'intake_id',
        'upload_id',
        'type',
        'provider',
        'model',
        'prompt_version',
        'provider_request_id',
        'input_hash',
        'output',
        'status',
        'error_message',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'image_count',
        'estimated_cost_cents',
        'estimated_cost_microcents',
        'started_at',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AiRunType::class,
            'status' => AiRunStatus::class,
            'output' => 'array',
            'upload_id' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'total_tokens' => 'integer',
            'image_count' => 'integer',
            'estimated_cost_cents' => 'integer',
            'estimated_cost_microcents' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Intake, $this> */
    public function intake(): BelongsTo
    {
        return $this->belongsTo(Intake::class);
    }

    /** @return BelongsTo<IntakeUpload, $this> */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(IntakeUpload::class, 'upload_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function completionResultAttributes(AiCompletionResult $result, ?string $fallbackModel = null): array
    {
        $guard = app(AiBudgetGuard::class);

        // Prefer fine currency → microcents; fall back to whole-cent ceil for legacy/rate paths.
        $microcents = $guard->toMicrocentsFromCurrency($result->estimatedCost)
            ?? $guard->toMicrocents(
                $result->estimatedCostCents !== null ? (float) $result->estimatedCostCents : null
            );

        return [
            'provider' => $result->provider,
            'model' => $result->model ?? $fallbackModel,
            'provider_request_id' => $result->providerResponseId,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'total_tokens' => $result->totalTokens,
            'image_count' => $result->imageCount,
            'estimated_cost_cents' => $result->estimatedCostCents,
            'estimated_cost_microcents' => $microcents,
        ];
    }
}
