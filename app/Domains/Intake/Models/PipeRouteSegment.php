<?php

declare(strict_types=1);

namespace App\Domains\Intake\Models;

use App\Domains\AI\Models\AiRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eén beoordeelde routefoto binnen een leidingroute-sessie. `analysis` bevat de
 * volledige gestructureerde AI-uitkomst (photo_usable, visible_elements, route_possible,
 * route_segments, confidence, missing_information, next_photo_instruction).
 *
 * @property bool|null $photo_usable
 * @property bool|null $route_possible
 * @property float|null $confidence
 * @property array<string, mixed>|null $analysis
 */
class PipeRouteSegment extends Model
{
    protected $fillable = [
        'pipe_route_session_id',
        'intake_upload_id',
        'ai_run_id',
        'sequence',
        'label',
        'photo_usable',
        'route_possible',
        'confidence',
        'analysis',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'photo_usable' => 'boolean',
            'route_possible' => 'boolean',
            'confidence' => 'float',
            'analysis' => 'array',
        ];
    }

    /** @return BelongsTo<PipeRouteSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(PipeRouteSession::class, 'pipe_route_session_id');
    }

    /** @return BelongsTo<IntakeUpload, $this> */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(IntakeUpload::class, 'intake_upload_id');
    }

    /** @return BelongsTo<AiRun, $this> */
    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'ai_run_id');
    }
}
