<?php

declare(strict_types=1);

namespace App\Domains\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed>|null $payload
 */
class AiTraceStep extends Model
{
    protected $fillable = [
        'ai_trace_id',
        'step_key',
        'sequence',
        'payload',
        'duration_ms',
        'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'duration_ms' => 'integer',
            'sequence' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AiTrace, $this> */
    public function trace(): BelongsTo
    {
        return $this->belongsTo(AiTrace::class, 'ai_trace_id');
    }
}
