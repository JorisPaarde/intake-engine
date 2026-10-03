<?php

declare(strict_types=1);

namespace App\Domains\AI\Models;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\AiTraceCallType;
use App\Enums\AiTraceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $trace_id
 * @property int $intake_id
 * @property int|null $ai_run_id
 * @property int|null $upload_id
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property AiTraceCallType $call_type
 * @property AiTraceStatus $status
 * @property string|null $provider
 * @property string|null $model
 * @property array<string, mixed>|null $model_parameters
 * @property string|null $prompt_version
 * @property string|null $schema_version
 * @property bool $fallback_used
 * @property int $retry_count
 * @property array<string, mixed>|null $request_snapshot
 * @property array<int, array<string, mixed>>|null $photo_refs
 * @property string|null $raw_response
 * @property string|null $finish_reason
 * @property array<string, mixed>|null $parsed_response
 * @property array<string, mixed>|null $validation_errors
 * @property array<int, array<string, mixed>>|null $normalizations
 * @property array<int, array<string, mixed>>|null $field_outcomes
 * @property array<string, mixed>|null $dossier_before
 * @property array<string, mixed>|null $dossier_after
 * @property array<string, mixed>|null $remaining_questions_before
 * @property array<string, mixed>|null $remaining_questions_after
 * @property int|null $network_upload_ms
 * @property int|null $persist_ms
 * @property int|null $preprocess_ms
 * @property int|null $provider_ms
 * @property int|null $process_ms
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property int|null $total_tokens
 * @property int|null $estimated_cost_cents
 * @property string|null $error_message
 */
class AiTrace extends Model
{
    protected $fillable = [
        'trace_id',
        'intake_id',
        'ai_run_id',
        'upload_id',
        'subject_type',
        'subject_id',
        'call_type',
        'status',
        'provider',
        'model',
        'model_parameters',
        'prompt_version',
        'schema_version',
        'fallback_used',
        'retry_count',
        'request_snapshot',
        'photo_refs',
        'raw_response',
        'finish_reason',
        'parsed_response',
        'validation_errors',
        'normalizations',
        'field_outcomes',
        'dossier_before',
        'dossier_after',
        'remaining_questions_before',
        'remaining_questions_after',
        'network_upload_ms',
        'persist_ms',
        'preprocess_ms',
        'provider_ms',
        'process_ms',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'estimated_cost_cents',
        'error_message',
        'started_at',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'call_type' => AiTraceCallType::class,
            'status' => AiTraceStatus::class,
            'model_parameters' => 'array',
            'request_snapshot' => 'array',
            'photo_refs' => 'array',
            'parsed_response' => 'array',
            'validation_errors' => 'array',
            'normalizations' => 'array',
            'field_outcomes' => 'array',
            'dossier_before' => 'array',
            'dossier_after' => 'array',
            'remaining_questions_before' => 'array',
            'remaining_questions_after' => 'array',
            'fallback_used' => 'boolean',
            'retry_count' => 'integer',
            'network_upload_ms' => 'integer',
            'persist_ms' => 'integer',
            'preprocess_ms' => 'integer',
            'provider_ms' => 'integer',
            'process_ms' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'total_tokens' => 'integer',
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

    /** @return BelongsTo<AiRun, $this> */
    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class);
    }

    /** @return BelongsTo<IntakeUpload, $this> */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(IntakeUpload::class, 'upload_id');
    }

    /** @return HasMany<AiTraceStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(AiTraceStep::class)->orderBy('sequence')->orderBy('id');
    }
}
