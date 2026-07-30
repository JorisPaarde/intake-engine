<?php

declare(strict_types=1);

namespace App\Domains\Intake\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AircoRoom extends Model
{
    protected $fillable = [
        'intake_id',
        'company_id',
        'dossier_subject_id',
        'key',
        'name',
        'use_type',
        'sort_order',
        'status',
        'source_type',
        'source_id',
        'dimensions',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'intake_id' => 'integer',
            'company_id' => 'integer',
            'dossier_subject_id' => 'integer',
            'sort_order' => 'integer',
            'source_id' => 'integer',
            'dimensions' => 'array',
        ];
    }

    /** @return BelongsTo<Intake, $this> */
    public function intake(): BelongsTo
    {
        return $this->belongsTo(Intake::class);
    }

    /** @return BelongsTo<DossierSubject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(DossierSubject::class, 'dossier_subject_id');
    }

    /** @return HasMany<AircoPlacementOption, $this> */
    public function placements(): HasMany
    {
        return $this->hasMany(AircoPlacementOption::class);
    }
}
