<?php

declare(strict_types=1);

namespace App\Domains\Intake\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $generated_at
 * @property Carbon|null $pdf_generated_at
 * @property string|null $pdf_disk
 * @property string|null $pdf_path
 */
class GeneratedReport extends Model
{
    protected $fillable = [
        'intake_id',
        'html',
        'pdf_disk',
        'pdf_path',
        'pdf_generated_at',
        'meta',
        'generated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'generated_at' => 'datetime',
            'pdf_generated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Intake, $this> */
    public function intake(): BelongsTo
    {
        return $this->belongsTo(Intake::class);
    }

    public function hasPdf(): bool
    {
        return filled($this->pdf_disk) && filled($this->pdf_path);
    }
}
