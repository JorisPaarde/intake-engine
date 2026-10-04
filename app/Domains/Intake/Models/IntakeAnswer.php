<?php

declare(strict_types=1);

namespace App\Domains\Intake\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $question_key
 * @property string|null $section_instance_key
 * @property array<string, mixed>|null $value
 * @property string|null $prefill_source
 * @property string|null $fact_provenance
 * @property int|null $fact_confidence
 * @property string|null $fact_evidence
 * @property string|null $fact_source
 */
class IntakeAnswer extends Model
{
    protected $fillable = [
        'intake_id',
        'question_key',
        'section_instance_key',
        'value',
        'prefill_source',
        'fact_provenance',
        'fact_confidence',
        'fact_evidence',
        'fact_source',
        'answered_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'intake_id' => 'integer',
            'value' => 'array',
            'fact_confidence' => 'integer',
            'answered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Intake, $this> */
    public function intake(): BelongsTo
    {
        return $this->belongsTo(Intake::class);
    }
}
