<?php

declare(strict_types=1);

namespace App\Domains\AI\Models;

use App\Domains\Intake\Models\Intake;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property AiRunType $type
 * @property AiRunStatus $status
 * @property array<string, mixed>|null $output
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
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Intake, $this> */
    public function intake(): BelongsTo
    {
        return $this->belongsTo(Intake::class);
    }
}
