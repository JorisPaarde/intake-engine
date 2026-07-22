<?php

declare(strict_types=1);

namespace App\Domains\Intake\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $fact_key
 * @property string $label
 * @property array<string, mixed> $value
 * @property string $source
 * @property string|null $source_reference
 * @property string|null $source_url
 * @property string $confidence
 * @property Carbon $captured_at
 */
class IntakeExternalFact extends Model
{
    protected $fillable = [
        'intake_id',
        'fact_key',
        'label',
        'value',
        'source',
        'source_reference',
        'source_url',
        'confidence',
        'captured_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'intake_id' => 'integer',
            'value' => 'array',
            'captured_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Intake, $this> */
    public function intake(): BelongsTo
    {
        return $this->belongsTo(Intake::class);
    }
}
