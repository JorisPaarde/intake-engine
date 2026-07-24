<?php

declare(strict_types=1);

namespace App\Domains\AI\Models;

use App\Domains\AI\DTOs\AiCompletionResult;
use App\Domains\Intake\Models\Intake;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property AiRunType $type
 * @property AiRunStatus $status
 * @property array<string, mixed>|null $output
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property int|null $total_tokens
 * @property int $image_count
 * @property int|null $estimated_cost_cents
 */
class AiRun extends Model
{
    protected $fillable = [
        'intake_id',
        'type',
        'provider',
        'model',
        'prompt_version',
        'input_hash',
        'output',
        'status',
        'error_message',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'image_count',
        'estimated_cost_cents',
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
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'total_tokens' => 'integer',
            'image_count' => 'integer',
            'estimated_cost_cents' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Intake, $this> */
    public function intake(): BelongsTo
    {
        return $this->belongsTo(Intake::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function completionResultAttributes(AiCompletionResult $result, ?string $fallbackModel = null): array
    {
        return [
            'provider' => $result->provider,
            'model' => $result->model ?? $fallbackModel,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'total_tokens' => $result->totalTokens,
            'image_count' => $result->imageCount,
            'estimated_cost_cents' => $result->estimatedCostCents,
        ];
    }
}
