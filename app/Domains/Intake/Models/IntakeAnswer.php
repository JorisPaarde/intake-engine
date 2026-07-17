<?php

declare(strict_types=1);

namespace App\Domains\Intake\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $question_key
 * @property string|null $section_instance_key
 * @property array<string, mixed>|null $value
 */
class IntakeAnswer extends Model
{
    protected $fillable = [
        'intake_id',
        'question_key',
        'section_instance_key',
        'value',
        'answered_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'array',
            'answered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Intake, $this> */
    public function intake(): BelongsTo
    {
        return $this->belongsTo(Intake::class);
    }
}
