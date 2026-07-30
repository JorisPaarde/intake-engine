<?php

declare(strict_types=1);

namespace App\Domains\Intake\Models;

use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DossierSubject extends Model
{
    protected $fillable = [
        'intake_id',
        'company_id',
        'parent_id',
        'type',
        'key',
        'label',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'intake_id' => 'integer',
            'company_id' => 'integer',
            'parent_id' => 'integer',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<Intake, $this> */
    public function intake(): BelongsTo
    {
        return $this->belongsTo(Intake::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<DossierSubject, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<DossierSubject, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<DossierRecord, $this> */
    public function records(): HasMany
    {
        return $this->hasMany(DossierRecord::class);
    }

    /** @return HasMany<DossierEvidenceLink, $this> */
    public function evidenceLinks(): HasMany
    {
        return $this->hasMany(DossierEvidenceLink::class);
    }
}
