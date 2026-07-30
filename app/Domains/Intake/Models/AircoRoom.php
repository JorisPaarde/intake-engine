<?php

declare(strict_types=1);

namespace App\Domains\Intake\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $intake_id
 * @property int $company_id
 * @property int $dossier_subject_id
 * @property string $key
 * @property string $name
 * @property string|null $use_type
 * @property int $sort_order
 * @property string $status
 * @property string $source_type
 * @property int|null $source_id
 * @property array<string, float>|null $dimensions
 * @property-read DossierSubject $subject
 */
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
